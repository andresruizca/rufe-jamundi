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

	it('la versión nueva NO se activa sola sobre una pestaña abierta', () => {
		// Es el fallo que esto cierra. Al activarse se borran las cachés de la
		// versión anterior, y la pestaña que seguía ejecutándola pedía archivos
		// con el nombre de antes —cada pantalla va en un archivo con el
		// contenido en el nombre—: ya no estaban ni en la caché ni en el
		// servidor, porque el despliegue los reemplazó. Pantalla en blanco al
		// pulsar cualquier enlace, y justo a quien deja la pantalla puesta toda
		// la jornada, que es la operadora del call center.
		expect(sw).toContain('if (sw.registration.active === null) {');
		expect(sw).toContain("evento.data?.tipo === 'aplicar-actualizacion'");
	});

	it('salvo en la primera instalación, que no rompe nada', () => {
		// Ahí no hay pestaña anterior que respetar, y hacerla esperar dejaría la
		// primera visita sin aplicación guardada: quien la instala en la
		// alcaldía y se va a una vereda se quedaría sin nada.
		const install = sw.slice(sw.indexOf("sw.addEventListener('install'"));

		expect(install.slice(0, install.indexOf("sw.addEventListener('activate'"))).toContain(
			'sw.registration.active === null'
		);
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
