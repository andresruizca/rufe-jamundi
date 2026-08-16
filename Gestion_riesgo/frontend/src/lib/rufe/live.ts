import { parseRufeRecords, buildDataset } from './parse';
import { SHEET_CSV_URL } from './source';
import type { Dataset } from './types';
import { fetchLiveBaseDatosRufe } from '../baseDatosRufe/live';
import { mergeConBaseDatosRufe } from '../baseDatosRufe/merge';

function formatAsOf(d: Date): string {
	const fecha = d.toLocaleDateString('es-CO', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'America/Bogota'
	});
	const hora = d.toLocaleTimeString('es-CO', {
		hour: 'numeric',
		minute: '2-digit',
		timeZone: 'America/Bogota'
	});
	return `${fecha}, ${hora}`;
}

/**
 * Trae el CSV del RUFE directo del navegador (sin backend propio) y lo
 * fusiona con BASE-DATOS RUFE (la continuación de la digitalización del
 * RUFE, ahora por barrio — ver `baseDatosRufe/merge.ts`) antes de agregarlo
 * en el mismo `Dataset` que consume la UI.
 *
 * El RUFE original es la fuente crítica: si su fetch falla, esta función
 * lanza (quien llama decide qué mostrar mientras tanto, ver el respaldo
 * estático en `$lib/data`). BASE-DATOS RUFE en cambio se trae "best
 * effort" — si falla por completo, el tablero sigue mostrando el RUFE
 * original solo, en vez de tumbar todo por un problema en una hoja
 * secundaria que además está en construcción activa (se le siguen
 * agregando pestañas).
 */
export async function fetchLiveDataset(signal?: AbortSignal): Promise<Dataset> {
	const res = await fetch(SHEET_CSV_URL, { signal, cache: 'no-store' });
	if (!res.ok) {
		throw new Error(
			`La hoja de Google respondió ${res.status}. ¿Sigue compartida como "Cualquiera con el enlace"?`
		);
	}
	const contentType = res.headers.get('content-type') ?? '';
	if (!contentType.includes('csv') && !contentType.includes('text')) {
		throw new Error('La hoja de Google no devolvió un CSV (¿pidió inicio de sesión?).');
	}
	const csvText = await res.text();
	const originalRecords = parseRufeRecords(csvText);

	let nuevosRecords: ReturnType<typeof parseRufeRecords> = [];
	let baseDatosWarnings: string[] | undefined;
	try {
		const baseDatos = await fetchLiveBaseDatosRufe(signal);
		nuevosRecords = baseDatos.records;
		baseDatosWarnings = baseDatos.warnings;
	} catch (e) {
		baseDatosWarnings = [
			`No se pudo leer BASE-DATOS RUFE, se muestra solo el RUFE original: ${e instanceof Error ? e.message : e}`
		];
	}

	const merge = mergeConBaseDatosRufe(originalRecords, nuevosRecords);
	const dataset = buildDataset(merge.records, formatAsOf(new Date()));

	const warnings = [
		...(dataset.warnings ?? []),
		...(merge.warnings ?? []),
		...(baseDatosWarnings ?? [])
	];
	return { ...dataset, ...(warnings.length ? { warnings } : {}) };
}
