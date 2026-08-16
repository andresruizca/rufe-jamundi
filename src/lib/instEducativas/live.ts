import { parseInstEducativasCsv } from './parse';
import { INST_EDUCATIVAS_CSV_URL } from './source';
import type { InstEducativasDataset } from './types';

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

export async function fetchLiveInstEducativas(
	signal?: AbortSignal
): Promise<InstEducativasDataset> {
	const res = await fetch(INST_EDUCATIVAS_CSV_URL, { signal, cache: 'no-store' });
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
	return parseInstEducativasCsv(csvText, formatAsOf(new Date()));
}
