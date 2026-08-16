import type { EquipamientoItem, EquipamientosDataset } from './equipamientos/types';

export interface EquipamientosAggregate {
	categorias: number;
	items: number;
	unidades: number;
	totalDeclarado: number;
	porCategoria: {
		nombre: string;
		unidades: number;
		totalReportado: number | null;
		nota?: string;
	}[];
	porEstado: Record<string, number>;
	visitaSi: number;
	visitaNo: number;
	visitaPorConfirmar: number;
	visitaSinDato: number;
	informeSi: number;
	informeNo: number;
	informeSinDato: number;
}

export type EquipamientosSortKey =
	'categoria' | 'nombre' | 'estado' | 'cantidad' | 'visita' | 'informe';

const TEXT_KEYS = new Set<EquipamientosSortKey>([
	'categoria',
	'nombre',
	'estado',
	'visita',
	'informe'
]);

export function sortEquipamientos(
	items: EquipamientoItem[],
	key: EquipamientosSortKey,
	dir: 1 | -1
): EquipamientoItem[] {
	return [...items].sort((a, b) => {
		const av = a[key];
		const bv = b[key];
		const cmp = TEXT_KEYS.has(key)
			? (av as string).localeCompare(bv as string)
			: (av as number) - (bv as number);
		return cmp * dir;
	});
}

export function filterEquipamientos(items: EquipamientoItem[], query: string): EquipamientoItem[] {
	const q = query.trim().toLowerCase();
	if (!q) return items;
	return items.filter(
		(i) => i.categoria.toLowerCase().includes(q) || i.nombre.toLowerCase().includes(q)
	);
}

export function aggregateEquipamientos(
	dataset: EquipamientosDataset,
	items: EquipamientoItem[]
): EquipamientosAggregate {
	const porEstado: Record<string, number> = {};
	let unidades = 0;
	let visitaSi = 0;
	let visitaNo = 0;
	let visitaPorConfirmar = 0;
	let visitaSinDato = 0;
	let informeSi = 0;
	let informeNo = 0;
	let informeSinDato = 0;

	const categoriasEnFiltro = new Set(items.map((i) => i.categoria));

	for (const i of items) {
		unidades += i.cantidad;
		const estado = i.estado || 'Sin dato';
		porEstado[estado] = (porEstado[estado] ?? 0) + i.cantidad;

		if (i.visita === 'Sí') visitaSi += i.cantidad;
		else if (i.visita === 'No') visitaNo += i.cantidad;
		else if (i.visita === 'Por confirmar') visitaPorConfirmar += i.cantidad;
		else visitaSinDato += i.cantidad;

		if (i.informe === 'Sí') informeSi += i.cantidad;
		else if (i.informe === 'No') informeNo += i.cantidad;
		else informeSinDato += i.cantidad;
	}

	const porCategoria = dataset.categorias
		.filter((c) => categoriasEnFiltro.has(c.nombre))
		.map((c) => ({
			nombre: c.nombre,
			unidades: items.filter((i) => i.categoria === c.nombre).reduce((a, i) => a + i.cantidad, 0),
			totalReportado: c.totalReportado,
			nota: c.nota
		}))
		.sort((a, b) => b.unidades - a.unidades);

	const totalDeclarado = dataset.categorias.reduce((a, c) => a + (c.totalReportado ?? 0), 0);

	return {
		categorias: categoriasEnFiltro.size,
		items: items.length,
		unidades,
		totalDeclarado,
		porCategoria,
		porEstado,
		visitaSi,
		visitaNo,
		visitaPorConfirmar,
		visitaSinDato,
		informeSi,
		informeNo,
		informeSinDato
	};
}
