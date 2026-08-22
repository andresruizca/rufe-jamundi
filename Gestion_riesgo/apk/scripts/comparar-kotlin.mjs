// Comprueba que el Kotlin y la especificación probada digan lo mismo.
//
//   node scripts/comparar-kotlin.mjs
//
// El problema que resuelve, dicho sin adornos: **el Kotlin de este proyecto no
// se puede compilar ni ejecutar aquí.** No hay kotlinc, ni Gradle, ni SDK de
// Android. Se escribió a mano contra `src/local/sincronizacion.ts`, que sí tiene
// 21 pruebas y es la especificación.
//
// Eso deja un hueco: nada impide que alguien cambie la escalera de reintentos en
// TypeScript, la deje probada y verde, y el APK siga esperando otros tiempos
// durante meses sin que nada avise. Este guion cierra ese hueco concreto —los
// números y las reglas— aunque no pueda cerrar el resto.
//
// Lo que NO comprueba, y conviene tenerlo presente: que el Kotlin compile, que
// las consultas SQL de `SyncWorker` sean correctas, o que WorkManager despierte.
// Eso solo lo dice un teléfono.

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const raiz = join(aqui, '..');

const ts = readFileSync(join(raiz, 'src', 'local', 'sincronizacion.ts'), 'utf8');
const kt = readFileSync(
	join(raiz, 'android', 'app', 'src', 'main', 'java', 'co', 'gov', 'jamundi', 'sgr', 'Decision.kt'),
	'utf8'
);
const limitesTs = readFileSync(join(raiz, 'src', 'captura', 'limites.ts'), 'utf8');
const api = readFileSync(
	join(raiz, 'android', 'app', 'src', 'main', 'java', 'co', 'gov', 'jamundi', 'sgr', 'ApiCliente.kt'),
	'utf8'
);

let fallos = 0;

function afirmar(condicion, queSeEsperaba) {
	console.log(`  ${condicion ? '✓' : '✗'} ${queSeEsperaba}`);
	if (!condicion) fallos++;
}

function numeros(texto, patron) {
	const m = texto.match(patron);

	return m ? m[1].split(',').map((n) => Number(n.trim())) : null;
}

console.log('\n  El Kotlin frente a la especificación probada\n');

// ── La escalera de reintentos ───────────────────────────────────────────────

const esperasTs = numeros(ts, /export const ESPERAS = \[([^\]]+)\]/);
const esperasKt = numeros(kt, /val ESPERAS = intArrayOf\(([^)]+)\)/);

afirmar(esperasTs !== null && esperasKt !== null, 'las dos escaleras de espera se encuentran');
afirmar(
	JSON.stringify(esperasTs) === JSON.stringify(esperasKt),
	`la escalera de espera coincide — TS ${JSON.stringify(esperasTs)} · Kotlin ${JSON.stringify(esperasKt)}`
);

// Que crezca es la regla que el plan original incumplía: traía el último valor
// menor que el anterior, y el sexto intento habría llegado antes que el quinto.
afirmar(
	esperasKt !== null && esperasKt.every((v, i) => i === 0 || v > esperasKt[i - 1]),
	'la escalera del Kotlin crece siempre, nunca se acorta'
);

// ── El tope de la cabecera del servidor ─────────────────────────────────────

const topeTs = numeros(ts, /const TOPE_RETRY_AFTER = (\d+) \* 3600/);
const topeKt = numeros(kt, /const val TOPE_RETRY_AFTER = (\d+) \* 3600/);

afirmar(
	topeTs !== null && JSON.stringify(topeTs) === JSON.stringify(topeKt),
	'el tope de Retry-After coincide (24 horas)'
);

// ── El troceo del video ─────────────────────────────────────────────────────
//
// Aquí un desajuste no se ve: el video se sube «bien» y el servidor lo BORRA por
// incompleto al recibir el formulario, dejando solo una nota en el historial.

// Se compara el VALOR, no la forma de escribirlo: la primera versión de esta
// comprobación buscaba `const BYTES_TROZO` y el Kotlin dice `const val`, así que
// daba un desajuste que no existía. Un comprobador que grita en falso se acaba
// ignorando, que es peor que no tenerlo.
function valorDe(texto, patron) {
	const m = texto.match(patron);
	if (!m) return null;

	// Se evalúa la expresión aritmética simple —«1024 * 1024»— en vez de
	// compararla como cadena.
	return m[1]
		.split('*')
		.map((n) => Number(n.trim()))
		.reduce((a, b) => a * b, 1);
}

const trozoTs = valorDe(limitesTs, /export const BYTES_TROZO = ([\d *]+);/);
const trozoKt = valorDe(api, /const val BYTES_TROZO = ([\d *]+)\n/);

afirmar(
	trozoTs !== null && trozoTs === trozoKt,
	`el trozo de video mide lo mismo en los dos lados (${trozoTs} vs ${trozoKt})`
);

// ── Las reglas que deciden si una solicitud se pierde ───────────────────────
//
// No se compara el código —son lenguajes distintos— sino que cada regla siga
// estando presente. Si alguien la quita, esto lo dice.

const REGLAS = [
	{
		que: 'un 422 con errores por campo NO se reintenta',
		ts: /estado === 422 && respuesta\?\.errors/,
		kt: /estado == 422 && errores != null/
	},
	{
		que: 'el radicado se acepta venga como venga (duplicada o reintento)',
		ts: /respuesta\?\.ok === true && typeof radicado === 'string'/,
		kt: /if \(ok && radicado\.isNotEmpty\(\)\)/
	},
	{
		que: 'sin respuesta se reintenta, no se descarta',
		ts: /if \(estado === null\)/,
		kt: /if \(estado == null\)/
	},
	{
		que: 'Retry-After manda sobre la escalera cuando el servidor lo envía',
		ts: /retryAfter !== null && Number\.isFinite\(retryAfter\) && retryAfter > 0/,
		kt: /retryAfter != null && retryAfter > 0/
	}
];

for (const regla of REGLAS) {
	afirmar(regla.ts.test(ts) && regla.kt.test(kt), regla.que);
}

// ── El orden de los cinco pasos ─────────────────────────────────────────────
//
// El formulario va AL FINAL. Si sale antes que los archivos, el servidor no
// tiene qué adoptar: las fotos y los videos quedan huérfanos y la purga se los
// lleva en dos horas. La solicitud entra sin una sola evidencia.

const worker = readFileSync(
	join(raiz, 'android', 'app', 'src', 'main', 'java', 'co', 'gov', 'jamundi', 'sgr', 'SyncWorker.kt'),
	'utf8'
);

const posFotos = worker.indexOf('api.subirArchivo(');
const posTrozos = worker.indexOf('api.subirTrozo(');
const posEnvio = worker.indexOf('api.enviarFormulario(');

afirmar(
	posFotos > 0 && posTrozos > 0 && posEnvio > 0,
	'SyncWorker usa los cinco pasos del protocolo'
);
afirmar(
	posFotos < posEnvio && posTrozos < posEnvio,
	'el formulario se manda DESPUÉS de fotos y videos, o los archivos se pierden'
);

// ── El pragma, otra vez ─────────────────────────────────────────────────────

const baseKt = readFileSync(
	join(raiz, 'android', 'app', 'src', 'main', 'java', 'co', 'gov', 'jamundi', 'sgr', 'BaseDatos.kt'),
	'utf8'
);

afirmar(
	/PRAGMA foreign_keys = ON/.test(baseKt),
	'la conexión de Kotlin emite PRAGMA foreign_keys — es por conexión, no del archivo'
);

if (fallos > 0) {
	console.log(`\n  ${fallos} desajuste(s). El APK haría algo distinto de lo que dicen las pruebas.\n`);
	process.exit(1);
}

console.log('');
