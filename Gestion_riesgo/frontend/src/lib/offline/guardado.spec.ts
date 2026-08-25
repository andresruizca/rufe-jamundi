// De cuándo son los datos que se están viendo.

import { describe, it, expect } from 'vitest';
import { CABECERA_FECHA, comoSeLee, datosGuardados } from './guardado.svelte';

function respuesta(marca?: string): Response {
	const headers = new Headers();
	if (marca !== undefined) headers.set(CABECERA_FECHA, marca);

	return new Response(null, { headers });
}

describe('anotar de dónde viene una respuesta', () => {
	it('con la marca del Service Worker, guarda la fecha', () => {
		datosGuardados.anotar(respuesta('2026-08-25T14:14:00.000Z'));

		expect(datosGuardados.cuando).toBeInstanceOf(Date);
	});

	it('sin marca, limpia el aviso: la red volvió', () => {
		// Es el más importante de los dos. Dejar el aviso puesto cuando el dato ya
		// es fresco hace desconfiar de todo lo demás.
		datosGuardados.anotar(respuesta('2026-08-25T14:14:00.000Z'));
		datosGuardados.anotar(respuesta());

		expect(datosGuardados.cuando).toBeNull();
	});

	it('una marca ilegible no deja una fecha inválida en pantalla', () => {
		datosGuardados.anotar(respuesta('no es una fecha'));

		expect(datosGuardados.cuando).toBeNull();
	});
});

describe('cómo se dice', () => {
	it('lo de hoy dice la hora', () => {
		// «guardado hoy a las 9:14» y «guardado el 24 de agosto» llevan a
		// decisiones distintas; a media mañana esa diferencia es la que importa.
		const ahora = new Date('2026-08-25T15:00:00');
		const antes = new Date('2026-08-25T09:14:00');

		expect(comoSeLee(antes, ahora)).toContain('hoy a las');
	});

	it('lo de otro día dice la fecha, no solo la hora', () => {
		const ahora = new Date('2026-08-25T15:00:00');
		const ayer = new Date('2026-08-24T09:14:00');

		const texto = comoSeLee(ayer, ahora);

		expect(texto).not.toContain('hoy');
		expect(texto).toContain('agosto');
	});
});
