import { describe, expect, it } from 'vitest';
import { LINEA_ATENCION, normalizar, revisarCedula } from './puerta';

describe('normalizar', () => {
	it('quita los puntos con los que la gente escribe la cédula', () => {
		expect(normalizar('1.144.062.345')).toBe('1144062345');
	});

	it('quita espacios y guiones', () => {
		expect(normalizar(' 16 285 943 ')).toBe('16285943');
	});
});

describe('revisarCedula', () => {
	it('deja pasar una cédula normal', () => {
		expect(revisarCedula('1144062345')).toBe('');
	});

	it('acepta la cédula escrita con puntos', () => {
		expect(revisarCedula('1.144.062.345')).toBe('');
	});

	it('pide la cédula cuando está vacía', () => {
		expect(revisarCedula('   ')).not.toBe('');
	});

	// El paso 1 exige entre 5 y 15 dígitos. Si la puerta fuera más laxa, dejaría
	// entrar a alguien para rechazarlo un paso después por lo mismo.
	it('rechaza lo demasiado corto y lo demasiado largo', () => {
		expect(revisarCedula('1234')).not.toBe('');
		expect(revisarCedula('1234567890123456')).not.toBe('');
	});

	it('no confunde letras con dígitos', () => {
		expect(revisarCedula('abcdefg')).not.toBe('');
	});
});

describe('LINEA_ATENCION', () => {
	// Es lo único que se lleva quien no puede continuar. Una errata aquí deja a
	// una familia damnificada sin a dónde llamar.
	it('el número marcable lleva indicativo de país y solo dígitos', () => {
		expect(LINEA_ATENCION.marcar).toBe('+576025190969');
		expect(LINEA_ATENCION.marcar.slice(1)).toMatch(/^\d+$/);
	});

	it('el número legible es el mismo número', () => {
		expect(`+57${LINEA_ATENCION.legible.replace(/\s/g, '')}`).toBe(LINEA_ATENCION.marcar);
	});
});
