// El guión, cargado una sola vez y compartido.
//
// Lo leen dos pantallas: el panel de la derecha —mientras se mira la lista— y
// la pantalla de atender una llamada, que lo lleva dentro. Si cada una lo
// pidiera por su cuenta, abrir un hogar dispararía una segunda petición para
// traer un texto que ya estaba en memoria, y durante un segundo se vería vacío
// justo al empezar la llamada.

import { callCenterApi } from '$lib/api/servicios';
import type { GuionVigente } from './tipos';

class AlmacenGuion {
	guion = $state<GuionVigente | null>(null);

	/**
	 * El WhatsApp oficial al que hay que mandar al ciudadano.
	 *
	 * Va aparte del texto del guión porque la pantalla lo pinta grande y con
	 * botón de copiar: es el mismo número en las mil trescientas llamadas.
	 * Aunque el administrador reescriba el guión entero, esto sigue estando.
	 */
	whatsappOficial = $state('');
	/** El texto original del sistema, para «restaurar». */
	predeterminado = $state('');
	cargando = $state(false);
	error = $state('');

	/** Evita que dos componentes montados a la vez pidan lo mismo dos veces. */
	private enCurso: Promise<void> | null = null;

	async cargar(forzar = false): Promise<void> {
		if (!forzar && this.guion !== null) return;
		if (this.enCurso !== null) return this.enCurso;

		this.cargando = true;
		this.error = '';

		this.enCurso = (async () => {
			try {
				const r = await callCenterApi.guion();
				this.guion = r.guion;
				this.whatsappOficial = r.whatsapp_oficial ?? '';
				this.predeterminado = r.predeterminado;
			} catch (e) {
				this.error = e instanceof Error ? e.message : 'No se pudo cargar el guión.';
			} finally {
				this.cargando = false;
				this.enCurso = null;
			}
		})();

		return this.enCurso;
	}

	/** Tras guardar una versión nueva, para que las dos pantallas la vean ya. */
	fijar(g: GuionVigente): void {
		this.guion = g;
	}
}

export const almacenGuion = new AlmacenGuion();
