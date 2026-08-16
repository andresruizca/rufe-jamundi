import { parseEquipamientosCsv } from './parse';
import { EQUIPAMIENTOS_CSV_URL } from './source';
import type { EquipamientosDataset } from './types';

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

export async function fetchLiveEquipamientos(signal?: AbortSignal): Promise<EquipamientosDataset> {
	const res = await fetch(EQUIPAMIENTOS_CSV_URL, { signal, cache: 'no-store' });
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
	return parseEquipamientosCsv(csvText, formatAsOf(new Date()));
}
