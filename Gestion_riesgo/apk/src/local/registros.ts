// Guardar y leer las solicitudes que esperan en el teléfono.
//
// Todo lo que se escribe aquí tiene que poder leerlo Kotlin: `SyncWorker` abre
// esta misma base con la aplicación cerrada. Por eso no hay JSON serializado ni
// nada que exija interpretar cadenas — las señales van en su tabla y los
// adjuntos en la suya.

import { abrir } from './base';
import type { EstadoRegistro } from './sincronizacion';

export type DatosRegistro = {
	nombre_completo: string;
	documento: string;
	telefono: string;
	correo: string;
	zona: 'URBANA' | 'RURAL';
	direccion: string;
	vereda: string;
	corregimiento: string;
	descripcion_dano: string;
	latitud: number | null;
	longitud: number | null;
	precision_m: number | null;
	senales: string[];
	aviso_version: string;
};

export type RegistroGuardado = {
	id: string;
	envio_id: string;
	nombre_completo: string;
	documento: string;
	direccion: string;
	zona: string;
	estado: EstadoRegistro;
	radicado: string | null;
	error_ultimo: string | null;
	intentos: number;
	proximo_intento_en: string | null;
	creado_en: string;
	adjuntos: number;
};

/**
 * Crea el registro vacío y devuelve su identificador.
 *
 * Se crea ANTES de tomar fotos, no al final: los adjuntos necesitan a qué
 * registro pertenecer, y hacer que la primera foto cree el registro dejaría a
 * quien no adjunta nada sin sitio donde escribir.
 */
export async function empezar(): Promise<string> {
	return crypto.randomUUID();
}

/**
 * Guarda la solicitud completa y la deja en cola.
 *
 * El `envio_id` se genera AQUÍ y una sola vez. Es lo que hace seguro reintentar:
 * si la solicitud entra pero la respuesta se pierde —lo normal con mala señal—,
 * el servidor devuelve el radicado original en vez de inscribir dos veces a la
 * misma familia. Regenerarlo en cada intento rompería justamente eso.
 *
 * Todo en una transacción: un corte a mitad dejaría un registro sin sus señales,
 * y ese registro se sincronizaría igual, callando lo que la persona marcó.
 */
export async function guardar(id: string, datos: DatosRegistro): Promise<void> {
	const db = await abrir();
	const ahora = new Date().toISOString();

	await db.executeTransaction([
		{
			statement: `INSERT INTO registros
				(id, envio_id, nombre_completo, documento, telefono, correo,
				 zona, direccion, vereda, corregimiento, latitud, longitud, precision_m,
				 descripcion_dano, autoriza_datos, aviso_version, autorizacion_en,
				 estado, creado_en, actualizado_en)
			 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?, 'PENDIENTE', ?, ?)`,
			values: [
				id,
				crypto.randomUUID(),
				datos.nombre_completo.trim(),
				datos.documento.replace(/\D+/g, ''),
				datos.telefono.replace(/\D+/g, ''),
				datos.correo.trim() || null,
				datos.zona,
				datos.direccion.trim(),
				datos.vereda.trim() || null,
				// En zona urbana no hay corregimiento. Se descarta aquí igual que
				// hace PHP: mandar una contradicción solo da trabajo a quien revisa.
				datos.zona === 'RURAL' ? datos.corregimiento || null : null,
				datos.latitud,
				datos.longitud,
				datos.precision_m,
				datos.descripcion_dano.trim() || null,
				datos.aviso_version,
				ahora,
				ahora,
				ahora
			]
		},
		...datos.senales.map((codigo) => ({
			statement: 'INSERT OR IGNORE INTO registro_senales (registro_id, codigo) VALUES (?, ?)',
			values: [id, codigo]
		}))
	]);
}

/** Lo que muestra «Mis registros», de lo más reciente a lo más viejo. */
export async function listar(): Promise<RegistroGuardado[]> {
	const db = await abrir();

	const r = await db.query(
		`SELECT r.id, r.envio_id, r.nombre_completo, r.documento, r.direccion, r.zona,
		        r.estado, r.radicado, r.error_ultimo, r.intentos, r.proximo_intento_en,
		        r.creado_en,
		        (SELECT COUNT(*) FROM adjuntos a WHERE a.registro_id = r.id) AS adjuntos
		   FROM registros r
		  ORDER BY r.creado_en DESC`
	);

	return (r.values ?? []) as RegistroGuardado[];
}

/**
 * Cuántas solicitudes todavía pueden salir solas.
 *
 * Android no avisa al desinstalar, así que esto se enseña al abrir la
 * aplicación. Sin ese aviso, alguien borra la aplicación creyendo que ya mandó
 * su solicitud y se lleva por delante fotos que no volverá a tomar.
 */
export async function cuantasEsperan(): Promise<number> {
	const db = await abrir();

	const r = await db.query(
		"SELECT COUNT(*) AS n FROM registros WHERE estado IN ('PENDIENTE','SINCRONIZANDO','ERROR')"
	);

	return Number(r.values?.[0]?.n ?? 0);
}

/**
 * Devuelve a la cola un registro que se había rendido.
 *
 * Es el botón «Reintentar ahora». Pone los intentos a cero a propósito: si la
 * persona lo pide es porque algo cambió —llegó al pueblo, se conectó a una red—
 * y hacerle esperar cuatro horas por los intentos de ayer no tendría sentido.
 */
export async function reintentar(id: string): Promise<void> {
	const db = await abrir();

	await db.run(
		`UPDATE registros
		    SET estado = 'PENDIENTE', intentos = 0, proximo_intento_en = NULL,
		        error_ultimo = NULL, actualizado_en = ?
		  WHERE id = ? AND estado IN ('ERROR','ERROR_VALIDACION')`,
		[new Date().toISOString(), id]
	);
}

/**
 * Borra una solicitud y sus archivos del teléfono.
 *
 * Las filas se van solas por las claves foráneas —siempre que la conexión tenga
 * el pragma, ver `base.ts`—, pero los ARCHIVOS no: hay que borrarlos aparte, y
 * por eso esta función devuelve sus rutas en vez de esconderlas.
 */
export async function rutasDeSusArchivos(id: string): Promise<string[]> {
	const db = await abrir();
	const r = await db.query('SELECT ruta FROM adjuntos WHERE registro_id = ?', [id]);

	return (r.values ?? []).map((f: { ruta: string }) => f.ruta);
}

export async function borrar(id: string): Promise<void> {
	const db = await abrir();

	await db.run('DELETE FROM registros WHERE id = ?', [id]);
}
