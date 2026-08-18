// La cola de envío es lo que salva el reporte cuando el teléfono se queda sin
// señal, así que su persistencia se prueba aparte del resto.

import { beforeEach, describe, expect, it, vi } from 'vitest';

// El módulo usa localStorage a través de `browser`, que en el entorno de
// pruebas es false: se fuerza a true y se monta un localStorage mínimo.
vi.mock('$app/environment', () => ({ browser: true }));

class AlmacenFalso implements Storage {
	#datos = new Map<string, string>();
	get length() { return this.#datos.size; }
	clear() { this.#datos.clear(); }
	getItem(k: string) { return this.#datos.get(k) ?? null; }
	key(i: number) { return [...this.#datos.keys()][i] ?? null; }
	removeItem(k: string) { this.#datos.delete(k); }
	setItem(k: string, v: string) { this.#datos.set(k, v); }
}

// `location` hace falta porque el módulo arrastra al cliente de la API, que
// resuelve su URL base al cargarse.
vi.stubGlobal('window', {
	localStorage: new AlmacenFalso(),
	location: { hostname: 'localhost' },
	addEventListener: () => {},
	removeEventListener: () => {}
});

const { CLAVE_ENVIO, borrarEnvioPendiente, leerEnvioPendiente } = await import('./envio.svelte');

function guardar(valor: unknown) {
	window.localStorage.setItem(CLAVE_ENVIO, JSON.stringify(valor));
}

describe('cola de envío', () => {
	beforeEach(() => window.localStorage.clear());

	it('sin nada guardado no devuelve nada', () => {
		expect(leerEnvioPendiente()).toBeNull();
	});

	it('recupera un envío guardado', () => {
		guardar({ envioId: 'abc', cuerpo: { evento: 'Terremoto' }, creadoEn: Date.now(), intentos: 2 });

		const p = leerEnvioPendiente();
		expect(p?.envioId).toBe('abc');
		expect(p?.intentos).toBe(2);
	});

	it('descarta un envío de hace más de una semana', () => {
		guardar({
			envioId: 'viejo',
			cuerpo: {},
			creadoEn: Date.now() - 8 * 86400000,
			intentos: 1
		});

		expect(leerEnvioPendiente()).toBeNull();
		// Además lo borra, para no arrastrarlo en cada arranque.
		expect(window.localStorage.getItem(CLAVE_ENVIO)).toBeNull();
	});

	it('conserva uno de hace seis días', () => {
		guardar({ envioId: 'reciente', cuerpo: {}, creadoEn: Date.now() - 6 * 86400000, intentos: 1 });
		expect(leerEnvioPendiente()?.envioId).toBe('reciente');
	});

	it('un contenido corrupto no revienta el arranque del formulario', () => {
		window.localStorage.setItem(CLAVE_ENVIO, 'esto no es json');
		expect(leerEnvioPendiente()).toBeNull();
	});

	it('sin envioId no se considera un envío válido', () => {
		guardar({ cuerpo: { a: 1 }, creadoEn: Date.now(), intentos: 0 });
		expect(leerEnvioPendiente()).toBeNull();
	});

	it('descartar lo borra', () => {
		guardar({ envioId: 'x', cuerpo: {}, creadoEn: Date.now(), intentos: 0 });
		borrarEnvioPendiente();
		expect(leerEnvioPendiente()).toBeNull();
	});
});
