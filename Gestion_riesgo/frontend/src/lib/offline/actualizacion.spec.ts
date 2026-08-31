import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { esActualizacion } from './actualizacion';

describe('esActualizacion', () => {
	it('lo instalado con alguien mandando es una versión nueva', () => {
		expect(esActualizacion('installed', true)).toBe(true);
	});

	it('la primera visita NO es una actualización', () => {
		// Sin controlador, este Service Worker es el primero que existe. Sin
		// esta distinción, a quien abre el sistema por primera vez le saldría
		// «hay una versión nueva» antes de haber usado ninguna, y el aviso
		// dejaría de significar algo desde el primer día.
		expect(esActualizacion('installed', false)).toBe(false);
	});

	it('no avisa hasta que esté guardada del todo', () => {
		// Avisar durante `installing` ofrecería actualizar a una versión que
		// todavía se está descargando: aceptar dejaría a medias a alguien que
		// quizá está en una vereda con una raya de señal.
		expect(esActualizacion('installing', true)).toBe(false);
		expect(esActualizacion('activated', true)).toBe(false);
		expect(esActualizacion('redundant', true)).toBe(false);
		expect(esActualizacion(undefined, true)).toBe(false);
	});
});


describe('cómo llega una versión nueva al aparato', () => {
	const sw = readFileSync(new URL('../../service-worker.ts', import.meta.url), 'utf-8');

	it('la versión nueva se estrena de inmediato', () => {
		// Estuvo esperando a que la persona pulsara «Actualizar», y fue un error
		// que costó caro: la versión anterior seguía siendo la ACTIVA, así que
		// cada pestaña nueva —aunque hubiera señal— recibía de la caché el
		// armazón viejo. El sistema retrocedía en el tiempo para quien no
		// pulsara, y lo que se desplegaba no lo veía nadie.
		expect(sw).toContain('await sw.skipWaiting();');
		expect(sw).not.toContain('if (sw.registration.active === null) {');
	});

	it('y no rompe la pestaña que sigue en la versión vieja', () => {
		// Ese era el motivo de la espera, y es real: al activarse se borraban
		// las cachés anteriores, y la pestaña que seguía en la versión vieja
		// pedía un archivo con el nombre de antes —cada pantalla va en uno, con
		// el contenido en el nombre— que ya no estaba. Pantalla en blanco.
		//
		// Se ataca donde nace: se conserva el armazón anterior una generación.
		// Sin esto habría que elegir entre romper una pestaña abierta o dejar a
		// todo el mundo en una versión vieja, y las dos ya se probaron.
		const activate = sw.slice(
			sw.indexOf("sw.addEventListener('activate'"),
			sw.indexOf("sw.addEventListener('fetch'")
		);

		expect(activate).toContain('const conservar = new Set([CACHE');
		expect(activate).toContain('.slice(-1)');
	});

	it('y sigue sin tocar lo que el censador se llevó a la vereda', () => {
		const activate = sw.slice(
			sw.indexOf("sw.addEventListener('activate'"),
			sw.indexOf("sw.addEventListener('fetch'")
		);

		expect(activate).toContain("!n.startsWith('sgr-datos-')");
	});

	it('navegar no espera a la red para siempre', () => {
		// `fetch` solo se rechaza cuando la conexión FALLA. Con una raya de
		// señal no falla: se queda colgada, a veces minutos. Y como la copia
		// guardada solo llegaba en el `catch`, la persona veía una pantalla en
		// blanco con la aplicación entera guardada en su propio teléfono.
		expect(sw).toContain('AbortController');
		expect(sw).toContain('ESPERA_RED_MS');
	});

	it('sin señal y sin copia, se dice con palabras', () => {
		// Antes se lanzaba un error y el navegador enseñaba SU pantalla, que no
		// dice nada de este sistema ni de que las fichas guardadas siguen a
		// salvo, que es lo único que hace falta saber en ese momento.
		expect(sw).toContain('function sinConexion()');
		expect(sw.split('return sinConexion();').length - 1).toBe(2);

		// Y NO en las llamadas al API: ahí la aplicación espera JSON, y
		// devolverle una página de disculpa la rompería de una forma mucho más
		// difícil de entender que un fallo de red.
		const consulta = sw.slice(
			sw.indexOf('async function responderConsulta('),
			sw.indexOf('async function vaciarDatos(')
		);

		expect(consulta).toContain("throw new Error('Sin conexión y sin copia guardada.')");
		expect(consulta).not.toContain('sinConexion()');
	});
});
