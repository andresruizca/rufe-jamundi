// Qué respuestas de la API puede guardar el teléfono.
//
// Vive fuera del Service Worker para poder probarlo: el Service Worker importa
// `$service-worker`, que solo existe dentro del navegador, así que su lógica no
// se puede ejercitar en una prueba. Y esta es justo la regla que no puede
// romperse en silencio.
//
// La regla general es NO guardar nada de `/api/`: esas respuestas llevan
// nombres, cédulas y direcciones de hogares damnificados, y servirlas rancias
// desde un teléfono perdido o prestado sería un problema serio.
//
// La única excepción son los catálogos del formulario —listas de opciones,
// límites y el código del formato oficial—, que no contienen dato personal
// alguno y sin los cuales el formulario no se puede ni dibujar.

/** Rutas de la API que sí se guardan, comparadas COMPLETAS. */
export const API_CACHEABLE = ['/api/rufe/catalogos'];

/**
 * ¿Se guarda esta ruta de la API?
 *
 * Se compara la ruta entera y nunca por prefijo, a propósito: con un prefijo,
 * añadir mañana `/api/rufe/catalogos/personas` lo metería en la caché sin que
 * nadie hubiera decidido nada.
 */
export function seGuardaDeLaApi(pathname: string): boolean {
	return API_CACHEABLE.includes(pathname);
}
