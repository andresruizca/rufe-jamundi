// De dónde viene quien está usando el sistema.
//
// El caso que originó esto está el primero: un Mac con Chrome, donde el menú
// ofrecía «Instalar en este teléfono».

import { describe, it, expect } from 'vitest';
import { reconocerAparato, nombreApple } from './aparato';

const MAC_CHROME =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';
const ANDROID_TELEFONO =
	'Mozilla/5.0 (Linux; Android 14; SM-A155M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36';
const ANDROID_TABLETA =
	'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';
const IPHONE =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const IPAD_MODERNO =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const WINDOWS =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';

describe('reconocerAparato', () => {
	it('un Mac es un equipo, no un teléfono', () => {
		// El error reportado: Chrome de escritorio SÍ ofrece instalar, así que el
		// botón salía; lo que estaba mal era la palabra.
		expect(reconocerAparato(MAC_CHROME, 0, false).clave).toBe('equipo');
		expect(reconocerAparato(MAC_CHROME, 0, false).este).toBe('este equipo');
	});

	it('Windows de escritorio también', () => {
		expect(reconocerAparato(WINDOWS, 0, false).clave).toBe('equipo');
	});

	it('en Android, «Mobile» es lo que separa teléfono de tableta', () => {
		expect(reconocerAparato(ANDROID_TELEFONO, 5, true).clave).toBe('telefono');
		expect(reconocerAparato(ANDROID_TABLETA, 5, false).clave).toBe('tableta');
	});

	it('el iPhone es un teléfono', () => {
		expect(reconocerAparato(IPHONE, 5).clave).toBe('telefono');
	});

	it('el iPad se hace pasar por Mac, y lo delata el táctil', () => {
		// Desde iPadOS 13 el agente es idéntico al de un Mac. Sin `maxTouchPoints`
		// no hay forma de distinguirlos, y un iPad quedaría como «equipo».
		expect(reconocerAparato(IPAD_MODERNO, 5).clave).toBe('tableta');
		expect(reconocerAparato(IPAD_MODERNO, 0).clave).toBe('equipo');
	});

	it('una tableta Android declara `mobile: false`, y aun así es tableta', () => {
		// Por esto `userAgentData.mobile` se consulta al final y no al principio:
		// solo sabe decir móvil o no-móvil, y confiarle la decisión convertiría
		// todas las tabletas en computadores.
		expect(reconocerAparato(ANDROID_TABLETA, 5, false).clave).toBe('tableta');
	});

	it('sin nada reconocible, se asume equipo antes que teléfono', () => {
		// Equivocarse hacia «equipo» solo suena raro; equivocarse hacia «teléfono»
		// es lo que se reportó.
		expect(reconocerAparato('agente desconocido').clave).toBe('equipo');
	});

	it('cada aparato trae su artículo, para que nadie escriba «esta teléfono»', () => {
		expect(reconocerAparato(ANDROID_TABLETA).este).toBe('esta tableta');
		expect(reconocerAparato(ANDROID_TABLETA).el).toBe('la tableta');
		expect(reconocerAparato(IPHONE).el).toBe('el teléfono');
	});
});

describe('nombreApple', () => {
	it('nombra el aparato como lo llama quien lo tiene en la mano', () => {
		expect(nombreApple(reconocerAparato(IPHONE, 5))).toBe('iPhone');
		expect(nombreApple(reconocerAparato(IPAD_MODERNO, 5))).toBe('iPad');
	});
});
