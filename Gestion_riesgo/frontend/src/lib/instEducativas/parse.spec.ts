import { describe, it, expect } from 'vitest';
import { parseInstEducativasCsv } from './parse';

const HEADER =
	'AÑO,DEPARTAMENTO,SECRETARIA,COD_DANE_MUNICIPIO,MUNICIPIO,CODIGO_DANE,NoMBRE_ESTABLECIMIENTO,SECTOR_ATENCION,NoMBRE_SEDE,ZONA,DIRECCION,BARRIO_VEREDA,TELEFONo,EMAIL,TOTAL_MATRICULA,ESTADO_FISICO,ESTUDIANTES_AFECTADOS,ALBERGUE,VIAS_ACCESO,SUSPENDIERON_CLASES,DANOS,ACCIONES_ETC,CONCEPTO_TECNICO,REQUIERE_EVACUACION,PRIORIDAD,REQUIERE_VISITA,OBSERVACIONES';

function csv(rows: string[]): string {
	return [HEADER, ...rows].join('\n');
}

// Fila base con las 27 columnas en orden, para no tener que contar comas a
// mano en cada prueba — cada test solo sobreescribe las columnas que le
// interesan.
function row(overrides: Record<number, string> = {}): string {
	const cols = [
		'2026',
		'Valle del Cauca',
		'JAMUNDÍ',
		'76364',
		'Jamundí',
		'276364000460',
		'IE EJEMPLO',
		'OFICIAL',
		'IE EJEMPLO - SEDE PRINCIPAL',
		'URBANA',
		'CALLE 1',
		'San Antonio',
		'123',
		'a@b.co',
		'100',
		'Afectación menor',
		'5',
		'No',
		'SI',
		'SI',
		'',
		'',
		'SI',
		'NO',
		'ALTA',
		'SI',
		''
	];
	for (const [i, v] of Object.entries(overrides)) cols[Number(i)] = v;
	return cols.map((c) => (c.includes(',') ? `"${c}"` : c)).join(',');
}

describe('parseInstEducativasCsv', () => {
	it('parses a clean row into a Sede', () => {
		const out = parseInstEducativasCsv(csv([row()]), '2026-08-15');
		expect(out.sedes).toHaveLength(1);
		expect(out.sedes[0]).toMatchObject({
			establecimiento: 'IE EJEMPLO',
			sede: 'IE EJEMPLO - SEDE PRINCIPAL',
			zona: 'Urbana',
			barrio: 'San Antonio',
			matricula: 100,
			estadoFisico: 'Afectación menor',
			estudiantesAfectados: 5,
			usadaComoAlbergue: 'No',
			viasDeAcceso: 'Sí',
			suspendieronClases: 'Sí',
			conceptoTecnico: 'Sí',
			requiereEvacuacion: 'No',
			prioridad: 'Alta',
			requiereVisitaTecnica: 'Sí'
		});
	});

	it('reads ZONA directly (RURAL/URBANA), unlike el RUFE de personas que la infiere', () => {
		const out = parseInstEducativasCsv(csv([row({ 9: 'RURAL' })]), '2026-08-15');
		expect(out.sedes[0].zona).toBe('Rural');
	});

	it('skips filler rows with no sede ni establecimiento', () => {
		const blank = Array(27).fill('').join(',');
		const out = parseInstEducativasCsv(csv([row(), blank]), '2026-08-15');
		expect(out.sedes).toHaveLength(1);
	});

	describe('canonSiNo / canonEvacuacion — respuestas reales encontradas en la hoja', () => {
		const cases: [string, string][] = [
			['SI', 'Sí'],
			['Si', 'Sí'],
			['si', 'Sí'],
			['NO', 'No'],
			['No', 'No'],
			['no', 'No'],
			[
				'SI existe acceso físico a la sede, pero este no es seguro. Se requiere inspección técnica urgente.',
				'Sí'
			],
			['No es seguro y requiere inspección técnica urgente', 'No'],
			['', 'Sin dato']
		];
		for (const [raw, expected] of cases) {
			it(`"${raw.slice(0, 30)}…" → ${expected}`, () => {
				const out = parseInstEducativasCsv(csv([row({ 18: raw })]), '2026-08-15');
				expect(out.sedes[0].viasDeAcceso).toBe(expected);
			});
		}
	});

	it('adds a third "En proceso" state to concepto técnico, distinto de Sí/No', () => {
		const out = parseInstEducativasCsv(
			csv([row({ 22: 'Proceso inicial visual' }), row({ 22: 'en proceso' })]),
			'2026-08-15'
		);
		expect(out.sedes[0].conceptoTecnico).toBe('En proceso');
		expect(out.sedes[1].conceptoTecnico).toBe('En proceso');
	});

	it('adds a "Parcial" state to requiere evacuación', () => {
		const out = parseInstEducativasCsv(csv([row({ 23: 'PARCIAL' })]), '2026-08-15');
		expect(out.sedes[0].requiereEvacuacion).toBe('Parcial');
	});

	it('treats a bare SI/NO in prioridad as "Sin dato" (no se puede saber el nivel real)', () => {
		const out = parseInstEducativasCsv(csv([row({ 24: 'SI' }), row({ 24: 'NO' })]), '2026-08-15');
		expect(out.sedes[0].prioridad).toBe('Sin dato');
		expect(out.sedes[1].prioridad).toBe('Sin dato');
	});

	it('classifies prioridad by keyword, "MEDIA ALTA" resolving to Alta (revisa INMEDIATA/URGENTE primero)', () => {
		const out = parseInstEducativasCsv(
			csv([
				row({ 24: 'INMEDIATA' }),
				row({ 24: 'PRIORIDAD ALTA – ATENCIÓN URGENTE. Se requiere inspección.' }),
				row({ 24: 'MEDIA ALTA: LA SEDE PRESENTA AFECTACIONES' }),
				row({ 24: 'ALTA: LA SEDE EDUCATIVA PRESENTA DAÑOS' }),
				row({ 24: 'MEDIA: LA SEDE PRESENTA AFECTACIONES' }),
				row({ 24: 'BAJA: PRESENTA DAÑOS MENORES' }),
				row({ 24: 'Minima' }),
				row({ 24: '0 FVQ' })
			]),
			'2026-08-15'
		);
		expect(out.sedes.map((s) => s.prioridad)).toEqual([
			'Inmediata',
			'Inmediata',
			'Alta',
			'Alta',
			'Media',
			'Baja',
			'Baja',
			'Sin dato'
		]);
	});

	it('treats a garbage "2" and a descriptive sentence in requiere visita técnica', () => {
		const out = parseInstEducativasCsv(
			csv([row({ 25: '2' }), row({ 25: 'Requiere visita técnica en el área de restaurante' })]),
			'2026-08-15'
		);
		expect(out.sedes[0].requiereVisitaTecnica).toBe('Sin dato');
		expect(out.sedes[1].requiereVisitaTecnica).toBe('Sí');
	});

	it('parses matrícula and estudiantes afectados as numbers, defaulting blanks to 0', () => {
		const out = parseInstEducativasCsv(csv([row({ 14: '1.454', 16: '' })]), '2026-08-15');
		expect(out.sedes[0].matricula).toBe(1454);
		expect(out.sedes[0].estudiantesAfectados).toBe(0);
	});

	it('carries the given asOf timestamp through untouched', () => {
		const out = parseInstEducativasCsv(csv([row()]), '2026-08-15 10:00');
		expect(out.asOf).toBe('2026-08-15 10:00');
	});
});
