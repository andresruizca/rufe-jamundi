import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { deBase64Url, sePuede } from './avisos';

describe('deBase64Url', () => {
	it('devuelve los 65 bytes que pide el navegador', () => {
		// Una clave VAPID es un punto sin comprimir: 0x04 y detrás X e Y de 32
		// bytes. `applicationServerKey` no acepta la cadena, quiere los bytes.
		const clave =
			'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';

		expect(deBase64Url(clave)).toHaveLength(65);
		expect(deBase64Url(clave)[0]).toBe(0x04);
	});

	it('le devuelve el relleno que el base64 de la web le quita', () => {
		// Sin esto `atob` lanza «string contains invalid characters» y el
		// interruptor de avisos falla sin decir por qué.
		expect(deBase64Url('QQ')).toEqual(new Uint8Array([0x41]));
		expect(deBase64Url('QUJD')).toEqual(new Uint8Array([0x41, 0x42, 0x43]));
	});

	it('entiende los `-` y `_` en vez de `+` y `/`', () => {
		// El base64 corriente lleva `+` y `/`, que en una URL significan otra
		// cosa. El servidor manda la variante web; si aquí no se tradujeran,
		// saldría una clave distinta y el navegador la rechazaría.
		expect(deBase64Url('-_8')).toEqual(deBase64Url('+/8'));
	});
});

describe('sePuede', () => {
	it('no promete nada fuera del navegador', () => {
		// Se importa desde el servidor al compilar: preguntar por `window` sin
		// comprobarlo tumbaría la compilación entera.
		expect(sePuede()).toBe(false);
	});

	it('exige las TRES piezas, no solo el permiso', () => {
		// iOS trae `Notification` desde hace años pero solo entrega
		// `PushManager` cuando la aplicación está instalada en la pantalla de
		// inicio. Mirar solo una dejaría a un iPhone ofreciendo avisos que
		// nunca van a llegar.
		const fuente = readFileSync(new URL('./avisos.ts', import.meta.url), 'utf-8');

		expect(fuente).toContain("'Notification' in window");
		expect(fuente).toContain("'serviceWorker' in navigator");
		expect(fuente).toContain("'PushManager' in window");
	});
});

describe('lo que el aviso NO lleva', () => {
	const sw = readFileSync(new URL('../../service-worker.ts', import.meta.url), 'utf-8');
	const push = sw.slice(sw.indexOf("sw.addEventListener('push'"), sw.indexOf("sw.addEventListener('sync'"));

	it('el texto está escrito aquí, no viene del servidor', () => {
		// Es la decisión de fondo. Un aviso con contenido pasa por los
		// servidores de Google o de Mozilla; el nombre de una familia
		// damnificada no tiene por qué salir de la Alcaldía para que a alguien
		// le suene el teléfono. Si esto leyera `evento.data`, ese dato habría
		// viajado.
		expect(push).not.toContain('.data.json()');
		expect(push).not.toContain('.data?.text()');
		expect(push).toContain('Solicitud ciudadana nueva');
	});

	it('siempre muestra algo', () => {
		// `userVisibleOnly` obliga: un navegador que detecte pushes silenciosos
		// le retira el permiso a TODA la aplicación, y entonces se pierden
		// también los avisos que sí importan.
		expect(push).toContain('showNotification');
	});

	it('agrupa en una sola notificación', () => {
		// Cinco solicitudes en una tarde con el teléfono guardado no pueden ser
		// cinco notificaciones: eso es exactamente lo que lleva a alguien a
		// desactivarlas, y entonces no se entera de ninguna.
		expect(push).toContain('tag: AVISO_ETIQUETA');
	});
});
