import { describe, expect, it } from 'vitest';
import { comoSeLee, cuandoSeLee } from './bitacora';

describe('la hora de un intento', () => {
	it('interpreta como UTC lo que escribe SQLite', () => {
		// `datetime('now')` de SQLite devuelve UTC y SIN la Z. Si se pasa tal cual
		// a `new Date()`, el navegador lo toma como hora local y en Colombia las
		// horas salen corridas cinco hacia atrás: un envío de las 8 de la noche se
		// leería como de las 3 de la tarde.
		//
		// Se compara contra el mismo instante ya marcado como UTC.
		const deSqlite = cuandoSeLee('2026-08-23 01:30:00');
		const conZ = new Date('2026-08-23T01:30:00Z').toLocaleString('es-CO', {
			day: '2-digit',
			month: 'short',
			hour: 'numeric',
			minute: '2-digit'
		});

		expect(deSqlite).toBe(conZ);
	});

	it('acepta también una fecha que ya venga en ISO', () => {
		expect(cuandoSeLee('2026-08-23T01:30:00Z')).toBe(cuandoSeLee('2026-08-23 01:30:00'));
	});

	it('lleva la hora, no solo el día', () => {
		// Una solicitud puede intentarse varias veces el mismo día. Sin la hora,
		// las anotaciones de la bitácora son indistinguibles entre sí.
		expect(cuandoSeLee('2026-08-23 01:30:00')).toMatch(/\d/);
		expect(cuandoSeLee('2026-08-23 01:30:00').length).toBeGreaterThan(8);
	});
});

describe('cómo se lee un intento', () => {
	it('«sin conexión» NO se cuenta como error', () => {
		// Es lo normal en una vereda y no hay nada que la persona pueda hacer.
		// Pintarlo de rojo la asusta por algo que no es culpa suya ni problema.
		const r = comoSeLee({ cuando: '', resultado: 'SIN_CONEXION', detalle: null });

		expect(r.clase).toBe('espera');
		expect(r.texto).not.toMatch(/error/i);
	});

	it('lo enviado lleva su radicado, que es lo único que se lleva', () => {
		const r = comoSeLee({ cuando: '', resultado: 'ENVIADO', detalle: 'PRE-2026-ABCD1234' });

		expect(r.clase).toBe('bien');
		expect(r.texto).toContain('PRE-2026-ABCD1234');
	});

	it('un envío sin radicado no inventa uno', () => {
		expect(comoSeLee({ cuando: '', resultado: 'ENVIADO', detalle: null }).texto).toBe('Enviado');
	});

	it('un error de verdad sí se marca como tal', () => {
		const r = comoSeLee({ cuando: '', resultado: 'ERROR', detalle: 'Hay datos que corregir.' });

		expect(r.clase).toBe('mal');
		expect(r.texto).toBe('Hay datos que corregir.');
	});
});
