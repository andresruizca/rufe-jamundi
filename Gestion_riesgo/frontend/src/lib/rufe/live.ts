import { parseRufeCsv } from './parse';
import { SHEET_CSV_URL } from './source';
import type { Dataset } from './types';

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
 * convierte en el mismo `Dataset` agregado que consume la UI. Lanza si la
 * red falla o si Google devuelve algo que no es CSV (por ejemplo, si la
 * hoja vuelve a quedar en "Restringido") — quien llama decide qué mostrar
 * mientras tanto (ver el respaldo estático en `$lib/data`).
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
	return parseRufeCsv(csvText, formatAsOf(new Date()));
}
