// Que el formulario abra por arriba, y no por donde quedó la vez pasada.
//
// ── Qué estaba pasando ───────────────────────────────────────────────────────
//
// La aplicación es una SPA sin renderizado en el servidor (`ssr = false` en
// `src/routes/+layout.ts`). Al recargar, el navegador tiene una posición de
// scroll guardada de la visita anterior, pero el documento todavía está vacío:
// no hay a dónde bajar. Así que se la guarda y la vuelve a aplicar A MEDIDA QUE
// la página crece.
//
// Y esta página crece tarde: el paso se dibuja después de pedir los catálogos y
// de leer el borrador de IndexedDB. Cuando por fin hay contenido, el navegador
// aplica el desplazamiento pendiente y la persona aterriza al final del
// formulario, delante del botón de enviar, sin haber visto la primera pregunta.
//
// Es peor de lo que parece: quien abre el enlace por WhatsApp y ve el final de
// un formulario no entiende que hay ocho campos arriba. Y quien no entiende,
// no se inscribe.
//
// ── Cómo se corrige ──────────────────────────────────────────────────────────
//
// No basta con un `scrollTo(0, 0)` al montar: cuando se ejecuta, el contenido
// aún no existe y el navegador restaura DESPUÉS. Hay que sostener el arranque
// arriba durante el rato en que la página se está armando.
//
// ── Pero sin pelear con la persona ───────────────────────────────────────────
//
// Un ancla que insista siempre es un error peor que el que arregla: alguien
// baja a leer y la página lo devuelve arriba a la fuerza. Por eso el ancla se
// suelta a la primera señal de que la persona quiso moverse —rueda, dedo,
// tecla— y en todo caso sola, al segundo largo. Solo cubre el arranque.

/** Cuánto se sostiene el arranque arriba. Suficiente para el dibujado tardío. */
export const VENTANA_MS = 1200;

/**
 * Lo que el ancla necesita del navegador.
 *
 * Se inyecta para poder probar el comportamiento —soltar al primer gesto, y
 * soltar sola al vencer la ventana— sin montar un navegador entero.
 */
export type Entorno = {
	/** Deja el documento arriba del todo. */
	irArriba: () => void;
	/** Escucha el primer gesto de la persona; devuelve cómo dejar de escuchar. */
	escucharGestos: (soltar: () => void) => () => void;
	/** Llama a `paso` en cada cuadro; devuelve cómo parar. */
	cadaCuadro: (paso: () => void) => () => void;
	/** Prepara el navegador; devuelve cómo dejarlo como estaba. */
	preparar: () => () => void;
	ahora: () => number;
};

const GESTOS = ['wheel', 'touchstart', 'keydown', 'pointerdown'] as const;

export function entornoDelNavegador(): Entorno {
	return {
		irArriba: () => window.scrollTo(0, 0),

		escucharGestos: (soltar) => {
			for (const gesto of GESTOS) {
				window.addEventListener(gesto, soltar, { passive: true });
			}

			return () => {
				for (const gesto of GESTOS) window.removeEventListener(gesto, soltar);
			};
		},

		cadaCuadro: (paso) => {
			let pedido = requestAnimationFrame(function siguiente() {
				paso();
				pedido = requestAnimationFrame(siguiente);
			});

			return () => cancelAnimationFrame(pedido);
		},

		preparar: () => {
			// Le quita al navegador la restauración automática mientras dura el
			// arranque. En un `try` porque Safari antiguo no la tiene y no vale la
			// pena tumbar el formulario por esto.
			try {
				const previo = history.scrollRestoration;
				history.scrollRestoration = 'manual';

				return () => {
					try {
						history.scrollRestoration = previo;
					} catch {
						// Igual que arriba: nada que hacer, nada que romper.
					}
				};
			} catch {
				return () => {};
			}
		},

		ahora: () => Date.now()
	};
}

/**
 * Sostiene la página arriba mientras el contenido termina de aparecer.
 *
 * Devuelve cómo soltarla antes de tiempo — lo que `onMount` debe llamar al
 * destruir el componente, para no dejar oyentes sueltos si alguien navega a
 * otra pantalla durante el arranque.
 */
export function anclarArriba(entorno: Entorno, ventanaMs: number = VENTANA_MS): () => void {
	const inicio = entorno.ahora();

	let vivo = true;
	let pararGestos: () => void = () => {};
	let pararCuadros: () => void = () => {};
	let restaurar: () => void = () => {};

	const soltar = () => {
		if (!vivo) return;

		vivo = false;
		pararGestos();
		pararCuadros();
		restaurar();
	};

	restaurar = entorno.preparar();
	entorno.irArriba();

	pararGestos = entorno.escucharGestos(soltar);

	pararCuadros = entorno.cadaCuadro(() => {
		if (!vivo) return;

		if (entorno.ahora() - inicio >= ventanaMs) {
			soltar();

			return;
		}

		entorno.irArriba();
	});

	return soltar;
}
