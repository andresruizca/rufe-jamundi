import { describe, expect, it } from 'vitest';
import { erroresSolicitud, esValida, solicitudVacia, type DatosSolicitudSinCenso } from './solicitud';

function base(cambios: Partial<DatosSolicitudSinCenso> = {}): DatosSolicitudSinCenso {
	return {
		...solicitudVacia(),
		nombres: 'Ana Lucía',
		apellidos: 'Torres',
		telefono: '315 123 4567',
		zona: 'URBANO',
		direccion: 'Cerca al parque principal',
		...cambios
	};
}

describe('erroresSolicitud', () => {
	it('una solicitud mínima y completa no tiene errores', () => {
		expect(erroresSolicitud(base())).toEqual({});
		expect(esValida(base())).toBe(true);
	});

	// Separados y no un solo «nombre completo»: son los mismos dos campos de
	// `Persona`, para precargar el jefe de hogar sin adivinar dónde corta.
	it('exige nombres y apellidos por separado', () => {
		expect(erroresSolicitud(base({ nombres: '' }))).toHaveProperty('nombres');
		expect(erroresSolicitud(base({ apellidos: '' }))).toHaveProperty('apellidos');
		expect(erroresSolicitud(base({ nombres: 'A' }))).toHaveProperty('nombres');
	});

	it('no acepta dígitos ni símbolos raros en el nombre', () => {
		expect(erroresSolicitud(base({ nombres: 'Ana123' }))).toHaveProperty('nombres');
		expect(erroresSolicitud(base({ apellidos: "O'Higgins" }))).toEqual({});
	});

	it('exige un teléfono con forma plausible', () => {
		expect(erroresSolicitud(base({ telefono: '123' }))).toHaveProperty('telefono');
		expect(erroresSolicitud(base({ telefono: '3151234567' }))).not.toHaveProperty('telefono');
	});

	it('exige elegir zona urbana o rural', () => {
		expect(erroresSolicitud(base({ zona: '' }))).toHaveProperty('zona');
	});

	// Mismo criterio que el validador de PHP: no se pide un campo concreto,
	// sino que quede AL MENOS una pista de dónde vive.
	it('hace falta al menos una pista de dónde vive', () => {
		const sinNinguna = base({ direccion: '' });
		expect(erroresSolicitud(sinNinguna)).toHaveProperty('direccion');

		expect(erroresSolicitud(base({ direccion: '', vereda_sector_barrio: 'Vereda La Liberia' }))).toEqual(
			{}
		);
		expect(erroresSolicitud(base({ direccion: '', corregimiento: 'Potrerito' }))).toEqual({});
	});

	it('la descripción tiene un tope de caracteres', () => {
		expect(erroresSolicitud(base({ descripcion: 'a'.repeat(501) }))).toHaveProperty('descripcion');
		expect(erroresSolicitud(base({ descripcion: 'a'.repeat(500) }))).toEqual({});
	});
});
