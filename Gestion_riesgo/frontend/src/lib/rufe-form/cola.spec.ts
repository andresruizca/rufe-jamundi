// La cola es la pieza más delicada del formulario: guarda datos de hogares
// damnificados que todavía no llegaron al servidor. Si se pierde, se pierde el
// trabajo de una jornada de campo y no hay forma de recuperarlo.
//
// Se prueba contra una IndexedDB real en memoria (fake-indexeddb), no con dobles
// de prueba: lo que interesa comprobar es el comportamiento del almacén —claves,
// índices, transacciones—, y un doble lo daría por bueno por construcción.

import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import {
	borrarFicha,
	borrarFotosDe,
	espejarToken,
	fichasPendientes,
	fotosDe,
	guardarFicha,
	guardarFoto,
	leerFicha,
	todasLasFichas,
	tokenEspejado,
	type FichaEnCola
} from './cola';

function ficha(envioId: string, cambios: Partial<FichaEnCola> = {}): FichaEnCola {
	return {
		envioId,
		cuerpo: { evento: 'Terremoto', direccion: 'Calle 10 # 5-32' },
		estado: 'pendiente',
		intentos: 0,
		creadoEn: Date.now(),
		actualizadoEn: Date.now(),
		resumen: { evento: 'Terremoto', direccion: 'Calle 10 # 5-32', personas: 3 },
		...cambios
	};
}

async function vaciar() {
	for (const f of await todasLasFichas()) await borrarFicha(f.envioId);
	await espejarToken(null);
}

beforeEach(vaciar);

describe('fichas en cola', () => {
	it('guarda y recupera una ficha', async () => {
		await guardarFicha(ficha('a1'));

		const leida = await leerFicha('a1');
		expect(leida?.envioId).toBe('a1');
		expect(leida?.resumen.personas).toBe(3);
	});

	it('el envioId es la clave: guardar dos veces actualiza, no duplica', async () => {
		await guardarFicha(ficha('a1'));
		await guardarFicha(ficha('a1', { intentos: 5 }));

		expect(await todasLasFichas()).toHaveLength(1);
		expect((await leerFicha('a1'))?.intentos).toBe(5);
	});

	it('pendientes trae las que faltan por salir, y no las enviadas', async () => {
		await guardarFicha(ficha('a1', { estado: 'pendiente' }));
		await guardarFicha(ficha('a2', { estado: 'error' }));
		await guardarFicha(ficha('a3', { estado: 'enviada' }));
		await guardarFicha(ficha('a4', { estado: 'enviando' }));

		const ids = (await fichasPendientes()).map((f) => f.envioId);
		expect(ids).toEqual(expect.arrayContaining(['a1', 'a2']));
		expect(ids).not.toContain('a3');
	});

	it('salen en el orden en que se levantaron', async () => {
		await guardarFicha(ficha('nueva', { creadoEn: 3000 }));
		await guardarFicha(ficha('vieja', { creadoEn: 1000 }));
		await guardarFicha(ficha('media', { creadoEn: 2000 }));

		expect((await fichasPendientes()).map((f) => f.envioId)).toEqual(['vieja', 'media', 'nueva']);
	});

	it('borrar una ficha se lleva sus fotos', async () => {
		await guardarFicha(ficha('a1'));
		await guardarFoto({
			uid: 'f1',
			envioId: 'a1',
			tipo: 'DANO',
			nombre: 'x.webp',
			mime: 'image/webp',
			blob: new Blob(['x']),
			subida: false
		});

		expect(await fotosDe('a1')).toHaveLength(1);

		await borrarFicha('a1');

		expect(await leerFicha('a1')).toBeNull();
		expect(await fotosDe('a1')).toHaveLength(0);
	});
});

describe('fotos en cola', () => {
	it('cada foto queda atada a su ficha', async () => {
		for (const [uid, envio] of [
			['f1', 'a1'],
			['f2', 'a1'],
			['f3', 'a2']
		]) {
			await guardarFoto({
				uid,
				envioId: envio,
				tipo: 'DANO',
				nombre: `${uid}.webp`,
				mime: 'image/webp',
				blob: new Blob(['x']),
				subida: false
			});
		}

		expect(await fotosDe('a1')).toHaveLength(2);
		expect(await fotosDe('a2')).toHaveLength(1);

		await borrarFotosDe('a1');

		expect(await fotosDe('a1')).toHaveLength(0);
		// Borrar las de una ficha no debe tocar las de otra.
		expect(await fotosDe('a2')).toHaveLength(1);
	});

	it('conserva el binario, no solo los metadatos', async () => {
		await guardarFoto({
			uid: 'f1',
			envioId: 'a1',
			tipo: 'DOCUMENTO',
			nombre: 'cedula.webp',
			mime: 'image/webp',
			blob: new Blob(['contenido de prueba']),
			subida: false
		});

		const [foto] = await fotosDe('a1');
		expect(foto.tipo).toBe('DOCUMENTO');
		expect(await foto.blob.text()).toBe('contenido de prueba');
	});
});

describe('espejo del token', () => {
	// El Service Worker no puede leer localStorage. Sin este espejo no podría
	// enviar nada con la aplicación cerrada, que es el punto de todo esto.
	it('guarda y recupera el token', async () => {
		await espejarToken('abc123');
		expect(await tokenEspejado()).toBe('abc123');
	});

	it('cerrar sesión lo borra', async () => {
		await espejarToken('abc123');
		await espejarToken(null);
		expect(await tokenEspejado()).toBeNull();
	});

	it('sin token guardado devuelve null, no revienta', async () => {
		expect(await tokenEspejado()).toBeNull();
	});
});
