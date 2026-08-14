import { describe, it, expect } from 'vitest';
import { parseRufeCsv } from './parse';

const HEADER = [
	'x,68',
	',',
	',',
	'DEPARTAMENTO',
	'MUNICPIO',
	'INFORMACION',
	'ITEMS,HOGAR,CORREGIMIENTO,BARRIO,DIRECCION,NOMBRE,APELLIDO,TIPODOC,NUMDOC,PARENTESCO,GENERO,DIA,MES,ANIO,EDAD,ETNIA,TEL,TENENCIA,ESTADO,TIPOBIEN,EVACUADA,VISITA,QUIEN,OBS',
	','
];

function csv(dataRows: string[]): string {
	return [...HEADER, ...dataRows].join('\n');
}

describe('parseRufeCsv', () => {
	it('parses a basic urban household with direct M/F gender', () => {
		const out = parseRufeCsv(
			csv([
				'1,1,,Terranova,,Javier,Aguilar,3,111,1,M,2,1,1957,69,6,,PROPIETARIO,HABITABLE,VIVIENDA,NO,SI,,',
				'1,1,,,,Maria,Aguilar,3,222,2,F,1,1,1960,66,6,,PROPIETARIO,HABITABLE,VIVIENDA,NO,SI,,'
			]),
			'2026-01-01'
		);
		expect(out.total).toBe(2);
		expect(out.barrios).toHaveLength(1);
		expect(out.barrios[0]).toMatchObject({
			name: 'Terranova',
			zona: 'Urbana',
			total: 2,
			M: 1,
			F: 1
		});
	});

	it('forward-fills corregimiento/barrio from the first member of a household', () => {
		const out = parseRufeCsv(
			csv([
				',5,Quinamayo,Via Principal,,Ana,Lopez,3,1,1,F,1,1,1990,36,5,,,,,,,,',
				',5,,,,,Pedro,Lopez,3,2,2,M,1,1,1988,38,5,,,,,,,,'
			]),
			'2026-01-01'
		);
		expect(out.barrios).toHaveLength(1);
		expect(out.barrios[0]).toMatchObject({ name: 'Quinamayo', zona: 'Rural', total: 2 });
	});

	it('classifies a known rural corregimiento as Rural and groups by corregimiento, not sector', () => {
		const out = parseRufeCsv(
			csv([
				',10,Robles,Sector A,,X,Y,3,1,1,M,1,1,1990,36,1,,,,,,,,',
				',11,Robles,Sector B,,X,Y,3,2,1,F,1,1,1990,36,1,,,,,,,,'
			]),
			'2026-01-01'
		);
		expect(out.barrios).toHaveLength(1);
		expect(out.barrios[0].name).toBe('Robles');
		expect(out.barrios[0].total).toBe(2);
	});

	it('reclassifies a blank corregimiento as Rural when the barrio field holds a known rural name (hogar 91/117 regression)', () => {
		const out = parseRufeCsv(
			csv(['(no)hogar91,91,,San Isidro,,Carlos,Lasso,3,1,1,M,1,1,1975,51,6,,,,,,,,']),
			'2026-01-01'
		);
		expect(out.barrios[0]).toMatchObject({ name: 'San Isidro', zona: 'Rural' });
	});

	it('treats a blank/JAMUNDI/TERRANOVA corregimiento as Urbana', () => {
		const out = parseRufeCsv(
			csv([
				'1,1,,ElRodeo,,A,B,3,1,1,M,1,1,1990,36,6,,,,,,,,',
				'2,2,JAMUNDI,ElRodeo,,C,D,3,2,1,F,1,1,1990,36,6,,,,,,,,'
			]),
			'2026-01-01'
		);
		expect(out.barrios).toHaveLength(1);
		expect(out.barrios[0]).toMatchObject({ name: 'Elrodeo', zona: 'Urbana', total: 2 });
	});

	it('counts an identity outside M/F (e.g. "T") toward the total but not toward M or F', () => {
		const out = parseRufeCsv(
			csv(['1,1,,Terranova,,A,B,3,1,1,T,1,1,1990,36,6,,,,,,,,']),
			'2026-01-01'
		);
		expect(out.total).toBe(1);
		expect(out.barrios[0].M).toBe(0);
		expect(out.barrios[0].F).toBe(0);
	});

	it('buckets age correctly at the boundaries (11/12, 28/29, 59/60)', () => {
		const row = (edad: number, doc: string) =>
			`1,1,,Terranova,,A,B,3,${doc},1,M,1,1,2000,${edad},6,,,,,,,,`;
		const out = parseRufeCsv(
			csv([row(11, '1'), row(12, '2'), row(28, '3'), row(29, '4'), row(59, '5'), row(60, '6')]),
			'2026-01-01'
		);
		const b = out.barrios[0];
		expect(b.Ninos).toBe(1);
		expect(b.Jovenes).toBe(2);
		expect(b.Adultos).toBe(2);
		expect(b.AdultosMayores).toBe(1);
	});

	it('skips filler rows with no nombre/apellido/documento', () => {
		const out = parseRufeCsv(
			csv(['1,1,,Terranova,,A,B,3,1,1,M,1,1,1990,36,6,,,,,,,,', ',,,,,,,,,,,,,,2026,,,,,,,,,']),
			'2026-01-01'
		);
		expect(out.total).toBe(1);
	});

	it('records a warning instead of throwing when a barrio label ends up with mixed zona', () => {
		// hogar 20's first member implies Rural via barrio="Robles" (blank
		// corregimiento), a later member of the SAME household has an
		// inconsistent non-rural corregimiento of their own.
		const out = parseRufeCsv(
			csv([
				',20,,Robles,,A,B,3,1,1,M,1,1,1990,36,6,,,,,,,,',
				',20,OtroLugar,,,,C,D,3,2,2,F,1,1,1990,36,6,,,,,,,,'
			]),
			'2026-01-01'
		);
		expect(out.warnings).toBeDefined();
		expect(out.warnings!.length).toBeGreaterThan(0);
		expect(out.barrios.find((b) => b.name === 'Robles')?.zona).toBe('Rural');
	});

	it('sorts barrios by total descending', () => {
		const out = parseRufeCsv(
			csv([
				'1,1,,A,,x,y,3,1,1,M,1,1,1990,36,6,,,,,,,,',
				'2,2,,B,,x,y,3,2,1,M,1,1,1990,36,6,,,,,,,,',
				'3,2,,B,,x,y,3,3,1,M,1,1,1990,36,6,,,,,,,,'
			]),
			'2026-01-01'
		);
		expect(out.barrios[0].name).toBe('B');
		expect(out.barrios[0].total).toBe(2);
	});

	it('carries the given asOf timestamp through untouched', () => {
		const out = parseRufeCsv(
			csv(['1,1,,A,,x,y,3,1,1,M,1,1,1990,36,6,,,,,,,,']),
			'2026-08-14 10:00'
		);
		expect(out.asOf).toBe('2026-08-14 10:00');
	});
});
