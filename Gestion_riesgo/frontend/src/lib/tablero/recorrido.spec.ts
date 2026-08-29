import { describe, expect, it } from 'vitest';
import { caidaEntre, pasos } from './recorrido';
import type { EtapaRecorrido } from '$lib/rufe/types';

function etapa(clave: string, hogares: number): EtapaRecorrido {
	return { clave, nombre: clave, pie: '', hogares };
}

describe('caidaEntre', () => {
	it('dice cuánto se pierde entre dos etapas', () => {
		expect(caidaEntre(1000, 250)).toBe(75);
	});

	it('no divide por cero', () => {
		// El día que se estrena el sistema, o la mañana siguiente a una
		// emergencia nueva, el censo está vacío. Un «NaN%» dibujado en el
		// tablero de la Alcaldía justo ese día es cuando menos tiempo hay para
		// averiguar qué pasó.
		expect(caidaEntre(0, 0)).toBeNull();
		expect(caidaEntre(0, 5)).toBeNull();
	});

	it('sin etapa anterior no hay caída', () => {
		expect(caidaEntre(null, 1382)).toBeNull();
	});

	it('crecer no es caer', () => {
		// Pasa de verdad: alguien se preinscribe por su cuenta, por el enlace
		// que le pasó un vecino, sin que nadie lo haya llamado. Pintar «−20 %»
		// sobre un aumento diría lo contrario de lo que ocurrió.
		expect(caidaEntre(100, 120)).toBeNull();
		expect(caidaEntre(100, 100)).toBeNull();
	});
});

describe('pasos', () => {
	const CAMINO = [
		etapa('censadas', 1382),
		etapa('contactadas', 400),
		etapa('preinscritas', 120),
		etapa('inspeccionadas', 30),
		etapa('aprobadas', 12)
	];

	it('la primera etapa no tiene caída', () => {
		expect(pasos(CAMINO)[0].caida).toBeNull();
	});

	it('cada etapa se compara con la anterior, no con el censo', () => {
		// Es la diferencia entre «de los contactados, tres cuartas partes no
		// pidieron el turno» y «casi nadie del censo se preinscribió». La
		// primera señala dónde intervenir; la segunda solo desanima.
		const p = pasos(CAMINO);

		expect(p[2].caida).toBe(70); // 400 → 120
		expect(p[2].delCenso).toBe(9); // pero solo el 9% del censo
	});

	it('respeta el orden que manda el servidor', () => {
		// El orden ES la regla de negocio y vive en `Recorrido` del servidor.
		// Reordenar aquí convertiría un avance en una fuga.
		expect(pasos(CAMINO).map((p) => p.etapa.clave)).toEqual([
			'censadas',
			'contactadas',
			'preinscritas',
			'inspeccionadas',
			'aprobadas'
		]);
	});

	it('aguanta un censo vacío entero', () => {
		const vacio = pasos([etapa('censadas', 0), etapa('contactadas', 0)]);

		expect(vacio[1].caida).toBeNull();
		expect(vacio[1].delCenso).toBeNull();
		expect(vacio.every((p) => Number.isNaN(p.delCenso ?? 0))).toBe(false);
	});

	it('aguanta que no llegue ninguna etapa', () => {
		// El servidor viejo no manda `recorrido`. Mientras se despliega, la
		// pantalla no puede reventar.
		expect(pasos([])).toEqual([]);
	});

	it('marca cuando una etapa crece en vez de caer', () => {
		const p = pasos([etapa('censadas', 100), etapa('contactadas', 130)]);

		expect(p[1].crece).toBe(true);
		expect(p[1].caida).toBeNull();
	});
});
