import Papa from 'papaparse';
import type {
	ConceptoTecnico,
	Evacuacion,
	InstEducativasDataset,
	Prioridad,
	SiNo,
	Sede
} from './types';

// Una sola fila de encabezado (a diferencia del RUFE, que trae 8). Se mapea
// por posición, no por nombre de columna: los encabezados reales traen
// saltos de línea y "Seleccione la opción" pegado, y no vale la pena
// depender de que ese texto nunca cambie en la hoja.
const COL = {
	establecimiento: 6,
	sede: 8,
	zona: 9,
	direccion: 10,
	barrio: 11,
	matricula: 14,
	estadoFisico: 15,
	estudiantesAfectados: 16,
	usadaComoAlbergue: 17,
	viasDeAcceso: 18,
	suspendieronClases: 19,
	danosObservados: 20,
	accionesEtc: 21,
	conceptoTecnico: 22,
	requiereEvacuacion: 23,
	prioridad: 24,
	requiereVisitaTecnica: 25,
	observaciones: 26
} as const;
const MIN_COLS = 27;

function clean(v: string | undefined): string {
	return (v ?? '').replace(/\s+/g, ' ').trim();
}

function toNumber(v: string): number {
	const n = Number(clean(v).replace(/\./g, '').replace(',', '.'));
	return Number.isFinite(n) ? n : 0;
}

/**
 * Las columnas Sí/No de este formulario llegan muy inconsistentes (SI, Si,
 * si, y varias veces una frase completa pegada en vez de elegir la opción:
 * "SI existe acceso físico a la sede, pero..."). Se clasifica por la
 * palabra con la que EMPIEZA el texto, que en la práctica es suficiente
 * para las ~58 sedes ya cargadas — ver parse.spec.ts para los casos reales
 * encontrados en la hoja.
 */
function canonSiNo(raw: string): SiNo {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/^s[ií]\b/i.test(v)) return 'Sí';
	if (/^no\b/i.test(v)) return 'No';
	return 'Sin dato';
}

function canonEvacuacion(raw: string): Evacuacion {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/^parcial/i.test(v)) return 'Parcial';
	if (/^s[ií]\b/i.test(v)) return 'Sí';
	if (/^no\b/i.test(v)) return 'No';
	return 'Sin dato';
}

/** Además de Sí/No, esta columna trae un tercer estado real ("en proceso",
 * "proceso inicial visual") que no existe en las demás — no se puede
 * canonicalizar con `canonSiNo`. */
function canonConceptoTecnico(raw: string): ConceptoTecnico {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/proceso/i.test(v)) return 'En proceso';
	if (/^s[ií]\b/i.test(v)) return 'Sí';
	if (/^no\b/i.test(v) || /^n[oó]\s+existe/i.test(v)) return 'No';
	return 'Sin dato';
}

/** Además de Sí/No/"2" (dato basura), algunas filas respondieron con la
 * frase "Requiere visita técnica en…" en vez de elegir la opción. */
function canonRequiereVisita(raw: string): SiNo {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/^requiere\b/i.test(v)) return 'Sí';
	return canonSiNo(v);
}

/**
 * Esta columna mezcla dos preguntas distintas a lo largo de la vida de la
 * hoja: unas filas traen un nivel de prioridad real (ALTA/MEDIA/BAJA/
 * INMEDIATA/Mínima, a veces con una frase pegada como "MEDIA ALTA: LA SEDE
 * PRESENTA…"), y otras traen solo "SI"/"NO" — probablemente respondiendo
 * "¿requiere atención prioritaria?" en vez de indicar el nivel. Un "SI"/"NO"
 * suelto no permite saber el nivel real, así que se clasifica como "Sin
 * dato" en vez de inventarlo. Se revisa primero la palabra clave más grave
 * (evita que "MEDIA ALTA" caiga en "Media" por contener esa palabra).
 */
function canonPrioridad(raw: string): Prioridad {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/INMEDIATA|URGENTE/i.test(v)) return 'Inmediata';
	if (/ALTA/i.test(v)) return 'Alta';
	if (/MEDIA/i.test(v)) return 'Media';
	if (/BAJA|M[ií]nima/i.test(v)) return 'Baja';
	return 'Sin dato';
}

// A diferencia del RUFE de personas (que no trae zona explícita y hay que
// inferirla del corregimiento), esta hoja sí trae una columna ZONA directa
// con solo dos valores limpios (RURAL/URBANA) — no hace falta inferir nada.
function zonaDe(raw: string): 'Urbana' | 'Rural' {
	return /^RURAL$/i.test(clean(raw)) ? 'Rural' : 'Urbana';
}

export function parseInstEducativasCsv(csvText: string, asOf: string): InstEducativasDataset {
	const parsed = Papa.parse<string[]>(csvText.trim(), { skipEmptyLines: true });
	const rows = parsed.data.slice(1); // fila 0 = encabezado

	const sedes: Sede[] = [];
	const warnings: string[] = [];

	for (const r of rows) {
		if (r.length < MIN_COLS) continue;
		const sedeNombre = clean(r[COL.sede]);
		const establecimiento = clean(r[COL.establecimiento]);
		if (!sedeNombre && !establecimiento) continue;

		sedes.push({
			establecimiento,
			sede: sedeNombre || establecimiento,
			zona: zonaDe(r[COL.zona]),
			direccion: clean(r[COL.direccion]),
			barrio: clean(r[COL.barrio]),
			matricula: toNumber(r[COL.matricula]),
			estadoFisico: clean(r[COL.estadoFisico]),
			estudiantesAfectados: toNumber(r[COL.estudiantesAfectados]),
			usadaComoAlbergue: canonSiNo(r[COL.usadaComoAlbergue]),
			viasDeAcceso: canonSiNo(r[COL.viasDeAcceso]),
			suspendieronClases: canonSiNo(r[COL.suspendieronClases]),
			danosObservados: clean(r[COL.danosObservados]),
			accionesEtc: clean(r[COL.accionesEtc]),
			conceptoTecnico: canonConceptoTecnico(r[COL.conceptoTecnico]),
			requiereEvacuacion: canonEvacuacion(r[COL.requiereEvacuacion]),
			prioridad: canonPrioridad(r[COL.prioridad]),
			requiereVisitaTecnica: canonRequiereVisita(r[COL.requiereVisitaTecnica]),
			observaciones: clean(r[COL.observaciones])
		});
	}

	return { asOf, sedes, warnings: warnings.length ? warnings : undefined };
}
