// Qué se guarda del API en el aparato, y sobre todo qué NO.
//
// Desde que la Alcaldía pidió que el sistema entero funcione sin señal, el censo
// que alguien consulta vive en su teléfono. Estas pruebas son el límite de esa
// decisión: lo que aquí se rechace no puede acabar en un aparato prestado.

import { describe, it, expect } from 'vitest';
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

	it('quién soy: sin eso, abrir sin señal no sabe ni qué menú dibujar', () => {
		expect(seGuardaDeLaApi('/api/auth/me')).toBe(true);
	});
});

describe('lo que NUNCA se guarda', () => {
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
