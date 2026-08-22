// Tomar una foto y dejarla lista para que WorkManager la suba más tarde.
//
// La diferencia con la web es dónde termina: allá la foto se sube en el acto y
// se olvida; aquí se guarda en el teléfono, se anota en SQLite y puede pasar un
// día antes de que salga. Eso cambia dos cosas:
//
//  • Todo lo que el servidor vaya a rechazar hay que rechazarlo AHORA, con la
//    cámara en la mano. Mañana la persona no está delante y la evidencia se
//    perdería en silencio.
//  • La original NUNCA se guarda. En la web era por no gastar datos; aquí es
//    además por espacio: cinco fotos de una cámara de gama media son 100 MB en
//    un teléfono que a lo mejor tiene 300 libres.

import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { comprimirEvidencia, extensionDe, type TipoEvidencia } from '../formulario/imagen';
import { cabe, type TipoAdjunto } from './limites';
import { abrir } from '../local/base';

export type Adjunto = {
	id: string;
	tipo: TipoAdjunto;
	ruta: string;
	mime: string;
	bytes: number;
};

export type ResultadoFoto = { ok: true; adjunto: Adjunto } | { ok: false; motivo: string };

/** Dónde viven los archivos mientras esperan a subir. */
const CARPETA = Directory.Data;

function nombreDeArchivo(registroId: string, mime: string): string {
	const azar = crypto.getRandomValues(new Uint8Array(8));
	const sufijo = Array.from(azar, (b) => b.toString(16).padStart(2, '0')).join('');

	return `${registroId}/${sufijo}.${extensionDe(mime)}`;
}

/**
 * Cuánto pesa ya esta solicitud, contando fotos y videos.
 *
 * El tope de 12 MiB del servidor es de la carga entera, no por archivo.
 */
async function pesoActual(registroId: string): Promise<{ bytes: number; delTipo: number }> {
	const db = await abrir();
	const total = await db.query('SELECT COALESCE(SUM(bytes), 0) AS b FROM adjuntos WHERE registro_id = ?', [registroId]);

	return { bytes: Number(total.values?.[0]?.b ?? 0), delTipo: 0 };
}

async function cuantosHay(registroId: string, tipo: TipoAdjunto): Promise<number> {
	const db = await abrir();
	const r = await db.query('SELECT COUNT(*) AS n FROM adjuntos WHERE registro_id = ? AND tipo = ?', [
		registroId,
		tipo
	]);

	return Number(r.values?.[0]?.n ?? 0);
}

/**
 * Abre la cámara o la galería, comprime y guarda.
 *
 * `fuente` sale de la pantalla: hay quien ya tiene la foto tomada de antes —de
 * cuando ocurrió el daño, que es cuando de verdad se veía— y obligarle a
 * repetirla ahora sería perder la mejor evidencia que tiene.
 */
export async function tomarFoto(
	registroId: string,
	tipo: 'PRE_CEDULA' | 'PRE_DANO',
	fuente: 'camara' | 'galeria' = 'camara'
): Promise<ResultadoFoto> {
	let original: File;

	try {
		const foto = await Camera.getPhoto({
			quality: 92,
			// Sin recorte: el ciudadano no tiene por qué encuadrar, y el editor de
			// recorte de Android confunde más de lo que ayuda.
			allowEditing: false,
			resultType: CameraResultType.Uri,
			source: fuente === 'camara' ? CameraSource.Camera : CameraSource.Photos,
			// Se pide alto y se comprime después: partir de una imagen ya degradada
			// por la cámara deja menos margen para leer un número de cédula.
			correctOrientation: true
		});

		if (!foto.webPath) {
			return { ok: false, motivo: 'No se pudo leer la foto. Intente de nuevo.' };
		}

		const respuesta = await fetch(foto.webPath);
		const blob = await respuesta.blob();

		original = new File([blob], `foto.${foto.format ?? 'jpg'}`, {
			type: blob.type || 'image/jpeg'
		});
	} catch (e) {
		// Cancelar es lo más común y no es un error: se sale en silencio.
		const mensaje = e instanceof Error ? e.message : '';

		if (/cancel/i.test(mensaje)) return { ok: false, motivo: '' };

		return { ok: false, motivo: 'No se pudo abrir la cámara. Revise los permisos.' };
	}

	// Se comprime ANTES de comprobar el cupo de peso: lo que cuenta contra el
	// límite es lo que se va a subir, no lo que entregó la cámara.
	const comprimida = await comprimirEvidencia(original, tipo as TipoEvidencia);

	if (!comprimida.ok) {
		return { ok: false, motivo: comprimida.motivo };
	}

	const { bytes } = await pesoActual(registroId);
	const yaHay = await cuantosHay(registroId, tipo);
	const veredicto = cabe(tipo, comprimida.archivo.size, yaHay, bytes);

	if (!veredicto.ok) {
		return { ok: false, motivo: veredicto.motivo };
	}

	const ruta = nombreDeArchivo(registroId, comprimida.archivo.type);

	await Filesystem.writeFile({
		path: ruta,
		directory: CARPETA,
		data: await aBase64(comprimida.archivo),
		recursive: true
	});

	const db = await abrir();
	const id = crypto.randomUUID();

	await db.run(
		`INSERT INTO adjuntos (id, registro_id, tipo, ruta, mime, bytes, creado_en, actualizado_en)
		 VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`,
		[id, registroId, tipo, ruta, comprimida.archivo.type, comprimida.archivo.size]
	);

	return {
		ok: true,
		adjunto: { id, tipo, ruta, mime: comprimida.archivo.type, bytes: comprimida.archivo.size }
	};
}

/**
 * Filesystem de Capacitor escribe base64, no binario.
 *
 * Se lee con FileReader y no montando la cadena a mano: para una foto de 900 KB,
 * un bucle sobre el arreglo de bytes bloquea el hilo de la interfaz casi un
 * segundo en un teléfono modesto, y parece que la aplicación se colgó.
 */
async function aBase64(archivo: Blob): Promise<string> {
	return new Promise((resolver, rechazar) => {
		const lector = new FileReader();

		lector.onerror = () => rechazar(new Error('No se pudo leer el archivo.'));
		lector.onload = () => {
			const url = String(lector.result);
			resolver(url.slice(url.indexOf(',') + 1));
		};

		lector.readAsDataURL(archivo);
	});
}

/** Quita una foto: del disco y de la base, en ese orden. */
export async function quitarAdjunto(id: string): Promise<void> {
	const db = await abrir();
	const r = await db.query('SELECT ruta FROM adjuntos WHERE id = ?', [id]);
	const ruta = r.values?.[0]?.ruta as string | undefined;

	if (ruta) {
		// Si el archivo ya no está, la fila se borra igual: dejarla apuntando a
		// algo inexistente haría que la sincronización fallara para siempre.
		await Filesystem.deleteFile({ path: ruta, directory: CARPETA }).catch(() => undefined);
	}

	await db.run('DELETE FROM adjuntos WHERE id = ?', [id]);
}
