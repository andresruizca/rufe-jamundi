import { describe, it, expect } from 'vitest';
import { aggregateEquipamientos, filterEquipamientos } from './equipamientosAggregate';
import type { EquipamientoItem, EquipamientosDataset } from './equipamientos/types';

function item(overrides: Partial<EquipamientoItem>): EquipamientoItem {
	return {
		categoria: 'Categoria A',
		nombre: 'Item A',
		estado: 'Averiado',
		cantidad: 1,
		sinDetalle: false,
		visita: 'Sí',
		informe: 'No',
		...overrides
	};
}

describe('aggregateEquipamientos()', () => {
	it('weights estado/visita/informe tallies by cantidad, not by number of rows', () => {
		const items = [
			item({ estado: 'Averiado', cantidad: 1, visita: 'Sí', informe: 'Sí' }),
			item({ estado: 'En verificación', cantidad: 6, visita: 'Sin dato', informe: 'Sin dato' })
		];
		const dataset: EquipamientosDataset = {
			asOf: 't',
			items,
			categorias: [{ nombre: 'Categoria A', totalReportado: 7 }]
		};
		const agg = aggregateEquipamientos(dataset, items);
		expect(agg.unidades).toBe(7);
		expect(agg.porEstado).toEqual({ Averiado: 1, 'En verificación': 6 });
		expect(agg.visitaSi).toBe(1);
		expect(agg.visitaSinDato).toBe(6);
		expect(agg.informeSi).toBe(1);
		expect(agg.informeSinDato).toBe(6);
	});

	it('tallies visita "Por confirmar" separately from Sí/No/Sin dato', () => {
		const items = [item({ visita: 'Por confirmar' })];
		const dataset: EquipamientosDataset = {
			asOf: 't',
			items,
			categorias: [{ nombre: 'Categoria A', totalReportado: 1 }]
		};
		const agg = aggregateEquipamientos(dataset, items);
		expect(agg.visitaPorConfirmar).toBe(1);
		expect(agg.visitaSi).toBe(0);
	});

	it('ranks categorías by unidades, carrying totalReportado and nota through', () => {
		const items = [
			item({ categoria: 'B', nombre: 'B1', cantidad: 1 }),
			item({ categoria: 'A', nombre: 'A1', cantidad: 3 })
		];
		const dataset: EquipamientosDataset = {
			asOf: 't',
			items,
			categorias: [
				{ nombre: 'A', totalReportado: 3 },
				{ nombre: 'B', totalReportado: 1 },
				{ nombre: 'C (sin ítems, filtrada afuera)', totalReportado: null, nota: 'pendiente' }
			]
		};
		const agg = aggregateEquipamientos(dataset, items);
		expect(agg.porCategoria).toEqual([
			{ nombre: 'A', unidades: 3, totalReportado: 3 },
			{ nombre: 'B', unidades: 1, totalReportado: 1 }
		]);
	});

	it('sums totalDeclarado across ALL categorías (not just the filtered ones), incluyendo notas sin número', () => {
		const dataset: EquipamientosDataset = {
			asOf: 't',
			items: [],
			categorias: [
				{ nombre: 'A', totalReportado: 3 },
				{ nombre: 'B', totalReportado: null, nota: 'en verificación' }
			]
		};
		const agg = aggregateEquipamientos(dataset, []);
		expect(agg.totalDeclarado).toBe(3);
	});
});

describe('filterEquipamientos()', () => {
	const sample = [
		item({ categoria: 'Centros Comerciales', nombre: 'Alguara' }),
		item({ categoria: 'Sector Religioso', nombre: 'Restauracion Olam' })
	];

	it('filters by categoría o nombre, sin distinguir mayúsculas', () => {
		expect(filterEquipamientos(sample, 'algu')).toEqual([sample[0]]);
		expect(filterEquipamientos(sample, 'religioso')).toEqual([sample[1]]);
		expect(filterEquipamientos(sample, '')).toEqual(sample);
	});
});
