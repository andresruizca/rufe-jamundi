#!/usr/bin/env -S npx tsx
/**
 * Refresca src/lib/data/inst-educativas-fallback.json desde la pestaña
 * "INST EDUCATIVAS" de la hoja del RUFE en vivo. Mismo rol que
 * refresh-snapshot.ts (ver ese archivo) pero para el dataset de sedes
 * educativas en vez del de personas.
 *
 * Uso: npm run data:refresh:inst
 */
import { writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { parseInstEducativasCsv } from '../src/lib/instEducativas/parse';
import { INST_EDUCATIVAS_CSV_URL } from '../src/lib/instEducativas/source';

const OUTPUT = fileURLToPath(
	new URL('../src/lib/data/inst-educativas-fallback.json', import.meta.url)
);

async function main() {
	console.log(`Descargando ${INST_EDUCATIVAS_CSV_URL} ...`);
	const res = await fetch(INST_EDUCATIVAS_CSV_URL);
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
	const dataset = parseInstEducativasCsv(csvText, asOf);

	const establecimientos = new Set(dataset.sedes.map((s) => s.establecimiento)).size;
	const matricula = dataset.sedes.reduce((a, s) => a + s.matricula, 0);
	console.log(`\nSedes: ${dataset.sedes.length}`);
	console.log(`Establecimientos: ${establecimientos}`);
	console.log(`Matrícula total: ${matricula}`);

	await writeFile(OUTPUT, JSON.stringify(dataset, null, 2) + '\n', 'utf-8');
	console.log(`\nEscrito ${OUTPUT}`);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
