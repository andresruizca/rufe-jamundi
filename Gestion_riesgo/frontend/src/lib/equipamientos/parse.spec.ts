import { describe, it, expect } from 'vitest';
import { parseEquipamientosCsv } from './parse';

// Encabezado real de 3 filas: título, "TOTAL,ESTADO,,SE REALIZÓ
// VISITA,EXISTE INFORME", "SI/NO,SI/NO" — ver parse.ts para por qué se
// salta por posición y no por contenido.
const HEADER = [
	'c,,,,,,,',
	',,,TOTAL,ESTADO,,SE REALIZÓ VISITA,EXISTE INFORME',
	',,,,,,SI/NO,SI/NO'
];

function csv(rows: string[]): string {
	return [...HEADER, ...rows].join('\n');
}

describe('parseEquipamientosCsv', () => {
	it('parses a single-item category, using the category name as the item name', () => {
		const out = parseEquipamientosCsv(csv(['ALCALDIA MUNICIPAL:,,,1,AVERIADO,,SI,NO']), 't');
		expect(out.categorias).toEqual([{ nombre: 'ALCALDIA MUNICIPAL', totalReportado: 1 }]);
		expect(out.items).toEqual([
			{
				categoria: 'ALCALDIA MUNICIPAL',
				nombre: 'ALCALDIA MUNICIPAL',
				estado: 'Averiado',
				cantidad: 1,
				sinDetalle: false,
				visita: 'Sí',
				informe: 'No'
			}
		]);
	});

	it('skips the per-category sub-header row ("…,TOTAL,ESTADO,NOMBRES:,,") without creating an item', () => {
		const out = parseEquipamientosCsv(
			csv([
				'INSTALACIONES DE CULTURA:,,,TOTAL,ESTADO,NOMBRES:,,',
				',,,3,AVERIADO,BIBLIOTECA MUNICIPAL,SI,NO',
				',,,,AVERIADO,CASA DE LA CULTURA,SI,NO',
				',,,,AVERIADO,CENTRO CULTURAS ANTIGUA ESTACION,NO,NO'
			]),
			't'
		);
		expect(out.categorias).toEqual([{ nombre: 'INSTALACIONES DE CULTURA', totalReportado: 3 }]);
		expect(out.items).toHaveLength(3);
		expect(out.items.map((i) => i.nombre)).toEqual([
			'BIBLIOTECA MUNICIPAL',
			'CASA DE LA CULTURA',
			'CENTRO CULTURAS ANTIGUA ESTACION'
		]);
	});

	it('forward-fills the category name across blank-categoria rows (una sola vez por grupo)', () => {
		const out = parseEquipamientosCsv(
			csv([
				'SECTOR RELIGIOSO:,,,TOTAL,ESTADO,NOMBRES:,,',
				',,,3,AVERIADO,ASAMBLEA DE DIOS - CRA 50,por confirmar,',
				',,,,AVERIADO,RESTAURACION OLAM,por confirmar,',
				',,,,AVERIADO,PENTECOSTAL UNIDA DE COLOMBIA,por confirmar,'
			]),
			't'
		);
		expect(out.items.every((i) => i.categoria === 'SECTOR RELIGIOSO')).toBe(true);
		expect(out.items.map((i) => i.visita)).toEqual([
			'Por confirmar',
			'Por confirmar',
			'Por confirmar'
		]);
	});

	it('groups an unnamed multi-unit row into one item with sinDetalle=true, adding its count to the category total (caso "6 EN VERIFICACIÓN")', () => {
		const out = parseEquipamientosCsv(
			csv([
				'CENTROS DE DESARROLLO,,,TOTAL,ESTADO,NOMBRES:,,',
				',,,2,AVERIADO,CDA TERRANOVA,SI,SI',
				',,,,AVERIADO,CDA QUINAMAYO,NO,NO',
				',,,6,EN VERIFICACIÓN,,,'
			]),
			't'
		);
		expect(out.categorias).toEqual([{ nombre: 'CENTROS DE DESARROLLO', totalReportado: 8 }]);
		expect(out.items).toHaveLength(3);
		const bundled = out.items[2];
		expect(bundled).toMatchObject({
			nombre: 'Sin detalle — 6 unidades',
			estado: 'En verificación',
			cantidad: 6,
			sinDetalle: true,
			visita: 'Sin dato',
			informe: 'Sin dato'
		});
	});

	it('captures free text in the TOTAL cell as a category nota, creating zero items (caso Geriátricos)', () => {
		const out = parseEquipamientosCsv(
			csv([
				'GERIATRICOS,,,TOTAL,ESTADO,NOMBRES:,,',
				',,,EN VERIFICACIÓN POR PARTE DE SECRETARIA DE SALUD,,,,'
			]),
			't'
		);
		expect(out.categorias).toEqual([
			{
				nombre: 'GERIATRICOS',
				totalReportado: null,
				nota: 'EN VERIFICACIÓN POR PARTE DE SECRETARIA DE SALUD'
			}
		]);
		expect(out.items).toHaveLength(0);
	});

	it('canonicalizes visita/informe SI/NO regardless of case, defaulting blanks to "Sin dato"', () => {
		const out = parseEquipamientosCsv(
			csv(['A:,,,1,AVERIADO,,si,Si', 'B:,,,1,AVERIADO,,NO,no', 'C:,,,1,AVERIADO,,,']),
			't'
		);
		expect(out.items.map((i) => [i.visita, i.informe])).toEqual([
			['Sí', 'Sí'],
			['No', 'No'],
			['Sin dato', 'Sin dato']
		]);
	});

	it('carries the given asOf timestamp through untouched', () => {
		const out = parseEquipamientosCsv(csv(['A:,,,1,AVERIADO,,SI,NO']), '2026-08-15 10:00');
		expect(out.asOf).toBe('2026-08-15 10:00');
	});

	it('parses the full real sheet (10 categorías, 18 ítems) without throwing', () => {
		const real = csv([
			'ALCALDIA MUNICIPAL:,,,1,AVERIADO,,SI,NO',
			'CONCEJO MUNICIPAL:,,,1,AVERIADO,,SI,NO',
			'HOSPITAL MUNICIPAL:,,,1,AVERIADO,,SI,NO',
			'PLAZA DE MERCADO:,,,1,AVERIADO,,SI,NO',
			'ESTACIÓN DE BOMBEROS SAN ANTONIO,,,1,AVERIADO,,SI,SI',
			'INSTALACIONES DE CULTURA:,,,TOTAL,ESTADO,NOMBRES:,,',
			',,,3,AVERIADO,BIBLIOTECA MUNICIPAL,SI,NO',
			',,,,AVERIADO,CASA DE LA CULTURA,SI,NO',
			',,,,AVERIADO,CENTRO CULTURAS ANTIGUA ESTACION,NO,NO',
			'GERIATRICOS,,,TOTAL,ESTADO,NOMBRES:,,',
			',,,EN VERIFICACIÓN POR PARTE DE SECRETARIA DE SALUD,,,,',
			'SECTOR RELIGIOSO:,,,TOTAL,ESTADO,NOMBRES:,,',
			',,,3,AVERIADO,ASAMBLEA DE DIOS - CRA 50,por confirmar,',
			',,,,AVERIADO,RESTAURACION OLAM,por confirmar,',
			',,,,AVERIADO,PENTECOSTAL UNIDA DE COLOMBIA,por confirmar,',
			'CENTROS DE DESARROLLO,,,TOTAL,ESTADO,NOMBRES:,,',
			',,,2,AVERIADO,CDA TERRANOVA,SI,SI',
			',,,,AVERIADO,CDA QUINAMAYO,NO,NO',
			',,,6,EN VERIFICACIÓN,,,',
			'CENTROS COMERCIALES:,,,TOTAL,ESTADO,NOMBRES:,,',
			',,,4,AVERIADO,ALGUARA,SI,SI',
			',,,,AVERIADO,CAÑA DULCE,SI,NO',
			',,,,AVERIADO,PRONTO PLAZA,SI,NO',
			',,,,AVERIADO,ANTURIAS PLAZA,SI,NO'
		]);
		const out = parseEquipamientosCsv(real, 't');
		expect(out.categorias).toHaveLength(10);
		expect(out.items).toHaveLength(18);
		expect(out.items.reduce((a, i) => a + i.cantidad, 0)).toBe(23);
	});
});
