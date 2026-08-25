// Guardar y leer las solicitudes que esperan en el teléfono.
//
// Todo lo que se escribe aquí tiene que poder leerlo Kotlin: `SyncWorker` abre
// esta misma base con la aplicación cerrada. Por eso no hay JSON serializado ni
// nada que exija interpretar cadenas — las señales van en su tabla y los
// adjuntos en la suya.

import { Directory, Filesystem } from '@capacitor/filesystem';

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

	// Primero se barren los abandonados, con sus archivos. Sin esto, cada
	// formulario que no se termina deja una fila y sus fotos ocupando el
	// teléfono para siempre.
	await borrarArchivos(await purgarBorradores());

	// ⚠ SE REUTILIZA EL BORRADOR ABIERTO, no se crea uno nuevo.
	//
	// Esto corre en `onMount`, o sea CADA VEZ que se abre la aplicación. Creando
	// uno nuevo siempre, veinte aperturas dejaban veinte filas —y las fotos de
	// cada intento abandonado colgando de una fila distinta, invisibles y sin
	// borrar hasta que la purga las alcanzara un día después.
	//
	// Reutilizar tiene además una consecuencia buena: quien tomó una foto, cerró
	// la aplicación y volvió, se la encuentra donde la dejó en vez de haberla
	// perdido en un borrador que nadie volverá a abrir.
	//
	// Solo puede haber UNO: la aplicación tiene un formulario, no varios.
	const abierto = await db.query(
		"SELECT id FROM registros WHERE estado = 'BORRADOR' ORDER BY creado_en DESC LIMIT 1"
	);

	const previo = (abierto.values ?? [])[0] as { id: string } | undefined;
	if (previo) return previo.id;

	const id = crypto.randomUUID();
	const ahora = new Date().toISOString();

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

	// ⚠ `datetime(creado_en)` y no `creado_en` a secas.
	//
	// Las dos puntas de la comparación estaban en formatos distintos:
	// TypeScript escribe `2026-08-24T05:00:00.000Z` y `datetime('now')` devuelve
	// `2026-08-24 05:00:00`. Comparadas como texto, la «T» (0x54) es mayor que el
	// espacio (0x20), así que un borrador del mismo día NUNCA salía menor que el
	// corte y sobrevivía hasta el día siguiente.
	//
	// `datetime()` normaliza las dos formas —entiende la T, la Z y los
	// milisegundos—, y entonces el margen de un día es de verdad un día.
	const corte = "datetime(r.creado_en) < datetime('now', '-1 day')";

	const rutas = await db.query(
		`SELECT a.ruta FROM adjuntos a
		   JOIN registros r ON r.id = a.registro_id
		  WHERE r.estado = 'BORRADOR' AND ${corte}`
	);

	await db.run(
		`DELETE FROM registros
		  WHERE estado = 'BORRADOR' AND datetime(creado_en) < datetime('now', '-1 day')`
	);

	return (rutas.values ?? []).map((f: { ruta: string }) => f.ruta);
}

/**
 * Borra del teléfono los archivos de unas rutas.
 *
 * Las filas se van solas por las claves foráneas; los ARCHIVOS no. Esta función
 * existe porque `purgarBorradores()` devolvía las rutas para que alguien las
 * borrara y NADIE lo hacía: los datos del borrador abandonado desaparecían y sus
 * fotos y videos se quedaban en el aparato para siempre. Con un video de 8 MB
 * por solicitud, en un teléfono de gama baja eso se nota en semanas.
 *
 * Nunca lanza: un archivo que ya no está no es un problema, y fallar aquí
 * impediría abrir el formulario — que es lo único que la persona vino a hacer.
 */
export async function borrarArchivos(rutas: string[]): Promise<void> {
	for (const ruta of rutas) {
		await Filesystem.deleteFile({ path: ruta, directory: Directory.Data }).catch(() => undefined);
	}
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

/**
 * Borra una solicitud del teléfono, con sus archivos.
 *
 * Los archivos se borran AQUÍ y no en quien llame. Antes esta función solo
 * quitaba la fila y dejaba a quien la usara la obligación de acordarse de las
 * rutas; hoy no la llama nadie, y así se queda cerrada antes de que alguien la
 * conecte a un botón y herede el olvido.
 */
export async function borrar(id: string): Promise<void> {
	const rutas = await rutasDeSusArchivos(id);
	const db = await abrir();

	await db.run('DELETE FROM registros WHERE id = ?', [id]);
	await borrarArchivos(rutas);
}
