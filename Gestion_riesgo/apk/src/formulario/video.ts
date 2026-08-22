// Grabar un video en el teléfono.
//
// **Cada teléfono graba distinto.** Android produce WebM con VP9 o VP8; iPhone
// no sabe grabar WebM y da MP4 con H.264. No se puede pedir un formato y
// confiar: hay que preguntarle al navegador qué sabe hacer y quedarse con lo
// primero que acepte.
//
// ⚠ AQUÍ EL APK SE APARTA DE LA WEB, A PROPÓSITO.
//
// El original trae además `subirVideo()`, que parte el video en trozos y los
// manda uno a uno. En el APK eso NO se usa: quien sube es `SyncWorker.kt`, con
// la aplicación cerrada y sin WebView, y una función de subida en TypeScript que
// nadie llama sería código muerto que alguien mantendría por error.
//
// Lo que se conserva —la detección de formato— sí hace falta, y su prueba
// también: es lo que evita grabar en un códec que el servidor rechaza después.

/**
 * Los formatos que se intentan, en orden de preferencia.
 *
 * VP9 comprime bastante mejor que VP8 —y en una vereda cada megabyte son
 * segundos de subida—, así que va primero. Android suele darlo.
 *
 * El MP4 del final NO es un adorno: Safari, en iPhone y en Mac, **solo** sabe
 * grabar MP4 con H.264 y AAC, y no soporta WebM en absoluto. Es lo que dice
 * WebKit al anunciar la API (webkit.org/blog/11353/mediarecorder-api/). Sin esa
 * entrada, ningún iPhone podría grabar.
 */
const FORMATOS = [
	'video/webm;codecs=vp9',
	'video/webm;codecs=vp8',
	'video/webm',
	'video/mp4;codecs=avc1',
	'video/mp4'
];

/**
 * Resolución objetivo.
 *
 * 480p y no 720p a conciencia: a 720p un video de 30 segundos ronda los 15 MB y
 * son más de quince minutos de subida en una 3G rural. A 480p baja a unos 3 MB.
 * Para ver una grieta o un techo hundido sobra.
 */
export const RESTRICCIONES: MediaStreamConstraints = {
	video: {
		width: { ideal: 854 },
		height: { ideal: 480 },
		frameRate: { ideal: 24, max: 30 },
		facingMode: { ideal: 'environment' }
	},
	audio: true
};

/** El primer formato que este navegador sepa grabar, o null si no sabe ninguno. */
export function formatoSoportado(): string | null {
	if (typeof MediaRecorder === 'undefined') return null;

	// Hay implementaciones de Safari con MediaRecorder pero SIN `isTypeSupported`.
	// El propio WebKit documenta la salida: dar por bueno MP4, que es lo único
	// que graba. Sin este caso, esos teléfonos dirían «no se puede grabar»
	// teniendo la cámara perfectamente disponible.
	if (typeof MediaRecorder.isTypeSupported !== 'function') {
		return 'video/mp4';
	}

	return FORMATOS.find((f) => MediaRecorder.isTypeSupported(f)) ?? null;
}

/** El MIME sin los parámetros de códec, que es lo que entiende el servidor. */
export function mimeBase(formato: string): string {
	return formato.split(';')[0];
}
