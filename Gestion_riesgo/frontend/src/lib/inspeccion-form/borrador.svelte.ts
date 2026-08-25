// Autoguardado de las inspecciones en curso.
//
// Una inspección se llena de pie en la puerta de una casa y lleva un rato: la
// tabla del 5.4 sola son siete elementos con su nivel. Perderla por una llamada
// entrante, una batería que se apaga o un toque en «atrás» significa repetir la
// visita, y esa visita cuesta un desplazamiento a una vereda.
//
// La caja donde se guardan —varias a la vez, ordenadas, con caducidad— es la de
// `$lib/borradores`, compartida con el RUFE. Aquí queda lo que es propio de
// este formato: con qué nombre se reconoce cada inspección en la lista.

import { browser } from '$app/environment';
import {
	crearAlmacen,
	diasQueLeQuedan as diasRestantes,
	haceCuanto,
	uid,
	type BorradorBase
} from '$lib/borradores';
import type { FormularioInspeccion } from './tipos';
import type { IdPaso } from './esquema';

export const CLAVE_ALMACEN = 'sgr_inspeccion_borradores_v2';

/** Donde vivía el borrador único, antes de que cupieran varios. */
export const CLAVE_ALMACEN_V1 = 'sgr_inspeccion_borrador_v1';

const VERSION = 2;
const DIAS_VIGENCIA = 7;
const RETARDO_MS = 800;

export type EstadoGuardado = 'sin-cambios' | 'guardando' | 'guardado' | 'error' | 'recuperado';

export type BorradorGuardado = BorradorBase<FormularioInspeccion> & { paso: IdPaso };

const almacen = crearAlmacen<FormularioInspeccion>({
	clave: CLAVE_ALMACEN,
	claveAnterior: CLAVE_ALMACEN_V1,
	version: VERSION,
	diasVigencia: DIAS_VIGENCIA
});

export const leerBorradores = (ahora?: number): BorradorGuardado[] =>
	almacen.leer(ahora) as BorradorGuardado[];

export const leerBorrador = (clave: string, ahora?: number): BorradorGuardado | null =>
	almacen.leerUno(clave, ahora) as BorradorGuardado | null;

export const guardarBorrador = (b: BorradorGuardado, ahora?: number): boolean =>
	almacen.guardar(b, ahora);

/**
 * Descarta una.
 *
 * NO borra sus fotos: viven en IndexedDB atadas a la misma clave y quien llama
 * tiene que encargarse. Se deja escrito porque olvidarlo deja megabytes de
 * fotos de casas ajenas en un aparato que se presta.
 */
export const descartarBorrador = (clave: string): void => almacen.descartar(clave);

export { uid, haceCuanto };

/** Cuándo deja de poder retomarse, para poder avisarlo antes de que pase. */
export function diasQueLeQuedan(b: BorradorGuardado, ahora = Date.now()): number {
	return diasRestantes(b.expira_en, ahora);
}

// ── Cómo se reconoce cada una ────────────────────────────────────────────────

export type SenasBorrador = {
	/** A nombre de quién. Es lo que se lee primero. */
	titulo: string;
	/** Dónde queda. Distingue dos casas del mismo apellido. */
	lugar: string;
	/** `true` cuando aún no hay nada con qué nombrarla. */
	anonima: boolean;
};

/**
 * Con qué nombre aparece una inspección en la lista.
 *
 * El propietario y la dirección son del numeral 3, de los primeros que se
 * llenan, así que casi siempre hay algo. Cuando no lo hay se dice —«Sin datos
 * del propietario todavía»— en vez de inventar un nombre: quien tiene que
 * decidir si la descarta necesita saber que no puede identificarla, no una
 * etiqueta que parezca un dato.
 */
export function senasDe(b: BorradorGuardado): SenasBorrador {
	const d = b.datos;
	const nombre = (d?.propietario_nombres ?? '').trim();

	const lugar =
		(d?.direccion_cabecera ?? '').trim() ||
		[(d?.corregimiento ?? '').trim(), (d?.vereda ?? '').trim()].filter(Boolean).join(' · ');

	return {
		titulo: nombre || 'Sin datos del propietario todavía',
		lugar,
		anonima: nombre === ''
	};
}

export class GestorBorrador {
	estado = $state<EstadoGuardado>('sin-cambios');
	clave = $state<string>('');
	guardadoEn = $state<number | null>(null);

	#temporizador: ReturnType<typeof setTimeout> | null = null;

	constructor(clave?: string) {
		this.clave = clave ?? uid();
	}

	marcarRecuperado(cuando: number): void {
		this.estado = 'recuperado';
		this.guardadoEn = cuando;
	}

	/**
	 * Programa el guardado.
	 *
	 * Con retardo y no en cada tecla: escribir en localStorage es síncrono y
	 * hacerlo en cada pulsación se nota en un teléfono de gama baja justo cuando
	 * alguien está escribiendo una dirección larga.
	 */
	programar(datos: FormularioInspeccion, paso: IdPaso): void {
		if (!browser) return;

		this.estado = 'guardando';

		if (this.#temporizador) clearTimeout(this.#temporizador);
		this.#temporizador = setTimeout(() => this.guardar(datos, paso), RETARDO_MS);
	}

	guardar(datos: FormularioInspeccion, paso: IdPaso): void {
		if (!browser) return;

		const ahora = Date.now();

		const ok = almacen.guardar(
			{
				version: VERSION,
				clave: this.clave,
				actualizado_en: ahora,
				expira_en: ahora + almacen.vigenciaMs,
				paso,
				datos: $state.snapshot(datos)
			},
			ahora
		);

		if (ok) {
			this.estado = 'guardado';
			this.guardadoEn = ahora;
		} else {
			// Sin espacio o con almacenamiento bloqueado. Se avisa en pantalla: es
			// la diferencia entre saber que hay que terminar de una sentada y
			// creerse a salvo.
			this.estado = 'error';
		}
	}

	detener(): void {
		if (this.#temporizador) clearTimeout(this.#temporizador);
		this.#temporizador = null;
	}
}

export function describirEstado(estado: EstadoGuardado, guardadoEn: number | null): string {
	switch (estado) {
		case 'guardando':
			return 'Guardando…';
		case 'guardado':
			return guardadoEn
				? `Guardado a las ${new Date(guardadoEn).toLocaleTimeString('es-CO', {
						hour: '2-digit',
						minute: '2-digit'
					})}`
				: 'Guardado';
		case 'recuperado':
			return 'Se recuperó una inspección sin terminar.';
		case 'error':
			return 'No se pudo guardar en este dispositivo. Termine sin cerrar la aplicación.';
		default:
			return '';
	}
}
