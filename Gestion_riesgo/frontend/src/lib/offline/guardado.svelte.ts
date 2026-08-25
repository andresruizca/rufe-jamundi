// De cuándo son los datos que se están viendo.
//
// Desde que el sistema entero funciona sin señal, una pantalla de consulta puede
// estar dibujada con lo que se guardó en el aparato la última vez que hubo
// cobertura. Quien la mira tiene derecho a saberlo: se decide sobre familias
// damnificadas —a quién visitar, a quién entregarle materiales— y hacerlo
// creyendo ver el estado de hoy cuando es el de anteayer es peor que no verlo.
//
// El Service Worker marca lo que sirve de su copia con una cabecera; el cliente
// la lee en cada respuesta y esto guarda la más reciente para que el armazón lo
// enseñe. No hay nada que llamar desde las pantallas: se enteran solas.

/** La pone `service-worker.ts` al guardar. Los dos nombres tienen que coincidir. */
export const CABECERA_FECHA = 'X-SGR-Guardado';

class DatosGuardados {
	/** Cuándo se guardó lo último que se sirvió de la copia. `null` = viene de la red. */
	cuando = $state<Date | null>(null);

	/**
	 * Anota lo que dijo una respuesta.
	 *
	 * Cualquier respuesta SIN la cabecera limpia el aviso: significa que la red
	 * volvió y lo que se está viendo ya es de ahora. Dejarlo puesto sería el peor
	 * de los dos errores — mentir diciendo que un dato fresco es viejo hace
	 * desconfiar de todo lo demás.
	 */
	anotar(respuesta: Response): void {
		const marca = respuesta.headers.get(CABECERA_FECHA);

		if (marca === null) {
			this.cuando = null;

			return;
		}

		const fecha = new Date(marca);
		this.cuando = Number.isNaN(fecha.getTime()) ? null : fecha;
	}

	limpiar(): void {
		this.cuando = null;
	}
}

export const datosGuardados = new DatosGuardados();

/**
 * Cómo se dice en pantalla.
 *
 * Con fecha y hora: «guardado hoy a las 9:14 a. m.» y «guardado el 24 de agosto»
 * llevan a decisiones distintas, y a media mañana la diferencia entre las dos
 * es justamente lo que hay que poder distinguir.
 */
export function comoSeLee(cuando: Date, ahora = new Date()): string {
	const mismoDia = cuando.toDateString() === ahora.toDateString();

	const hora = cuando.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' });

	if (mismoDia) return `guardado hoy a las ${hora}`;

	const dia = cuando.toLocaleDateString('es-CO', { day: '2-digit', month: 'long' });

	return `guardado el ${dia} a las ${hora}`;
}
