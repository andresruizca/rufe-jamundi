// Qué sabe hacer ESTE navegador.

import { describe, it, expect, vi, afterEach } from 'vitest';
import { esWebKitDeApple, porQueNoSaleSolo } from './plataforma';

function navegador(userAgent: string, maxTouchPoints = 0) {
	vi.stubGlobal('navigator', { userAgent, maxTouchPoints });
}

afterEach(() => vi.unstubAllGlobals());

const IPHONE =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const CHROME_EN_IPHONE =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/139.0.0.0 Mobile/15E148 Safari/604.1';
const IPAD =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const ANDROID =
	'Mozilla/5.0 (Linux; Android 14; SM-A155M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36';
const MAC =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';

describe('reconocer WebKit de Apple', () => {
	it('un iPhone lo es', () => {
		navegador(IPHONE, 5);
		expect(esWebKitDeApple()).toBe(true);
	});

	it('«Chrome» en un iPhone TAMBIÉN lo es', () => {
		// Es el caso que más confunde: Apple obliga a todos los navegadores de iOS
		// a usar WebKit por dentro. Chrome de iPhone tampoco tiene Background
		// Sync, aunque se llame Chrome.
		navegador(CHROME_EN_IPHONE, 5);
		expect(esWebKitDeApple()).toBe(true);
	});

	it('un iPad, que se hace pasar por Mac, lo delata el táctil', () => {
		navegador(IPAD, 5);
		expect(esWebKitDeApple()).toBe(true);
	});

	it('un Mac de verdad no lo es: ahí Chrome es Chrome', () => {
		navegador(MAC, 0);
		expect(esWebKitDeApple()).toBe(false);
	});

	it('Android tampoco', () => {
		navegador(ANDROID, 5);
		expect(esWebKitDeApple()).toBe(false);
	});
});

describe('cómo se explica', () => {
	it('en iPhone se nombra a Safari: no es un fallo del sistema', () => {
		// Sin nombrarlo, suena a aplicación a medio hacer y el equipo pierde
		// tiempo buscando un fallo que no existe.
		navegador(IPHONE, 5);
		expect(porQueNoSaleSolo()).toContain('Safari');
	});

	it('en los demás, sin culpar a nadie por su nombre', () => {
		navegador(MAC, 0);
		expect(porQueNoSaleSolo()).not.toContain('Safari');
		expect(porQueNoSaleSolo()).toContain('Este navegador');
	});
});
