import type { Barrio, Zona } from './data';

export interface Aggregate {
	total: number;
	M: number;
	F: number;
	Ninos: number;
	Jovenes: number;
	Adultos: number;
	AdultosMayores: number;
	Urbana: number;
	Rural: number;
	sinGenero: number;
	sinEdad: number;
}

export function aggregate(list: Barrio[]): Aggregate {
	const a: Aggregate = {
		total: 0,
		M: 0,
		F: 0,
		Ninos: 0,
		Jovenes: 0,
		Adultos: 0,
		AdultosMayores: 0,
		Urbana: 0,
		Rural: 0,
		sinGenero: 0,
		sinEdad: 0
	};
	for (const b of list) {
		a.total += b.total;
		a.M += b.M;
		a.F += b.F;
		a.Ninos += b.Ninos;
		a.Jovenes += b.Jovenes;
		a.Adultos += b.Adultos;
		a.AdultosMayores += b.AdultosMayores;
		a[b.zona] += b.total;
	}
	a.sinGenero = a.total - a.M - a.F;
	a.sinEdad = a.total - a.Ninos - a.Jovenes - a.Adultos - a.AdultosMayores;
	return a;
}

export function filterBarrios(barrios: Barrio[], zona: Zona | 'todas', query: string): Barrio[] {
	const q = query.trim().toLowerCase();
	return barrios.filter(
		(b) => (zona === 'todas' || b.zona === zona) && (!q || b.name.toLowerCase().includes(q))
	);
}

export function fmt(n: number): string {
	return n.toLocaleString('es-CO');
}

export function pct(n: number, d: number): number {
	return d > 0 ? Math.round((n / d) * 100) : 0;
}

export type SortKey =
	'name' | 'zona' | 'total' | 'F' | 'M' | 'Ninos' | 'Jovenes' | 'Adultos' | 'AdultosMayores';

export function sortBarrios(barrios: Barrio[], key: SortKey, dir: 1 | -1): Barrio[] {
	return [...barrios].sort((a, b) => {
		const av = a[key];
		const bv = b[key];
		const cmp =
			typeof av === 'string' ? av.localeCompare(bv as string) : (av as number) - (bv as number);
		return cmp * dir;
	});
}
