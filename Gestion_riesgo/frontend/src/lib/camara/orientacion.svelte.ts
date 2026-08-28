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
