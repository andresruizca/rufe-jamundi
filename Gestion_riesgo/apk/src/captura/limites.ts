// Los techos que el servidor impone, escritos aquí para poder respetarlos
// ANTES de subir.
//
// El APK guarda en el teléfono y sincroniza horas después. Si un archivo se
// acepta ahora y el servidor lo rechaza mañana, la persona no está delante para
// arreglarlo: la evidencia se pierde y solo se entera quien revisa la bandeja.
// Por eso todo lo que el servidor vaya a rechazar tiene que rechazarse aquí,
// con la cámara todavía en la mano.
//
// Los valores salen de `backend/src/Rufe/Catalogos.php` y
// `backend/src/Preinscripcion/Videos.php`. Están duplicados y no hay forma de
// evitarlo —el APK funciona sin conexión, no puede preguntar—, así que van con
// su origen anotado y `catalogo.ts` los refresca cuando hay señal.

/** Por archivo. `Catalogos::MAX_BYTES_ARCHIVO`. */
export const MAX_BYTES_FOTO = 1024 * 1024;

/** Toda la carga junta. `Catalogos::MAX_BYTES_CARGA`. */
export const MAX_BYTES_CARGA = 12 * 1024 * 1024;

/** Fotos del daño. `Catalogos::MAX_FOTOS_PREINSCRIPCION`. */
export const MAX_FOTOS_DANO = 4;

/** Una sola, y es la que confirma que la solicitud es de quien dice. */
export const MAX_FOTOS_CEDULA = 1;

/** `Videos::BYTES_TROZO`. El servidor rechaza trozos fuera de orden. */
export const BYTES_TROZO = 1024 * 1024;

/** `Videos::MAX_BYTES_VIDEO`. */
export const MAX_BYTES_VIDEO = 8 * 1024 * 1024;

/** `Videos::MAX_VIDEOS_POR_CARGA`. */
export const MAX_VIDEOS = 8;

export type TipoAdjunto = 'PRE_CEDULA' | 'PRE_DANO' | 'VIDEO';

export function cupoDe(tipo: TipoAdjunto): number {
	if (tipo === 'PRE_CEDULA') return MAX_FOTOS_CEDULA;
	if (tipo === 'PRE_DANO') return MAX_FOTOS_DANO;

	return MAX_VIDEOS;
}

/**
 * En cuántos trozos se parte un video.
 *
 * El servidor los espera EN ORDEN y numerados desde cero. Un video de exactamente
 * 1 MiB es un trozo, no dos: el error clásico aquí es un `+1` de más que hace
 * que el último trozo vaya vacío y el servidor dé el video por incompleto —y un
 * video incompleto lo BORRA al recibir el formulario.
 */
export function trozosDe(bytes: number): number {
	if (bytes <= 0) return 0;

	return Math.ceil(bytes / BYTES_TROZO);
}

/** El rango de bytes del trozo `indice`, para leerlo del archivo. */
export function rangoDelTrozo(indice: number, bytes: number): { desde: number; hasta: number } {
	const desde = indice * BYTES_TROZO;

	return { desde, hasta: Math.min(desde + BYTES_TROZO, bytes) };
}

export type Rechazo = { ok: false; motivo: string };
export type Aceptacion = { ok: true };

/**
 * ¿Cabe este archivo?
 *
 * `yaHay` son los adjuntos de ese tipo que la solicitud ya tiene, y `bytesCarga`
 * lo que pesan todos juntos —fotos y videos—, porque el tope de carga es común.
 */
export function cabe(
	tipo: TipoAdjunto,
	bytes: number,
	yaHay: number,
	bytesCarga: number
): Aceptacion | Rechazo {
	const cupo = cupoDe(tipo);

	if (yaHay >= cupo) {
		return {
			ok: false,
			motivo:
				tipo === 'PRE_CEDULA'
					? 'Ya tomó la foto de su cédula. Quite la anterior si desea cambiarla.'
					: `Ya alcanzó el máximo de ${cupo}. Quite alguno si desea cambiarlo.`
		};
	}

	const techo = tipo === 'VIDEO' ? MAX_BYTES_VIDEO : MAX_BYTES_FOTO;

	if (bytes > techo) {
		return {
			ok: false,
			motivo:
				tipo === 'VIDEO'
					? 'El video quedó muy pesado. Grábelo más corto.'
					: 'La foto quedó muy pesada. Tómela de nuevo con menos zoom.'
		};
	}

	// El tope de carga es de todo junto. Comprobarlo aquí, y no al sincronizar,
	// es lo que evita que la persona grabe ocho videos y descubra semanas
	// después que tres nunca llegaron.
	if (bytesCarga + bytes > MAX_BYTES_CARGA) {
		return {
			ok: false,
			motivo:
				'Ya no cabe más en esta solicitud. Quite alguna foto o video para poder agregar este.'
		};
	}

	return { ok: true };
}
