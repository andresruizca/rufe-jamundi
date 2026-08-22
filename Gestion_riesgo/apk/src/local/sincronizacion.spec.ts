import { describe, expect, it } from 'vitest';
import {
	ESPERAS,
	MAX_INTENTOS,
	comoSeDice,
	decidir,
	esperaSegunServidor,
	esperaTrasIntento,
	proximoIntento,
	sigueEsperando
} from './sincronizacion';

describe('la espera entre intentos', () => {
	it('siempre crece, nunca se acorta', () => {
		// El plan original traía [0, 5, 15, 60, 240, 600] con el último MENOR que
		// el anterior: el sexto intento habría llegado antes que el quinto.
		for (let i = 1; i < ESPERAS.length; i++) {
			expect(ESPERAS[i]).toBeGreaterThan(ESPERAS[i - 1]);
		}
	});

	it('el primer reintento es inmediato', () => {
		// WorkManager despierta justamente porque acaba de haber señal: esperar
		// cinco minutos en ese momento es tiempo regalado.
		expect(esperaTrasIntento(0)).toBe(0);
	});

	it('se queda en el último escalón en vez de desbordarse', () => {
		const ultimo = ESPERAS[ESPERAS.length - 1];

		expect(esperaTrasIntento(ESPERAS.length)).toBe(ultimo);
		expect(esperaTrasIntento(999)).toBe(ultimo);
	});

	it('calcula el instante del próximo intento', () => {
		const ahora = new Date('2026-08-22T10:00:00Z');

		expect(proximoIntento(1, ahora).toISOString()).toBe('2026-08-22T10:05:00.000Z');
	});
});

describe('qué hacer tras un intento', () => {
	it('una solicitud aceptada termina', () => {
		const d = decidir(201, { ok: true, data: { radicado: 'PRE-2026-ABCD1234' } }, 0);

		expect(d).toEqual({ hacer: 'listo', radicado: 'PRE-2026-ABCD1234' });
	});

	it('«ya estaba registrada» es ÉXITO, no error', () => {
		// El servidor devuelve el radicado original. Tratarlo como fallo haría
		// que el APK reintentara para siempre algo que ya llegó, y que la persona
		// viera un aviso rojo sobre una solicitud perfectamente registrada.
		const d = decidir(200, { ok: true, data: { radicado: 'PRE-2026-XY', duplicada: true } }, 2);

		expect(d.hacer).toBe('listo');
	});

	it('un reintento que el servidor reconoce también termina', () => {
		const d = decidir(200, { ok: true, data: { radicado: 'PRE-2026-XY', reintento: true } }, 3);

		expect(d.hacer).toBe('listo');
	});

	it('sin conexión se reintenta, y eso no es un error', () => {
		const d = decidir(null, null, 0);

		expect(d.hacer).toBe('reintentar');
		expect(d.hacer === 'reintentar' && d.motivo).toBe('Sin conexión.');
	});

	it('un 422 con errores por campo NO se reintenta', () => {
		// Los datos no van a mejorar solos. Insistir mil veces contra el mismo
		// rechazo solo le gasta la batería a quien ya tuvo el problema.
		const d = decidir(422, { ok: false, message: 'Revisa los datos.', errors: { zona: 'Falta' } }, 0);

		expect(d.hacer).toBe('rendirse');
	});

	it('un 422 SIN errores por campo sí se reintenta', () => {
		// Un rechazo que no sabemos explicar. Descartar la solicitud de alguien
		// por algo que no entendemos es peor que volver a intentarlo.
		expect(decidir(422, { ok: false, message: 'Algo pasó' }, 0).hacer).toBe('reintentar');
	});

	it('un 500 se reintenta: es del camino, no del contenido', () => {
		expect(decidir(500, { ok: false }, 0).hacer).toBe('reintentar');
	});

	it('un 429 se reintenta — es el límite de tasa, y pasa', () => {
		expect(decidir(429, { ok: false, message: 'Demasiadas solicitudes.' }, 0).hacer).toBe(
			'reintentar'
		);
	});

	it('tras agotar los intentos se para, pero el registro no se pierde', () => {
		expect(decidir(500, { ok: false }, MAX_INTENTOS - 1).hacer).toBe('rendirse');
		expect(decidir(null, null, MAX_INTENTOS - 1).hacer).toBe('rendirse');
	});

	it('un ok:true sin radicado no cuenta como enviado', () => {
		// Si el servidor respondiera algo raro, dar por bueno un envío sin
		// radicado dejaría a la persona con una constancia que no existe.
		expect(decidir(200, { ok: true, data: {} }, 0).hacer).toBe('reintentar');
	});
});

describe('cómo se le cuenta a la persona', () => {
	it('lo enviado lleva su radicado, que es lo único que se lleva', () => {
		expect(comoSeDice('SINCRONIZADO', { radicado: 'PRE-2026-ABCD1234' })).toContain(
			'PRE-2026-ABCD1234'
		);
	});

	it('lo pendiente no habla de sincronizar ni de colas', () => {
		const texto = comoSeDice('PENDIENTE');

		expect(texto).toBe('Se enviará en cuanto haya internet.');
		expect(texto.toLowerCase()).not.toContain('sincroniz');
	});

	it('dice cuántos minutos faltan cuando hay una espera de verdad', () => {
		const ahora = new Date('2026-08-22T10:00:00Z');
		const texto = comoSeDice('PENDIENTE', {
			proximoIntento: new Date('2026-08-22T10:15:00Z'),
			ahora
		});

		expect(texto).toContain('15 minutos');
	});

	it('una espera ya vencida no promete un futuro que ya pasó', () => {
		const ahora = new Date('2026-08-22T10:00:00Z');
		const texto = comoSeDice('PENDIENTE', {
			proximoIntento: new Date('2026-08-22T09:00:00Z'),
			ahora
		});

		expect(texto).toBe('Se enviará en cuanto haya internet.');
	});

	it('el error de datos dice que hay que corregir, no que falló el envío', () => {
		expect(comoSeDice('ERROR_VALIDACION')).toContain('corregir');
	});
});

describe('el aviso de no desinstalar', () => {
	it('cuenta lo que todavía puede salir', () => {
		// Android no avisa al desinstalar. Si la aplicación no lo dice al
		// abrirse, alguien borra la aplicación creyendo que ya mandó su
		// solicitud y se lleva por delante fotos que no volverá a tomar.
		expect(sigueEsperando('PENDIENTE')).toBe(true);
		expect(sigueEsperando('SINCRONIZANDO')).toBe(true);
		expect(sigueEsperando('ERROR')).toBe(true);
	});

	it('no cuenta lo ya enviado ni lo que necesita corrección de la persona', () => {
		expect(sigueEsperando('SINCRONIZADO')).toBe(false);
		expect(sigueEsperando('ERROR_VALIDACION')).toBe(false);
	});
});

describe('honrar el Retry-After del servidor', () => {
	it('usa lo que dice el servidor en vez de la escalera genérica', () => {
		// `Limite.php` manda los segundos que quedan de su ventana. Ignorarlo era
		// lo que dejaba cinco de veinte solicitudes esperando un toque a mano
		// cuando una brigada sincroniza desde una vereda: todas salen por la
		// misma IP y el límite es de cinco por hora.
		//
		// Medido sobre ese límite real: con la escalera salen 15 de 20 y la
		// última tarda 320 minutos; honrando Retry-After salen las 20, ninguna
		// pide toque, y la última tarda 180.
		expect(esperaSegunServidor(1800, 1)).toBe(1800);
	});

	it('sin cabecera, cae en la escalera de siempre', () => {
		expect(esperaSegunServidor(null, 1)).toBe(esperaTrasIntento(1));
	});

	it('una cabecera absurda no duerme la solicitud para siempre', () => {
		// No puede pasar de un día: una cabecera equivocada —o puesta con mala
		// intención por algo en el camino— dejaría la solicitud de alguien
		// esperando meses.
		expect(esperaSegunServidor(99999999, 0)).toBe(24 * 3600);
	});

	it('valores sin sentido se ignoran', () => {
		expect(esperaSegunServidor(0, 2)).toBe(esperaTrasIntento(2));
		expect(esperaSegunServidor(-5, 2)).toBe(esperaTrasIntento(2));
		expect(esperaSegunServidor(Number.NaN, 2)).toBe(esperaTrasIntento(2));
	});

	it('un 429 con Retry-After espera lo que le dicen', () => {
		const d = decidir(429, { ok: false, message: 'Demasiadas solicitudes.' }, 0, 2400);

		expect(d.hacer).toBe('reintentar');
		expect(d.hacer === 'reintentar' && d.esperaSegundos).toBe(2400);
	});
});
