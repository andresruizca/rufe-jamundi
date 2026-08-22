import { describe, expect, it } from 'vitest';
import {
	BYTES_TROZO,
	MAX_BYTES_CARGA,
	MAX_BYTES_FOTO,
	MAX_BYTES_VIDEO,
	cabe,
	cupoDe,
	rangoDelTrozo,
	trozosDe
} from './limites';

describe('el troceo del video', () => {
	it('parte por trozos de 1 MiB, sin uno vacío al final', () => {
		// El error clásico es un +1 de más: el servidor daría el video por
		// incompleto y lo BORRA al recibir el formulario, sin que nadie se entere
		// hasta que alguien abre la ficha y no encuentra el video.
		expect(trozosDe(BYTES_TROZO)).toBe(1);
		expect(trozosDe(BYTES_TROZO + 1)).toBe(2);
		expect(trozosDe(BYTES_TROZO * 3)).toBe(3);
	});

	it('un video vacío no tiene trozos', () => {
		expect(trozosDe(0)).toBe(0);
	});

	it('los rangos cubren el archivo entero y no se solapan', () => {
		const bytes = BYTES_TROZO * 2 + 12345;
		const trozos = trozosDe(bytes);
		let cubierto = 0;
		let anterior = 0;

		for (let i = 0; i < trozos; i++) {
			const { desde, hasta } = rangoDelTrozo(i, bytes);

			expect(desde).toBe(anterior);
			cubierto += hasta - desde;
			anterior = hasta;
		}

		expect(cubierto).toBe(bytes);
		expect(anterior).toBe(bytes);
	});

	it('el último trozo se queda corto, no se pasa del archivo', () => {
		const bytes = BYTES_TROZO + 100;

		expect(rangoDelTrozo(1, bytes)).toEqual({ desde: BYTES_TROZO, hasta: bytes });
	});
});

describe('los cupos', () => {
	it('la cédula es una sola', () => {
		expect(cupoDe('PRE_CEDULA')).toBe(1);
	});

	it('coinciden con lo que acepta el servidor', () => {
		// Si estos números se separan de los de PHP, el APK acepta en el campo
		// algo que el servidor rechazará horas después, cuando la persona ya no
		// está delante para volver a tomarlo.
		expect(cupoDe('PRE_DANO')).toBe(4);
		expect(cupoDe('VIDEO')).toBe(8);
		expect(MAX_BYTES_FOTO).toBe(1024 * 1024);
		expect(MAX_BYTES_VIDEO).toBe(8 * 1024 * 1024);
		expect(MAX_BYTES_CARGA).toBe(12 * 1024 * 1024);
	});
});

describe('si cabe un archivo más', () => {
	it('deja pasar lo normal', () => {
		expect(cabe('PRE_DANO', 300_000, 1, 900_000)).toEqual({ ok: true });
	});

	it('frena la segunda cédula y lo dice sin regañar', () => {
		const r = cabe('PRE_CEDULA', 200_000, 1, 200_000);

		expect(r.ok).toBe(false);
		expect(r.ok === false && r.motivo).toContain('Quite la anterior');
	});

	it('frena un video que se pasó de peso', () => {
		expect(cabe('VIDEO', MAX_BYTES_VIDEO + 1, 0, 0).ok).toBe(false);
	});

	it('frena cuando la carga entera ya no da más, aunque quede cupo', () => {
		// Cuatro fotos de daño caben por cantidad, pero el tope de 12 MiB es
		// común a fotos y videos. Comprobarlo AQUÍ, y no al sincronizar, es lo
		// que evita que alguien grabe ocho videos y descubra semanas después que
		// tres nunca llegaron.
		const r = cabe('PRE_DANO', 900_000, 0, MAX_BYTES_CARGA - 100);

		expect(r.ok).toBe(false);
		expect(r.ok === false && r.motivo).toContain('Quite alguna foto o video');
	});

	it('el límite es «cabe justo», no «se pasa por uno»', () => {
		expect(cabe('PRE_DANO', MAX_BYTES_FOTO, 0, 0)).toEqual({ ok: true });
		expect(cabe('PRE_DANO', MAX_BYTES_FOTO + 1, 0, 0).ok).toBe(false);
	});
});
