import { describe, expect, it } from 'vitest';
import { frasesQueSeDicen, leerGuion } from './guion';

describe('el guión de la llamada', () => {
	it('parte el texto en secciones', () => {
		const s = leerGuion('## Saludo\n» Buenos días.\n\n## Cierre\n- Anote la llamada.');

		expect(s.map((x) => x.titulo)).toEqual(['Saludo', 'Cierre']);
		expect(s[0].lineas).toEqual([{ tipo: 'decir', texto: 'Buenos días.' }]);
	});

	it('distingue lo que se dice de lo que se hace y de lo que no se debe decir', () => {
		const [seccion] = leerGuion('## X\n» Le habla la Alcaldía.\n- Marque en el teléfono IP.\n! No prometa ayudas.');

		expect(seccion.lineas.map((l) => l.tipo)).toEqual(['decir', 'hacer', 'nunca']);
	});

	it('separa la pregunta frecuente de su respuesta', () => {
		const [seccion] = leerGuion('## X\n? ¿Tiene costo? » Ninguno.');

		expect(seccion.lineas[0]).toEqual({
			tipo: 'pregunta',
			texto: '¿Tiene costo?',
			respuesta: 'Ninguno.'
		});
	});

	it('una pregunta sin respuesta sigue apareciendo', () => {
		// Sin esto, media línea de un guión editado a las prisas desaparecería
		// de la pantalla sin que nadie se enterara.
		const [seccion] = leerGuion('## X\n? ¿Cuándo me visitan?');

		expect(seccion.lineas[0]).toEqual({ tipo: 'pregunta', texto: '¿Cuándo me visitan?' });
	});

	it('no pierde el texto que va antes del primer encabezado', () => {
		const s = leerGuion('» Buenos días.\n## Saludo\n» ¿Hablo con Juan?');

		expect(s).toHaveLength(2);
		expect(s[0].titulo).toBe('');
		expect(s[0].lineas[0].texto).toBe('Buenos días.');
	});

	it('una línea sin marca se muestra igual, no se tira', () => {
		const [seccion] = leerGuion('## X\nEsto lo escribió alguien sin poner marca.');

		expect(seccion.lineas[0].tipo).toBe('texto');
	});

	it('cuenta las frases que se leen en voz alta', () => {
		expect(frasesQueSeDicen('## X\n» Una.\n- Dos.\n? ¿Tres? » Sí.')).toBe(2);
		expect(frasesQueSeDicen('## X\n- Solo notas.')).toBe(0);
	});
});
