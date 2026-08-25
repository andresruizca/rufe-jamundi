// Tres reglas del guardado local que no se pueden romper en silencio.
//
// Se leen del código fuente y no ejecutándolo a propósito: `registros.ts` habla
// con SQLite y con el sistema de archivos del teléfono, y montar los dos aquí
// probaría el simulacro, no lo que corre en el aparato. Lo que SÍ se puede
// fijar es la forma de las consultas, que es donde estuvieron los tres fallos.
//
// El comportamiento de esas consultas contra un SQLite de verdad lo comprueba
// `scripts/comprobar-esquema.mjs`.

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const fuente = readFileSync(fileURLToPath(new URL('./registros.ts', import.meta.url)), 'utf8');

describe('la purga de borradores', () => {
	it('normaliza la fecha antes de compararla', () => {
		// TypeScript escribe `2026-08-24T05:00:00.000Z`; `datetime('now')` devuelve
		// `2026-08-24 05:00:00`. Como texto, la «T» (0x54) es mayor que el espacio
		// (0x20): un borrador del mismo día jamás salía menor que el corte y
		// sobrevivía un día de más, con sus fotos ocupando el teléfono.
		expect(fuente).toContain("datetime(creado_en) < datetime('now', '-1 day')");
		expect(fuente).toContain("datetime(r.creado_en) < datetime('now', '-1 day')");
	});

	it('no queda ninguna comparación cruda contra la fecha', () => {
		// La forma rota, por si alguien la reintroduce «simplificando».
		expect(fuente).not.toMatch(/[^(]creado_en\s*<\s*datetime\('now'/);
	});
});

describe('abrir el formulario', () => {
	it('reutiliza el borrador abierto en vez de crear otro', () => {
		// `empezar()` corre en cada apertura de la aplicación. Creando uno nuevo
		// siempre, veinte aperturas dejaban veinte filas, y las fotos de cada
		// intento abandonado colgaban de una fila distinta que nadie volvería a
		// abrir.
		expect(fuente).toContain("SELECT id FROM registros WHERE estado = 'BORRADOR'");
		expect(fuente).toContain('if (previo) return previo.id;');
	});

	it('borra los archivos de lo que purga', () => {
		// `purgarBorradores()` devolvía las rutas para que alguien las borrara y
		// nadie lo hacía: la fila desaparecía y el video de 8 MB se quedaba en el
		// aparato para siempre.
		expect(fuente).toContain('await borrarArchivos(await purgarBorradores())');
	});
});

describe('borrar una solicitud', () => {
	it('se lleva sus archivos, sin depender de que quien llame se acuerde', () => {
		const cuerpo = fuente.slice(fuente.indexOf('export async function borrar('));

		expect(cuerpo).toContain('rutasDeSusArchivos(id)');
		expect(cuerpo).toContain('borrarArchivos(rutas)');
	});
});
