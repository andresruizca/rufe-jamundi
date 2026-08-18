// Envío del reporte, tolerante a quedarse sin señal.
//
// Un teléfono en zona de emergencia pierde cobertura a mitad del trámite. Si el
// envío fallara sin más, el ciudadano tendría que acordarse de volver a entrar
// cuando le vuelva la raya, que es justo lo que no va a pasar. Así que el envío
// se guarda en el dispositivo y sale solo en cuanto hay red.
//
// Lo que hace seguro reintentar es el `envio_id`: se genera una sola vez por
// reporte y viaja en cada intento. Si el reporte ya entró pero la respuesta se
// perdió, el servidor reconoce ese identificador y devuelve el radicado original
// en vez de registrar dos veces al mismo hogar.

import { browser } from '$app/environment';
import { ApiError } from '$lib/api/client';
import { rufeApi } from '$lib/api/servicios';
import type { RespuestaEnvio } from './tipos';
import { uid } from './esquema';

export const CLAVE_ENVIO = 'sgr_rufe_envio_pendiente_v1';

/** Un envío en cola no se guarda para siempre: pasada una semana ya no sirve. */
const DIAS_VIGENCIA = 7;

/** Espera entre reintentos cuando el navegador se cree conectado pero no lo está. */
const MS_REINTENTO = 30000;

export type EnvioPendiente = {
	envioId: string;
	cuerpo: Record<string, unknown>;
	creadoEn: number;
	intentos: number;
};

export type EstadoEnvio = 'inactivo' | 'enviando' | 'en-cola' | 'enviado' | 'error';

export function leerEnvioPendiente(): EnvioPendiente | null {
	if (!browser) return null;

	try {
		const crudo = window.localStorage.getItem(CLAVE_ENVIO);
		if (!crudo) return null;

		const p = JSON.parse(crudo) as EnvioPendiente;
		if (!p.envioId || !p.cuerpo) return null;

		if (Date.now() - p.creadoEn > DIAS_VIGENCIA * 86400000) {
			window.localStorage.removeItem(CLAVE_ENVIO);

			return null;
		}

		return p;
	} catch {
		return null;
	}
}

export function borrarEnvioPendiente(): void {
	if (!browser) return;
	try {
		window.localStorage.removeItem(CLAVE_ENVIO);
	} catch {
		/* almacenamiento bloqueado: no hay nada que borrar */
	}
}

export class GestorEnvio {
	estado = $state<EstadoEnvio>('inactivo');
	error = $state<string | null>(null);
	respuesta = $state<RespuestaEnvio | null>(null);
	intentos = $state(0);

	/** El envío quedó guardado y saldrá solo. */
	readonly enCola = $derived(this.estado === 'en-cola');

	#alVolverLaRed: (() => void) | null = null;
	#temporizador: ReturnType<typeof setInterval> | null = null;

	/**
	 * Empieza a vigilar la conexión y retoma un envío que hubiera quedado en cola
	 * de una visita anterior. Devuelve la función de limpieza.
	 */
	iniciar(): () => void {
		if (!browser) return () => {};

		this.#alVolverLaRed = () => void this.reintentarPendiente();
		window.addEventListener('online', this.#alVolverLaRed);

		// `online` no es de fiar por sí solo: en redes móviles el navegador puede
		// creerse conectado sin salida real. Un latido de treinta segundos cubre
		// ese caso, y no hace nada mientras no haya un envío en cola.
		this.#temporizador = setInterval(() => void this.reintentarPendiente(), MS_REINTENTO);

		if (leerEnvioPendiente()) {
			this.estado = 'en-cola';
			void this.reintentarPendiente();
		}

		return () => this.detener();
	}

	detener(): void {
		if (browser && this.#alVolverLaRed) {
			window.removeEventListener('online', this.#alVolverLaRed);
			this.#alVolverLaRed = null;
		}
		if (this.#temporizador) clearInterval(this.#temporizador);
		this.#temporizador = null;
	}

	/**
	 * Manda el reporte. Si no hay red, lo deja en cola y devuelve null: la página
	 * debe mostrar que quedó pendiente, no que falló.
	 */
	async enviar(cuerpo: Record<string, unknown>): Promise<RespuestaEnvio | null> {
		const pendiente: EnvioPendiente = {
			// Se reaprovecha el identificador si ya había un envío en cola: es el
			// mismo reporte, no uno nuevo.
			envioId: leerEnvioPendiente()?.envioId ?? uid(),
			cuerpo,
			creadoEn: Date.now(),
			intentos: 0
		};

		this.#guardar(pendiente);

		return this.#intentar(pendiente);
	}

	/** Reintenta lo que haya en cola. No hace nada si no hay nada, o si ya está en curso. */
	async reintentarPendiente(): Promise<RespuestaEnvio | null> {
		if (this.estado === 'enviando' || this.estado === 'enviado') return null;
		if (browser && !navigator.onLine) return null;

		const pendiente = leerEnvioPendiente();
		if (!pendiente) return null;

		return this.#intentar(pendiente);
	}

	descartar(): void {
		borrarEnvioPendiente();
		this.estado = 'inactivo';
		this.error = null;
		this.intentos = 0;
	}

	async #intentar(pendiente: EnvioPendiente): Promise<RespuestaEnvio | null> {
		this.estado = 'enviando';
		this.error = null;

		pendiente.intentos += 1;
		this.intentos = pendiente.intentos;
		this.#guardar(pendiente);

		try {
			const respuesta = await rufeApi.enviarReporte({
				...pendiente.cuerpo,
				envio_id: pendiente.envioId
			});

			this.respuesta = respuesta;
			this.estado = 'enviado';
			borrarEnvioPendiente();

			return respuesta;
		} catch (e) {
			// status 0 es fallo de red y 5xx es servidor caído: los dos se resuelven
			// esperando, así que el envío se queda en cola. Un 4xx no: significa que
			// los datos no sirven y reintentar los mismos daría igual.
			const deRed = e instanceof ApiError && (e.status === 0 || e.status >= 500);

			if (deRed) {
				this.estado = 'en-cola';
				this.error = null;

				return null;
			}

			borrarEnvioPendiente();
			this.estado = 'error';
			this.error = e instanceof ApiError ? e.message : 'No se pudo enviar el reporte.';

			// El error de validación se propaga para que la página lleve al
			// ciudadano al campo que hay que corregir.
			throw e;
		}
	}

	#guardar(pendiente: EnvioPendiente): void {
		if (!browser) return;
		try {
			window.localStorage.setItem(CLAVE_ENVIO, JSON.stringify(pendiente));
		} catch {
			// Sin espacio en disco no se puede encolar; el envío directo aún puede
			// funcionar, así que no se interrumpe nada.
		}
	}
}
