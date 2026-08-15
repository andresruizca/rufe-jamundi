#!/usr/bin/env -S npx tsx
/**
 * Refresca src/lib/data/equipamientos-fallback.json desde la pestaña
 * "EQUIPAMIENTOS" de la hoja del RUFE en vivo. Mismo rol que
 * refresh-snapshot.ts (ver ese archivo) pero para el dataset de
 * equipamientos públicos y privados.
 *
 * Uso: npm run data:refresh:equip
 */
import { writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { parseEquipamientosCsv } from '../src/lib/equipamientos/parse';
import { EQUIPAMIENTOS_CSV_URL } from '../src/lib/equipamientos/source';

const OUTPUT = fileURLToPath(
	new URL('../src/lib/data/equipamientos-fallback.json', import.meta.url)
);

async function main() {
	console.log(`Descargando ${EQUIPAMIENTOS_CSV_URL} ...`);
	const res = await fetch(EQUIPAMIENTOS_CSV_URL);
	if (!res.ok) {
		throw new Error(
			`La hoja respondió ${res.status}. ¿Sigue compartida como "Cualquiera con el enlace"?`
		);
	}
	const csvText = await res.text();

	const asOf = new Date().toLocaleString('es-CO', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		timeZone: 'America/Bogota'
	});
	const dataset = parseEquipamientosCsv(csvText, asOf);

	console.log(`\nCategorías: ${dataset.categorias.length}`);
	console.log(`Ítems: ${dataset.items.length}`);
	console.log(`Unidades: ${dataset.items.reduce((a, i) => a + i.cantidad, 0)}`);

	await writeFile(OUTPUT, JSON.stringify(dataset, null, 2) + '\n', 'utf-8');
	console.log(`\nEscrito ${OUTPUT}`);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
