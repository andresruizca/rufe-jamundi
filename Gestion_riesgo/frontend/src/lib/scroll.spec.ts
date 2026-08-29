import { describe, expect, it } from 'vitest';
import { anclarArriba, type Entorno } from './scroll';

/**
 * Un navegador de mentira con el reloj y los cuadros en la mano.
 *
 * `cuadro()` avanza el tiempo y dispara un repintado, que es donde el ancla
 * decide; `gesto()` es la persona tocando la pantalla.
 */
function entornoFalso(msPorCuadro = 16) {
	let reloj = 0;
	let subidas = 0;
	let restaurado = false;
	let escuchando = false;

	let paso: (() => void) | null = null;
	let soltar: (() => void) | null = null;

	const entorno: Entorno = {
		irArriba: () => {
			subidas += 1;
		},
		escucharGestos: (fn) => {
			escuchando = true;
			soltar = fn;

			return () => {
				escuchando = false;
			};
		},
		cadaCuadro: (fn) => {
			paso = fn;

			return () => {
				paso = null;
			};
		},
		preparar: () => () => {
			restaurado = true;
		},
		ahora: () => reloj
	};

	return {
		entorno,
		cuadro(veces = 1) {
			for (let i = 0; i < veces; i += 1) {
				reloj += msPorCuadro;
				paso?.();
			}
		},
		gesto: () => soltar?.(),
		get subidas() {
			return subidas;
		},
		get vigilando() {
			return escuchando && paso !== null;
		},
		get restaurado() {
			return restaurado;
		}
	};
}

describe('anclarArriba', () => {
	it('sube nada más empezar, sin esperar al primer cuadro', () => {
		// El caso corriente —la página que sí dibuja rápido— tiene que quedar
		// arriba de una vez, no un cuadro después.
		const f = entornoFalso();
		anclarArriba(f.entorno);

		expect(f.subidas).toBe(1);
	});

	it('sostiene la página arriba mientras el contenido va apareciendo', () => {
		// Esta es la razón de existir del ancla: con `ssr = false` el navegador
		// restaura la posición guardada A MEDIDA QUE la página crece, no al
		// cargarla. Una sola subida al montar no alcanza.
		const f = entornoFalso();
		anclarArriba(f.entorno);

		f.cuadro(10);

		expect(f.subidas).toBe(11);
	});

	it('se suelta al primer gesto de la persona', () => {
		// Un ancla que insista es peor que el error que arregla: alguien baja a
		// leer las señales de daño y la página lo devuelve arriba a la fuerza.
		const f = entornoFalso();
		anclarArriba(f.entorno);

		f.cuadro(3);
		const antes = f.subidas;

		f.gesto();
		f.cuadro(10);

		expect(f.subidas).toBe(antes);
		expect(f.vigilando).toBe(false);
	});

	it('se suelta sola al vencer la ventana', () => {
		const f = entornoFalso(100);
		anclarArriba(f.entorno, 500);

		f.cuadro(4); // 400 ms: todavía vigila.
		const antes = f.subidas;

		f.cuadro(1); // 500 ms: vence.
		f.cuadro(10);

		expect(f.subidas).toBe(antes);
		expect(f.vigilando).toBe(false);
	});

	it('devuelve la restauración del navegador al soltar', () => {
		// Si no se devolviera, volver atrás desde otra pantalla dejaría de
		// recordar dónde iba la persona en TODA la aplicación.
		const f = entornoFalso();
		const soltar = anclarArriba(f.entorno);

		expect(f.restaurado).toBe(false);

		soltar();

		expect(f.restaurado).toBe(true);
	});

	it('aguanta que la suelten dos veces', () => {
		// Pasa de verdad: la persona toca la pantalla y acto seguido se destruye
		// el componente. Soltar dos veces no puede llamar dos veces a limpiar.
		const f = entornoFalso();
		const soltar = anclarArriba(f.entorno);

		f.gesto();
		soltar();

		expect(f.vigilando).toBe(false);
	});
});
