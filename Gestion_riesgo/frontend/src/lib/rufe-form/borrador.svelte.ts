// Autoguardado del formulario RUFE en el dispositivo del ciudadano.
//
// El borrador NO va al servidor. Guardar nombres, documentos y pertenencia
// étnica de terceros antes de que el ciudadano acepte el aviso de tratamiento
// sería recolectar datos sensibles sin base legal, así que el reporte solo
// existe en este navegador hasta que se pulsa Enviar.
//
// Las casillas de autorización se excluyen a propósito de lo que se guarda: el
// consentimiento debe darse en la sesión del envío, no heredarse de un borrador
// de hace tres días.
//
// ── Por qué caben VARIAS ─────────────────────────────────────────────────────
//
// Antes solo cabía una y la pantalla decía «Hay una ficha sin terminar» sin
// decir de quién. Una brigada levanta varias casas seguidas y deja alguna a
// medias porque falta un documento: con un solo borrador, la siguiente pisaba a
// la anterior, y como no tenía nombre, la única forma de saber qué se iba a
// perder era abrirla.
//
// La caja donde se guardan es la de `$lib/borradores`, la misma del formato de
// inspección. Aquí queda lo propio del RUFE: qué campos no se persisten nunca y
// con qué nombre se reconoce cada ficha.

import { browser } from '$app/environment';
import {
	crearAlmacen,
	diasQueLeQuedan as diasRestantes,
	haceCuanto,
	type BorradorBase
} from '$lib/borradores';
import type { FormularioRufe } from './tipos';
import type { IdPaso } from './esquema';
import { uid } from './esquema';

export const CLAVE_ALMACEN = 'sgr_rufe_borradores_v2';

/** Donde vivía el borrador único, antes de que cupieran varias. */
export const CLAVE_ALMACEN_V1 = 'sgr_rufe_borrador_v1';

const VERSION = 2;
const DIAS_VIGENCIA = 7;
const DEBOUNCE_MS = 800;

export type EstadoGuardado = 'sin-cambios' | 'guardando' | 'guardado' | 'error' | 'recuperado';

export type BorradorGuardado = BorradorBase<FormularioRufe> & { paso: IdPaso };

/** Campos que nunca se persisten. */
const NO_PERSISTIR = ['autoriza_tratamiento'] as const;

function limpiarParaGuardar(d: FormularioRufe): FormularioRufe {
	const copia = structuredClone($state.snapshot(d)) as FormularioRufe;
	for (const campo of NO_PERSISTIR) copia[campo] = false;

	return copia;
}

const almacen = crearAlmacen<FormularioRufe>({
	clave: CLAVE_ALMACEN,
	claveAnterior: CLAVE_ALMACEN_V1,
	version: VERSION,
	diasVigencia: DIAS_VIGENCIA
});

export const leerBorradores = (ahora?: number): BorradorGuardado[] =>
	almacen.leer(ahora) as BorradorGuardado[];

export const leerBorrador = (clave: string, ahora?: number): BorradorGuardado | null =>
	almacen.leerUno(clave, ahora) as BorradorGuardado | null;

/**
 * Descarta una.
 *
 * NO borra sus fotos: viven en IndexedDB atadas a la misma clave y quien llama
 * tiene que encargarse. Sin eso quedan megabytes de fotos de casas ajenas en un
 * aparato que se presta.
 */
export const descartarBorrador = (clave: string): void => almacen.descartar(clave);

export { haceCuanto };

/**
 * Arma un borrador nuevo con datos ya escritos y devuelve su clave.
 *
 * Nace de «Convertir a ficha RUFE», en la bandeja de quien no aparece en el
 * censo: el funcionario decide que el caso es real y esto le ahorra
 * volver a teclear el nombre, el teléfono y la ubicación que la persona ya
 * dejó. El resto de la ficha —personas del hogar, daños, fotos— lo completa
 * él, en campo o por teléfono.
 *
 * No es una ruta nueva ni un campo de servidor: se guarda exactamente como
 * cualquier otro borrador —solo en este navegador— y aparece solo donde ya
 * aparecen los demás, en la lista de fichas sin terminar de `/riesgo/reportar`.
 */
export function crearBorradorDesdeSolicitud(datos: FormularioRufe): string {
	const clave = uid();
	const ahora = Date.now();

	almacen.guardar(
		{
			version: VERSION,
			clave,
			actualizado_en: ahora,
			expira_en: ahora + almacen.vigenciaMs,
			paso: 'ubicacion',
			datos: limpiarParaGuardar(datos)
		},
		ahora
	);

	return clave;
}

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
 * Con qué nombre aparece una ficha en la lista.
 *
 * El jefe de hogar es la primera persona de la lista del numeral 6 y la
 * dirección es del 4: los dos se llenan antes que casi todo lo demás, así que
 * casi siempre hay con qué nombrarla.
 *
 * Cuando no hay nada se dice —«Sin datos del hogar todavía»— en vez de inventar
 * un nombre: quien decide si la descarta necesita saber que no puede
 * identificarla, no una etiqueta que parezca un dato.
 */
export function senasDe(b: BorradorGuardado): SenasBorrador {
	const d = b.datos;
	const jefe = d?.personas?.[0];

	const nombre = [(jefe?.nombres ?? '').trim(), (jefe?.apellidos ?? '').trim()]
		.filter(Boolean)
		.join(' ')
		.trim();

	const lugar =
		(d?.direccion ?? '').trim() ||
		[(d?.corregimiento ?? '').trim(), (d?.vereda_sector_barrio ?? '').trim()]
			.filter(Boolean)
			.join(' · ');

	return {
		titulo: nombre || 'Sin datos del hogar todavía',
		lugar,
		anonima: nombre === ''
	};
}

/**
 * Gestor del autoguardado. Se instancia uno por formulario.
 *
 * Es una clase y no un singleton de módulo porque el temporizador y los oyentes
 * deben poder desmontarse con la página; un singleton dejaría el `debounce`
 * corriendo tras salir del formulario.
 */
export class GestorBorrador {
	estado = $state<EstadoGuardado>('sin-cambios');
	clave = $state<string>('');
	guardadoEn = $state<number | null>(null);

	/**
	 * Otra pestaña está editando el mismo borrador. Se pasa a solo lectura en vez
	 * de fusionar: dos versiones del mismo hogar no se pueden combinar sin
	 * inventarse cuál gana, y el ciudadano no debería tener que decidirlo.
	 */
	otraPestana = $state(false);

	#temporizador: ReturnType<typeof setTimeout> | null = null;
	#alCambiarAlmacen: ((e: StorageEvent) => void) | null = null;

	constructor(clave?: string) {
		this.clave = clave ?? uid();
	}

	/** Empieza a vigilar cambios de otras pestañas. Devuelve la función de limpieza. */
	iniciar(): () => void {
		if (!browser) return () => {};

		this.#alCambiarAlmacen = (e: StorageEvent) => {
			if (e.key !== CLAVE_ALMACEN || !e.newValue) return;

			try {
				// Ahora la caja guarda una lista, así que hay que buscar la propia:
				// otra pestaña trabajando en OTRA ficha ya no es un conflicto — es
				// justamente lo que este cambio vino a permitir.
				const lista = JSON.parse(e.newValue) as BorradorGuardado[];
				const otro = Array.isArray(lista) ? lista.find((b) => b.clave === this.clave) : null;
				if (!otro) return;

				if (this.guardadoEn !== null && otro.actualizado_en > this.guardadoEn + 50) {
					this.otraPestana = true;
				}
			} catch {
				/* un borrador ilegible de otra pestaña no es asunto de esta */
			}
		};

		window.addEventListener('storage', this.#alCambiarAlmacen);

		return () => this.detener();
	}

	detener(): void {
		if (this.#temporizador) clearTimeout(this.#temporizador);
		this.#temporizador = null;

		if (browser && this.#alCambiarAlmacen) {
			window.removeEventListener('storage', this.#alCambiarAlmacen);
			this.#alCambiarAlmacen = null;
		}
	}

	/** Agenda un guardado. Llamar en cada cambio: el debounce evita escribir de más. */
	programar(datos: FormularioRufe, paso: IdPaso): void {
		if (!browser || this.otraPestana) return;

		this.estado = 'guardando';
		if (this.#temporizador) clearTimeout(this.#temporizador);
		this.#temporizador = setTimeout(() => this.guardarYa(datos, paso), DEBOUNCE_MS);
	}

	/** Guardado inmediato. Se usa al cambiar de paso y antes de cerrar la pestaña. */
	guardarYa(datos: FormularioRufe, paso: IdPaso): void {
		if (!browser || this.otraPestana) return;

		if (this.#temporizador) {
			clearTimeout(this.#temporizador);
			this.#temporizador = null;
		}

		const ahora = Date.now();

		const ok = almacen.guardar(
			{
				version: VERSION,
				clave: this.clave,
				actualizado_en: ahora,
				expira_en: ahora + almacen.vigenciaMs,
				paso,
				datos: limpiarParaGuardar(datos)
			},
			ahora
		);

		if (ok) {
			this.guardadoEn = ahora;
			this.estado = 'guardado';
		} else {
			// Cuota llena o almacenamiento bloqueado. Se avisa, pero el formulario
			// sigue usable: los datos están en memoria mientras no se recargue.
			this.estado = 'error';
		}
	}

	/** Suelta ESTA ficha y estrena clave. Las demás siguen guardadas. */
	descartar(): void {
		if (this.#temporizador) clearTimeout(this.#temporizador);
		this.#temporizador = null;
		almacen.descartar(this.clave);
		this.clave = uid();
		this.guardadoEn = null;
		this.otraPestana = false;
		this.estado = 'sin-cambios';
	}

	marcarRecuperado(cuando: number): void {
		this.guardadoEn = cuando;
		this.estado = 'recuperado';
	}
}

export function describirEstado(estado: EstadoGuardado, guardadoEn: number | null): string {
	switch (estado) {
		case 'guardando':
			return 'Guardando…';
		case 'guardado':
			return guardadoEn
				? `Guardado en este dispositivo · ${hora(guardadoEn)}`
				: 'Guardado en este dispositivo';
		case 'error':
			return 'No se pudo guardar en este dispositivo';
		case 'recuperado':
			return 'Reporte recuperado';
		default:
			return 'Sin cambios por guardar';
	}
}

function hora(ms: number): string {
	return new Date(ms).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
}
