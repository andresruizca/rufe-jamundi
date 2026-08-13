import { describe, it, expect } from 'vitest';
import { aggregate, filterBarrios, fmt, pct, sortBarrios } from './aggregate';
import { DATA } from './data';
import type { Barrio } from './data';

describe('DATA integrity', () => {
	it('sums to the documented total', () => {
		const agg = aggregate(DATA.barrios);
		expect(agg.total).toBe(DATA.total);
		expect(agg.total).toBe(540);
	});

	it('every barrio bucket has a single consistent zona (no mixed-zona bug)', () => {
		// Regression test for the hogar 91/117 bug found while building the
		// original artifact: a barrio bucket must never mix Urbana/Rural people.
		for (const b of DATA.barrios) {
			expect(['Urbana', 'Rural']).toContain(b.zona);
		}
	});

	it('urbana + rural splits match the corrected totals', () => {
		const agg = aggregate(DATA.barrios);
		expect(agg.Urbana).toBe(391);
		expect(agg.Rural).toBe(149);
		expect(agg.Urbana + agg.Rural).toBe(agg.total);
	});

	it('gender and age columns never exceed the row total', () => {
		for (const b of DATA.barrios) {
			expect(b.M + b.F).toBeLessThanOrEqual(b.total);
			expect(b.Ninos + b.Jovenes + b.Adultos + b.AdultosMayores).toBeLessThanOrEqual(b.total);
		}
	});
});

describe('aggregate()', () => {
	it('returns zeros for an empty list', () => {
		const agg = aggregate([]);
		expect(agg.total).toBe(0);
		expect(agg.sinGenero).toBe(0);
		expect(agg.sinEdad).toBe(0);
	});

	const sample: Barrio[] = [
		{ name: 'A', total: 10, M: 4, F: 5, Ninos: 2, Jovenes: 2, Adultos: 5, AdultosMayores: 0, zona: 'Urbana' },
		{ name: 'B', total: 5, M: 2, F: 2, Ninos: 0, Jovenes: 1, Adultos: 3, AdultosMayores: 1, zona: 'Rural' }
	];

	it('sums fields across barrios and derives sin dato counts', () => {
		const agg = aggregate(sample);
		expect(agg.total).toBe(15);
		expect(agg.M).toBe(6);
		expect(agg.F).toBe(7);
		expect(agg.sinGenero).toBe(2); // 15 - 6 - 7
		expect(agg.Urbana).toBe(10);
		expect(agg.Rural).toBe(5);
	});
});

describe('filterBarrios()', () => {
	const sample: Barrio[] = [
		{ name: 'Terranova', total: 10, M: 5, F: 5, Ninos: 0, Jovenes: 0, Adultos: 10, AdultosMayores: 0, zona: 'Urbana' },
		{ name: 'Quinamayo', total: 5, M: 2, F: 3, Ninos: 0, Jovenes: 0, Adultos: 5, AdultosMayores: 0, zona: 'Rural' }
	];

	it('filters by zona', () => {
		expect(filterBarrios(sample, 'Urbana', '')).toHaveLength(1);
		expect(filterBarrios(sample, 'Rural', '')).toHaveLength(1);
		expect(filterBarrios(sample, 'todas', '')).toHaveLength(2);
	});

	it('filters by case-insensitive name search', () => {
		expect(filterBarrios(sample, 'todas', 'terra')).toEqual([sample[0]]);
		expect(filterBarrios(sample, 'todas', 'TERRA')).toEqual([sample[0]]);
	});

	it('combines zona and search filters', () => {
		expect(filterBarrios(sample, 'Rural', 'terra')).toHaveLength(0);
	});

	it('returns an empty array for no matches', () => {
		expect(filterBarrios(sample, 'todas', 'zzz-no-existe')).toEqual([]);
	});
});

describe('fmt() / pct()', () => {
	it('formats numbers with es-CO locale grouping', () => {
		expect(fmt(540)).toBe('540');
		expect(fmt(1000)).toBe('1.000');
	});

	it('computes rounded percentages and guards division by zero', () => {
		expect(pct(50, 100)).toBe(50);
		expect(pct(1, 3)).toBe(33);
		expect(pct(5, 0)).toBe(0);
	});
});

describe('sortBarrios()', () => {
	const sample: Barrio[] = [
		{ name: 'B', total: 5, M: 1, F: 1, Ninos: 0, Jovenes: 0, Adultos: 0, AdultosMayores: 0, zona: 'Urbana' },
		{ name: 'A', total: 10, M: 2, F: 2, Ninos: 0, Jovenes: 0, Adultos: 0, AdultosMayores: 0, zona: 'Rural' }
	];

	it('sorts numerically descending', () => {
		const sorted = sortBarrios(sample, 'total', -1);
		expect(sorted.map((b) => b.name)).toEqual(['A', 'B']);
	});

	it('sorts alphabetically ascending', () => {
		const sorted = sortBarrios(sample, 'name', 1);
		expect(sorted.map((b) => b.name)).toEqual(['A', 'B']);
	});

	it('does not mutate the original array', () => {
		const original = [...sample];
		sortBarrios(sample, 'total', -1);
		expect(sample).toEqual(original);
	});
});
