// Qué se guarda del API en el aparato, y sobre todo qué NO.
//
// Desde que la Alcaldía pidió que el sistema entero funcione sin señal, el censo
// que alguien consulta vive en su teléfono. Estas pruebas son el límite de esa
// decisión: lo que aquí se rechace no puede acabar en un aparato prestado.

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { API_CACHEABLE, seGuardaDeLaApi } from './cacheables';

describe('lo que SÍ se guarda', () => {
	it('los catálogos de los dos formatos, sin los que no hay formulario', () => {
		expect(seGuardaDeLaApi('/api/rufe/catalogos')).toBe(true);
		expect(seGuardaDeLaApi('/api/inspeccion/catalogos')).toBe(true);
	});

	it('las bandejas y las fichas que se consultan', () => {
		expect(seGuardaDeLaApi('/api/rufe/reportes')).toBe(true);
		expect(seGuardaDeLaApi('/api/rufe/reportes/128')).toBe(true);
		expect(seGuardaDeLaApi('/api/inspeccion/fichas/7')).toBe(true);
		expect(seGuardaDeLaApi('/api/preinscripcion/fichas/3')).toBe(true);
		expect(seGuardaDeLaApi('/api/mapa/fichas')).toBe(true);
		expect(seGuardaDeLaApi('/api/callcenter/hogares')).toBe(true);
	});

	it('el guión del call center, que sin señal se lee igual de bien', () => {
		// Un corte de internet no puede dejar a tres personas improvisando lo
		// que le dicen por teléfono a familias damnificadas.
		expect(seGuardaDeLaApi('/api/callcenter/guion')).toBe(true);
	});

	it('quién soy: sin eso, abrir sin señal no sabe ni qué menú dibujar', () => {
		expect(seGuardaDeLaApi('/api/auth/me')).toBe(true);
	});
});

describe('lo que NUNCA se guarda', () => {
	it('quién está llamando ahora mismo: guardado, miente', () => {
		// Es el único dato de la pantalla cuyo valor entero está en ser de este
		// segundo. Servirlo de una copia haría creer que un hogar está ocupado
		// cuando no lo está —o al revés, que es peor: dos llamadas a la misma
		// familia.
		expect(seGuardaDeLaApi('/api/callcenter/atenciones')).toBe(false);
	});


	it('las evidencias: la foto de una cédula no se queda en un teléfono', () => {
		// Es el dato más sensible del sistema y pesa megabytes. Que la ficha se
		// guarde no autoriza a guardar sus archivos.
		expect(seGuardaDeLaApi('/api/rufe/reportes/128/evidencias/9')).toBe(false);
		expect(seGuardaDeLaApi('/api/preinscripcion/fichas/3/fotos/1')).toBe(false);
		expect(seGuardaDeLaApi('/api/preinscripcion/fichas/3/videos/2')).toBe(false);
		expect(seGuardaDeLaApi('/api/inspeccion/fichas/7/fotos/4')).toBe(false);
	});

	it('el login y el cambio de contraseña', () => {
		expect(seGuardaDeLaApi('/api/auth/login')).toBe(false);
		expect(seGuardaDeLaApi('/api/auth/password')).toBe(false);
		expect(seGuardaDeLaApi('/api/auth/logout')).toBe(false);
	});

	it('la administración de usuarios', () => {
		expect(seGuardaDeLaApi('/api/usuarios')).toBe(false);
		expect(seGuardaDeLaApi('/api/usuarios/4')).toBe(false);
	});

	it('lo que no está en la lista, aunque se le parezca', () => {
		expect(seGuardaDeLaApi('/api/rufe/reportes/128/estado')).toBe(false);
		expect(seGuardaDeLaApi('/api/sistema/actualizaciones')).toBe(false);
	});
});

describe('cómo se compara', () => {
	it('el comodín cubre UN tramo, no una rama entera', () => {
		// Con un prefijo suelto, añadir mañana una ruta bajo `/fichas/` la metería
		// en la caché sin que nadie hubiera decidido nada.
		expect(seGuardaDeLaApi('/api/inspeccion/fichas/7')).toBe(true);
		expect(seGuardaDeLaApi('/api/inspeccion/fichas/7/algo')).toBe(false);
	});

	it('y no casa con un tramo vacío', () => {
		expect(seGuardaDeLaApi('/api/inspeccion/fichas/')).toBe(false);
	});

	it('la ruta se compara entera, no por su comienzo', () => {
		expect(seGuardaDeLaApi('/api/rufe/catalogos/personas')).toBe(false);
		expect(seGuardaDeLaApi('/otro/api/rufe/catalogos')).toBe(false);
	});
});

describe('la lista', () => {
	it('no lleva nada de auth salvo saber quién soy', () => {
		// Un despiste aquí es una respuesta de login guardada en un aparato.
		const deAuth = API_CACHEABLE.filter((r) => r.startsWith('/api/auth/'));

		expect(deAuth).toEqual(['/api/auth/me']);
	});

	it('no lleva ninguna ruta de archivos', () => {
		expect(API_CACHEABLE.filter((r) => /fotos|videos|evidencias|archivos/.test(r))).toEqual([]);
	});
});

describe('las rutas que el sistema usa de verdad están cubiertas', () => {
	// Esta es la prueba que faltaba. La lista de lo que se guarda no se entera
	// de una ruta nueva: el día que el tablero pasó de leer una hoja de Google a
	// leer la base, `/api/rufe/tablero` se quedó fuera y el tablero y los mapas
	// dejaron de abrir sin señal, sin que nada lo avisara.
	it('guarda el tablero, que alimenta el panel y los mapas', () => {
		expect(seGuardaDeLaApi('/api/rufe/tablero')).toBe(true);
	});

	it('guarda el catálogo del formulario ciudadano', () => {
		// Es el único formulario que abre un ciudadano desde su casa. Sin este
		// catálogo, la aplicación instalada abre en blanco cuando no hay señal.
		expect(seGuardaDeLaApi('/api/preinscripcion/catalogos')).toBe(true);
	});

	it('guarda las cifras de avance de la bandeja ciudadana', () => {
		expect(seGuardaDeLaApi('/api/preinscripcion/resumen')).toBe(true);
	});

	it('sigue sin guardar las evidencias ni la sesión', () => {
		// Lo que no puede acabar en un aparato prestado, pase lo que pase.
		expect(seGuardaDeLaApi('/api/preinscripcion/fichas/3/fotos/7')).toBe(false);
		expect(seGuardaDeLaApi('/api/rufe/reportes/3/evidencias/9')).toBe(false);
		expect(seGuardaDeLaApi('/api/auth/login')).toBe(false);
	});
});


describe('lo guardado sobrevive a un despliegue', () => {
	const sw = readFileSync(new URL('../../service-worker.ts', import.meta.url), 'utf-8');

	it('la caché de datos NO lleva la versión del build', () => {
		// El fallo que esto cierra, y explica el «la aplicación no guarda nada»:
		// el nombre era `sgr-datos-${version}`, y `activate` borra toda caché
		// `sgr-*` que no sea la actual. Cada publicación estrenaba una caché
		// vacía y se llevaba la anterior por delante: los catálogos del
		// formulario, la bandeja, el censo que ese censador abrió antes de subir
		// a la vereda. En silencio, y tres veces en un día de tres despliegues.
		expect(sw).toContain("const CACHE_DATOS = 'sgr-datos-v1'");
		expect(sw).not.toContain('sgr-datos-${version}');
	});

	it('y la purga de armazones viejos no se la lleva', () => {
		const activate = sw.slice(sw.indexOf("sw.addEventListener('activate'"));

		expect(activate.slice(0, activate.indexOf("sw.addEventListener('fetch'"))).toContain(
			"!n.startsWith('sgr-datos-')"
		);
	});
});

describe('cuánto dura lo guardado', () => {
	const sw = readFileSync(new URL('../../service-worker.ts', import.meta.url), 'utf-8');

	it('un catálogo dura mucho más que un dato de familia', () => {
		// Un catálogo no es dato de nadie: es la lista de corregimientos, de
		// parentescos, de tipos de daño. Y es lo ÚNICO que hace que el formulario
		// se pueda dibujar. Con las mismas 24 h que un hogar damnificado, el
		// censador que sube el miércoles con el teléfono cargado el lunes
		// encuentra el formato muerto justo donde no hay señal.
		expect(sw).toContain('VIGENCIA_CATALOGOS_MS');
		expect(sw).toContain('function vigenciaDe(');
		expect(sw).toContain('vigenciaDe(clave)');
	});

	it('pero el dato de una familia sigue caducando a las 24 h', () => {
		// Lo otro no se toca: decidir sobre una familia con un dato de la semana
		// pasada es peor que no tener dato.
		expect(sw).toContain('const VIGENCIA_MS = 24 * 60 * 60 * 1000;');
	});
});
