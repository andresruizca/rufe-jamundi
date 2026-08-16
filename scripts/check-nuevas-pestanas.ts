#!/usr/bin/env -S npx tsx
/**
 * Revisa si la hoja "BASE-DATOS RUFE" tiene pestañas (barrios) nuevas que
 * `src/lib/baseDatosRufe/barrioTabs.json` todavía no conoce, y si las hay,
 * las agrega.
 *
 * Corre por hora vía `.github/workflows/check-nuevas-pestanas.yml`: ese
 * workflow comitea el cambio y dispara el despliegue si este script tocó
 * `barrioTabs.json` — así que agregar un barrio nuevo a la hoja no exige
 * avisar a nadie ni tocar código a mano.
 *
 * La lista de pestañas de una hoja de Google NO se puede leer con CORS
 * desde el navegador (por eso vive en un archivo aparte en vez de
 * descubrirse en vivo) — pero SÍ se puede leer aquí, en Node, pidiendo
 * directo la página `/edit`: no hay backend propio corriendo esto, es un
 * script que se ejecuta puntualmente en GitHub Actions.
 *
 * Uso: npm run data:check-pestanas
 */
import { writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { BASE_DATOS_SHEET_ID, BARRIO_TABS } from '../src/lib/baseDatosRufe/source';

const OUTPUT = fileURLToPath(new URL('../src/lib/baseDatosRufe/barrioTabs.json', import.meta.url));

// Mismo patrón que ya se usó a mano para armar la lista original (buscando
// `docs-sheet-tab-caption` en el HTML de la hoja) — acá compilado en
// regex para poder correrlo sin intervención.
const TAB_PATTERN = /\[\d+,0,\\"(\d+)\\",\[\{\\"1\\":\[\[0,0,\\"([^\\]+)\\"\]/g;

async function fetchTabsFromSheet(): Promise<{ nombre: string; gid: string }[]> {
	const url = `https://docs.google.com/spreadsheets/d/${BASE_DATOS_SHEET_ID}/edit`;
	const res = await fetch(url);
	if (!res.ok) {
		throw new Error(`La hoja respondió ${res.status} al pedir ${url}.`);
	}
	const html = await res.text();

	const tabs: { nombre: string; gid: string }[] = [];
	for (const m of html.matchAll(TAB_PATTERN)) {
		tabs.push({ nombre: m[2], gid: m[1] });
	}
	if (tabs.length === 0) {
		// La hoja cambió de estructura interna, o dejó de estar compartida
		// como "cualquiera con el enlace" — de cualquiera de las dos formas,
		// es mejor fallar ruidosamente que escribir una lista vacía.
		throw new Error(
			'No se encontró ninguna pestaña en el HTML de la hoja — revisar a mano si cambió de estructura o de permisos.'
		);
	}
	return tabs;
}

async function main() {
	console.log('Revisando pestañas de BASE-DATOS RUFE...');
	const enLaHoja = await fetchTabsFromSheet();
	const gidsConocidos = new Set(BARRIO_TABS.map((t) => t.gid));
	const nuevas = enLaHoja.filter((t) => !gidsConocidos.has(t.gid));

	if (nuevas.length === 0) {
		console.log(
			`Sin pestañas nuevas (${BARRIO_TABS.length} conocidas, ${enLaHoja.length} en la hoja).`
		);
		return;
	}

	console.log(`${nuevas.length} pestaña(s) nueva(s):`);
	for (const t of nuevas) console.log(` - ${t.nombre} (gid=${t.gid})`);

	const actualizado = [...BARRIO_TABS, ...nuevas];
	await writeFile(OUTPUT, JSON.stringify(actualizado, null, '\t') + '\n', 'utf-8');
	console.log(`\nEscrito ${OUTPUT} con ${actualizado.length} pestañas en total.`);
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
