import { describe, it, expect } from 'vitest';
import { aggregateInstEducativas, filterSedes, listDanos } from './instEducativasAggregate';
import type { Sede } from './instEducativas/types';

function sede(overrides: Partial<Sede>): Sede {
	return {
		establecimiento: 'IE Ejemplo',
		sede: 'IE Ejemplo - Sede Principal',
		zona: 'Urbana',
		direccion: 'Calle 1',
		barrio: 'San Antonio',
		matricula: 100,
		estadoFisico: 'Afectación menor',
		estudiantesAfectados: 0,
		usadaComoAlbergue: 'No',
		viasDeAcceso: 'Sí',
		suspendieronClases: 'No',
		danosObservados: '',
		accionesEtc: '',
		conceptoTecnico: 'Sin dato',
		requiereEvacuacion: 'No',
		prioridad: 'Sin dato',
		requiereVisitaTecnica: 'Sin dato',
		observaciones: '',
		...overrides
	};
}

describe('aggregateInstEducativas()', () => {
	it('counts sedes and establecimientos únicos por separado (varias sedes por establecimiento)', () => {
		const agg = aggregateInstEducativas([
			sede({ establecimiento: 'IE A', sede: 'IE A - Sede 1' }),
			sede({ establecimiento: 'IE A', sede: 'IE A - Sede 2' }),
			sede({ establecimiento: 'IE B', sede: 'IE B - Sede 1' })
		]);
		expect(agg.sedes).toBe(3);
		expect(agg.establecimientos).toBe(2);
	});

	it('sums matrícula y estudiantes afectados', () => {
		const agg = aggregateInstEducativas([
			sede({ matricula: 400, estudiantesAfectados: 50 }),
			sede({ matricula: 300, estudiantesAfectados: 30 })
		]);
		expect(agg.matricula).toBe(700);
		expect(agg.estudiantesAfectados).toBe(80);
	});

	it('tallies sedes by zona', () => {
		const agg = aggregateInstEducativas([
			sede({ zona: 'Urbana' }),
			sede({ zona: 'Rural' }),
			sede({ zona: 'Rural' })
		]);
		expect(agg.urbana).toBe(1);
		expect(agg.rural).toBe(2);
	});

	it('tallies estado físico, defaulting blanks to "Sin dato"', () => {
		const agg = aggregateInstEducativas([
			sede({ estadoFisico: 'Colapso parcial' }),
			sede({ estadoFisico: 'Colapso parcial' }),
			sede({ estadoFisico: '' })
		]);
		expect(agg.estadoFisico).toEqual({ 'Colapso parcial': 2, 'Sin dato': 1 });
	});

	it('counts sedes con daños observados', () => {
		const agg = aggregateInstEducativas([
			sede({ danosObservados: 'Grietas en muro' }),
			sede({ danosObservados: '' })
		]);
		expect(agg.conDanosObservados).toBe(1);
	});
});

describe('filterSedes()', () => {
	const sample: Sede[] = [
		sede({ sede: 'IE Terranova', barrio: 'Terranova', zona: 'Urbana' }),
		sede({
			sede: 'IE Quinamayo',
			establecimiento: 'IE Quinamayo',
			barrio: 'Quinamayo',
			zona: 'Rural'
		})
	];

	it('filters by zona and by texto (sede, establecimiento o barrio), sin distinguir mayúsculas', () => {
		expect(filterSedes(sample, 'Urbana', '')).toHaveLength(1);
		expect(filterSedes(sample, 'todas', 'quina')).toEqual([sample[1]]);
		expect(filterSedes(sample, 'Rural', 'terra')).toHaveLength(0);
	});
});

describe('listDanos()', () => {
	it('lists only sedes con daños observados, ordenadas alfabéticamente por sede', () => {
		const list = listDanos([
			sede({ sede: 'Zeta', danosObservados: 'Grietas leves' }),
			sede({ sede: 'Alfa', danosObservados: '' }),
			sede({ sede: 'Beta', danosObservados: 'Fisuras menores' })
		]);
		expect(list.map((d) => d.sede)).toEqual(['Beta', 'Zeta']);
	});

	it('marks as critical when prioridad is Inmediata/Alta or el texto menciona riesgo', () => {
		const list = listDanos([
			sede({ sede: 'A', danosObservados: 'Grietas menores', prioridad: 'Baja' }),
			sede({ sede: 'B', danosObservados: 'Riesgo de colapso', prioridad: 'Sin dato' }),
			sede({ sede: 'C', danosObservados: 'Fachada rayada', prioridad: 'Inmediata' })
		]);
		const byId = Object.fromEntries(list.map((d) => [d.sede, d.critical]));
		expect(byId['A']).toBe(false);
		expect(byId['B']).toBe(true);
		expect(byId['C']).toBe(true);
	});
});
