// Cómo se lee el estado de un hogar en la lista de llamadas.

import { describe, it, expect } from 'vitest';
import { estadoDe, porcentaje, PESTANAS, type HogarParaLlamar } from './tipos';

function hogar(extra: Partial<HogarParaLlamar> = {}): HogarParaLlamar {
	return {
		id: 1,
		radicado: 'RUFE-2026-000001',
		nombre: 'Rosa Elena Mina',
		telefono: '3157729890',
		zona: 'URBANO',
		lugar: 'Belalcázar',
		fecha_evento: '2026-08-01',
		preinscrita: false,
		preinscripcion: null,
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
	it('preinscrito manda sobre todo lo demás', () => {
		// Aunque la última llamada dijera «no contesta»: si ya está en el
		// formulario, la campaña terminó para ese hogar.
		const h = hogar({ preinscrita: true, ultima: ultima('NO_CONTESTA') });

		expect(estadoDe(h).texto).toBe('Ya se preinscribió');
		expect(estadoDe(h).clase).toBe('ok');
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

		expect(estadoDe(h).clase).toBe('ok');
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
