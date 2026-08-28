import { describe, expect, it } from 'vitest';
import { estaEnLaLista, filtrarBarrios, llano } from './barrios';

// Una muestra de los 165 del POT, con los casos que de verdad se dan.
const BARRIOS = [
	'Belalcazar',
	'Belalcazar II',
	'Ciudadela Terranova',
	'Nuevo Belén',
	'Alferez Real I',
	'Bocas del Palo'
];

describe('llano', () => {
	it('quita tildes, mayúsculas y espacios de más', () => {
		expect(llano('  BELALCÁZAR  ')).toBe('belalcazar');
		expect(llano('Nuevo   Belén')).toBe('nuevo belen');
	});
});

describe('filtrarBarrios', () => {
	it('encuentra aunque la persona no ponga la tilde', () => {
		// Desde un celular casi nadie la pone. Si «belen» no encontrara «Belén»,
		// la lista estorbaría en vez de ayudar.
		expect(filtrarBarrios(BARRIOS, 'belen')).toEqual(['Nuevo Belén']);
	});

	it('pone primero los que empiezan por lo escrito', () => {
		// Quien teclea «bel» busca Belalcázar, no Nuevo Belén.
		expect(filtrarBarrios(BARRIOS, 'bel')).toEqual([
			'Belalcazar',
			'Belalcazar II',
			'Nuevo Belén'
		]);
	});

	it('con el campo vacío devuelve la lista entera', () => {
		// Es lo que se quiere al abrirla de un toque, sin escribir nada.
		expect(filtrarBarrios(BARRIOS, '')).toEqual(BARRIOS);
		expect(filtrarBarrios(BARRIOS, '   ')).toEqual(BARRIOS);
	});

	it('busca también en medio del nombre', () => {
		// El nombre oficial es «Ciudadela Terranova», pero el censo lleva meses
		// escribiendo «Terranova» a secas: quien teclee eso tiene que encontrarlo.
		expect(filtrarBarrios(BARRIOS, 'terranova')).toEqual(['Ciudadela Terranova']);
	});

	it('sin coincidencias devuelve nada, no la lista entera', () => {
		// Devolver todo sería peor: parecería que sí encontró algo.
		expect(filtrarBarrios(BARRIOS, 'zzz')).toEqual([]);
	});
});

describe('estaEnLaLista', () => {
	it('reconoce el barrio escrito de cualquier manera', () => {
		expect(estaEnLaLista(BARRIOS, 'ciudadela terranova')).toBe(true);
		expect(estaEnLaLista(BARRIOS, '  CIUDADELA  TERRANOVA ')).toBe(true);
	});

	it('un barrio que no está no es un error, pero se sabe', () => {
		// Se usa para avisar, no para bloquear: la lista es de 2021 y en un
		// municipio que crece por invasión siempre va a faltar alguno.
		expect(estaEnLaLista(BARRIOS, 'Invasión Nueva Esperanza')).toBe(false);
	});

	it('el campo vacío no cuenta como encontrado', () => {
		expect(estaEnLaLista(BARRIOS, '')).toBe(false);
	});

	it('un fragmento no basta', () => {
		// «Terranova» no es «Ciudadela Terranova»: si contara como encontrado, se
		// marcaría como oficial un nombre que no lo es y volveríamos a las 249
		// grafías.
		expect(estaEnLaLista(BARRIOS, 'Terranova')).toBe(false);
	});
});
