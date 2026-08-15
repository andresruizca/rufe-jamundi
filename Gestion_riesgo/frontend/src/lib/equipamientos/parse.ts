import Papa from 'papaparse';
import type {
	EquipamientoCategoria,
	EquipamientoItem,
	EquipamientosDataset,
	InformeEstado,
	VisitaEstado
} from './types';

// La hoja NO es una tabla plana como las otras dos pestañas: es un reporte
// agrupado por categoría, con un encabezado global de 3 filas
// (título/typo, "TOTAL,ESTADO,,SE REALIZÓ VISITA,EXISTE INFORME", "SI/NO
// ,SI/NO") y luego, dentro de cada categoría con más de un equipamiento,
// una fila de sub-encabezado que repite "TOTAL"/"ESTADO"/"NOMBRES:" — ver
// parse.spec.ts para el recorrido fila por fila con datos reales.
const HEADER_ROWS = 3;
const COL = { categoria: 0, total: 3, estado: 4, nombre: 5, visita: 6, informe: 7 } as const;
const MIN_COLS = 8;

function clean(v: string | undefined): string {
	return (v ?? '').replace(/\s+/g, ' ').trim();
}

// Igual que el resto del tablero (ver CANON_ESTADO_BIEN en rufe/parse.ts):
// "Mayúscula solo en la primera letra" ("No habitable", no "No Habitable").
function sentenceCase(s: string): string {
	const lower = s.toLowerCase();
	return lower ? lower[0].toUpperCase() + lower.slice(1) : lower;
}

function canonVisita(raw: string): VisitaEstado {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/confirmar/i.test(v)) return 'Por confirmar';
	if (/^s[ií]\b/i.test(v)) return 'Sí';
	if (/^no\b/i.test(v)) return 'No';
	return 'Sin dato';
}

function canonInforme(raw: string): InformeEstado {
	const v = clean(raw);
	if (!v) return 'Sin dato';
	if (/^s[ií]\b/i.test(v)) return 'Sí';
	if (/^no\b/i.test(v)) return 'No';
	return 'Sin dato';
}

function canonEstado(raw: string): string {
	const v = clean(raw);
	if (!v) return '';
	return sentenceCase(v);
}

function parseTotal(raw: string): number | null {
	const v = clean(raw);
	if (!v) return null;
	const n = Number(v.replace(/\./g, '').replace(',', '.'));
	return Number.isFinite(n) ? n : null;
}

export function parseEquipamientosCsv(csvText: string, asOf: string): EquipamientosDataset {
	const parsed = Papa.parse<string[]>(csvText.trim(), { skipEmptyLines: true });
	const rows = parsed.data.slice(HEADER_ROWS);

	const categoriasMap = new Map<string, EquipamientoCategoria>();
	const items: EquipamientoItem[] = [];
	const warnings: string[] = [];

	let currentCategoria = '';

	for (const r of rows) {
		if (r.length < MIN_COLS) continue;

		const catRaw = clean(r[COL.categoria]);
		if (catRaw) {
			currentCategoria = catRaw.replace(/:\s*$/, '');
			if (!categoriasMap.has(currentCategoria)) {
				categoriasMap.set(currentCategoria, { nombre: currentCategoria, totalReportado: null });
			}
		}
		if (!currentCategoria) continue;

		const totalRaw = clean(r[COL.total]);
		// Fila de sub-encabezado ("…,TOTAL,ESTADO,NOMBRES:,,") dentro de una
		// categoría con varios equipamientos — no trae datos, solo repite los
		// títulos de columna.
		if (totalRaw.toUpperCase() === 'TOTAL') continue;

		const cat = categoriasMap.get(currentCategoria)!;
		const estadoRaw = clean(r[COL.estado]);
		const nombreRaw = clean(r[COL.nombre]);
		const totalNum = parseTotal(totalRaw);

		if (totalNum === null && totalRaw) {
			// Texto libre en vez de un número (p. ej. Geriátricos: "En
			// verificación por parte de Secretaría de Salud") — se guarda como
			// nota de la categoría, no como un equipamiento.
			cat.nota = totalRaw;
			continue;
		}

		if (totalNum !== null) {
			cat.totalReportado = (cat.totalReportado ?? 0) + totalNum;
		}

		if (!estadoRaw && !nombreRaw) continue; // fila sin nada que describir

		// Varias unidades reportadas juntas sin nombre individual (p. ej. "6
		// EN VERIFICACIÓN" dentro de Centros de Desarrollo): se muestran como
		// un único ítem agrupado en vez de inventarles nombres.
		const sinDetalle = !nombreRaw && totalNum !== null && totalNum > 1;

		items.push({
			categoria: currentCategoria,
			nombre: nombreRaw || (sinDetalle ? `Sin detalle — ${totalNum} unidades` : currentCategoria),
			estado: canonEstado(estadoRaw),
			cantidad: sinDetalle ? (totalNum as number) : 1,
			sinDetalle,
			visita: canonVisita(r[COL.visita]),
			informe: canonInforme(r[COL.informe])
		});
	}

	return {
		asOf,
		items,
		categorias: [...categoriasMap.values()],
		warnings: warnings.length ? warnings : undefined
	};
}
