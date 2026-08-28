// ¿El teléfono está de pie o acostado?
//
// Hace falta en dos sitios —la foto de la cédula y el video de la vivienda— y
// para lo mismo: los dos se toman apaisados y hay que decírselo a quien está
// sujetando el aparato.
//
// ── Por qué se mide la VENTANA y no la orientación del aparato ───────────────
//
// `screen.orientation` dice cómo está el teléfono; `window.innerWidth` dice
// cómo está la página, que es lo que la persona ve. No siempre coinciden: con
// el giro de pantalla bloqueado —algo muy común—, el aparato se gira y la
// página no. Si midiéramos el aparato, le diríamos «ya puede grabar» a alguien
// que sigue viendo la cámara de pie.
//
// En un portátil o una tableta la ventana ya es apaisada, así que el aviso no
// aparece nunca. Es lo correcto: no hay nada que girar.

import { onMount } from 'svelte';

export type Orientacion = 'vertical' | 'apaisado';

/**
 * Cómo está una ventana de este tamaño.
 *
 * Función aparte y pura para poder probarla: el caso cuadrado —una ventana tan
 * ancha como alta— cuenta como apaisado, porque en ella la cédula ya cabe y no
 * hay nada que pedirle a nadie que gire.
 */
export function orientacionDe(ancho: number, alto: number): Orientacion {
	return ancho >= alto ? 'apaisado' : 'vertical';
}

/**
 * La orientación actual, reactiva.
 *
 * Se llama desde el `<script>` de un componente: registra sus escuchas al
 * montarse y las quita al destruirse, sin que quien lo use tenga que acordarse.
 */
export function usarOrientacion(): { readonly actual: Orientacion } {
	// Se empieza en 'apaisado' y no en 'vertical' a propósito: en el servidor no
	// hay ventana que medir, y arrancar diciendo «gire el teléfono» haría que el
	// aviso parpadeara en cada carga incluso en un computador.
	let actual = $state<Orientacion>('apaisado');

	function medir() {
		if (typeof window === 'undefined') return;

		actual = orientacionDe(window.innerWidth, window.innerHeight);
	}

	onMount(() => {
		medir();

		window.addEventListener('resize', medir);
		window.addEventListener('orientationchange', medir);

		return () => {
			window.removeEventListener('resize', medir);
			window.removeEventListener('orientationchange', medir);
		};
	});

	return {
		get actual() {
			return actual;
		}
	};
}

/**
 * Pide que la pantalla se ponga apaisada de verdad, no que el usuario la gire.
 *
 * ── Por qué hace falta esto además del aviso ─────────────────────────────────
 *
 * El aviso «gire el teléfono» daba por hecho que girar el aparato giraba la
 * página, y no siempre: la aplicación instalada declaraba `orientation` en el
 * manifiesto y se quedaba de pie por mucho que el teléfono girara. Quien lo
 * probaba veía el aviso, giraba, y no pasaba nada — pedirle algo a alguien y
 * que al hacerlo no ocurra nada es peor que no pedírselo.
 *
 * Corregido el manifiesto, esto va un paso más allá: en Android la pantalla se
 * pone apaisada SOLA al abrir la cámara. La persona no tiene que hacer nada más
 * que girar el aparato para que se le vea derecho.
 *
 * ── Y por qué el aviso sigue existiendo ──────────────────────────────────────
 *
 * `screen.orientation.lock` no existe en Safari de iOS, y en el resto exige
 * pantalla completa —que a su vez exige venir de un gesto de la persona, como
 * el toque que abrió la cámara—. Cuando algo de eso falla, no pasa nada malo:
 * queda la pantalla como estaba y el aviso hace su trabajo. Por eso todo va
 * envuelto y ningún fallo se propaga.
 *
 * ── Hay que llamarla DESDE EL TOQUE, antes de cualquier `await` ─────────────
 *
 * Pantalla completa solo se concede con «activación transitoria»: el permiso
 * que deja un toque reciente de la persona. La primera versión la pedía después
 * de `getUserMedia`, y ese `await` abre el diálogo de permiso de la cámara —
 * para cuando volvía, la activación se había gastado y la petición se rechazaba
 * en silencio. La pantalla se quedaba de pie y el aviso de girar no servía de
 * nada, que es exactamente lo que se veía en el teléfono.
 *
 * Por eso se pide sobre `documentElement` y no sobre el contenedor de la
 * cámara: ese contenedor todavía no existe en el momento del toque —se dibuja
 * cuando la cámara se abre— y esperar a que exista es volver a perder la
 * activación.
 *
 * @return si de verdad se consiguió el apaisado
 */
export async function pedirApaisado(elemento?: HTMLElement): Promise<boolean> {
	const destino = elemento ?? document.documentElement;

	try {
		if (document.fullscreenElement === null && destino.requestFullscreen) {
			await destino.requestFullscreen({ navigationUI: 'hide' });
		}
	} catch {
		// Sin pantalla completa se puede intentar el bloqueo igual: algunos
		// navegadores lo permiten en una aplicación instalada.
	}

	try {
		await screen.orientation?.lock('landscape');

		return true;
	} catch {
		// Safari de iOS no lo implementa, y un navegador dentro de otra
		// aplicación puede tenerlo restringido. No es un error: queda el aviso.
		return false;
	}
}

/**
 * Devuelve la pantalla a como estaba.
 *
 * Se llama SIEMPRE al cerrar la cámara, incluso si el bloqueo falló: dejar la
 * aplicación entera apaisada después de tomar una foto convertiría un formulario
 * de pie en algo que se lee de lado.
 */
export async function soltarApaisado(): Promise<void> {
	try {
		screen.orientation?.unlock();
	} catch {
		// No todos lo implementan. Al salir de pantalla completa se suelta solo.
	}

	try {
		if (document.fullscreenElement !== null) {
			await document.exitFullscreen();
		}
	} catch {
		// Ya estaba fuera, o el navegador lo cerró por su cuenta.
	}
}
