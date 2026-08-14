#!/usr/bin/env -S npx tsx
/**
 * Refresca src/lib/data/rufe-fallback.json desde la hoja del RUFE en vivo.
 *
 * Este snapshot NO es la fuente de datos del tablero en producción (el
 * tablero se conecta directo a la hoja desde el navegador de cada
 * visitante, ver src/lib/rufe/live.ts): es solo el primer render antes de
 * que termine el fetch en vivo, y el respaldo si la hoja deja de estar
 * accesible. Conviene correr este script de vez en cuando (o antes de un
 * deploy) para que ese respaldo no quede demasiado desactualizado.
 *
 * Uso: npm run data:refresh
 */
import { writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { parseRufeCsv } from '../src/lib/rufe/parse';
import { SHEET_CSV_URL } from '../src/lib/rufe/source';

const OUTPUT = fileURLToPath(new URL('../src/lib/data/rufe-fallback.json', import.meta.url));

async function main() {
	console.log(`Descargando ${SHEET_CSV_URL} ...`);
	const res = await fetch(SHEET_CSV_URL);
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
	const dataset = parseRufeCsv(csvText, asOf);

	if (dataset.warnings?.length) {
		console.warn(
			`\n${dataset.warnings.length} advertencia(s) de zona (no bloquean, pero conviene revisarlas):`
		);
		for (const w of dataset.warnings) console.warn(' -', w);
	}

	const urbana = dataset.barrios
		.filter((b) => b.zona === 'Urbana')
		.reduce((a, b) => a + b.total, 0);
	const rural = dataset.barrios.filter((b) => b.zona === 'Rural').reduce((a, b) => a + b.total, 0);
	console.log(`\nTotal personas: ${dataset.total}`);
	console.log(`Barrios/veredas: ${dataset.barrios.length}`);
	console.log(`Urbana: ${urbana}  Rural: ${rural}  (suma ${urbana + rural})`);

	await writeFile(OUTPUT, JSON.stringify(dataset, null, 2) + '\n', 'utf-8');
	console.log(`\nEscrito ${OUTPUT}`);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
