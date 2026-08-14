import type { Hogar, Zona } from './data';

/** Un nombre de persona o de entidad ("Pilar Patiño", "Cruz Roja",
 * "Esperanza Correa R - Cruz Roja") no suele pasar de esto; lo que sí lo
 * supera en la práctica siempre resultó ser una observación mal
 * digitada en la columna equivocada. */
const QUIEN_VISITA_MAX_LEN = 40;

export interface HogaresAggregate {
	count: number;
	estadoBien: Record<string, number>;
	tipoBien: Record<string, number>;
	visitaSi: number;
	visitaNo: number;
	visitaSinDato: number;
	conObservacion: number;
	visitantes: { nombre: string; count: number }[];
}

export function filterHogares(hogares: Hogar[], zona: Zona | 'todas', query: string): Hogar[] {
	const q = query.trim().toLowerCase();
	return hogares.filter(
		(h) => (zona === 'todas' || h.zona === zona) && (!q || h.barrio.toLowerCase().includes(q))
	);
}

export function aggregateHogares(hogares: Hogar[]): HogaresAggregate {
	const estadoBien: Record<string, number> = {};
	const tipoBien: Record<string, number> = {};
	const visitantes = new Map<string, number>();
	let visitaSi = 0;
	let visitaNo = 0;
	let visitaSinDato = 0;
	let conObservacion = 0;

	for (const h of hogares) {
		const estado = h.estadoBien || 'Sin dato';
		estadoBien[estado] = (estadoBien[estado] ?? 0) + 1;
		const tipo = h.tipoBien || 'Sin dato';
		tipoBien[tipo] = (tipoBien[tipo] ?? 0) + 1;

		if (h.visita === 'SI') visitaSi += 1;
		else if (h.visita === 'NO') visitaNo += 1;
		else visitaSinDato += 1;

		if (h.observacion) conObservacion += 1;
		// Algunos registros traen la observación pegada por error en la
		// columna "quién realizó la visita" (frases largas en vez de un
		// nombre o el nombre de una entidad como "Cruz Roja"/"Defensa
		// Civil") — se descartan de este conteo para no mostrarlas como si
		// fueran visitantes.
		if (h.quienVisita && h.quienVisita.length <= QUIEN_VISITA_MAX_LEN) {
			visitantes.set(h.quienVisita, (visitantes.get(h.quienVisita) ?? 0) + 1);
		}
	}

	return {
		count: hogares.length,
		estadoBien,
		tipoBien,
		visitaSi,
		visitaNo,
		visitaSinDato,
		conObservacion,
		visitantes: [...visitantes.entries()]
			.map(([nombre, count]) => ({ nombre, count }))
			.sort((a, b) => b.count - a.count)
	};
}

/** Etiquetas por palabra clave — no es NLP, solo una búsqueda de patrones
 * comunes en las observaciones para dar un primer vistazo de qué se está
 * reportando, sin tener que leer cientos de notas una por una. La lista
 * completa siempre queda disponible debajo (ver `listObservaciones`). */
const OBS_KEYWORDS: { label: string; pattern: RegExp; critical: boolean }[] = [
	{ label: 'Grietas', pattern: /GRIET|FISURA/i, critical: false },
	{ label: 'Colapso', pattern: /COLAPS|DERRUMB|CAY[OÓ]/i, critical: true },
	{ label: 'Destruida', pattern: /DESTRUI|DESTRUCCI/i, critical: true },
	{ label: 'Evacuación', pattern: /EVACU/i, critical: true },
	{ label: 'Alojamiento', pattern: /ALOJAMIENTO/i, critical: false },
	{ label: 'Riesgo colapso', pattern: /RIESGO/i, critical: true },
	{ label: 'Urgente', pattern: /URGENTE/i, critical: true },
	{ label: 'Fuga agua/gas', pattern: /FUGA/i, critical: true }
];

export interface ObsTag {
	label: string;
	count: number;
	critical: boolean;
}

export function tagObservaciones(hogares: Hogar[]): ObsTag[] {
	return OBS_KEYWORDS.map(({ label, pattern, critical }) => ({
		label,
		critical,
		count: hogares.filter((h) => h.observacion && pattern.test(h.observacion)).length
	}))
		.filter((t) => t.count > 0)
		.sort((a, b) => b.count - a.count);
}

/** Riesgo inminente para las personas (colapso, evacuación, urgencia, fuga
 * de gas) — se muestran primero y marcadas en la lista, para que quien
 * coordina la respuesta priorice esos hogares sin tener que leer las 500+
 * observaciones. "Grietas" y "requiere alojamiento" solas no bastan para
 * marcar un hogar como crítico (son comunes y no implican riesgo
 * inmediato). */
const CRITICAL_PATTERN = /COLAPS|DERRUMB|CAY[OÓ]|DESTRUI|DESTRUCCI|EVACU|RIESGO|URGENTE|FUGA/i;

export interface ObservacionItem {
	hogar: string;
	barrio: string;
	zona: Zona;
	texto: string;
	critical: boolean;
}

export function listObservaciones(hogares: Hogar[]): ObservacionItem[] {
	// Orden alfabético por barrio siempre, sin importar si el filtro "solo
	// críticas" está activo o no: si las críticas siempre aparecieran
	// primero, activar/desactivar el filtro no cambiaba lo que se veía en
	// pantalla (ambas vistas mostraban las mismas primeras filas) y parecía
	// que el botón no hacía nada. El filtrado real ocurre en
	// ObservacionesList.svelte según el estado del checkbox.
	return hogares
		.filter((h) => h.observacion)
		.map((h) => ({
			hogar: h.hogar,
			barrio: h.barrio,
			zona: h.zona,
			texto: h.observacion,
			critical: CRITICAL_PATTERN.test(h.observacion)
		}))
		.sort((a, b) => a.barrio.localeCompare(b.barrio));
}
