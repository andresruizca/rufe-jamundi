// La conexión a la base local del teléfono.
//
// Hay un solo sitio en todo el TypeScript del APK que abre la base, y es este.
// No por orden, sino porque abrir es donde se emite `PRAGMA foreign_keys = ON`,
// y ese pragma en SQLite es POR CONEXIÓN: una segunda vía de apertura que se lo
// olvidara borraría registros dejando señales y adjuntos huérfanos, apuntando a
// archivos que nadie va a borrar nunca.
//
// El lado Kotlin tiene su propia conexión y su propia obligación de emitirlo.
// `scripts/comprobar-esquema.mjs` comprueba las dos mitades del asunto.

import { CapacitorSQLite, SQLiteConnection, type SQLiteDBConnection } from '@capacitor-community/sqlite';
import esquema from './esquema.sql?raw';

const NOMBRE = 'sgr_ciudadano';

let conexion: SQLiteDBConnection | null = null;

/**
 * Abre —o devuelve— la conexión, ya con las claves foráneas activas.
 *
 * Es idempotente a propósito: la llaman la pantalla del formulario, la de «Mis
 * registros» y el arranque, y ninguna debería tener que saber si alguien la
 * llamó antes.
 */
export async function abrir(): Promise<SQLiteDBConnection> {
	if (conexion !== null) return conexion;

	const sqlite = new SQLiteConnection(CapacitorSQLite);

	// `checkConnectionsConsistency` limpia conexiones que quedaron colgando de
	// un cierre brusco de la aplicación. Sin esto, `createConnection` falla con
	// «connection already exists» tras un cierre forzado, y el ciudadano ve una
	// aplicación que no arranca sin ninguna explicación.
	await sqlite.checkConnectionsConsistency().catch(() => undefined);

	const yaExiste = (await sqlite.isConnection(NOMBRE, false)).result === true;

	conexion = yaExiste
		? await sqlite.retrieveConnection(NOMBRE, false)
		: await sqlite.createConnection(NOMBRE, false, 'no-encryption', 1, false);

	await conexion.open();

	// ⚠ Esto es obligatorio y va SIEMPRE tras abrir. Ver la cabecera.
	await conexion.execute('PRAGMA foreign_keys = ON;');

	// El esquema es idempotente (CREATE TABLE IF NOT EXISTS), así que aplicarlo
	// en cada arranque es a la vez la instalación y la migración.
	await conexion.execute(esquema);

	return conexion;
}

export async function cerrar(): Promise<void> {
	if (conexion === null) return;

	await conexion.close();
	conexion = null;
}

// ── Ajustes ─────────────────────────────────────────────────────────────────

export async function leerAjuste(clave: string): Promise<string | null> {
	const db = await abrir();
	const r = await db.query('SELECT valor FROM ajustes WHERE clave = ?', [clave]);

	return (r.values?.[0]?.valor as string | null) ?? null;
}

export async function guardarAjuste(clave: string, valor: string | null): Promise<void> {
	const db = await abrir();

	await db.run(
		`INSERT INTO ajustes (clave, valor, actualizado_en) VALUES (?, ?, datetime('now'))
		 ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor,
		                                 actualizado_en = excluded.actualizado_en`,
		[clave, valor]
	);
}

/**
 * El identificador de esta instalación, creado la primera vez que hace falta.
 *
 * No identifica a nadie: son 16 bytes aleatorios. Existe porque el servidor
 * cuenta su límite de tasa por IP, y los operadores móviles colombianos usan
 * CGNAT —una vereda entera sale por la misma IP pública—. Sin esto, la sexta
 * familia de un corregimiento recibiría un rechazo por hacer exactamente lo que
 * se le pidió. Ver `docs/servidor-requerido.md`.
 */
export async function dispositivoId(): Promise<string> {
	const guardado = await leerAjuste('dispositivo_id');
	if (guardado !== null && guardado !== '') return guardado;

	const bytes = crypto.getRandomValues(new Uint8Array(16));
	const nuevo = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

	await guardarAjuste('dispositivo_id', nuevo);

	return nuevo;
}
