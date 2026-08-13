#!/usr/bin/env python3
"""Genera src/lib/data/<evento>.json a partir del CSV crudo del RUFE.

Uso:
    python3 scripts/build_data.py [--input data/raw/rufe-sismo-2026-08-10.csv]
                                   [--output src/lib/data/rufe-sismo-2026-08-10.json]

El CSV de entrada es una exportación del formulario RUFE tal cual lo entrega
Google Sheets/Excel (con las filas de encabezado institucional al inicio).
Este script:
  1. Parsea las columnas fijas del formulario (posición, no nombre de columna,
     porque el encabezado real está partido en varias filas y con acentos
     corruptos en el archivo de origen).
  2. Rellena corregimiento/barrio hacia adelante dentro de un mismo hogar
     (solo el primer integrante trae esos datos en el formulario físico).
  3. Infiere género desde la marca "X" en las columnas M/F, y edad desde la
     columna EDAD ya calculada en el formulario.
  4. Clasifica cada persona en zona Urbana/Rural según el corregimiento, con
     un diccionario de corregimientos rurales conocidos de Jamundí.
  5. Agrupa por barrio/vereda (para zona rural, agrupa por corregimiento en
     vez de por sector, porque el sector fragmenta demasiado un mismo
     corregimiento) y escribe el JSON que consume src/lib/data.ts.

Para una nueva emergencia: cambia --input/--output y ajusta RURAL/CANON_*
si aparecen corregimientos o barrios que este script no reconoce todavía
(el propio script imprime la lista de corregimientos vistos para revisar).
"""

from __future__ import annotations

import argparse
import csv
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

# Corregimientos rurales conocidos de Jamundí (Valle del Cauca). Cualquier
# corregimiento fuera de esta lista (incluido vacío, o "JAMUNDI"/"TERRANOVA",
# que en este formulario aparecen por error en la columna de corregimiento)
# se clasifica como Urbana.
RURAL = {
	"QUINAMAYO",
	"ROBLES",
	"CHAGRES",
	"POTRERITO",
	"SAN ANTONIO",
	"TIMBA",
	"AMPUDIA",
	"PUENTE VELEZ",
	"VILLA PAZ",
	"VILLA COLOMBIA",
	"SAN ISIDRO",
	"SAN VICENTE",
	"LA FERRERIRA",
	"CHONTADURO",
	"PEON",
	"CLAVELLINAS",
	"GUACHINTE",
}

# Variantes de escritura del mismo corregimiento vistas en el CSV crudo.
CANON_CORE = {
	"VILLACOLOMBIA": "VILLA COLOMBIA",
	"CLAVELLINA": "CLAVELLINAS",
}

# Variantes de escritura del mismo barrio/urbanización.
CANON_BARRIO = {
	"OASIS - TERRANOVA": "OASIS DE TERRANOVA",
	"PAISAJE LAS FLORES": "PAISAJE DE LAS FLORES",
	"PARQUES DE CASTILLO": "PARQUES DE CASTILLA",
	"TERRANOVA-SECTOR J": "TERRANOVA SECTOR J",
	"SECTOR LA J": "TERRANOVA SECTOR J",
	"PANGOLA-": "PANGOLA",
	"PANGOLA TORRE 1 APTO 1008": "PANGOLA",
	"PANGOLA MIRADOR DEL RIO": "PANGOLA",
	"CIUDADELA DE TERRANOVA": "TERRANOVA",
	"ALAMEDA DE RIO CLARO": "ALAMEDA RIO CLARO",
	"VILLA LAS PALMAS": "LAS PALMAS",
	"LA ESTACIÃN": "LA ESTACION",
	"BONANZA - TULIPANES": "BONANZA TULIPANES",
}

ACCENT_WORDS = {
	"JORDAN": "JORDÁN",
	"ESTACION": "ESTACIÓN",
	"RIO": "RÍO",
	"BOLIVAR": "BOLÍVAR",
	"MARIA": "MARÍA",
}

# Número de filas de encabezado institucional antes de la primera fila de
# datos (título del formulario, código, versión, y las dos filas de
# encabezado de columna partidas). Ver README.md para el detalle de columnas.
HEADER_ROWS = 8

# Índices de columna (0-based) dentro de cada fila de datos.
COL_HOGAR = 1
COL_CORREGIMIENTO = 2
COL_BARRIO = 3
COL_NOMBRE = (5, 6, 7)
COL_APELLIDO = (8, 9, 10, 11)
COL_DOCUMENTO = 15
COL_PARENTESCO = 19
COL_GENERO_M = 21
COL_GENERO_F = 22
COL_EDAD = 27
MIN_COLS = 40


def clean(s: str | None) -> str:
	return re.sub(r"\s+", " ", (s or "")).strip()


def fix_accents(s: str) -> str:
	return " ".join(ACCENT_WORDS.get(w, w) for w in s.split(" "))


def title_case(s: str) -> str:
	small = {"de", "la", "las", "los", "del", "el", "y"}
	words = fix_accents(s).split(" ")
	out = []
	for i, w in enumerate(words):
		lw = w.lower()
		out.append(lw if i > 0 and lw in small else lw.capitalize())
	return " ".join(out)


def zona_de(corregimiento_upper: str) -> str:
	return "Rural" if corregimiento_upper in RURAL else "Urbana"


def age_bucket(edad: int | None) -> str:
	if edad is None:
		return "Sin dato"
	if edad <= 11:
		return "Ninos"
	if edad <= 28:
		return "Jovenes"
	if edad <= 59:
		return "Adultos"
	return "Adultos Mayores"


def parse_records(csv_path: Path) -> list[dict]:
	with csv_path.open(encoding="utf-8", newline="") as f:
		rows = list(csv.reader(f))

	data_rows = rows[HEADER_ROWS:]
	core_by_hogar: dict[str, str] = {}
	barrio_by_hogar: dict[str, str] = {}
	records = []

	for r in data_rows:
		if len(r) < MIN_COLS:
			r = r + [""] * (MIN_COLS - len(r))

		hogar = clean(r[COL_HOGAR])
		core = clean(r[COL_CORREGIMIENTO])
		barrio = clean(r[COL_BARRIO])
		nombre = clean(" ".join(clean(r[i]) for i in COL_NOMBRE))
		apellido = clean(" ".join(clean(r[i]) for i in COL_APELLIDO))
		doc = clean(r[COL_DOCUMENTO])
		gm = clean(r[COL_GENERO_M]).upper()
		gf = clean(r[COL_GENERO_F]).upper()
		edad_raw = clean(r[COL_EDAD])

		# Solo el primer integrante de cada hogar trae corregimiento/barrio en
		# el formulario físico; se propaga a los demás integrantes del mismo
		# hogar (mismo número consecutivo de HOGAR).
		if hogar:
			if core:
				core_by_hogar[hogar] = core
			elif hogar in core_by_hogar:
				core = core_by_hogar[hogar]
			if barrio:
				barrio_by_hogar[hogar] = barrio
			elif hogar in barrio_by_hogar:
				barrio = barrio_by_hogar[hogar]

		# Filas de relleno del formulario (sin nombre/apellido/documento).
		if not nombre and not apellido and not doc:
			continue

		genero = "M" if gm == "X" else "F" if gf == "X" else None

		edad = None
		try:
			e = int(float(edad_raw))
			if 0 <= e <= 115:
				edad = e
		except ValueError:
			pass

		records.append(
			{
				"hogar": hogar,
				"corregimiento": core,
				"barrio": barrio,
				"genero": genero,
				"edad": edad,
			}
		)

	return records


def build_dataset(records: list[dict], as_of: str) -> dict:
	barrio_agg: dict[str, dict] = defaultdict(
		lambda: {
			"total": 0,
			"M": 0,
			"F": 0,
			"Ninos": 0,
			"Jovenes": 0,
			"Adultos": 0,
			"AdultosMayores": 0,
			"zona": None,
		}
	)
	corregimientos_vistos = Counter()

	for rec in records:
		core_u = CANON_CORE.get(clean(rec["corregimiento"]).upper(), clean(rec["corregimiento"]).upper())
		barrio_u = CANON_BARRIO.get(clean(rec["barrio"]).upper(), clean(rec["barrio"]).upper())
		corregimientos_vistos[core_u] += 1

		# Filas donde el corregimiento quedó vacío pero el propio nombre del
		# corregimiento rural quedó escrito en el campo de barrio (ver
		# hogares 91 y 117 del sismo de agosto 2026: "SAN ISIDRO"/"CHAGRES"
		# en el campo de barrio, corregimiento vacío). Sin esto, esas
		# personas quedan mal clasificadas como Urbana y, peor, corrompen la
		# zona de TODO el grupo de barrio al que terminan uniéndose.
		if not core_u and barrio_u in RURAL:
			core_u = barrio_u

		zona = zona_de(core_u)
		label_raw = (core_u or barrio_u or "SIN ESPECIFICAR") if zona == "Rural" else (barrio_u or core_u or "SIN ESPECIFICAR")
		label = "Sin especificar" if label_raw == "SIN ESPECIFICAR" else title_case(label_raw)

		b = barrio_agg[label]
		b["total"] += 1
		if b["zona"] is not None and b["zona"] != zona:
			raise AssertionError(
				f"zona mixta bajo la etiqueta {label!r}: {b['zona']} vs {zona} "
				"(revisa RURAL/CANON_CORE/CANON_BARRIO para este corregimiento/barrio)"
			)
		b["zona"] = zona

		if rec["genero"] == "M":
			b["M"] += 1
		elif rec["genero"] == "F":
			b["F"] += 1

		bucket = age_bucket(rec["edad"])
		if bucket == "Ninos":
			b["Ninos"] += 1
		elif bucket == "Jovenes":
			b["Jovenes"] += 1
		elif bucket == "Adultos":
			b["Adultos"] += 1
		elif bucket == "Adultos Mayores":
			b["AdultosMayores"] += 1

	barrios = [{"name": name, **agg} for name, agg in sorted(barrio_agg.items(), key=lambda kv: -kv[1]["total"])]

	print("Corregimientos vistos en el CSV (revisar si falta alguno en RURAL):")
	for k, v in corregimientos_vistos.most_common():
		print(f"  {k!r}: {v}")

	return {"total": len(records), "asOf": as_of, "barrios": barrios}


def main() -> None:
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--input", default="data/raw/rufe-sismo-2026-08-10.csv")
	parser.add_argument("--output", default="src/lib/data/rufe-sismo-2026-08-10.json")
	parser.add_argument("--as-of", default="2026-08-12 19:00", help="Fecha/hora de corte del registro")
	args = parser.parse_args()

	repo_root = Path(__file__).resolve().parent.parent
	input_path = repo_root / args.input
	output_path = repo_root / args.output

	records = parse_records(input_path)
	dataset = build_dataset(records, args.as_of)

	print(f"\nTotal personas: {dataset['total']}")
	print(f"Barrios/veredas: {len(dataset['barrios'])}")
	urbana = sum(b["total"] for b in dataset["barrios"] if b["zona"] == "Urbana")
	rural = sum(b["total"] for b in dataset["barrios"] if b["zona"] == "Rural")
	print(f"Urbana: {urbana}  Rural: {rural}  (suma {urbana + rural})")

	output_path.parent.mkdir(parents=True, exist_ok=True)
	output_path.write_text(json.dumps(dataset, ensure_ascii=False, indent=2), encoding="utf-8")
	print(f"\nEscrito {output_path}")


if __name__ == "__main__":
	main()
