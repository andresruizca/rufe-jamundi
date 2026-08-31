// Las inspecciones a medias guardadas en el aparato.
//
// Lo que está en juego: cada borrador es una visita a una vereda. Perderlo —o
// pisarlo con el siguiente— significa volver a hacer el desplazamiento.

import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('$app/environment', () => ({ browser: true }));

const caja = new Map<string, string>();

vi.stubGlobal('window', {
	localStorage: {
		getItem: (k: string) => caja.get(k) ?? null,
		setItem: (k: string, v: string) => void caja.set(k, v),
		removeItem: (k: string) => void caja.delete(k)
	}
});

const {
	CLAVE_ALMACEN,
	CLAVE_ALMACEN_V1,
	leerBorradores,
	leerBorrador,
	guardarBorrador,
	descartarBorrador,
	senasDe,
	haceCuanto,
	diasQueLeQuedan
} = await import('./borrador.svelte');

const AHORA = Date.UTC(2026, 7, 24, 15, 0, 0);
const SEMANA = 7 * 86400_000;

function borrador(clave: string, datos: Record<string, unknown> = {}, cuando = AHORA) {
	return {
		version: 2,
		clave,
		actualizado_en: cuando,
		expira_en: cuando + SEMANA,
		paso: 'profesional',
		datos
	} as never;
}

beforeEach(() => caja.clear());

describe('varias inspecciones a la vez', () => {
	it('empezar una segunda no pisa la primera', () => {
		// El caso que originó el cambio: una brigada deja una casa a medias
		// porque falta hablar con el propietario y sigue con la de al lado. Antes
		// solo cabía un borrador y la segunda borraba a la primera.
		guardarBorrador(borrador('a', { propietario_nombres: 'Rosa Elena Mina' }), AHORA);
		guardarBorrador(borrador('b', { propietario_nombres: 'Jairo Caicedo' }), AHORA + 1000);

		const lista = leerBorradores(AHORA + 2000);

		expect(lista).toHaveLength(2);
		expect(lista.map((b) => b.clave)).toEqual(['b', 'a']);
	});

	it('la más reciente va primero: es la que se retoma', () => {
		guardarBorrador(borrador('vieja', {}, AHORA - 3600_000), AHORA);
		guardarBorrador(borrador('nueva', {}, AHORA), AHORA);

		expect(leerBorradores(AHORA + 1)[0].clave).toBe('nueva');
	});

	it('guardar dos veces la misma la reemplaza, no la duplica', () => {
		guardarBorrador(borrador('a', { propietario_nombres: 'Rosa' }), AHORA);
		guardarBorrador(borrador('a', { propietario_nombres: 'Rosa Elena Mina' }), AHORA + 1000);

		const lista = leerBorradores(AHORA + 2000);

		expect(lista).toHaveLength(1);
		expect(lista[0].datos.propietario_nombres).toBe('Rosa Elena Mina');
	});

	it('descartar una deja intactas las demás', () => {
		guardarBorrador(borrador('a'), AHORA);
		guardarBorrador(borrador('b'), AHORA);
		guardarBorrador(borrador('c'), AHORA);

		// Con la fecha fija, como todo lo demás de este archivo. Sin ella,
		// `descartar` miraba el reloj real y esta prueba empezaba a fallar sola
		// el día que estos borradores caducaban de verdad.
		descartarBorrador('b', AHORA + 1);

		expect(leerBorradores(AHORA + 1).map((x) => x.clave).sort()).toEqual(['a', 'c']);
	});
});

describe('lo que había guardado antes del cambio', () => {
	it('el borrador único se adopta en vez de perderse', () => {
		// En el momento del despliegue puede haber un censador con una inspección
		// a medias en el teléfono. Descartarla sería la visita repetida que este
		// módulo entero existe para evitar.
		caja.set(
			CLAVE_ALMACEN_V1,
			JSON.stringify({
				version: 1,
				clave: 'la-de-antes',
				actualizado_en: AHORA,
				expira_en: AHORA + SEMANA,
				paso: 'propietario',
				datos: { propietario_nombres: 'Herman Sandoval' }
			})
		);

		const lista = leerBorradores(AHORA + 1);

		expect(lista).toHaveLength(1);
		expect(lista[0].clave).toBe('la-de-antes');
		expect(lista[0].version).toBe(2);
	});

	it('la caja vieja se retira, para no adoptarla dos veces', () => {
		caja.set(
			CLAVE_ALMACEN_V1,
			JSON.stringify({ version: 1, clave: 'x', actualizado_en: AHORA, expira_en: AHORA + SEMANA, paso: 'profesional', datos: {} })
		);

		leerBorradores(AHORA + 1);

		expect(caja.has(CLAVE_ALMACEN_V1)).toBe(false);
		expect(caja.has(CLAVE_ALMACEN)).toBe(true);
	});

	it('una vieja ya caducada no revive', () => {
		caja.set(
			CLAVE_ALMACEN_V1,
			JSON.stringify({ version: 1, clave: 'x', actualizado_en: AHORA - SEMANA * 2, expira_en: AHORA - 1, paso: 'profesional', datos: {} })
		);

		expect(leerBorradores(AHORA)).toEqual([]);
	});
});

describe('caducidad', () => {
	it('a los siete días deja de ofrecerse: los daños de una vivienda cambian', () => {
		guardarBorrador(borrador('a'), AHORA);

		expect(leerBorradores(AHORA + SEMANA - 1)).toHaveLength(1);
		expect(leerBorradores(AHORA + SEMANA + 1)).toHaveLength(0);
	});

	it('leer purga lo caducado y conserva lo vigente', () => {
		// La limpieza va en la lectura porque es lo único que ocurre siempre; en
		// una pantalla concreta dependería de que alguien pasara por ella.
		guardarBorrador(borrador('vieja', {}, AHORA - SEMANA - 1000), AHORA - SEMANA - 1000);
		guardarBorrador(borrador('viva', {}, AHORA), AHORA);

		expect(leerBorradores(AHORA + 1).map((b) => b.clave)).toEqual(['viva']);
		expect(leerBorrador('vieja', AHORA + 1)).toBeNull();
	});

	it('avisa los días que le quedan', () => {
		const b = borrador('a');

		expect(diasQueLeQuedan(b, AHORA)).toBe(7);
		expect(diasQueLeQuedan(b, AHORA + SEMANA - 3600_000)).toBe(1);
		expect(diasQueLeQuedan(b, AHORA + SEMANA + 1)).toBe(0);
	});
});

describe('cómo se reconoce cada una', () => {
	it('por el propietario y la dirección', () => {
		const s = senasDe(
			borrador('a', {
				propietario_nombres: 'Rosa Elena Mina',
				direccion_cabecera: 'Calle 7 # 14-52'
			})
		);

		expect(s.titulo).toBe('Rosa Elena Mina');
		expect(s.lugar).toBe('Calle 7 # 14-52');
		expect(s.anonima).toBe(false);
	});

	it('en zona rural, el corregimiento y la vereda hacen de dirección', () => {
		const s = senasDe(borrador('a', { corregimiento: 'Potrerito', vereda: 'El Guabal' }));

		expect(s.lugar).toBe('Potrerito · El Guabal');
	});

	it('sin nombre todavía lo dice, y no inventa uno', () => {
		// Quien decide si la descarta necesita saber que no puede identificarla.
		// Una etiqueta inventada se leería como si fuera un dato.
		const s = senasDe(borrador('a', { propietario_nombres: '   ' }));

		expect(s.anonima).toBe(true);
		expect(s.titulo).toBe('Sin datos del propietario todavía');
	});

	it('sobrevive a un borrador sin datos', () => {
		// Puede quedar uno guardado en el primer paso, antes de tocar el numeral 3.
		expect(() => senasDe(borrador('a', {}))).not.toThrow();
	});
});

describe('hace cuánto', () => {
	it('responde a la pregunta que trae quien mira la lista', () => {
		// «11:40 a. m.» no dice si fue hoy, y lo que se está decidiendo es cuál
		// retomar: una pregunta sobre hace cuánto, no sobre qué hora era.
		expect(haceCuanto(AHORA, AHORA)).toBe('hace un momento');
		expect(haceCuanto(AHORA - 5 * 60_000, AHORA)).toBe('hace 5 min');
		expect(haceCuanto(AHORA - 3 * 3600_000, AHORA)).toBe('hace 3 horas');
		expect(haceCuanto(AHORA - 26 * 3600_000, AHORA)).toBe('ayer');
		expect(haceCuanto(AHORA - 3 * 86400_000, AHORA)).toBe('hace 3 días');
	});
});
