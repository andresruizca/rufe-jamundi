// Mandarle a una persona concreta el enlace para preinscribirse.
//
// El caso es este: un profesional termina de inspeccionar una casa y el vecino
// se acerca a preguntar por la suya. Hoy la respuesta es «vaya a la Alcaldía» o
// dictarle una dirección web de memoria, de pie y en la calle. Con el teléfono
// de esa persona ya escrito, el sistema puede mandarle el enlace en un toque.
//
// Va como módulo aparte del componente para poder probar lo único que aquí
// puede salir mal de verdad: convertir un número colombiano escrito a mano —con
// espacios, guiones, con o sin indicativo— en el formato que WhatsApp exige.

/** El indicativo de Colombia. */
const INDICATIVO = '57';

/**
 * El número tal como lo quiere `wa.me`: solo dígitos, con indicativo y sin `+`.
 *
 * Devuelve `null` cuando no reconoce un móvil colombiano. Es a propósito: abrir
 * WhatsApp con un número mal formado enseña un «número no válido» que la
 * persona interpreta como que el sistema falla, cuando lo que pasa es que el
 * número no sirve. Mejor no ofrecer el botón.
 */
export function aNumeroDeWhatsapp(bruto: string): string | null {
	const digitos = (bruto ?? '').replace(/\D/g, '');

	if (digitos === '') return null;

	// Ya viene con indicativo: 57 + 10 dígitos.
	if (digitos.length === 12 && digitos.startsWith(INDICATIVO)) {
		return digitos.startsWith(`${INDICATIVO}3`) ? digitos : null;
	}

	// Móvil colombiano: 10 dígitos que empiezan por 3.
	if (digitos.length === 10 && digitos.startsWith('3')) {
		return INDICATIVO + digitos;
	}

	// Un fijo de Jamundí —7 dígitos, o 8 con el indicativo 602— no recibe
	// WhatsApp ni mensajes de texto. No es un error del dato: es que por ahí no
	// se puede mandar un enlace.
	return null;
}

/**
 * El mensaje que le llega a la persona.
 *
 * Escrito para leerse en la pantalla de bloqueo de un teléfono, donde se ven
 * dos líneas. Por eso lo primero es de parte de quién viene: un enlace de un
 * número desconocido, sin remitente, se borra sin abrirlo — y con más razón
 * cuando pide la foto de una cédula.
 */
export function mensajePara(nombre: string, enlace: string): string {
	const saludo = nombre.trim() !== '' ? `Buen día, ${nombre.trim()}. ` : 'Buen día. ';

	return (
		`${saludo}Le escribimos de la Alcaldía de Jamundí, Gestión del Riesgo.\n\n` +
		'Para que registremos la afectación de su vivienda, diligencie este formulario ' +
		'desde su celular. Toma unos minutos y puede adjuntar fotos del daño:\n\n' +
		`${enlace}\n\n` +
		'Al terminar recibirá un número de radicado. Guárdelo: es su constancia.'
	);
}

/** La dirección del formulario ciudadano, en el mismo dominio donde se esté. */
export function enlaceDePreinscripcion(origen: string): string {
	return `${origen.replace(/\/$/, '')}/preinscripcion`;
}
