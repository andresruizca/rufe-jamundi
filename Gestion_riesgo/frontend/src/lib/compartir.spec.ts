// Mandarle el enlace de preinscripción a una persona concreta.

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { aNumeroDeWhatsapp, mensajePara, enlaceDePreinscripcion } from './compartir';

describe('el número, como lo quiere WhatsApp', () => {
	it('un móvil colombiano de diez dígitos recibe su indicativo', () => {
		expect(aNumeroDeWhatsapp('3157729890')).toBe('573157729890');
	});

	it('lo escrito a mano viene con espacios, guiones y paréntesis', () => {
		// Es lo que pasa cuando alguien dicta un número de pie en la calle y otro
		// lo copia en un teléfono.
		expect(aNumeroDeWhatsapp('315 772 98 90')).toBe('573157729890');
		expect(aNumeroDeWhatsapp('315-772-9890')).toBe('573157729890');
		expect(aNumeroDeWhatsapp('+57 315 7729890')).toBe('573157729890');
		expect(aNumeroDeWhatsapp('(315) 7729890')).toBe('573157729890');
	});

	it('un fijo no recibe WhatsApp, y no se ofrece el botón', () => {
		// No es un dato malo: es que por ahí no se puede mandar un enlace. Abrir
		// WhatsApp igual enseñaría «número no válido» y parecería que el sistema
		// falla.
		expect(aNumeroDeWhatsapp('5551234')).toBeNull();
		expect(aNumeroDeWhatsapp('6025551234')).toBeNull();
	});

	it('un número incompleto o vacío tampoco', () => {
		expect(aNumeroDeWhatsapp('315772')).toBeNull();
		expect(aNumeroDeWhatsapp('')).toBeNull();
		expect(aNumeroDeWhatsapp('   ')).toBeNull();
	});

	it('con indicativo, solo si detrás va un móvil', () => {
		expect(aNumeroDeWhatsapp('573157729890')).toBe('573157729890');
		expect(aNumeroDeWhatsapp('576025551234')).toBeNull();
	});
});

describe('el mensaje', () => {
	const enlace = 'https://grj.oticjamundi.com/preinscripcion';

	it('empieza diciendo de parte de quién viene', () => {
		// Un enlace de un número desconocido y sin remitente se borra sin abrirlo,
		// y con más razón cuando pide la foto de una cédula.
		const m = mensajePara('Rosa Elena', enlace);

		expect(m.startsWith('Buen día, Rosa Elena.')).toBe(true);
		expect(m).toContain('Alcaldía de Jamundí');
		expect(m).toContain(enlace);
	});

	it('sin nombre sigue saludando, no empieza en seco', () => {
		expect(mensajePara('', enlace).startsWith('Buen día. Le escribimos')).toBe(true);
		expect(mensajePara('   ', enlace)).not.toContain('undefined');
	});

	it('avisa del radicado: es la constancia de la persona', () => {
		expect(mensajePara('Ana', enlace)).toContain('radicado');
	});
});

describe('el mensaje general de la bandeja', () => {
	// El que se copia desde la bandeja no va dirigido a nadie: puede acabar en un
	// grupo del barrio o en una cartelera. Lo que sí tiene que decir es de dónde
	// viene y qué hay que tener a mano, que es lo que evita que se abandone a
	// mitad del formulario.
	const fuente = readFileSync(
		fileURLToPath(new URL('./components/CompartirFormulario.svelte', import.meta.url)),
		'utf8'
	);

	it('dice de parte de quién viene', () => {
		expect(fuente).toContain('Alcaldía de Jamundí · Gestión del Riesgo');
	});

	it('avisa de tener la cédula a mano, y del radicado al terminar', () => {
		expect(fuente).toContain('Tenga a mano su cédula');
		expect(fuente).toContain('radicado');
	});

	it('lleva el enlace, y sale del dominio donde se esté', () => {
		// Escrito a mano, una copia del sistema en otro dominio mandaría a la
		// gente al dominio de al lado.
		expect(fuente).toContain('enlaceDePreinscripcion(page.url.origin)');
		expect(fuente).toContain('${enlace}');
	});

	it('se puede ABRIR el formulario, no solo copiarlo', () => {
		// Quien va a pegarlo en un grupo quiere ver primero qué se encuentra la
		// gente; y quien atiende el teléfono lo abre para acompañar a alguien paso
		// a paso. En pestaña nueva, para no perder el filtro de la bandeja.
		expect(fuente).toContain('Abrir el formulario');
		expect(fuente).toContain('href={enlace}');
		expect(fuente).toContain('target="_blank"');
	});

	it('WhatsApp se abre SIN número: aquí no se sabe a quién', () => {
		// Con número es el call center, que sí sabe a quién llama. Aquí el enlace
		// es general y `wa.me` sin número abre la lista de contactos.
		expect(fuente).toContain('https://wa.me/?text=');
	});
});

describe('el enlace', () => {
	it('sale del dominio donde se esté, no escrito a mano', () => {
		// Escrito a mano, una copia del sistema en otro dominio mandaría a la
		// gente al dominio de al lado.
		expect(enlaceDePreinscripcion('https://grj.oticjamundi.com')).toBe(
			'https://grj.oticjamundi.com/preinscripcion'
		);
	});

	it('no duplica la barra', () => {
		expect(enlaceDePreinscripcion('http://localhost:5173/')).toBe(
			'http://localhost:5173/preinscripcion'
		);
	});
});
