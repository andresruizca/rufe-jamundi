// Qué se pinta en el mapa y qué no.
//
// Es la parte del mapa donde un error se paga caro: los puntos que se dibujan
// se usan para decidir a dónde va la ayuda, y un punto inventado desvía recursos
// de donde hacen falta.

import { describe, expect, it } from 'vitest';
import {
	calorDe,
	puntosDeFichas,
	colorDe,
	direccionesDe,
	puntosDe,
	ubicable,
	type FichaMapa,
	type Ubicacion
} from './datos';
import type { Hogar } from '$lib/rufe/types';

function hogar(cambios: Partial<Hogar> = {}): Hogar {
	return {
		hogar: '1',
		barrio: 'Terranova',
		zona: 'Urbana',
		direccion: 'Carrera 11 # 8-26',
		personas: 3,
		estadoBien: 'Averiado',
		tipoBien: 'Vivienda',
		tenencia: 'Propietario',
		visita: 'Sin dato',
		quienVisita: '',
		observacion: '',
		evacuada: 'Sin dato',
		...cambios
	};
}

function ubicacion(cambios: Partial<Ubicacion> = {}): Ubicacion {
	return { lat: 3.27, lon: -76.55, precision: 'EXACTA', fuente: 'NOMINATIM', ...cambios };
}

describe('qué se puede pintar', () => {
	it('acepta las tres precisiones útiles', () => {
		for (const p of ['EXACTA', 'CALLE', 'BARRIO'] as const) {
			expect(ubicable(ubicacion({ precision: p }))).toBe(true);
		}
	});

	// LA trampa de todo el mapa: una dirección que el geocodificador solo supo
	// resolver hasta «Jamundí» devuelve coordenadas perfectamente válidas y del
	// todo inútiles. Pintarlas amontonaría cientos de hogares sobre el parque
	// principal e inventaría una zona de calor donde no la hay.
	it('rechaza el punto que solo llegó al municipio', () => {
		expect(ubicable(ubicacion({ precision: 'MUNICIPIO' }))).toBe(false);
	});

	it('rechaza lo fallido y lo que no existe', () => {
		expect(ubicable(ubicacion({ precision: 'FALLIDA' }))).toBe(false);
		expect(ubicable(undefined)).toBe(false);
	});
});

describe('cruce de hogares con ubicaciones', () => {
	it('separa los ubicados de los que no', () => {
		const hogares = [
			hogar({ hogar: '1', direccion: 'Carrera 11 # 8-26' }),
			hogar({ hogar: '2', direccion: 'Sin dirección conocida' })
		];

		const { puntos, sinUbicar } = puntosDe(hogares, { 'Carrera 11 # 8-26': ubicacion() });

		expect(puntos.map((p) => p.hogar)).toEqual(['1']);
		expect(sinUbicar.map((h) => h.hogar)).toEqual(['2']);
	});

	// Un hogar con coordenadas del centroide debe contarse como NO ubicado, no
	// desaparecer en silencio: el contador de la pantalla depende de esto.
	it('un punto de precisión municipal cuenta como sin ubicar', () => {
		const { puntos, sinUbicar } = puntosDe(
			[hogar()],
			{ 'Carrera 11 # 8-26': ubicacion({ precision: 'MUNICIPIO' }) }
		);

		expect(puntos).toHaveLength(0);
		expect(sinUbicar).toHaveLength(1);
	});

	it('un hogar sin estado del bien no se queda sin color', () => {
		const { puntos } = puntosDe(
			[hogar({ estadoBien: '' })],
			{ 'Carrera 11 # 8-26': ubicacion() }
		);

		expect(puntos[0].estadoBien).toBe('No informa');
		expect(colorDe(puntos[0].estadoBien)).toBeTruthy();
	});

	it('los espacios sobrantes no impiden el cruce', () => {
		const { puntos } = puntosDe(
			[hogar({ direccion: '  Carrera 11 # 8-26  ' })],
			{ 'Carrera 11 # 8-26': ubicacion() }
		);

		expect(puntos).toHaveLength(1);
	});
});

describe('direcciones a consultar', () => {
	it('no repite la misma dirección', () => {
		const d = direccionesDe([hogar({ hogar: '1' }), hogar({ hogar: '2' })]);
		expect(d).toEqual(['Carrera 11 # 8-26']);
	});

	it('descarta las vacías', () => {
		expect(direccionesDe([hogar({ direccion: '' }), hogar({ direccion: '   ' })])).toEqual([]);
	});
});

describe('intensidad de la mancha de calor', () => {
	// Un hogar de nueve personas debe pesar más que uno de una: la mancha sirve
	// para decidir a dónde mandar ayuda, y la ayuda va a personas, no a casas.
	it('pesa según cuánta gente vive en el hogar', () => {
		const { puntos } = puntosDe(
			[
				hogar({ hogar: '1', personas: 9, direccion: 'A 1' }),
				hogar({ hogar: '2', personas: 1, direccion: 'B 2' })
			],
			{ 'A 1': ubicacion(), 'B 2': ubicacion({ lat: 3.28 }) }
		);

		const [grande, chico] = calorDe(puntos);
		expect(grande[2]).toBeGreaterThan(chico[2]);
		expect(grande[2]).toBe(1);
	});

	it('ningún punto pesa cero: un hogar pequeño también se ve', () => {
		const { puntos } = puntosDe(
			[
				hogar({ hogar: '1', personas: 40, direccion: 'A 1' }),
				hogar({ hogar: '2', personas: 1, direccion: 'B 2' })
			],
			{ 'A 1': ubicacion(), 'B 2': ubicacion({ lat: 3.28 }) }
		);

		expect(calorDe(puntos)[1][2]).toBeGreaterThan(0);
	});

	it('sin puntos no revienta', () => {
		expect(calorDe([])).toEqual([]);
	});
});

describe('fichas del sistema en el mapa', () => {
	function ficha(cambios: Partial<FichaMapa> = {}): FichaMapa {
		return {
			radicado: 'RUFE-2026-ABCD1234',
			zona: 'Urbana',
			barrio: 'Terranova',
			direccion: 'Carrera 11 # 8-26',
			personas: 4,
			estado: 'Recibido',
			estado_bien: 'Averiado',
			tipo_bien: 'Vivienda',
			latitud: null,
			longitud: null,
			precision_m: null,
			...cambios
		};
	}

	// El GPS del censador es el dato más preciso que puede haber: mejor que
	// cualquier dirección escrita, y además no gasta cupo del geocodificador.
	it('la ficha con GPS se dibuja sin geocodificar', () => {
		const { puntos, sinUbicar } = puntosDeFichas(
			[ficha({ latitud: 3.27, longitud: -76.55, precision_m: 8 })],
			{}
		);

		expect(sinUbicar).toHaveLength(0);
		expect(puntos[0]).toMatchObject({ lat: 3.27, lon: -76.55, precision: 'EXACTA', origen: 'sistema' });
	});

	it('sin GPS se ubica por su dirección', () => {
		const { puntos } = puntosDeFichas([ficha()], {
			'Carrera 11 # 8-26': { lat: 3.28, lon: -76.54, precision: 'CALLE', fuente: 'NOMINATIM' }
		});

		expect(puntos[0]).toMatchObject({ lat: 3.28, precision: 'CALLE', origen: 'sistema' });
	});

	it('sin GPS y sin dirección ubicable, cuenta como no ubicada', () => {
		const { puntos, sinUbicar } = puntosDeFichas([ficha()], {});

		expect(puntos).toHaveLength(0);
		expect(sinUbicar).toHaveLength(1);
	});

	it('el radicado identifica el punto, para poder volver a la ficha', () => {
		const { puntos } = puntosDeFichas([ficha({ latitud: 3.27, longitud: -76.55 })], {});
		expect(puntos[0].hogar).toBe('RUFE-2026-ABCD1234');
	});

	// Una misma casa puede estar en el censo en papel y en una ficha nueva.
	// Preguntar dos veces por ella gastaría el doble de cupo para nada.
	it('las direcciones de las dos fuentes se piden una sola vez', () => {
		const hogares = [hogar({ direccion: 'Carrera 11 # 8-26' })];
		const fichas = [ficha({ direccion: 'Carrera 11 # 8-26' }), ficha({ direccion: 'Calle 3 # 2-10' })];

		expect(direccionesDe(hogares, fichas).sort()).toEqual(['Calle 3 # 2-10', 'Carrera 11 # 8-26']);
	});

	it('una ficha con GPS no añade su dirección a la cola de geocodificación', () => {
		const fichas = [ficha({ direccion: 'Ya ubicada', latitud: 3.27, longitud: -76.55 })];
		expect(direccionesDe([], fichas)).toEqual([]);
	});
});
