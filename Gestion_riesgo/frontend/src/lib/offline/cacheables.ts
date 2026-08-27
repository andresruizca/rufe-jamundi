// Qué respuestas de la API puede guardar el aparato.
//
// Vive fuera del Service Worker para poder probarlo: el Service Worker importa
// `$service-worker`, que solo existe dentro del navegador, así que su lógica no
// se puede ejercitar en una prueba. Y esta es justo la regla que no puede
// romperse en silencio.
//
// ── Lo que cambió, y lo que cuesta ───────────────────────────────────────────
//
// Durante mucho tiempo la regla fue NO guardar nada de `/api/` salvo los dos
// catálogos: el resto lleva nombres, cédulas y direcciones de hogares
// damnificados, y servir eso rancio desde un teléfono perdido sería un problema
// serio.
//
// La Alcaldía pidió que el sistema entero funcione sin señal, sabiendo ese
// coste. Desde entonces también se guardan las CONSULTAS —tablero, bandeja,
// mapa, reportes, call center—, y por tanto **el censo que alguien haya
// consultado vive en su aparato**.
//
// Lo que sostiene esa decisión son cuatro salvaguardas, y ninguna es opcional:
//
//   1. Se guarda lo que se ABRE, nunca se descarga la base por adelantado.
//   2. La caché se vacía al cerrar sesión, incluida la sesión que caduca sola.
//   3. Lo guardado caduca a las 24 h: antes que enseñar un dato viejo de una
//      familia, se prefiere decir que no hay dato.
//   4. Cuando se sirve de la copia, la pantalla lo dice y con qué fecha.
//
// Las tres últimas viven en `service-worker.ts`. Ver `docs/offline.md`.

/**
 * Rutas de la API que sí se guardan.
 *
 * Se comparan ENTERAS, con `{}` como único comodín y solo para un tramo. Nunca
 * por prefijo suelto: con un prefijo, añadir mañana
 * `/api/rufe/reportes/3/evidencias/7` lo metería en la caché sin que nadie
 * hubiera decidido nada — y eso es la foto de una cédula.
 */
export const API_CACHEABLE = [
	// Los catálogos de los tres formatos. Sin ellos no hay ni formulario que
	// dibujar, y no contienen dato personal alguno.
	//
	// El de la pre-inscripción faltaba, y era el más importante de los tres: es
	// el único formulario que abre un ciudadano desde su casa, muchas veces con
	// la señal que le queda. Sin este catálogo guardado, el formulario público
	// no se dibujaba sin conexión — la aplicación instalada abría en blanco.
	'/api/rufe/catalogos',
	'/api/inspeccion/catalogos',
	'/api/preinscripcion/catalogos',

	// Censo RUFE: la bandeja y la ficha.
	'/api/rufe/reportes',
	'/api/rufe/reportes/{}',

	// Inspecciones de vivienda.
	'/api/inspeccion/fichas',
	'/api/inspeccion/fichas/{}',

	// Solicitudes ciudadanas.
	'/api/preinscripcion/fichas',
	'/api/preinscripcion/fichas/{}',

	// Solicitudes ciudadanas: las cifras de avance de la bandeja.
	'/api/preinscripcion/resumen',

	// Mapa y tablero.
	//
	// `/api/rufe/tablero` entró al pasar el tablero de leer una hoja de Google a
	// leer la base. Sin él aquí, el tablero y los mapas —que también lo usan—
	// dejaron de funcionar sin señal el día que se hizo ese cambio, sin que
	// nada lo avisara: la lista de lo que se guarda no sabe de rutas nuevas.
	'/api/mapa/fichas',
	'/api/mapa/ubicaciones/{}',
	'/api/rufe/tablero',

	// Call center.
	'/api/callcenter/hogares',
	'/api/callcenter/resumen',

	// Quién soy: sin esto, abrir la aplicación sin señal no sabe ni qué rol
	// tiene quien entra, y le enseñaría un menú vacío.
	'/api/auth/me'
];

/**
 * Lo que NUNCA se guarda, aunque casara con lo de arriba.
 *
 * Va como lista propia y no como «lo que no está en la otra» porque son las dos
 * cosas que de verdad no pueden acabar en un aparato prestado:
 *
 *  • Las evidencias: la foto de una cédula, el video de una vivienda. Pesan
 *    megabytes cada una y son el dato más sensible del sistema.
 *  • Todo lo de sesión menos `/auth/me`: guardar una respuesta de login o de
 *    cambio de contraseña no tiene ningún uso y sí un riesgo evidente.
 */
const NUNCA = [/\/(fotos|videos|evidencias|archivos)(\/|$)/, /^\/api\/auth\/(?!me$)/];

/**
 * ¿Se guarda esta ruta de la API?
 *
 * El comodín `{}` casa con UN tramo y solo si no está vacío: `/fichas/{}` acepta
 * `/fichas/12` y rechaza `/fichas/` y `/fichas/12/fotos/3`.
 */
export function seGuardaDeLaApi(pathname: string): boolean {
	if (NUNCA.some((r) => r.test(pathname))) return false;

	const partes = pathname.split('/');

	return API_CACHEABLE.some((patron) => {
		const suyas = patron.split('/');
		if (suyas.length !== partes.length) return false;

		return suyas.every((tramo, i) => (tramo === '{}' ? partes[i] !== '' : tramo === partes[i]));
	});
}
