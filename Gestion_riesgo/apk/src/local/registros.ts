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
 * Abre un borrador y devuelve su identificador.
 *
 * ⚠ ESTA FUNCIÓN TIENE QUE ESCRIBIR EN LA BASE, no solo generar un UUID.
 *
 * `adjuntos.registro_id` es una clave foránea contra `registros`. Mientras esto
 * devolvía un identificador sin fila detrás, CADA foto y CADA video fallaban con
 * «FOREIGN KEY constraint failed (code 787)» y no se guardaba ni uno. En el
 * teléfono se vio en el video, que es donde el error sale a la vista; las fotos
 * fallaban igual, en silencio.
 *
 * Por eso la fila nace aquí, vacía y en estado BORRADOR: los adjuntos necesitan
 * a qué pertenecer desde la primera foto, no al final.
 *
 * BORRADOR no lo recoge el sincronizador —`SyncWorker` solo mira PENDIENTE y
 * SINCRONIZANDO— así que una solicitud a medias nunca sale.
 */
export async function empezar(): Promise<string> {
	const db = await abrir();
	const id = crypto.randomUUID();
	const ahora = new Date().toISOString();

	// De paso se barren los borradores que alguien empezó y abandonó. Sin esto,
	// cada formulario que no se termina deja una fila y sus fotos ocupando el
	// teléfono para siempre.
	await purgarBorradores();

	await db.run(
		`INSERT INTO registros
			(id, envio_id, nombre_completo, documento, telefono, zona, direccion,
			 aviso_version, autorizacion_en, estado, creado_en, actualizado_en)
		 VALUES (?, ?, '', '', '', '', '', '', ?, 'BORRADOR', ?, ?)`,
		[id, crypto.randomUUID(), ahora, ahora, ahora]
	);

	return id;
}

/**
 * Borra los borradores abandonados y sus archivos.
 *
 * Un día de margen: alguien puede empezar el formulario, quedarse sin batería y
 * volver mañana. Más allá de eso, lo que hay es basura ocupando un teléfono que
 * seguramente no anda sobrado.
 */
export async function purgarBorradores(): Promise<string[]> {
	const db = await abrir();

	const rutas = await db.query(
		`SELECT a.ruta FROM adjuntos a
		   JOIN registros r ON r.id = a.registro_id
		  WHERE r.estado = 'BORRADOR' AND r.creado_en < datetime('now', '-1 day')`
	);

	await db.run(
		"DELETE FROM registros WHERE estado = 'BORRADOR' AND creado_en < datetime('now', '-1 day')"
	);

	return (rutas.values ?? []).map((f: { ruta: string }) => f.ruta);
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

	// UPDATE y no INSERT: la fila ya existe desde `empezar()`, con las fotos y
	// los videos colgando de ella. Insertarla otra vez fallaría por clave
	// duplicada, y borrarla y recrearla se llevaría los adjuntos por cascada.
	//
	// El `envio_id` NO se toca: se generó al abrir el borrador y es lo que hace
	// seguro reintentar. Regenerarlo aquí rompería la idempotencia y el servidor
	// podría inscribir dos veces a la misma familia.
	await db.executeTransaction([
		{
			statement: `UPDATE registros SET
					nombre_completo = ?, documento = ?, telefono = ?, correo = ?,
					zona = ?, direccion = ?, vereda = ?, corregimiento = ?,
					latitud = ?, longitud = ?, precision_m = ?, descripcion_dano = ?,
					autoriza_datos = 1, aviso_version = ?, autorizacion_en = ?,
					estado = 'PENDIENTE', actualizado_en = ?
				  WHERE id = ?`,
			values: [
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
				id
			]
		},
		// Las señales se rehacen: si alguien volvió atrás y cambió lo que marcó,
		// las de antes tienen que irse.
		{ statement: 'DELETE FROM registro_senales WHERE registro_id = ?', values: [id] },
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
		  WHERE r.estado <> 'BORRADOR'
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
