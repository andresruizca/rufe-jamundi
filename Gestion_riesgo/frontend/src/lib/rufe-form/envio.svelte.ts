// Envío del reporte, tolerante a quedarse sin señal.
//
// Un teléfono en zona de emergencia pierde cobertura a mitad del trámite. La
// ficha se guarda en el dispositivo y sale sola en cuanto hay red — y, desde que
// existe el Service Worker, también cuando el censador ya cerró la aplicación.
//
// Reparto de responsabilidades:
//
//   cola.ts             guarda la ficha y sus fotos en IndexedDB
//   service-worker.ts   las envía, incluso con la aplicación cerrada
//   este archivo        coordina, y reintenta en primer plano donde no hay
//                       Background Sync (Firefox y Safari no lo implementan)
//
// Lo que hace seguro reintentar es el `envio_id`: se genera una sola vez por
// ficha y viaja en cada intento. Si la ficha ya entró pero se perdió la
// respuesta, el servidor devuelve el radicado original en vez de registrar dos
// veces al mismo hogar.

import { browser } from '$app/environment';
import { ApiError } from '$lib/api/client';
import { rufeApi } from '$lib/api/servicios';
import type { RespuestaEnvio } from './tipos';
import { uid } from './esquema';
import {
	borrarFicha,
	fichasPendientes,
	guardarFicha,
	leerFicha,
	pedirAlmacenamientoPersistente,
	pedirEnvioEnSegundoPlano,
	todasLasFichas,
	type FichaEnCola
} from './cola';

/** Una ficha en cola no se guarda para siempre: pasada una semana ya no sirve. */
const DIAS_VIGENCIA = 7;

/** Latido de reintento donde no hay Background Sync. */
const MS_REINTENTO = 30000;

export type EstadoEnvio = 'inactivo' | 'enviando' | 'en-cola' | 'enviado' | 'error';

export class GestorEnvio {
	estado = $state<EstadoEnvio>('inactivo');
	error = $state<string | null>(null);
	respuesta = $state<RespuestaEnvio | null>(null);
	intentos = $state(0);

	/** Cuántas fichas esperan salir, contando las de sesiones anteriores. */
	pendientes = $state(0);

	/** true cuando el navegador se encarga solo, aunque se cierre la aplicación. */
	enSegundoPlano = $state(false);

	/** La sesión venció y hay fichas esperando. */
	sesionRequerida = $state(false);

	readonly enCola = $derived(this.estado === 'en-cola');

	#envioId: string | null = null;
	#alVolverLaRed: (() => void) | null = null;
	#alMensaje: ((e: MessageEvent) => void) | null = null;
	#temporizador: ReturnType<typeof setInterval> | null = null;

	/**
	 * Arranca la vigilancia y retoma lo que quedara de una visita anterior.
	 * Devuelve la función de limpieza.
	 */
	iniciar(): () => void {
		if (!browser) return () => {};

		void this.#prepararse();

		this.#alVolverLaRed = () => void this.reintentarPendiente();
		window.addEventListener('online', this.#alVolverLaRed);

		// El Service Worker avisa cuando la cola cambia o cuando hace falta
		// iniciar sesión, para que la pantalla no se quede contando mal.
		this.#alMensaje = (e: MessageEvent) => {
			if (e.data?.origen !== 'sgr-sw') return;

			if (e.data.tipo === 'sesion-requerida') this.sesionRequerida = true;
			void this.#contarPendientes();
		};
		navigator.serviceWorker?.addEventListener('message', this.#alMensaje);

		// `online` no basta: en redes móviles el navegador puede creerse conectado
		// sin salida real. Este latido cubre ese caso y, sobre todo, los
		// navegadores sin Background Sync.
		this.#temporizador = setInterval(() => void this.reintentarPendiente(), MS_REINTENTO);

		return () => this.detener();
	}

	async #prepararse(): Promise<void> {
		await this.#purgarVencidas();
		await this.#contarPendientes();

		// Sin esto, IndexedDB se desaloja por «usado menos recientemente» y se
		// borra el origen entero: se perderían todas las fichas sin enviar.
		await pedirAlmacenamientoPersistente();

		if (this.pendientes > 0) {
			this.estado = 'en-cola';
			void this.reintentarPendiente();
		}
	}

	detener(): void {
		if (browser && this.#alVolverLaRed) {
			window.removeEventListener('online', this.#alVolverLaRed);
			this.#alVolverLaRed = null;
		}
		if (browser && this.#alMensaje) {
			navigator.serviceWorker?.removeEventListener('message', this.#alMensaje);
			this.#alMensaje = null;
		}
		if (this.#temporizador) clearInterval(this.#temporizador);
		this.#temporizador = null;
	}

	/**
	 * Encola la ficha y trata de enviarla.
	 *
	 * Devuelve null cuando quedó en cola por falta de red: no es un error, y la
	 * pantalla debe decirlo con sus propias palabras.
	 */
	async enviar(
		cuerpo: Record<string, unknown>,
		resumen: FichaEnCola['resumen']
	): Promise<RespuestaEnvio | null> {
		this.#envioId ??= uid();

		const ficha: FichaEnCola = {
			envioId: this.#envioId,
			cuerpo,
			estado: 'pendiente',
			intentos: 0,
			creadoEn: Date.now(),
			actualizadoEn: Date.now(),
			resumen
		};

		await guardarFicha(ficha);
		await this.#contarPendientes();

		// Se le pide al navegador que se encargue aunque cerremos la aplicación.
		// Donde no hay soporte, queda el latido de arriba.
		this.enSegundoPlano = await pedirEnvioEnSegundoPlano();

		return this.#intentar(ficha);
	}

	/** Reintenta lo que haya en cola. No hace nada si no hay nada o ya está en curso. */
	async reintentarPendiente(): Promise<RespuestaEnvio | null> {
		if (this.estado === 'enviando') return null;
		if (browser && !navigator.onLine) return null;

		// Con Service Worker activo, es él quien envía: aquí solo se le da un
		// empujón para no esperar al evento del navegador.
		if (this.enSegundoPlano && browser) {
			navigator.serviceWorker?.controller?.postMessage({ tipo: 'enviar-pendientes' });
		}

		const pendientes = await fichasPendientes();
		if (pendientes.length === 0) {
			await this.#contarPendientes();

			return null;
		}

		return this.#intentar(pendientes[0]);
	}

	descartar(): void {
		this.#envioId = null;
		this.estado = 'inactivo';
		this.error = null;
		this.intentos = 0;
		this.sesionRequerida = false;
	}

	async #intentar(ficha: FichaEnCola): Promise<RespuestaEnvio | null> {
		this.estado = 'enviando';
		this.error = null;

		ficha.intentos += 1;
		ficha.actualizadoEn = Date.now();
		this.intentos = ficha.intentos;
		await guardarFicha(ficha);

		try {
			const respuesta = await rufeApi.enviarReporte({
				...ficha.cuerpo,
				envio_id: ficha.envioId
			});

			this.respuesta = respuesta;
			this.estado = 'enviado';
			this.sesionRequerida = false;
			await borrarFicha(ficha.envioId);
			await this.#contarPendientes();

			return respuesta;
		} catch (e) {
			// La sesión venció: la ficha se queda intacta y se pide entrar de nuevo.
			// No es culpa del dato ni de la red.
			if (e instanceof ApiError && e.status === 401) {
				this.sesionRequerida = true;
				this.estado = 'en-cola';
				await this.#contarPendientes();

				return null;
			}

			// Red caída o servidor caído: los dos se resuelven esperando.
			if (e instanceof ApiError && (e.status === 0 || e.status >= 500)) {
				this.estado = 'en-cola';
				this.error = null;
				await this.#contarPendientes();

				return null;
			}

			// 4xx: los datos no sirven. Reintentarlos daría igual.
			await borrarFicha(ficha.envioId);
			await this.#contarPendientes();
			this.estado = 'error';
			this.error = e instanceof ApiError ? e.message : 'No se pudo enviar la ficha.';

			throw e;
		}
	}

	async #contarPendientes(): Promise<void> {
		this.pendientes = (await fichasPendientes()).length;
	}

	async #purgarVencidas(): Promise<void> {
		const limite = Date.now() - DIAS_VIGENCIA * 86400000;

		for (const f of await todasLasFichas()) {
			if (f.estado === 'enviada' || f.creadoEn < limite) {
				await borrarFicha(f.envioId);
			}
		}
	}
}

/** ¿Hay algo esperando? Lo usa la pantalla para retomar sin instanciar el gestor. */
export async function hayFichasPendientes(): Promise<number> {
	return (await fichasPendientes()).length;
}

export { leerFicha };
