import { describe, expect, it } from 'vitest';
import { orientacionDe } from './orientacion.svelte';

describe('la orientación de la ventana', () => {
	it('un teléfono de pie es vertical', () => {
		expect(orientacionDe(390, 844)).toBe('vertical');
	});

	it('el mismo teléfono acostado es apaisado', () => {
		expect(orientacionDe(844, 390)).toBe('apaisado');
	});

	it('una ventana cuadrada cuenta como apaisada', () => {
		// La cédula ya cabe: pedirle a alguien que gire una pantalla cuadrada es
		// pedirle algo que no cambia nada.
		expect(orientacionDe(600, 600)).toBe('apaisado');
	});

	it('un computador nunca ve el aviso', () => {
		// Es lo que evita que salga «gire el teléfono» en un portátil, donde no
		// hay nada que girar y el mensaje solo confunde.
		expect(orientacionDe(1512, 860)).toBe('apaisado');
	});
});
