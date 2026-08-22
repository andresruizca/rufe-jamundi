// Avisa cuando el formulario del APK se separa del de la web.
//
//   node scripts/comparar-con-web.mjs
//
// Se decidió COPIAR el formulario en vez de compartirlo, para que esta carpeta
// fuera autónoma. El precio de esa decisión es la deriva: la web cambia, nadie
// se acuerda del APK, y meses después un teléfono manda un código de señal que
// el servidor ya no reconoce y pierde la solicitud entera de una familia.
//
// Este guion no impide la deriva —a veces el APK DEBE apartarse—, la hace
// RUIDOSA. Si algo cambió, falla, alguien mira el diff y decide: copiar o
// anotar por qué se aparta. Lo que no puede pasar es que se separen en
// silencio.
//
// Corre dentro de `npm test`.

import { readFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const raizApk = join(aqui, '..');

// El original vive fuera de esta carpeta. Es la ÚNICA referencia hacia afuera y
// solo se lee: nada del APK se escribe ahí.
const raizWeb = join(raizApk, '..', 'frontend', 'src', 'lib', 'preinscripcion');

/**
 * Qué se compara, y con qué permiso de apartarse.
 *
 * `motivo` no es documentación decorativa: es el compromiso de que alguien
 * pensó por qué esa copia puede ser distinta. Un archivo sin motivo tiene que
 * ser idéntico, byte a byte.
 */
const ARCHIVOS = [
	{
		nombre: 'pasos.ts',
		motivo: null
	},
	{
		nombre: 'IconoSenal.svelte',
		motivo: null
	},
	{
		nombre: 'SelectorSenales.svelte',
		motivo: null
	},
	{
		nombre: 'AutorizacionDatos.svelte',
		motivo: null
	},
	{
		nombre: 'video.ts',
		motivo: null
	},
	{
		nombre: 'GrabadorVideo.svelte',
		motivo:
			'En el APK el video se guarda en el teléfono y lo sube WorkManager más ' +
			'tarde; en la web se sube en el momento. La grabación es la misma, el ' +
			'destino no.'
	}
];

function huella(ruta) {
	return createHash('sha256').update(readFileSync(ruta)).digest('hex').slice(0, 12);
}

let iguales = 0;
const distintos = [];
const perdidos = [];

for (const { nombre, motivo } of ARCHIVOS) {
	const enApk = join(raizApk, 'src', 'formulario', nombre);
	const enWeb = join(raizWeb, nombre);

	if (!existsSync(enWeb)) {
		perdidos.push(`${nombre} — ya no existe en la web; revise si el APK debe seguir teniéndolo`);
		continue;
	}
	if (!existsSync(enApk)) {
		perdidos.push(`${nombre} — falta la copia en el APK`);
		continue;
	}

	if (huella(enApk) === huella(enWeb)) {
		iguales++;
	} else {
		distintos.push({ nombre, motivo, enApk, enWeb });
	}
}

console.log(`\n  Formulario del APK frente al de la web\n`);
console.log(`  ${iguales} de ${ARCHIVOS.length} archivos idénticos`);

for (const { nombre, motivo, enApk, enWeb } of distintos) {
	if (motivo) {
		console.log(`  ~ ${nombre} — se aparta a propósito:`);
		console.log(`      ${motivo}`);
	} else {
		console.log(`  ✗ ${nombre} — DERIVÓ sin motivo declarado`);
		console.log(`      diff "${enWeb}" \\`);
		console.log(`           "${enApk}"`);
	}
}

for (const aviso of perdidos) {
	console.log(`  ✗ ${aviso}`);
}

const sinMotivo = distintos.filter((d) => !d.motivo);

if (sinMotivo.length > 0 || perdidos.length > 0) {
	console.log(
		`\n  Copie el cambio, o declare el motivo en ARCHIVOS de este guion.` +
			`\n  Si el APK manda un formulario que el servidor ya no entiende, la` +
			`\n  solicitud de una familia se pierde entera.\n`
	);
	process.exit(1);
}

console.log('');
