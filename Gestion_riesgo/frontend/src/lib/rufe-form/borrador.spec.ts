// Las fichas RUFE a medias guardadas en el aparato.
//
// Cada una es una casa visitada. Perderla —o pisarla con la siguiente— es
// volver a tocar la puerta de un hogar damnificado y pedirle que repita todo.

import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('$app/environment', () => ({ browser: true }));

const caja = new Map<string, string>();

vi.stubGlobal('window', {
	localStorage: {
		getItem: (k: string) => caja.get(k) ?? null,
		setItem: (k: string, v: string) => void caja.set(k, v),
		removeItem: (k: string) => void caja.delete(k)
	},
	addEventListener() {},
	removeEventListener() {}
});

const {
	CLAVE_ALMACEN,
	CLAVE_ALMACEN_V1,
	GestorBorrador,
	leerBorradores,
	descartarBorrador,
	senasDe
} = await import('./borrador.svelte');

const AHORA = Date.UTC(2026, 7, 24, 15, 0, 0);
const SEMANA = 7 * 86400_000;

function ficha(datos: Record<string, unknown>) {
	return { version: 2, clave: 'x', actualizado_en: AHORA, expira_en: AHORA + SEMANA, paso: 'evento', datos } as never;
}

beforeEach(() => caja.clear());

describe('varias fichas a la vez', () => {
	it('levantar la siguiente casa no pisa la anterior', () => {
		// El caso que originó el cambio: una brigada levanta varias casas seguidas
		// y deja alguna a medias porque falta un documento.
		const a = new GestorBorrador('casa-a');
		const b = new GestorBorrador('casa-b');

		a.guardarYa({ personas: [{ nombres: 'Rosa', apellidos: 'Mina' }] } as never, 'personas');
		b.guardarYa({ personas: [{ nombres: 'Jairo', apellidos: 'Caicedo' }] } as never, 'personas');

		const lista = leerBorradores();

		expect(lista).toHaveLength(2);
		expect(lista.map((x) => x.clave).sort()).toEqual(['casa-a', 'casa-b']);
	});

	it('descartar la propia deja intactas las demás', () => {
		new GestorBorrador('casa-a').guardarYa({ personas: [] } as never, 'evento');

		const b = new GestorBorrador('casa-b');
		b.guardarYa({ personas: [] } as never, 'evento');
		b.descartar();

		expect(leerBorradores().map((x) => x.clave)).toEqual(['casa-a']);
	});

	it('tras descartar, el gestor estrena clave', () => {
		// Si conservara la anterior, la ficha siguiente heredaría las fotos de la
		// casa que se acaba de soltar.
		const g = new GestorBorrador('casa-a');
		g.guardarYa({ personas: [] } as never, 'evento');
		g.descartar();

		expect(g.clave).not.toBe('casa-a');
	});
});

describe('el consentimiento no se hereda', () => {
	it('la casilla de autorización nunca se guarda marcada', () => {
		// El consentimiento debe darse en la sesión del envío. Heredarlo de un
		// borrador de hace tres días sería tratar datos sensibles sin base legal.
		const g = new GestorBorrador('casa-a');
		g.guardarYa({ personas: [], autoriza_tratamiento: true } as never, 'revision');

		expect(leerBorradores()[0].datos.autoriza_tratamiento).toBe(false);
	});
});

describe('lo que había guardado antes del cambio', () => {
	it('la ficha única se adopta en vez de perderse', () => {
		caja.set(
			CLAVE_ALMACEN_V1,
			JSON.stringify({
				version: 1,
				clave: 'la-de-antes',
				actualizado_en: AHORA,
				expira_en: AHORA + SEMANA,
				paso: 'hogar',
				datos: { personas: [{ nombres: 'Herman', apellidos: 'Sandoval' }] }
			})
		);

		const lista = leerBorradores(AHORA + 1);

		expect(lista).toHaveLength(1);
		expect(lista[0].clave).toBe('la-de-antes');
		expect(caja.has(CLAVE_ALMACEN_V1)).toBe(false);
		expect(caja.has(CLAVE_ALMACEN)).toBe(true);
	});
});

describe('cómo se reconoce cada una', () => {
	it('por el jefe de hogar y la dirección', () => {
		const s = senasDe(
			ficha({
				personas: [{ nombres: 'Rosa Elena', apellidos: 'Mina Carabalí' }],
				direccion: 'Calle 7 # 14-52'
			})
		);

		expect(s.titulo).toBe('Rosa Elena Mina Carabalí');
		expect(s.lugar).toBe('Calle 7 # 14-52');
		expect(s.anonima).toBe(false);
	});

	it('en zona rural, corregimiento y vereda hacen de dirección', () => {
		const s = senasDe(
			ficha({ personas: [], corregimiento: 'Potrerito', vereda_sector_barrio: 'El Guabal' })
		);

		expect(s.lugar).toBe('Potrerito · El Guabal');
	});

	it('con solo el nombre de pila ya sirve para distinguirla', () => {
		// El apellido se escribe después; la ficha tiene que poder nombrarse antes.
		expect(senasDe(ficha({ personas: [{ nombres: 'Rosa', apellidos: '' }] })).titulo).toBe('Rosa');
	});

	it('sin datos del hogar lo dice, y no inventa un nombre', () => {
		const s = senasDe(ficha({ personas: [] }));

		expect(s.anonima).toBe(true);
		expect(s.titulo).toBe('Sin datos del hogar todavía');
	});

	it('sobrevive a una ficha recién empezada, sin lista de personas', () => {
		expect(() => senasDe(ficha({}))).not.toThrow();
	});
});

describe('caducidad', () => {
	it('a los siete días deja de ofrecerse', () => {
		const g = new GestorBorrador('casa-a');
		g.guardarYa({ personas: [] } as never, 'evento');

		expect(leerBorradores(Date.now() + SEMANA + 1000)).toHaveLength(0);
	});

	it('descartar una que ya no existe no rompe nada', () => {
		expect(() => descartarBorrador('la-que-no-esta')).not.toThrow();
	});
});
