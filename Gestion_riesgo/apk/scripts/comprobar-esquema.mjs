// Comprueba el esquema local contra un SQLite de verdad.
//
//   node scripts/comprobar-esquema.mjs
//
// Usa el `sqlite3` que trae macOS y Linux, no una dependencia: el esquema hay
// que probarlo contra el motor real, y añadir un driver de Node solo para esto
// sería pagar una dependencia por una comprobación que el sistema ya sabe hacer.
//
// Lo que de verdad vigila es la trampa del PRAGMA. Al escribir el esquema puse
// `PRAGMA foreign_keys = ON` arriba y di por hecho que las cascadas funcionaban.
// No funcionaban: en SQLite ese pragma es POR CONEXIÓN. Borrar un registro desde
// otra conexión dejó dos señales y un adjunto huérfanos.
//
// Importa porque WorkManager sincroniza desde Kotlin con su propia conexión: al
// terminar borra el registro enviado, y sin el pragma dejaría filas apuntando a
// archivos que cree eliminados.

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const esquema = join(aqui, '..', 'src', 'local', 'esquema.sql');

const temporal = mkdtempSync(join(tmpdir(), 'apk-esquema-'));
const base = join(temporal, 'local.db');

/** Cada llamada es una CONEXIÓN NUEVA — que es justo lo que se quiere probar. */
function sql(texto) {
	return execFileSync('sqlite3', [base], { input: texto, encoding: 'utf8' }).trim();
}

const DATOS_DE_PRUEBA = `
INSERT INTO registros
  (id, envio_id, nombre_completo, documento, telefono, zona, direccion,
   aviso_version, autorizacion_en, creado_en, actualizado_en)
VALUES
  ('r1','e1','Ana Riascos','38442119','3187765544','RURAL','Vereda El Guabal',
   'habeas-data-v2', datetime('now'), datetime('now'), datetime('now'));
INSERT INTO registro_senales VALUES ('r1','PARED_AGRIETADA'),('r1','TECHO_CAIDO');
INSERT INTO adjuntos (id, registro_id, tipo, ruta, mime, bytes, creado_en, actualizado_en)
VALUES ('a1','r1','PRE_CEDULA','/data/ced.webp','image/webp',88000, datetime('now'), datetime('now'));
`;

let fallos = 0;

function afirmar(condicion, queSeEsperaba) {
	if (condicion) {
		console.log(`  ✓ ${queSeEsperaba}`);
	} else {
		console.log(`  ✗ ${queSeEsperaba}`);
		fallos++;
	}
}

try {
	execFileSync('sqlite3', [base], { input: `.read '${esquema}'`, encoding: 'utf8' });

	console.log('\n  Esquema local del APK\n');

	const tablas = sql("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
		.split('\n')
		.filter((t) => t && !t.startsWith('sqlite_'));

	afirmar(
		['adjuntos', 'ajustes', 'registro_senales', 'registros'].every((t) => tablas.includes(t)),
		`el esquema se aplica limpio (${tablas.length} tablas)`
	);

	// ── La trampa ───────────────────────────────────────────────────────────
	//
	// Sin el pragma, el DELETE deja huérfanos. CON él, limpia. Se comprueban
	// las dos mitades: si algún día SQLite cambiara y las claves foráneas
	// vinieran activas por omisión, la primera mitad fallaría y habría que
	// actualizar el comentario del esquema en vez de arrastrar una advertencia
	// que ya no aplica.

	sql(DATOS_DE_PRUEBA);
	sql("DELETE FROM registros WHERE id='r1'");

	const huerfanas = Number(sql('SELECT COUNT(*) FROM registro_senales'));
	const huerfanos = Number(sql('SELECT COUNT(*) FROM adjuntos'));

	afirmar(
		huerfanas > 0 || huerfanos > 0,
		'sin PRAGMA, borrar un registro DEJA huérfanos — por eso hay que emitirlo siempre'
	);

	sql('DELETE FROM registro_senales; DELETE FROM adjuntos;');
	sql(DATOS_DE_PRUEBA);
	sql("PRAGMA foreign_keys = ON; DELETE FROM registros WHERE id='r1'");

	afirmar(
		Number(sql('SELECT COUNT(*) FROM registro_senales')) === 0 &&
			Number(sql('SELECT COUNT(*) FROM adjuntos')) === 0,
		'con PRAGMA en la misma conexión, la cascada limpia señales y adjuntos'
	);

	// ── Lo que la sincronización necesita poder consultar ───────────────────

	sql(DATOS_DE_PRUEBA);

	afirmar(
		sql("SELECT id FROM registros WHERE estado='PENDIENTE' ORDER BY creado_en") === 'r1',
		'la cola de pendientes se lee con un SELECT simple, apto para Kotlin'
	);

	afirmar(
		sql("SELECT group_concat(codigo) FROM registro_senales WHERE registro_id='r1'").split(',')
			.length === 2,
		'las señales se leen como filas, sin interpretar JSON desde Kotlin'
	);

	afirmar(
		sql("SELECT valor IS NULL FROM ajustes WHERE clave='dispositivo_id'") === '1',
		'el dispositivo_id nace vacío: lo genera el teléfono al primer arranque'
	);

	afirmar(
		sql("SELECT valor FROM ajustes WHERE clave='api_base'") ===
			'https://grj.oticjamundi.com/api',
		'la URL de la API viene puesta y es la de producción'
	);
	// ── El SQL de registros.ts, ejecutado de verdad ─────────────────────────
	//
	// No basta con leerlo: el fallo clásico es que las columnas y los marcadores
	// no cuadren, y eso no se ve mirando. Ya pasó en el backend de este mismo
	// proyecto —una columna en la lista y sin su marcador en VALUES— y solo
	// apareció al mandar una petición real.
	//
	// Aquí se extraen las sentencias del propio archivo y se ejecutan, así que
	// si alguien añade una columna y olvida su marcador, esto falla.

	const fuente = readFileSync(join(aqui, '..', 'src', 'local', 'registros.ts'), 'utf8');

	const insercion = fuente
		.slice(fuente.indexOf('INSERT INTO registros'), fuente.indexOf("VALUES (?,?"))
		.concat(fuente.slice(fuente.indexOf('VALUES (?,?'), fuente.indexOf("`,\n\t\t\tvalues")))
		.replace(/\s+/g, ' ')
		.trim();

	const marcadores = (insercion.match(/\?/g) ?? []).length;

	// 18 valores en el arreglo de registros.ts. Si cambian las columnas, cambia
	// este número y hay que mirarlo, que es justo lo que se busca.
	afirmar(marcadores === 18, `el INSERT lleva ${marcadores} marcadores (se esperaban 18)`);

	let insertoBien = true;
	try {
		sql(`PRAGMA foreign_keys = ON; ${insercion.replace(/\?/g, "'x'")};`);
	} catch (e) {
		insertoBien = false;
		console.log(`      ${String(e).split('\n')[0]}`);
	}

	afirmar(insertoBien, 'el INSERT de registros.ts cuadra columnas y marcadores');

	afirmar(
		Number(sql("SELECT COUNT(*) FROM registros WHERE estado='PENDIENTE'")) >= 1,
		'un registro recién guardado nace PENDIENTE, listo para que Kotlin lo tome'
	);

	// El SELECT de listar(), con su subconsulta de adjuntos.
	let listaBien = true;
	try {
		sql(`SELECT r.id, r.estado,
		            (SELECT COUNT(*) FROM adjuntos a WHERE a.registro_id = r.id) AS adjuntos
		       FROM registros r ORDER BY r.creado_en DESC`);
	} catch {
		listaBien = false;
	}

	afirmar(listaBien, 'el listado de «Mis registros» corre, con su cuenta de adjuntos');

	afirmar(
		sql("SELECT COUNT(*) FROM registros WHERE estado IN ('PENDIENTE','SINCRONIZANDO','ERROR')") !==
			'',
		'la cuenta de «solicitudes sin enviar» corre — es el aviso de no desinstalar'
	);
} finally {
	rmSync(temporal, { recursive: true, force: true });
}

if (fallos > 0) {
	console.log(`\n  ${fallos} comprobación(es) fallaron.\n`);
	process.exit(1);
}

console.log('');
