import type { Sede } from './instEducativas/types';
import type { Zona } from './data';

export interface InstEducativasAggregate {
	sedes: number;
	establecimientos: number;
	matricula: number;
	estudiantesAfectados: number;
	urbana: number;
	rural: number;
	estadoFisico: Record<string, number>;
	usadaComoAlbergue: Record<string, number>;
	viasDeAcceso: Record<string, number>;
	suspendieronClases: Record<string, number>;
	conceptoTecnico: Record<string, number>;
	requiereEvacuacion: Record<string, number>;
	prioridad: Record<string, number>;
	requiereVisitaTecnica: Record<string, number>;
	conDanosObservados: number;
}

export function filterSedes(sedes: Sede[], zona: Zona | 'todas', query: string): Sede[] {
	const q = query.trim().toLowerCase();
	return sedes.filter(
		(s) =>
			(zona === 'todas' || s.zona === zona) &&
			(!q ||
				s.sede.toLowerCase().includes(q) ||
				s.establecimiento.toLowerCase().includes(q) ||
				s.barrio.toLowerCase().includes(q))
	);
}

function tally(record: Record<string, number>, key: string): void {
	record[key] = (record[key] ?? 0) + 1;
}

export function aggregateInstEducativas(sedes: Sede[]): InstEducativasAggregate {
	const estadoFisico: Record<string, number> = {};
	const usadaComoAlbergue: Record<string, number> = {};
	const viasDeAcceso: Record<string, number> = {};
	const suspendieronClases: Record<string, number> = {};
	const conceptoTecnico: Record<string, number> = {};
	const requiereEvacuacion: Record<string, number> = {};
	const prioridad: Record<string, number> = {};
	const requiereVisitaTecnica: Record<string, number> = {};

	let urbana = 0;
	let rural = 0;
	let matricula = 0;
	let estudiantesAfectados = 0;
	let conDanosObservados = 0;
	const establecimientos = new Set<string>();

	for (const s of sedes) {
		if (s.zona === 'Urbana') urbana += 1;
		else rural += 1;

		establecimientos.add(s.establecimiento);
		matricula += s.matricula;
		estudiantesAfectados += s.estudiantesAfectados;
		if (s.danosObservados) conDanosObservados += 1;

		tally(estadoFisico, s.estadoFisico || 'Sin dato');
		tally(usadaComoAlbergue, s.usadaComoAlbergue);
		tally(viasDeAcceso, s.viasDeAcceso);
		tally(suspendieronClases, s.suspendieronClases);
		tally(conceptoTecnico, s.conceptoTecnico);
		tally(requiereEvacuacion, s.requiereEvacuacion);
		tally(prioridad, s.prioridad);
		tally(requiereVisitaTecnica, s.requiereVisitaTecnica);
	}

	return {
		sedes: sedes.length,
		establecimientos: establecimientos.size,
		matricula,
		estudiantesAfectados,
		urbana,
		rural,
		estadoFisico,
		usadaComoAlbergue,
		viasDeAcceso,
		suspendieronClases,
		conceptoTecnico,
		requiereEvacuacion,
		prioridad,
		requiereVisitaTecnica,
		conDanosObservados
	};
}

export type SedesSortKey =
	'sede' | 'establecimiento' | 'barrio' | 'zona' | 'matricula' | 'estadoFisico' | 'prioridad';

const TEXT_KEYS = new Set<SedesSortKey>([
	'sede',
	'establecimiento',
	'barrio',
	'zona',
	'estadoFisico',
	'prioridad'
]);

export function sortSedes(sedes: Sede[], key: SedesSortKey, dir: 1 | -1): Sede[] {
	return [...sedes].sort((a, b) => {
		const av = a[key];
		const bv = b[key];
		const cmp = TEXT_KEYS.has(key)
			? (av as string).localeCompare(bv as string)
			: (av as number) - (bv as number);
		return cmp * dir;
	});
}

export interface DanoItem {
	sede: string;
	establecimiento: string;
	barrio: string;
	zona: Zona;
	prioridad: string;
	texto: string;
}

/** Riesgo inminente, no daños cosméticos — igual que en el RUFE de
 * personas, "grietas"/"fisuras" solas no bastan para marcar crítico (son
 * comunes y no implican colapso inminente). */
const CRITICAL_PATTERN = /COLAPS|DERRUMB|RIESGO|URGENTE|EVACU|DESTRUI/i;

export function listDanos(sedes: Sede[]): (DanoItem & { critical: boolean })[] {
	return sedes
		.filter((s) => s.danosObservados)
		.map((s) => ({
			sede: s.sede,
			establecimiento: s.establecimiento,
			barrio: s.barrio,
			zona: s.zona,
			prioridad: s.prioridad,
			texto: s.danosObservados,
			critical:
				s.prioridad === 'Inmediata' ||
				s.prioridad === 'Alta' ||
				CRITICAL_PATTERN.test(s.danosObservados)
		}))
		.sort((a, b) => a.sede.localeCompare(b.sede));
}
