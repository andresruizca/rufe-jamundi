// La puerta del formulario: la cédula, antes que nada.
//
// La pre-inscripción dejó de ser un formulario abierto. Es la CONTINUACIÓN del
// proceso de quien ya fue censado en campo, así que lo primero que se pregunta
// es la cédula; si no aparece en el RUFE, no se sigue y se le da la línea de
// atención para que una persona decida qué hacer con su caso.
//
// La lógica vive aquí y no dentro del componente para poder probarla: es la
// única pantalla del sistema que puede dejar a una familia damnificada fuera, y
// un fallo tonto aquí no puede descubrirse en producción.
//
// Quien decide de verdad es PHP (`PreinscripcionController::crear`), que vuelve
// a comprobarlo. Esto existe para avisar ANTES de que alguien llene cuatro
// pasos para nada.

/** La línea de atención, escrita una sola vez. */
export const LINEA_ATENCION = {
	/** Como se marca: sin espacios y con el indicativo del país. */
	marcar: '+576025190969',
	/** Como se lee en voz alta y se copia a mano. */
	legible: '602 519 0969',
	extension: '2070',
	entidad: 'Gestión del Riesgo de Jamundí'
} as const;

const SOLO_DIGITOS = /\D+/g;

/** La cédula reducida a dígitos: la gente la escribe con puntos, como la lee. */
export function normalizar(crudo: string): string {
	return crudo.replace(SOLO_DIGITOS, '');
}

/**
 * Qué le impide consultar, si algo.
 *
 * Devuelve el aviso que hay que enseñar, o cadena vacía si se puede consultar.
 * Mismos límites que `validarPaso('datos')` y que el validador de PHP: si
 * discreparan, la puerta dejaría pasar cédulas que el paso 1 rechaza después.
 */
export function revisarCedula(crudo: string): string {
	const documento = normalizar(crudo);

	if (documento === '') {
		return 'Escriba su número de cédula.';
	}

	if (documento.length < 5 || documento.length > 15) {
		return 'Escriba su número de cédula, sin puntos ni espacios.';
	}

	return '';
}
