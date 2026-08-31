// Cómo se lee el estado de un hogar en la lista de llamadas.

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import {
	COLA_DE_CIFRA,
	estadoDe,
	porcentaje,
	PESTANAS,
	type HogarParaLlamar
} from './tipos';

function hogar(extra: Partial<HogarParaLlamar> = {}): HogarParaLlamar {
	return {
		id: 1,
		radicado: 'RUFE-2026-000001',
		nombre: 'Rosa Elena Mina',
		documento: '31982114',
		telefono: '3157729890',
		zona: 'URBANO',
		lugar: 'Belalcázar',
		fecha_evento: '2026-08-01',
		preinscrita: false,
		preinscripcion: null,
		inspeccion: null,
		descarte: null,
		no_llamar: false,
		atendida: null,
		intentos: 0,
		agotado: false,
		ultima: null,
		proxima_llamada: null,
		...extra
	};
}

function ultima(resultado: string) {
	return { resultado, etiqueta: resultado, creado_en: '2026-08-24 10:00:00', nota: null, por: null };
}

describe('el estado de un hogar', () => {
	it('preinscrito manda sobre el historial de llamadas', () => {
		// Aunque la última llamada dijera «no contesta»: lo que hizo la familia
		// pesa más que lo que pasó en el teléfono.
		//
		// Lo que cambió es qué SIGNIFICA: antes decía «Ya se preinscribió» en
		// verde, como si la campaña hubiera terminado para ese hogar. No había
		// terminado. Estar en el RUFE es el requisito para que le hagan la
		// inspección y llenar el formulario es pedir el turno; el final es la
		// inspección aprobada.
		const h = hogar({ preinscrita: true, ultima: ultima('NO_CONTESTA') });

		expect(estadoDe(h).texto).toBe('Espera la inspección');
		expect(estadoDe(h).clase).not.toBe('ok');
	});

	it('un rechazo subsanable manda sobre el historial de llamadas', () => {
		// Diez llamadas anotadas dan igual: lo que la operadora tiene que ver
		// primero es qué decidió el ingeniero y qué le falta a esa familia.
		const h = hogar({
			ultima: ultima('NO_CONTESTA'),
			descarte: {
				motivo: 'FALTA_EVIDENCIA',
				etiqueta: 'Faltó evidencia',
				llamar: true,
				decirle: 'Le faltaron fotos o videos de la vivienda.'
			}
		});

		expect(estadoDe(h)).toEqual({ texto: 'Faltó evidencia', clase: 'espera' });
	});

	it('«no aplica» se ve como lo que es: no hay que marcar ese número', () => {
		const h = hogar({
			no_llamar: true,
			descarte: {
				motivo: 'NO_APLICA',
				etiqueta: 'No aplica',
				llamar: false,
				decirle: 'El ingeniero determinó que el caso no aplica.'
			}
		});

		expect(estadoDe(h)).toEqual({ texto: 'No aplica · no llamar', clase: 'problema' });
	});

	it('una solicitud viva sigue ganándole al descarte de otra anterior', () => {
		// `preinscrita` solo es true cuando la solicitud NO está descartada, así
		// que las dos condiciones no pueden darse a la vez. Esta prueba fija ese
		// orden: si algún día se invirtiera, una familia ya inscrita aparecería
		// como rechazada y la volverían a llamar.
		const h = hogar({ preinscrita: true, descarte: null });

		expect(estadoDe(h).texto).toBe('Espera la inspección');
	});

	it('sin ninguna llamada es trabajo por hacer, no un problema', () => {
		expect(estadoDe(hogar())).toEqual({ texto: 'Sin llamar', clase: 'pendiente' });
	});

	it('«dice que ya» no cuenta como preinscrito', () => {
		// Es la trampa del módulo entero: mucha gente cree haber terminado el
		// formulario cuando cerró el navegador a mitad. Si esto se pintara como
		// logrado, el hogar saldría de la campaña sin estar registrado.
		const s = estadoDe(hogar({ ultima: ultima('YA_DILIGENCIO') }));

		expect(s.clase).not.toBe('ok');
		expect(s.texto).toContain('sin constancia');
	});

	it('un número errado o un rechazo se distinguen de una llamada pendiente', () => {
		// No son «vuelva a intentarlo»: exigen buscar otra vía o cerrar el caso.
		expect(estadoDe(hogar({ ultima: ultima('NUMERO_ERRADO') })).clase).toBe('problema');
		expect(estadoDe(hogar({ ultima: ultima('NO_INTERESA') })).clase).toBe('problema');
	});

	it('con fecha para volver a llamar, lo dice', () => {
		const h = hogar({ ultima: ultima('VOLVER_A_LLAMAR'), proxima_llamada: '2026-08-26' });

		expect(estadoDe(h).texto).toBe('Para volver a llamar');
	});
});

describe('el avance', () => {
	it('sin censo no es 0%, es que no hay nada que medir', () => {
		// «0%» sobre cero hogares parece un fracaso donde solo hay una base vacía.
		expect(porcentaje(0, 0)).toBe('—');
	});

	it('redondea al entero', () => {
		expect(porcentaje(41, 255)).toBe('16%');
		expect(porcentaje(255, 255)).toBe('100%');
	});
});

describe('las pestañas', () => {
	it('abren en el trabajo del día, no en el resultado', () => {
		expect(PESTANAS[0].valor).toBe('pendiente');
		expect(PESTANAS[PESTANAS.length - 1].valor).toBe('todos');
	});
});


describe('cuándo un hogar sale de la campaña', () => {
	// La regla de negocio: estar en el RUFE es el REQUISITO para que le hagan la
	// inspección de vivienda, y llenar el formulario es pedir el turno. Ninguna
	// de las dos cosas es haber recibido ayuda. Solo se deja de llamar a quien
	// ya tiene la inspección aprobada.
	const aprobada = { numero: 'INS-2026-000001', fecha: '2026-08-27' };

	it('la inspección aprobada es lo único que marca el final', () => {
		expect(estadoDe(hogar({ inspeccion: aprobada })).texto).toBe('Inspección aprobada');
		expect(estadoDe(hogar({ inspeccion: aprobada })).clase).toBe('ok');
	});

	it('haberse preinscrito ya no se pinta como terminado', () => {
		// Antes decía «Ya se preinscribió» en verde, y esa familia desaparecía de
		// la cola. Sigue esperando al ingeniero: puede faltarle evidencia, o
		// pueden no encontrarla en la dirección.
		const st = estadoDe(hogar({ preinscrita: true }));

		expect(st.texto).toBe('Espera la inspección');
		expect(st.clase).not.toBe('ok');
	});

	it('con inspección aprobada manda la inspección, no la preinscripción', () => {
		// Quien tiene la inspección aprobada también se preinscribió. Si el orden
		// se invirtiera, el final del camino no se vería nunca.
		expect(estadoDe(hogar({ preinscrita: true, inspeccion: aprobada })).texto).toBe(
			'Inspección aprobada'
		);
	});

	it('la lista de pestañas ofrece ver a los que ya terminaron', () => {
		expect(PESTANAS.map((p) => p.valor)).toContain('terminado');
	});
});


describe('las tarjetas del resumen', () => {
	it('cada cifra abre una pestaña que existe de verdad', () => {
		// La promesa de la pantalla: se pulsa la tarjeta y sale ESA gente. Una
		// cifra apuntando a una cola inexistente lanzaría la consulta
		// equivocada, y el conteo de la lista no sería el que la tarjeta
		// prometió — que es exactamente lo que hace que una operadora deje de
		// creerle al tablero.
		const colas = new Set(PESTANAS.map((p) => p.valor));

		for (const [cifra, cola] of Object.entries(COLA_DE_CIFRA)) {
			expect(colas, `la tarjeta «${cifra}» abre una cola sin pestaña`).toContain(cola);
		}
	});

	it('están las nueve, ninguna repetida', () => {
		// Repetida significaría que dos tarjetas distintas abren la misma lista:
		// una de las dos estaría prometiendo un número que no es el suyo.
		const colas = Object.values(COLA_DE_CIFRA);

		expect(colas).toHaveLength(9);
		expect(new Set(colas).size).toBe(9);
	});

	it('el servidor conoce las mismas colas', () => {
		// Las dos tablas —esta y `CallCenterController::COLA_DE_CIFRA`— tienen
		// que decir lo mismo, y viven en lenguajes distintos: no hay compilador
		// que las cuadre. Esta prueba es lo único que hay entre ellas.
		const php = readFileSync(
			new URL('../../../../backend/src/Controllers/CallCenterController.php', import.meta.url),
			'utf-8'
		);
		const tabla = php.slice(php.indexOf('public const COLA_DE_CIFRA'));

		for (const [cifra, cola] of Object.entries(COLA_DE_CIFRA)) {
			expect(tabla, `el servidor no manda la cifra «${cifra}»`).toContain(`'${cifra}'`);
			expect(tabla, `el servidor no conoce la cola «${cola}»`).toContain(`=> '${cola}'`);
		}
	});
});

describe('KpiTile fuera del call center', () => {
	it('sin `alPulsar` no dibuja un botón', () => {
		// El componente lo comparten el tablero de riesgo, las instituciones
		// educativas y los equipamientos, donde las cifras no filtran nada. Un
		// botón ahí sería mentir sobre lo que la pantalla hace: se pulsaría
		// esperando algo que no va a pasar.
		const svelte = readFileSync(
			new URL('../components/KpiTile.svelte', import.meta.url),
			'utf-8'
		);

		expect(svelte).toContain('{#if alPulsar}');
		// La rama sin `alPulsar` sigue siendo un <div>.
		expect(svelte).toContain('<div class="kpi-tile">');
	});

	it('cuando es control, es un <button> con teclado y foco', () => {
		// No un <div> con onclick. Esa diferencia decide si la pantalla se puede
		// usar sin ratón, y no se nota nunca desde el ratón de quien la programa.
		const svelte = readFileSync(
			new URL('../components/KpiTile.svelte', import.meta.url),
			'utf-8'
		);

		expect(svelte).toContain('<button');
		expect(svelte).toContain('aria-pressed={activa}');
		expect(svelte).toContain(':focus-visible');
	});
});
