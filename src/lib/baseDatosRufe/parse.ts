import Papa from 'papaparse';
import type { PersonRecord } from '../rufe/parse';
import { CANON_ESTADO_BIEN, CANON_TENENCIA, CANON_TIPO_BIEN, titleCase } from '../rufe/parse';
import type { Zona } from '../rufe/types';
import type { BaseDatosDataset } from './types';

/**
 * "BASE-DATOS RUFE": una pestaña por barrio/vereda, todas con el mismo
 * encabezado (verificado el 2026-08-15 contra las 13 pestañas existentes).
 * A diferencia de la hoja original, acá cada fila trae su propio
 * corregimiento/barrio/zona diligenciados (no hace falta rellenar hacia
 * adelante dentro del hogar), y la zona viene directa en "Ubicación del
 * Bien" — no hay que inferirla del corregimiento.
 *
 *   0 N° Hogar · 1 Departamento · 2 Municipio · 3 Evento · 4 Fecha Evento
 *   5 Fecha RUFE · 6 Ubicación del Bien (zona) · 7 Corregimiento
 *   8 Vereda/Sector/Barrio · 9 Dirección · 10 Alojamiento Actual
 *   11 Forma de Tenencia · 12 Estado del Bien · 13 Tipo de Bien · 14 Item
 *   15 Nombre(s) · 16 Apellido(s) · 17 Tipo de Documento
 *   18 Número de Documento · 19 Parentesco · 20 Identidad de Género
 *   21 Fecha de Nacimiento · 22 Pertenencia Étnica · 23 N° de Teléfono
 *   24-28 datos agropecuarios (no usados todavía) · 29 Observaciones
 */
const HEADER_ROWS = 1;
const COL = {
	hogar: 0,
	zona: 6,
	corregimiento: 7,
	barrio: 8,
	tenencia: 11,
	estadoBien: 12,
	tipoBien: 13,
	documento: 18,
	genero: 20,
	fechaNacimiento: 21,
	observacion: 29
} as const;
const MIN_COLS = 30;

/** Edad calculada contra la fecha del sismo (fija), no la fecha de hoy — así
 * un mismo registro no cambia de "grupo de edad" con el paso del tiempo real,
 * igual que la EDAD de la hoja original quedó fija a cuando se diligenció. */
const FECHA_REFERENCIA = new Date(Date.UTC(2026, 7, 10));

function clean(s: string | undefined): string {
	return (s ?? '').replace(/\s+/g, ' ').trim();
}

/** Exige que la celda sea SOLO la fecha (nada más) — así una anotación de
 * quien está validando los datos pegada en la misma celda (p. ej.
 * "01/03/2026 (año imposible, ver nota)") no se lee como si la fecha fuera
 * confiable solo porque el principio del texto sí calza con DD/MM/AAAA. */
function edadDesdeNacimiento(raw: string, referencia: Date): number | null {
	const m = clean(raw).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
	if (!m) return null;
	const dia = Number(m[1]);
	const mes = Number(m[2]);
	const anio = Number(m[3]);
	if (
		dia < 1 ||
		dia > 31 ||
		mes < 1 ||
		mes > 12 ||
		anio < 1900 ||
		anio > referencia.getUTCFullYear()
	) {
		return null;
	}
	const nacimiento = new Date(Date.UTC(anio, mes - 1, dia));
	let edad = referencia.getUTCFullYear() - nacimiento.getUTCFullYear();
	const aunNoCumple =
		referencia.getUTCMonth() < nacimiento.getUTCMonth() ||
		(referencia.getUTCMonth() === nacimiento.getUTCMonth() &&
			referencia.getUTCDate() < nacimiento.getUTCDate());
	if (aunNoCumple) edad -= 1;
	return edad >= 0 && edad <= 115 ? edad : null;
}

function generoDe(raw: string): 'M' | 'F' | null {
	const v = clean(raw).toLowerCase();
	if (v.startsWith('masculino')) return 'M';
	if (v.startsWith('femenino')) return 'F';
	return null;
}

function zonaCruda(raw: string): Zona | '' {
	const v = clean(raw).toLowerCase();
	if (v.startsWith('urban')) return 'Urbana';
	if (v.startsWith('rural')) return 'Rural';
	return '';
}

/**
 * Parsea una sola pestaña (un barrio) de la hoja BASE-DATOS RUFE.
 *
 * A diferencia de la hoja original, acá `corregimiento`/`barrio` no se
 * infieren de la zona — se leen directo de la fila (cada fila trae los
 * suyos, no hace falta rellenar hacia adelante dentro del hogar) y es
 * `rufe/parse.ts#buildDataset` quien decide Rural/Urbana a partir de esos
 * dos campos, igual que con la hoja original — por eso el `RURAL` de ese
 * módulo se amplió (ver su comentario) al incorporar esta hoja: así ambas
 * fuentes comparten una sola noción de qué corregimiento es rural, en vez
 * de tener el criterio de "Ubicación del Bien" por un lado y el de
 * `RURAL`/`zonaDe()` por otro pudiendo desacordarse en silencio.
 */
export function parseBarrioTabCsv(csvText: string, nombreTab: string): PersonRecord[] {
	const { data, errors } = Papa.parse<string[]>(csvText, { skipEmptyLines: false });
	const blockingErrors = errors.filter((e) => e.type !== 'FieldMismatch');
	if (blockingErrors.length > 0) {
		throw new Error(
			`Error al leer la pestaña "${nombreTab}" de BASE-DATOS RUFE: ${blockingErrors[0].message}`
		);
	}

	const dataRows = data.slice(HEADER_ROWS);
	const records: PersonRecord[] = [];

	for (const raw of dataRows) {
		const r = raw.length < MIN_COLS ? [...raw, ...Array(MIN_COLS - raw.length).fill('')] : raw;

		const nHogar = clean(r[COL.hogar]);
		const documento = clean(r[COL.documento]);
		// Filas de relleno (sin hogar ni documento) — no describen a nadie.
		if (!nHogar && !documento) continue;

		const hogar = nHogar ? `${nombreTab}-${nHogar}` : '';
		const zona = zonaCruda(r[COL.zona]);

		let corregimiento = clean(r[COL.corregimiento]);
		let barrio = clean(r[COL.barrio]);
		// Ninguno de los dos trae el nombre del barrio en esta fila, pero
		// sabemos de cuál pestaña vino — mejor eso que "Sin especificar".
		if (!corregimiento && !barrio) {
			if (zona === 'Rural') corregimiento = titleCase(nombreTab.replace(/-/g, ' '));
			else barrio = titleCase(nombreTab.replace(/-/g, ' '));
		}

		const tenenciaRaw = clean(r[COL.tenencia]);
		const estadoBienRaw = clean(r[COL.estadoBien]);
		const tipoBienRaw = clean(r[COL.tipoBien]);

		records.push({
			hogar,
			corregimiento,
			barrio,
			documento,
			// "Ubicación del Bien" es la zona del predio puntual, no la del
			// corregimiento en general (un corregimiento suele tener cabecera
			// urbana y veredas rurales) — se confirmó contra los datos reales
			// que corregimiento/barrio solos no bastan para reproducirla
			// siempre (~8% de discrepancia), así que cuando se conoce se usa
			// directa en vez de inferirla.
			zonaDirecta: zona || undefined,
			genero: generoDe(r[COL.genero]),
			edad: edadDesdeNacimiento(r[COL.fechaNacimiento], FECHA_REFERENCIA),
			tenencia: tenenciaRaw
				? (CANON_TENENCIA[tenenciaRaw.toUpperCase()] ?? titleCase(tenenciaRaw))
				: '',
			estadoBien: estadoBienRaw
				? (CANON_ESTADO_BIEN[estadoBienRaw.toUpperCase()] ?? titleCase(estadoBienRaw))
				: '',
			tipoBien: tipoBienRaw
				? (CANON_TIPO_BIEN[tipoBienRaw.toUpperCase()] ?? titleCase(tipoBienRaw))
				: '',
			// Esta hoja no capta visita técnica ni evacuación — se conservan
			// desde la hoja original al fusionar (ver baseDatosRufe/merge.ts).
			visita: '',
			quienVisita: '',
			evacuada: '',
			observacion: clean(r[COL.observacion])
		});
	}

	return records;
}

/** Parsea todas las pestañas ya descargadas (una por barrio) en un solo
 * `BaseDatosDataset`. No hace fetch — eso lo hace `live.ts` (navegador) o el
 * script de refresco (Node), para que este módulo sea igual de fácil de
 * probar con texto CSV fijo. */
export function parseBaseDatosRufe(tabs: { nombre: string; csvText: string }[]): BaseDatosDataset {
	const records: PersonRecord[] = [];
	const porBarrio: { barrio: string; personas: number }[] = [];
	const warnings: string[] = [];

	for (const tab of tabs) {
		try {
			const tabRecords = parseBarrioTabCsv(tab.csvText, tab.nombre);
			records.push(...tabRecords);
			porBarrio.push({ barrio: tab.nombre, personas: tabRecords.length });
		} catch (e) {
			warnings.push(e instanceof Error ? e.message : `Error al leer la pestaña "${tab.nombre}".`);
		}
	}

	return { records, porBarrio, ...(warnings.length ? { warnings } : {}) };
}
