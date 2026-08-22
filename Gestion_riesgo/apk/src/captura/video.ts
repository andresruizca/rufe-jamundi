// Guardar el video grabado, para que WorkManager lo suba más tarde.
//
// Aquí está la diferencia de fondo con la web. Allá `GrabadorVideo` graba y sube
// en el acto: si falla, la persona lo ve y reintenta. En el APK puede pasar un
// día entre grabar y subir, así que lo que se guarda tiene que bastarse solo —el
// archivo, su categoría, su duración y en qué trozo iba— sin la pantalla
// delante.
//
// Lo que NO cambia: el codec. `video.ts` del formulario elige WebM en Android y
// MP4 en iPhone —Safari nunca graba WebM— y esa decisión sigue siendo suya. El
// APK es Android, pero la comprobación se conserva porque el mismo código corre
// en `npm run dev` dentro de un navegador de escritorio.

import { Directory, Filesystem } from '@capacitor/filesystem';
import { cabe, trozosDe, MAX_BYTES_VIDEO } from './limites';
import { abrir } from '../local/base';

const CARPETA = Directory.Data;

export type VideoGuardado = {
	id: string;
	ruta: string;
	bytes: number;
	segundos: number;
	trozos: number;
};

export type ResultadoVideo = { ok: true; video: VideoGuardado } | { ok: false; motivo: string };

/**
 * Guarda un video recién grabado y lo deja en cola.
 *
 * `categoriaId` y `categoriaNombre` se guardan LOS DOS. El identificador es lo
 * que el servidor espera; el nombre es lo que la persona tenía en pantalla al
 * grabar. Si el catálogo cambia mientras el teléfono está sin señal, el
 * identificador puede quedar apuntando a una categoría que ya no existe —allá la
 * clave foránea es ON DELETE SET NULL y el video se guarda igual—, pero el
 * nombre sigue diciendo qué se grabó.
 */
export async function guardarVideo(
	registroId: string,
	datos: {
		blob: Blob;
		mime: string;
		segundos: number;
		categoriaId: number | null;
		categoriaNombre: string;
	}
): Promise<ResultadoVideo> {
	const db = await abrir();

	const cuantos = await db.query(
		"SELECT COUNT(*) AS n FROM adjuntos WHERE registro_id = ? AND tipo = 'VIDEO'",
		[registroId]
	);
	const peso = await db.query('SELECT COALESCE(SUM(bytes), 0) AS b FROM adjuntos WHERE registro_id = ?', [
		registroId
	]);

	const veredicto = cabe(
		'VIDEO',
		datos.blob.size,
		Number(cuantos.values?.[0]?.n ?? 0),
		Number(peso.values?.[0]?.b ?? 0)
	);

	if (!veredicto.ok) {
		return { ok: false, motivo: veredicto.motivo };
	}

	const azar = crypto.getRandomValues(new Uint8Array(8));
	const sufijo = Array.from(azar, (b) => b.toString(16).padStart(2, '0')).join('');
	const ruta = `${registroId}/${sufijo}.${extensionDe(datos.mime)}`;

	await Filesystem.writeFile({
		path: ruta,
		directory: CARPETA,
		data: await aBase64(datos.blob),
		recursive: true
	});

	const id = crypto.randomUUID();
	const trozos = trozosDe(datos.blob.size);

	await db.run(
		`INSERT INTO adjuntos
		   (id, registro_id, tipo, ruta, mime, bytes, segundos,
		    categoria_id, categoria_nombre, trozos_totales, creado_en, actualizado_en)
		 VALUES (?, ?, 'VIDEO', ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`,
		[
			id,
			registroId,
			ruta,
			datos.mime,
			datos.blob.size,
			datos.segundos,
			datos.categoriaId,
			datos.categoriaNombre,
			trozos
		]
	);

	return { ok: true, video: { id, ruta, bytes: datos.blob.size, segundos: datos.segundos, trozos } };
}

/**
 * La extensión que corresponde al formato grabado.
 *
 * Tiene que coincidir con `Videos::FORMATOS` del servidor, que es quien decide
 * qué acepta: `video/webm`, `video/mp4` y `video/quicktime`. Un formato que no
 * esté ahí se rechaza al reservar el video, ya con el archivo grabado.
 */
export function extensionDe(mime: string): string {
	if (mime.startsWith('video/webm')) return 'webm';
	if (mime.startsWith('video/quicktime')) return 'mov';

	return 'mp4';
}

/** Lo que el servidor acepta. Comprobarlo aquí evita grabar para nada. */
export function formatoAdmitido(mime: string): boolean {
	return /^video\/(webm|mp4|quicktime)\b/.test(mime);
}

/**
 * Cuántos segundos caben, como mucho, antes de pasarse de peso.
 *
 * Es una estimación para avisar mientras se graba, no una medida. A 480p con
 * VP9 el ritmo ronda 1 Mbit/s: ocho megas dan poco más de un minuto. Sirve para
 * cortar antes de que el archivo sea inservible, que es lo que pasa si se
 * descubre el exceso cuando ya se grabó.
 */
export function segundosQueCaben(bitsPorSegundo = 1_000_000): number {
	return Math.floor((MAX_BYTES_VIDEO * 8) / bitsPorSegundo);
}

async function aBase64(archivo: Blob): Promise<string> {
	return new Promise((resolver, rechazar) => {
		const lector = new FileReader();

		lector.onerror = () => rechazar(new Error('No se pudo leer el video.'));
		lector.onload = () => {
			const url = String(lector.result);
			resolver(url.slice(url.indexOf(',') + 1));
		};

		lector.readAsDataURL(archivo);
	});
}
