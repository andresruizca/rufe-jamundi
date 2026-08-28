// Lo que impide que una familia pierda diez fotos por salirse sin querer.
//
// Estas pruebas existen por un fallo real: el identificador del envío se
// generaba de nuevo en cada carga de la página, y de él cuelga la clave con la
// que las fotos viven en IndexedDB. Al volver se buscaban donde no había nada.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { datosVacios } from './pasos';

// Las pruebas corren en Node, sin navegador. Se suplanta el almacenamiento con
// un mapa, igual que en `sesionCache.spec.ts`.
const almacen = new Map<string, string>();

vi.stubGlobal('localStorage', {
	getItem: (k: string) => almacen.get(k) ?? null,
	setItem: (k: string, v: string) => void almacen.set(k, v),
	removeItem: (k: string) => void almacen.delete(k),
	clear: () => almacen.clear()
});

vi.mock('$app/environment', () => ({ browser: true }));

const {
	borrar,
	cuandoFue,
	DIAS_VIGENCIA,
	estaCaducado,
	guardar,
	leer,
	nuevoEnvioId,
	valeLaPena
} = await import('./borrador');

type BorradorPre = Awaited<ReturnType<typeof leer>> extends infer T
	? T extends null
		? never
		: T
	: never;

function borradorDe(extra: Partial<BorradorPre> = {}) {
	return {
		envioId: 'e-1',
		carga: 'c'.repeat(64),
		datos: datosVacios(),
		personas: [],
		hogar: null,
		indice: 0,
		videosListos: [],
		...extra
	};
}

beforeEach(() => {
	almacen.clear();
});

describe('el borrador de la pre-inscripción', () => {
	it('devuelve el MISMO envío entre visitas', () => {
		// Es el fallo que hizo perder fotos a una familia: el envío se generaba
		// de nuevo en cada carga, y como de él cuelga la clave con la que las
		// fotos viven en IndexedDB, al volver se buscaban donde no había nada.
		guardar(borradorDe({ envioId: 'el-de-siempre' }));

		expect(leer()?.envioId).toBe('el-de-siempre');
	});

	it('conserva el token de la carga', () => {
		// Sin él se abre una carga nueva al volver, y las fotos y videos que ya
		// estaban en el servidor quedan huérfanos hasta que la purga se los lleva.
		guardar(borradorDe({ carga: 'a'.repeat(64) }));

		expect(leer()?.carga).toBe('a'.repeat(64));
	});

	it('conserva el paso, lo escrito y el hogar', () => {
		const datos = { ...datosVacios(), nombre_completo: 'Martha Londoño', telefono: '3183333510' };

		guardar(borradorDe({ datos, indice: 2, videosListos: [7] }));

		const recuperado = leer();

		expect(recuperado?.datos.nombre_completo).toBe('Martha Londoño');
		expect(recuperado?.indice).toBe(2);
		expect(recuperado?.videosListos).toEqual([7]);
	});

	it('sin nada guardado no devuelve nada', () => {
		expect(leer()).toBeNull();
	});

	it('lo que no tiene la forma esperada se descarta', () => {
		// Una versión anterior del formulario, o algo tocado a mano. Arrancar con
		// un objeto a medias produce errores que la persona no puede arreglar.
		almacen.set('sgr_preinscripcion_borrador_v1', '{"envioId":123}');

		expect(leer()).toBeNull();
	});

	it('un borrador viejo se descarta y se limpia solo', () => {
		// Lleva cédula, nombres y dirección de una familia damnificada. Un
		// teléfono se presta.
		const viejo = new Date(Date.now() - (DIAS_VIGENCIA + 1) * 86400_000).toISOString();

		almacen.set(
			'sgr_preinscripcion_borrador_v1',
			JSON.stringify({ ...borradorDe(), actualizado_en: viejo })
		);

		expect(leer()).toBeNull();
		expect(almacen.get('sgr_preinscripcion_borrador_v1')).toBeUndefined();
	});

	it('borrar lo quita', () => {
		guardar(borradorDe());
		borrar();

		expect(leer()).toBeNull();
	});

	it('caduca a los siete días, no antes', () => {
		const casi = new Date(Date.now() - (DIAS_VIGENCIA - 1) * 86400_000).toISOString();
		const pasado = new Date(Date.now() - (DIAS_VIGENCIA + 1) * 86400_000).toISOString();

		expect(estaCaducado(casi)).toBe(false);
		expect(estaCaducado(pasado)).toBe(true);
	});

	it('un envío nuevo nunca repite el anterior', () => {
		expect(nuevoEnvioId()).not.toBe(nuevoEnvioId());
	});
});

describe('cuándo vale la pena ofrecer lo guardado', () => {
	it('solo la cédula no es trabajo que nadie eche de menos', () => {
		// La escribe la puerta sola. Avisar de que «recuperamos» eso sería ruido.
		const solo = { ...borradorDe(), actualizado_en: new Date().toISOString() };
		solo.datos.documento = '16844290';

		expect(valeLaPena(solo, false)).toBe(false);
	});

	it('con fotos siempre vale la pena', () => {
		const b = { ...borradorDe(), actualizado_en: new Date().toISOString() };

		expect(valeLaPena(b, true)).toBe(true);
	});

	it('con algo escrito también', () => {
		const b = { ...borradorDe(), actualizado_en: new Date().toISOString() };
		b.datos.direccion = 'Terranova, casa 12';

		expect(valeLaPena(b, false)).toBe(true);
	});
});

describe('cómo se le dice a la persona cuánto hace', () => {
	it('lo reciente se dice en minutos', () => {
		vi.useFakeTimers();
		const hace = new Date(Date.now() - 3 * 60_000).toISOString();

		expect(cuandoFue(hace)).toBe('hace 3 minutos');
		vi.useRealTimers();
	});

	it('lo de hace segundos no dice «hace 0 minutos»', () => {
		expect(cuandoFue(new Date().toISOString())).toBe('hace un momento');
	});

	it('a partir de una hora se dice en horas', () => {
		const hace = new Date(Date.now() - 3 * 3600_000).toISOString();

		expect(cuandoFue(hace)).toBe('hace 3 horas');
	});
});
