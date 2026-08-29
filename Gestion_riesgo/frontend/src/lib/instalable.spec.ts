// Lo que decide cómo se ve la aplicación instalada en el teléfono.
//
// No hay código que probar aquí: son un JSON y una plantilla HTML. Pero es
// justamente el tipo de archivo que alguien toca de paso, que nada compila y
// que solo se descubre roto cuando un funcionario instala la aplicación y ve
// una pantalla de arranque en blanco. Estas pruebas fijan las decisiones que
// costaron encontrar.

import { describe, expect, it } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const raiz = join(import.meta.dirname, '..', '..');
const estatico = join(raiz, 'static');

const manifiesto = JSON.parse(
	readFileSync(join(estatico, 'manifest.webmanifest'), 'utf8')
) as {
	id: string;
	start_url: string;
	scope: string;
	background_color: string;
	theme_color: string;
	icons: { src: string; sizes: string; purpose?: string }[];
	screenshots: { src: string; sizes: string; form_factor: string; label?: string }[];
	shortcuts: { icons: { src: string }[] }[];
};

const html = readFileSync(join(raiz, 'src', 'app.html'), 'utf8');

/** El azul institucional del escudo, el mismo de la tarjeta para compartir. */
const AZUL_JAMUNDI = '#0d2b4e';

describe('el manifiesto', () => {
	it('pinta la pantalla de arranque con el azul institucional', () => {
		// Este es EL campo del problema original. Con el `#f1f5fc` que había
		// antes, Chrome dibujaba el arranque casi blanco y encima el icono
		// recortado en un círculo: se veía «SGR» flotando en una pantalla vacía.
		expect(manifiesto.background_color).toBe(AZUL_JAMUNDI);
	});

	it('mantiene la barra de estado del arranque del mismo color que el fondo', () => {
		// Si difieren, durante el segundo que dura el arranque queda una franja de
		// otro color pegada arriba, que parece un fallo de dibujado.
		expect(manifiesto.theme_color).toBe(AZUL_JAMUNDI);
	});

	it('tiene identidad propia', () => {
		// Sin `id`, la identidad de la aplicación instalada se deriva de
		// `start_url`. El día que esa ruta cambie —ya cambió una vez, de
		// `/riesgo/reportar` a `/`— los teléfonos que ya la tienen verían una
		// aplicación DISTINTA en vez de una actualización, y se quedarían con
		// la vieja sin que nadie se enterara.
		expect(manifiesto.id).toBeTruthy();
	});

	it('arranca dentro de su propio alcance', () => {
		// Un `start_url` fuera del `scope` abre el navegador POR ENCIMA de la
		// aplicación instalada: aparece la barra de direcciones y deja de
		// parecer una aplicación desde el primer segundo.
		expect(manifiesto.start_url.startsWith(manifiesto.scope)).toBe(true);
	});

	it('trae capturas de las dos formas de pantalla', () => {
		// Sin ellas, Chrome en Android no ofrece la ficha de instalación
		// completa: se conforma con la barrita de abajo, que mucha gente no ve.
		// Y son capturas REALES de la aplicación en producción: una imagen de
		// adorno en este campo engaña a quien está decidiendo si instalarla.
		const formas = manifiesto.screenshots.map((c) => c.form_factor);

		expect(formas).toContain('narrow');
		expect(formas).toContain('wide');

		for (const captura of manifiesto.screenshots) {
			expect(captura.label, `${captura.src} sin descripción`).toBeTruthy();
			expect(existsSync(join(estatico, captura.src)), `falta ${captura.src}`).toBe(true);
		}
	});

	it('las capturas NO se guardan en el teléfono', () => {
		// Pesan medio mega entre las dos y solo las mira el sistema operativo al
		// ofrecer la instalación, una vez. Meterlas en el armazón sería cobrarle
		// esos datos a cada censador para dibujar algo que ya vio.
		const sw = readFileSync(join(raiz, 'src', 'service-worker.ts'), 'utf8');
		const fuera = sw.slice(sw.indexOf('const FUERA = ['), sw.indexOf('const ARMAZON'));

		for (const captura of manifiesto.screenshots) {
			expect(fuera, `${captura.src} se descargaría en la instalación`).toContain(captura.src);
		}
	});

	it('declara exactamente un icono enmascarable', () => {
		// Sin ninguno, Android encoge el icono normal y lo pega sobre un círculo
		// blanco. Con varios, no está definido cuál elige.
		const enmascarables = manifiesto.icons.filter((i) => i.purpose === 'maskable');

		expect(enmascarables).toHaveLength(1);
		expect(enmascarables[0].sizes).toBe('512x512');
	});

	it('apunta a iconos que existen de verdad', () => {
		// Un icono que da 404 no rompe nada visible en el sitio: simplemente la
		// aplicación se instala con el icono genérico del navegador.
		const rutas = [
			...manifiesto.icons.map((i) => i.src),
			...manifiesto.shortcuts.flatMap((s) => s.icons.map((i) => i.src))
		];

		for (const ruta of rutas) {
			expect(existsSync(join(estatico, ruta)), `falta static${ruta}`).toBe(true);
		}
	});
});

describe('el HTML de arranque', () => {
	it('da a iOS su propio icono, sin las esquinas ya redondeadas', () => {
		// Apuntaba al icono de 192, que trae las esquinas dibujadas; iOS las
		// redondea otra vez y deja un reborde oscuro alrededor del icono.
		expect(html).toContain('apple-touch-icon');
		expect(html).toContain('/apple-touch-icon.png');
		expect(html).not.toContain('rel="apple-touch-icon" href="%sveltekit.assets%/icono-192.png"');
		expect(existsSync(join(estatico, 'apple-touch-icon.png'))).toBe(true);
	});

	it('ajusta la barra de estado al claro y al oscuro', () => {
		// La barra superior de la aplicación es blanca en claro y casi negra en
		// oscuro. Un solo color fijo acierta como mucho la mitad de las veces.
		expect(html).toContain('media="(prefers-color-scheme: light)"');
		expect(html).toContain('media="(prefers-color-scheme: dark)"');
	});
});

describe('el armazón sin conexión', () => {
	it('deja los iconos grandes fuera de la precarga', () => {
		// Desde que llevan el escudo pesan unos 210 KB cada uno, y la aplicación
		// en marcha no los pide nunca: los descarga el sistema al instalarla.
		// Precargarlos le costaba medio mega de datos a cada censador para
		// dibujar algo que ya está en su pantalla de inicio.
		const sw = readFileSync(join(raiz, 'src', 'service-worker.ts'), 'utf8');
		const fuera = sw.slice(sw.indexOf('const FUERA'), sw.indexOf('const ARMAZON'));

		expect(fuera).toContain('/icono-512.png');
		expect(fuera).toContain('/icono-maskable-512.png');
		expect(fuera).toContain('/apple-touch-icon.png');
	});
});


describe('la pantalla completa', () => {
	it('llega hasta el borde del aparato', () => {
		// Sin esto, la aplicación instalada deja dos franjas del color del
		// sistema arriba y abajo: se ve como una página web metida en un marco,
		// que es justo lo que una aplicación instalada no debe parecer.
		expect(html).toContain('viewport-fit=cover');
	});

	it('y aparta lo que el aparato tapa', () => {
		// `viewport-fit=cover` SIN esto es peor que no ponerlo: la aplicación
		// llega al borde físico y el escudo se mete debajo de la cámara, y el
		// botón de enviar una ficha queda donde Android se traga el toque.
		const shell = readFileSync(join(raiz, 'src', 'lib', 'shell.css'), 'utf8');

		expect(shell).toContain('env(safe-area-inset-top)');
		expect(shell).toContain('env(safe-area-inset-bottom)');
	});
});
