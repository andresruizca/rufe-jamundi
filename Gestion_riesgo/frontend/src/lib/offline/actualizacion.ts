// Cómo llega al aparato una versión nueva del sistema.
//
// ── Qué estaba mal ───────────────────────────────────────────────────────────
//
// La versión nueva se activaba sola nada más terminar de guardarse, y al
// hacerlo borraba las cachés de la anterior. Pero la pestaña abierta seguía
// ejecutando la anterior: la aplicación carga cada pantalla en un archivo
// aparte y con el contenido en el nombre, así que al pulsar un enlace pedía un
// archivo con el nombre de antes —que ya no estaba ni en la caché ni en el
// servidor, porque el despliegue lo reemplazó—. Pantalla en blanco.
//
// Le pasaba a quien deja la pantalla puesta toda la jornada, que es
// exactamente la operadora del call center.
//
// ── Cómo funciona ahora ──────────────────────────────────────────────────────
//
// La versión nueva se queda ESPERANDO. La pantalla lo detecta vigilando el
// registro, avisa, y solo cuando la persona acepta se le dice que tome el
// relevo; entonces la pestaña se recarga sola, una vez, ya con todo nuevo.
//
// Mientras espera no se pierde nada: la versión anterior sigue activa con su
// cola y su envío en segundo plano. Lo que espera es el cambio, no el trabajo.

/**
 * ¿Lo que acaba de instalarse es una actualización, o la primera visita?
 *
 * La diferencia está en si ya había alguien mandando: sin controlador, este
 * Service Worker es el primero que existe y no hay nada que actualizar. Sin
 * esta comprobación, a quien abre el sistema por primera vez le saldría «hay
 * una versión nueva» antes de haber usado ninguna.
 */
export function esActualizacion(estado: string | undefined, hayControlador: boolean): boolean {
	return estado === 'installed' && hayControlador;
}

/**
 * Avisa cuando haya una versión nueva esperando.
 *
 * Devuelve cómo dejar de vigilar. `avisar` se llama como mucho una vez por
 * versión: el aviso es una barra que se queda en pantalla, no una notificación
 * que se repite.
 */
export function vigilarActualizaciones(avisar: () => void): () => void {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return () => {};

	let vivo = true;
	let registro: ServiceWorkerRegistration | null = null;
	const limpiezas: (() => void)[] = [];

	void navigator.serviceWorker.getRegistration().then((r) => {
		if (!vivo || !r) return;

		registro = r;

		// Puede haber una esperando desde antes de que se abriera esta pestaña
		// —se guardó ayer y nadie aceptó—. Preguntarlo es lo que evita que la
		// persona se quede en una versión vieja para siempre.
		if (r.waiting && navigator.serviceWorker.controller) avisar();

		const alEncontrar = () => {
			const nueva = r.installing;
			if (!nueva) return;

			const alCambiar = () => {
				if (esActualizacion(nueva.state, navigator.serviceWorker.controller !== null)) {
					avisar();
				}
			};

			nueva.addEventListener('statechange', alCambiar);
			limpiezas.push(() => nueva.removeEventListener('statechange', alCambiar));
		};

		r.addEventListener('updatefound', alEncontrar);
		limpiezas.push(() => r.removeEventListener('updatefound', alEncontrar));
	});

	return () => {
		vivo = false;
		for (const soltar of limpiezas) soltar();
		registro = null;
	};
}

/**
 * Cambiar a la versión nueva, ahora que la persona lo aceptó.
 *
 * Se le pide el relevo al que espera y se recarga cuando el navegador confirma
 * que ya manda otro. Recargar antes serviría la versión anterior otra vez y el
 * aviso volvería a salir, que es la clase de fallo que enseña a la gente a
 * ignorar los avisos.
 */
export async function aplicarActualizacion(): Promise<void> {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		location.reload();

		return;
	}

	const registro = await navigator.serviceWorker.getRegistration();
	const esperando = registro?.waiting;

	if (!esperando) {
		// No hay ninguna esperando: o ya se activó sola —todas las pestañas
		// cerradas— o el aviso venía del propio Service Worker al activarse.
		// Recargar es exactamente lo que hace falta.
		location.reload();

		return;
	}

	// Una sola vez: `controllerchange` también se dispara en otros momentos, y
	// recargar en bucle es peor que no actualizar.
	let recargado = false;

	navigator.serviceWorker.addEventListener('controllerchange', () => {
		if (recargado) return;

		recargado = true;
		location.reload();
	});

	esperando.postMessage({ tipo: 'aplicar-actualizacion' });

	// Red de seguridad: si el relevo no llega en unos segundos —el navegador
	// puede negarse mientras haya una descarga en curso— se recarga igual. Es
	// preferible una recarga que quizá no cambie nada a un botón que no hace
	// nada visible.
	setTimeout(() => {
		if (!recargado) {
			recargado = true;
			location.reload();
		}
	}, 3000);
}
