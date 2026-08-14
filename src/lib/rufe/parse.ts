import Papa from 'papaparse';
import type { Barrio, Dataset, Hogar, Zona } from './types';

/**
 * Parser único del CSV del RUFE — corre igual en el navegador (fetch en
 * vivo, ver `live.ts`) y en Node (script de refresco del snapshot de
 * respaldo, ver `scripts/refresh-snapshot.ts`). Que sea la MISMA función en
 * los dos casos es intencional: dos implementaciones (una en Python, otra
 * en TS) ya causaron una vez un desacuerdo silencioso entre el snapshot y
 * los datos reales (ver el bug de los hogares 91/117 corregido antes de
 * publicar la primera versión).
 *
 * Mapeo de columnas (0-index) del CSV exportado por Google Sheets — export
 * directo, sin el relleno de celdas combinadas que sí trae un copiar/pegar
 * manual desde Excel/Sheets. Verificado contra la hoja en vivo el
 * 2026-08-14:
 *
 *   0 ITEMS · 1 HOGAR No. · 2 CORREGIMIENTO · 3 SECTOR/BARRIO · 4 DIRECCION
 *   5 NOMBRE(S) · 6 APELLIDO(S) · 7 TIPO DOC · 8 NUMERO DOC · 9 PARENTESCO
 *   10 GENERO (M/F/T directo) · 11 DIA · 12 MES · 13 AÑO · 14 EDAD
 *   15 ETNIA · 16 TELEFONO · 17 TENENCIA · 18 ESTADO BIEN · 19 TIPO BIEN
 *   20 EVACUADA · 21 VISITA · 22 QUIEN VISITA · 23 OBSERVACIONES
 */
const HEADER_ROWS = 8;
const COL = {
	hogar: 1,
	corregimiento: 2,
	barrio: 3,
	nombre: 5,
	apellido: 6,
	documento: 8,
	genero: 10,
	edad: 14,
	tenencia: 17,
	estadoBien: 18,
	tipoBien: 19,
	visita: 21,
	quienVisita: 22,
	observacion: 23
} as const;
const MIN_COLS = 25;

/** Estado/tipo de bien son de la vivienda, no de la persona: solo el primer
 * integrante del hogar suele traerlos diligenciados (a veces un par más, a
 * veces ninguno), así que se toma el primer valor no vacío visto para ese
 * hogar — igual que corregimiento/barrio. */
const CANON_ESTADO_BIEN: Record<string, string> = {
	AVERIADA: 'Averiado',
	AVERIDO: 'Averiado',
	AVERIADO: 'Averiado',
	HABITABLE: 'Habitable',
	'NO HABITABLE': 'No habitable',
	'NO HABITABE': 'No habitable',
	DESTRUIDO: 'Destruido',
	'NO INFORMA': 'No informa',
	'SIN DATOS': 'No informa'
};

const CANON_TIPO_BIEN: Record<string, string> = {
	VIVIENDA: 'Vivienda',
	VIVENDA: 'Vivienda',
	'LOCAL COMERCIAL': 'Local comercial',
	FINCA: 'Finca',
	'CENTRO DE BIENESTAR': 'Centro de bienestar',
	'CENTRO EDUCATIVO / ESCUELA': 'Centro educativo'
};

/** Corregimientos rurales conocidos de Jamundí (Valle del Cauca). Cualquier
 * corregimiento fuera de esta lista (incluido vacío, o "JAMUNDI"/"TERRANOVA",
 * que en este formulario a veces aparecen por error en esa columna) se
 * clasifica como Urbana. */
const RURAL = new Set([
	'QUINAMAYO',
	'ROBLES',
	'CHAGRES',
	'POTRERITO',
	'SAN ANTONIO',
	'TIMBA',
	'AMPUDIA',
	'PUENTE VELEZ',
	'VILLA PAZ',
	'VILLA COLOMBIA',
	'SAN ISIDRO',
	'SAN VICENTE',
	'LA FERRERIRA',
	'CHONTADURO',
	'PEON',
	'CLAVELLINAS',
	'GUACHINTE'
]);

const CANON_CORE: Record<string, string> = {
	VILLACOLOMBIA: 'VILLA COLOMBIA',
	CLAVELLINA: 'CLAVELLINAS'
};

const CANON_BARRIO: Record<string, string> = {
	'OASIS - TERRANOVA': 'OASIS DE TERRANOVA',
	'PAISAJE LAS FLORES': 'PAISAJE DE LAS FLORES',
	'PARQUES DE CASTILLO': 'PARQUES DE CASTILLA',
	'TERRANOVA-SECTOR J': 'TERRANOVA SECTOR J',
	'SECTOR LA J': 'TERRANOVA SECTOR J',
	'PANGOLA-': 'PANGOLA',
	'PANGOLA TORRE 1 APTO 1008': 'PANGOLA',
	'PANGOLA MIRADOR DEL RIO': 'PANGOLA',
	'CIUDADELA DE TERRANOVA': 'TERRANOVA',
	'ALAMEDA DE RIO CLARO': 'ALAMEDA RIO CLARO',
	'VILLA LAS PALMAS': 'LAS PALMAS',
	'LA ESTACIÓN': 'LA ESTACION',
	'BONANZA - TULIPANES': 'BONANZA TULIPANES'
};

const ACCENT_WORDS: Record<string, string> = {
	JORDAN: 'JORDÁN',
	ESTACION: 'ESTACIÓN',
	RIO: 'RÍO',
	BOLIVAR: 'BOLÍVAR',
	MARIA: 'MARÍA'
};

const SMALL_WORDS = new Set(['de', 'la', 'las', 'los', 'del', 'el', 'y']);

function clean(s: string | undefined | null): string {
	return (s ?? '').replace(/\s+/g, ' ').trim();
}

function fixAccents(s: string): string {
	return s
		.split(' ')
		.map((w) => ACCENT_WORDS[w] ?? w)
		.join(' ');
}

function titleCase(s: string): string {
	return fixAccents(s)
		.split(' ')
		.map((w, i) => {
			const lw = w.toLowerCase();
			return i > 0 && SMALL_WORDS.has(lw) ? lw : lw.charAt(0).toUpperCase() + lw.slice(1);
		})
		.join(' ');
}

function zonaDe(corregimientoUpper: string): Zona {
	return RURAL.has(corregimientoUpper) ? 'Rural' : 'Urbana';
}

function ageBucket(
	edad: number | null
): 'Ninos' | 'Jovenes' | 'Adultos' | 'AdultosMayores' | 'SinDato' {
	if (edad === null) return 'SinDato';
	if (edad <= 11) return 'Ninos';
	if (edad <= 28) return 'Jovenes';
	if (edad <= 59) return 'Adultos';
	return 'AdultosMayores';
}

interface PersonRecord {
	hogar: string;
	corregimiento: string;
	barrio: string;
	genero: 'M' | 'F' | null;
	edad: number | null;
	tenencia: string;
	estadoBien: string;
	tipoBien: string;
	visita: 'SI' | 'NO' | '';
	quienVisita: string;
	observacion: string;
}

function parseRows(rows: string[][]): PersonRecord[] {
	const dataRows = rows.slice(HEADER_ROWS);
	const coreByHogar = new Map<string, string>();
	const barrioByHogar = new Map<string, string>();
	const records: PersonRecord[] = [];

	for (const raw of dataRows) {
		const r = raw.length < MIN_COLS ? [...raw, ...Array(MIN_COLS - raw.length).fill('')] : raw;

		const hogar = clean(r[COL.hogar]);
		let corregimiento = clean(r[COL.corregimiento]);
		let barrio = clean(r[COL.barrio]);
		const nombre = clean(r[COL.nombre]);
		const apellido = clean(r[COL.apellido]);
		const documento = clean(r[COL.documento]);
		const generoRaw = clean(r[COL.genero]).toUpperCase();
		const edadRaw = clean(r[COL.edad]);
		const tenencia = clean(r[COL.tenencia]);
		const estadoBien = clean(r[COL.estadoBien]);
		const tipoBien = clean(r[COL.tipoBien]);
		const visitaRaw = clean(r[COL.visita]).toUpperCase();
		const quienVisita = clean(r[COL.quienVisita]);
		const observacion = clean(r[COL.observacion]);

		if (hogar) {
			if (corregimiento) coreByHogar.set(hogar, corregimiento);
			else if (coreByHogar.has(hogar)) corregimiento = coreByHogar.get(hogar)!;
			if (barrio) barrioByHogar.set(hogar, barrio);
			else if (barrioByHogar.has(hogar)) barrio = barrioByHogar.get(hogar)!;
		}

		// Filas de relleno del formulario (sin nombre/apellido/documento).
		if (!nombre && !apellido && !documento) continue;

		const genero: 'M' | 'F' | null = generoRaw === 'M' ? 'M' : generoRaw === 'F' ? 'F' : null;

		let edad: number | null = null;
		const parsed = Number.parseInt(edadRaw, 10);
		if (Number.isFinite(parsed) && parsed >= 0 && parsed <= 115) edad = parsed;

		const visita: 'SI' | 'NO' | '' = visitaRaw === 'SI' ? 'SI' : visitaRaw === 'NO' ? 'NO' : '';

		records.push({
			hogar,
			corregimiento,
			barrio,
			genero,
			edad,
			tenencia,
			estadoBien,
			tipoBien,
			visita,
			quienVisita,
			observacion
		});
	}

	return records;
}

function buildDataset(records: PersonRecord[], asOf: string): Dataset {
	const barrioAgg = new Map<
		string,
		{
			total: number;
			M: number;
			F: number;
			Ninos: number;
			Jovenes: number;
			Adultos: number;
			AdultosMayores: number;
			zona: Zona | null;
		}
	>();
	const warnings: string[] = [];
	const hogaresMap = new Map<string, Hogar>();

	for (const rec of records) {
		let coreU =
			CANON_CORE[clean(rec.corregimiento).toUpperCase()] ?? clean(rec.corregimiento).toUpperCase();
		const barrioU =
			CANON_BARRIO[clean(rec.barrio).toUpperCase()] ?? clean(rec.barrio).toUpperCase();

		// Corregimiento vacío pero el nombre de un corregimiento rural quedó
		// escrito en el campo de barrio (ver hogares 91/117 del sismo de
		// agosto 2026: "SAN ISIDRO"/"CHAGRES" en barrio, corregimiento
		// vacío). Sin esto, esas personas quedan mal clasificadas como
		// Urbana y corrompen la zona de todo el grupo de barrio al que se
		// terminan uniendo.
		if (!coreU && RURAL.has(barrioU)) coreU = barrioU;

		const zona = zonaDe(coreU);
		const labelRaw =
			zona === 'Rural'
				? coreU || barrioU || 'SIN ESPECIFICAR'
				: barrioU || coreU || 'SIN ESPECIFICAR';
		const label = labelRaw === 'SIN ESPECIFICAR' ? 'Sin especificar' : titleCase(labelRaw);

		let b = barrioAgg.get(label);
		if (!b) {
			b = { total: 0, M: 0, F: 0, Ninos: 0, Jovenes: 0, Adultos: 0, AdultosMayores: 0, zona: null };
			barrioAgg.set(label, b);
		}
		b.total += 1;
		if (b.zona !== null && b.zona !== zona) {
			// No detenemos el parseo por esto: la hoja está en edición activa y
			// una fila con un corregimiento/barrio inconsistente no debe tumbar
			// el tablero para todo el mundo. Se mantiene la zona ya asignada al
			// grupo y se registra la advertencia para revisar el dato fuente.
			warnings.push(
				`Zona inconsistente para "${label}" (hogar ${rec.hogar}): se mantuvo ${b.zona}, se ignoró ${zona}.`
			);
		} else {
			b.zona = zona;
		}

		if (rec.genero === 'M') b.M += 1;
		else if (rec.genero === 'F') b.F += 1;

		const bucket = ageBucket(rec.edad);
		if (bucket === 'Ninos') b.Ninos += 1;
		else if (bucket === 'Jovenes') b.Jovenes += 1;
		else if (bucket === 'Adultos') b.Adultos += 1;
		else if (bucket === 'AdultosMayores') b.AdultosMayores += 1;

		if (rec.hogar) {
			let h = hogaresMap.get(rec.hogar);
			if (!h) {
				h = {
					hogar: rec.hogar,
					barrio: label,
					zona,
					estadoBien: '',
					tipoBien: '',
					tenencia: '',
					visita: 'Sin dato',
					quienVisita: '',
					observacion: ''
				};
				hogaresMap.set(rec.hogar, h);
			}
			// Estado/tipo de bien, tenencia, visita y observación quedan
			// diligenciados de forma pareja entre los integrantes de un mismo
			// hogar en la práctica (a veces solo el primero, a veces varios,
			// a veces ninguno) — se toma el primer valor no vacío visto.
			if (!h.estadoBien && rec.estadoBien) {
				h.estadoBien = CANON_ESTADO_BIEN[rec.estadoBien.toUpperCase()] ?? titleCase(rec.estadoBien);
			}
			if (!h.tipoBien && rec.tipoBien) {
				h.tipoBien = CANON_TIPO_BIEN[rec.tipoBien.toUpperCase()] ?? titleCase(rec.tipoBien);
			}
			if (!h.tenencia && rec.tenencia) h.tenencia = titleCase(rec.tenencia);
			if (h.visita === 'Sin dato' && rec.visita) h.visita = rec.visita;
			if (!h.quienVisita && rec.quienVisita) h.quienVisita = rec.quienVisita;
			if (!h.observacion && rec.observacion) h.observacion = rec.observacion;
		}
	}

	const barrios: Barrio[] = [...barrioAgg.entries()]
		.map(([name, b]) => ({ ...b, name, zona: b.zona as Zona }))
		.sort((a, b) => b.total - a.total);

	const hogares = [...hogaresMap.values()];

	return {
		total: records.length,
		asOf,
		barrios,
		hogares,
		...(warnings.length ? { warnings } : {})
	};
}

/**
 * Parsea el texto crudo del CSV exportado del RUFE y devuelve el mismo
 * `Dataset` que consume la UI (agregado por barrio/vereda, sin datos
 * personales — ningún nombre, cédula ni teléfono sale de esta función).
 */
export function parseRufeCsv(csvText: string, asOf: string): Dataset {
	const { data, errors } = Papa.parse<string[]>(csvText, { skipEmptyLines: false });
	const blockingErrors = errors.filter((e) => e.type !== 'FieldMismatch');
	if (blockingErrors.length > 0) {
		throw new Error(`Error al leer el CSV del RUFE: ${blockingErrors[0].message}`);
	}
	const records = parseRows(data);
	return buildDataset(records, asOf);
}
