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
		['adjuntos', 'ajustes', 'bitacora', 'registro_senales', 'registros'].every((t) => tablas.includes(t)),
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

	// Se extraen las dos sentencias que escriben en `registros` y se EJECUTAN
	// con literales en lugar de los marcadores. Si las columnas y los `?` no
	// cuadran, SQLite lo dice; leyéndolas no se ve.
	function sentencia(desde, hasta) {
		return fuente
			.slice(fuente.indexOf(desde), fuente.indexOf(hasta, fuente.indexOf(desde)))
			.replace(/\s+/g, ' ')
			.replace(/\?/g, "'x'")
			.trim()
			.replace(/,$/, '');
	}

	const insertaBorrador = sentencia('INSERT INTO registros', '`,');
	const actualiza = sentencia('UPDATE registros SET', '`,');

	let escrituraBien = true;
	let fallo = '';
	try {
		sql(`PRAGMA foreign_keys = ON; DELETE FROM registros; ${insertaBorrador};`);
		sql(`PRAGMA foreign_keys = ON; ${actualiza};`);
	} catch (e) {
		escrituraBien = false;
		fallo = String(e).split('\n').find((l) => l.toLowerCase().includes('error')) ?? '';
	}

	afirmar(escrituraBien, `el INSERT y el UPDATE de registros.ts corren de verdad ${fallo}`);

	afirmar(
		Number(sql("SELECT COUNT(*) FROM registros")) >= 1,
		'el borrador queda escrito, que es lo que las fotos necesitan para colgarse'
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
	// ── El orden REAL de la aplicación ──────────────────────────────────────
	//
	// Esta es la comprobación que faltaba, y su ausencia costó que ninguna foto
	// ni ningún video se guardara en el teléfono.
	//
	// Las pruebas de arriba insertan primero el registro y luego el adjunto, que
	// es el orden cómodo. La aplicación hace lo contrario: abre el formulario,
	// toma fotos —y ahí ya escribe adjuntos— y solo al final guarda los datos.
	// Con `empezar()` devolviendo un UUID sin fila detrás, cada adjunto moría con
	// «FOREIGN KEY constraint failed (code 787)».
	//
	// Aquí se reproduce ese orden exacto: adjunto ANTES de tener los datos.

	sql('DELETE FROM registros');

	const borrador = fuente
		.slice(fuente.indexOf('INSERT INTO registros\n\t\t\t(id, envio_id'), fuente.indexOf("VALUES (?, ?, '', '',"))
		.concat("VALUES ('b1','e-b1','','','','','','', datetime('now'), 'BORRADOR', datetime('now'), datetime('now'))")
		.replace(/\s+/g, ' ')
		.trim();

	let ordenBien = true;
	let motivo = '';
	try {
		// 1. Se abre el borrador, como hace `empezar()`.
		sql(`PRAGMA foreign_keys = ON; ${borrador};`);

		// 2. Se adjunta una foto ANTES de que existan los datos del formulario.
		sql(`PRAGMA foreign_keys = ON;
			INSERT INTO adjuntos (id, registro_id, tipo, ruta, mime, bytes, creado_en, actualizado_en)
			VALUES ('a-b1','b1','PRE_CEDULA','/x.webp','image/webp',9000, datetime('now'), datetime('now'));`);

		// 3. Y un video, que es donde se vio el fallo en el teléfono.
		sql(`PRAGMA foreign_keys = ON;
			INSERT INTO adjuntos (id, registro_id, tipo, ruta, mime, bytes, segundos, trozos_totales, creado_en, actualizado_en)
			VALUES ('v-b1','b1','VIDEO','/v.webm','video/webm',900000,12,1, datetime('now'), datetime('now'));`);
	} catch (e) {
		ordenBien = false;
		motivo = String(e).split('\n').find((l) => l.includes('Error')) ?? '';
	}

	afirmar(ordenBien, `se puede adjuntar ANTES de guardar el formulario ${motivo}`);

	afirmar(
		Number(sql("SELECT COUNT(*) FROM adjuntos WHERE registro_id='b1'")) === 2,
		'la foto y el video quedan colgando del borrador'
	);

	// 4. Y al guardar, el borrador pasa a PENDIENTE sin perder sus adjuntos.
	sql("PRAGMA foreign_keys = ON; UPDATE registros SET estado='PENDIENTE' WHERE id='b1'");

	afirmar(
		Number(sql("SELECT COUNT(*) FROM adjuntos WHERE registro_id='b1'")) === 2,
		'al guardar, los adjuntos siguen ahí — se actualiza la fila, no se recrea'
	);

	afirmar(
		sql("SELECT id FROM registros WHERE estado='PENDIENTE'") === 'b1',
		'y la solicitud queda en la cola que lee Kotlin'
	);

	// ── La bitácora ─────────────────────────────────────────────────────────
	//
	// La escribe Kotlin en cada intento. Sin ella, «se enviará en cuanto haya
	// internet» es cierto pero no dice nada: quien lo lee tres horas después no
	// sabe si el teléfono lo ha intentado siquiera.

	sql(`PRAGMA foreign_keys = ON;
		INSERT INTO bitacora (id, registro_id, cuando, resultado, detalle)
		VALUES ('l1','b1', datetime('now'), 'SIN_CONEXION', null),
		       ('l2','b1', datetime('now','+1 minute'), 'ENVIADO', 'PRE-2026-ABCD1234');`);

	afirmar(
		Number(sql("SELECT COUNT(*) FROM bitacora WHERE registro_id='b1'")) === 2,
		'la bitácora guarda una fila por INTENTO, no una por registro'
	);

	afirmar(
		sql("SELECT detalle FROM bitacora WHERE registro_id='b1' ORDER BY cuando DESC LIMIT 1") ===
			'PRE-2026-ABCD1234',
		'y el radicado del envío queda ahí, que es lo único que la familia se lleva'
	);

	// Y se va con su registro: son datos de una solicitud, no de la aplicación.
	sql("PRAGMA foreign_keys = ON; DELETE FROM registros WHERE id='b1'");

	afirmar(
		Number(sql('SELECT COUNT(*) FROM bitacora')) === 0,
		'la bitácora se va en cascada al borrar el registro'
	);

	// ── La purga de borradores, con las fechas como las escribe cada lado ─────
	//
	// El fallo que esto cierra: TypeScript escribe `2026-08-24T05:00:00.000Z` y
	// `datetime('now')` devuelve `2026-08-24 05:00:00`. Comparadas como texto, la
	// «T» es mayor que el espacio, así que un borrador del mismo día nunca salía
	// menor que el corte y sobrevivía un día de más — con sus fotos.

	const ayer = new Date(Date.now() - 26 * 3600_000).toISOString();
	const haceUnRato = new Date(Date.now() - 10 * 60_000).toISOString();

	sql(`
		INSERT INTO registros
			(id, envio_id, nombre_completo, documento, telefono, zona, direccion,
			 aviso_version, autorizacion_en, estado, creado_en, actualizado_en)
		VALUES
			('viejo','ev','','','','','','','${ayer}','BORRADOR','${ayer}','${ayer}'),
			('nuevo','en','','','','','','','${haceUnRato}','BORRADOR','${haceUnRato}','${haceUnRato}');
	`);

	afirmar(
		sql(
			"SELECT id FROM registros WHERE estado='BORRADOR' AND " +
				"datetime(creado_en) < datetime('now','-1 day')"
		) === 'viejo',
		'la purga alcanza el borrador de ayer, aunque TypeScript escriba la fecha con T y Z'
	);

	afirmar(
		sql(
			"SELECT COUNT(*) FROM registros WHERE estado='BORRADOR' AND " +
				"creado_en < datetime('now','-1 day')"
		) === '0',
		'y comparando sin `datetime()` no alcanzaba ninguno — que era el fallo'
	);

	afirmar(
		sql("SELECT COUNT(*) FROM registros WHERE estado='BORRADOR'") === '2' &&
			sql(
				"SELECT id FROM registros WHERE estado='BORRADOR' " +
					'ORDER BY creado_en DESC LIMIT 1'
			) === 'nuevo',
		'y `empezar()` reutiliza el más reciente en vez de abrir otro en cada apertura'
	);
} finally {
	rmSync(temporal, { recursive: true, force: true });
}

if (fallos > 0) {
	console.log(`\n  ${fallos} comprobación(es) fallaron.\n`);
	process.exit(1);
}

console.log('');
