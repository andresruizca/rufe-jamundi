// Las reglas de cuándo reintentar y cuándo rendirse.
//
// Vive en TypeScript aunque quien sincroniza sea Kotlin, y no es un descuido:
// aquí se puede PROBAR. `SyncWorker.kt` implementa exactamente esto, y estas
// pruebas son la especificación contra la que se escribe. Si las dos se
// separan, se separan sobre algo escrito y comprobado, no sobre una idea que
// alguien recordaba.
//
// La pantalla «Mis registros» también las usa, para decirle a la persona cuándo
// se va a volver a intentar sin tener que preguntárselo a Kotlin.

/** Los estados por los que pasa una solicitud guardada en el teléfono. */
export type EstadoRegistro =
	| 'PENDIENTE'
	| 'SINCRONIZANDO'
	| 'SINCRONIZADO'
	| 'ERROR_VALIDACION'
	| 'ERROR';

/**
 * Cuánto se espera antes de cada reintento, en segundos.
 *
 * Creciente, siempre. El plan original traía `[0, 5, 15, 60, 240, 600]`
 * comentado como «5m, 15m, 1h, 4h» —los valores estaban en segundos y el
 * comentario en minutos— y con el último MENOR que el anterior, que habría
 * hecho que el sexto intento llegara antes que el quinto.
 *
 * El primero es inmediato: la causa más común de fallo es que no había señal, y
 * cuando WorkManager despierta es justamente porque acaba de haberla.
 */
export const ESPERAS = [0, 300, 900, 3600, 14400];

/**
 * Cuántas veces se intenta antes de parar.
 *
 * Parar no es rendirse: el registro sigue en el teléfono y la persona puede
 * pedir el reintento a mano. Lo que se detiene es el reintento automático, para
 * no gastarle la batería a alguien golpeando un servidor que no responde.
 */
export const MAX_INTENTOS = ESPERAS.length;

export function esperaTrasIntento(intentos: number): number {
	const i = Math.min(Math.max(intentos, 0), ESPERAS.length - 1);

	return ESPERAS[i];
}

export function proximoIntento(intentos: number, ahora: Date = new Date()): Date {
	return new Date(ahora.getTime() + esperaTrasIntento(intentos) * 1000);
}

// ── Qué hacer con la respuesta del servidor ─────────────────────────────────

/**
 * Lo que devuelve la API, tal como es HOY.
 *
 * No se inventa un contrato nuevo: la web está en producción con este y
 * cambiarlo la rompería. Ver `docs/servidor-requerido.md`, §3.
 */
export type RespuestaEnvio = {
	ok: boolean;
	data?: {
		radicado?: string;
		duplicada?: boolean;
		reintento?: boolean;
		archivos_agregados?: number;
	};
	message?: string;
	errors?: Record<string, string>;
};

export type Decision =
	| { hacer: 'listo'; radicado: string }
	| { hacer: 'reintentar'; motivo: string; esperaSegundos: number }
	| { hacer: 'rendirse'; motivo: string };

/**
 * Cuánto esperar cuando el servidor dice explícitamente cuánto falta.
 *
 * `Limite.php` manda la cabecera `Retry-After` con los segundos que quedan de su
 * ventana. Ignorarla y usar la escalera genérica es lo que hacía que una brigada
 * de veinte familias en una vereda —todas tras la misma IP por CGNAT— dejara
 * cinco solicitudes esperando un toque a mano.
 *
 * Simulado sobre el límite real de cinco envíos por hora: con la escalera
 * genérica salen solas 15 de 20 y la última tarda 320 minutos; honrando
 * `Retry-After` salen las 20, ninguna pide toque, y la última tarda 180.
 *
 * Se acota a 24 horas: una cabecera absurda —o maliciosa— no puede dormir la
 * solicitud de alguien para siempre.
 */
const TOPE_RETRY_AFTER = 24 * 3600;

export function esperaSegunServidor(retryAfter: number | null, intentos: number): number {
	if (retryAfter !== null && Number.isFinite(retryAfter) && retryAfter > 0) {
		return Math.min(retryAfter, TOPE_RETRY_AFTER);
	}

	return esperaTrasIntento(intentos);
}

/**
 * Qué hacer tras un intento de envío.
 *
 * Las tres reglas que importan:
 *
 * 1. **`duplicada` y `reintento` son ÉXITO.** El servidor dice «esto ya
 *    estaba» y devuelve el radicado original. Tratarlo como error haría que el
 *    APK reintentara para siempre algo que ya llegó, y que la persona viera un
 *    aviso rojo sobre una solicitud que está perfectamente registrada.
 *
 * 2. **Un 422 con errores por campo NO se reintenta.** Los datos no van a
 *    mejorar solos. Se le dice a la persona qué corregir; insistir mil veces
 *    contra el mismo rechazo solo gasta batería.
 *
 * 3. **Todo lo demás se reintenta.** Sin señal, tiempo agotado, 500, 429: son
 *    fallos del camino, no del contenido, y el camino cambia.
 */
export function decidir(
	estado: number | null,
	respuesta: RespuestaEnvio | null,
	intentos: number,
	retryAfter: number | null = null
): Decision {
	const espera = () => esperaSegunServidor(retryAfter, intentos);

	// Sin respuesta: no hubo red. Es el caso normal en una vereda, no un error.
	if (estado === null) {
		return intentos + 1 >= MAX_INTENTOS
			? { hacer: 'rendirse', motivo: 'No hubo conexión en varios intentos.' }
			: { hacer: 'reintentar', motivo: 'Sin conexión.', esperaSegundos: espera() };
	}

	const radicado = respuesta?.data?.radicado;

	if (respuesta?.ok === true && typeof radicado === 'string' && radicado !== '') {
		return { hacer: 'listo', radicado };
	}

	// Regla 2. Se comprueba que HAYA errores por campo: un 422 sin ellos es un
	// rechazo que no sabemos explicar, y ahí es mejor reintentar que descartar
	// la solicitud de alguien.
	if (estado === 422 && respuesta?.errors && Object.keys(respuesta.errors).length > 0) {
		return {
			hacer: 'rendirse',
			motivo: respuesta.message ?? 'Hay datos que hay que corregir.'
		};
	}

	if (intentos + 1 >= MAX_INTENTOS) {
		return {
			hacer: 'rendirse',
			motivo: respuesta?.message ?? `El servidor respondió ${estado} varias veces.`
		};
	}

	return {
		hacer: 'reintentar',
		motivo: respuesta?.message ?? `El servidor respondió ${estado}.`,
		esperaSegundos: espera()
	};
}

// ── Lo que ve la persona ────────────────────────────────────────────────────

/**
 * Cómo se le cuenta el estado a quien no sabe —ni tiene por qué— qué es
 * sincronizar.
 */
export function comoSeDice(
	estado: EstadoRegistro,
	datos: { radicado?: string | null; proximoIntento?: Date | null; ahora?: Date } = {}
): string {
	const ahora = datos.ahora ?? new Date();

	if (estado === 'SINCRONIZADO') {
		return datos.radicado
			? `Enviado. Su radicado es ${datos.radicado}`
			: 'Enviado.';
	}

	if (estado === 'SINCRONIZANDO') return 'Enviando…';

	if (estado === 'ERROR_VALIDACION') {
		return 'Hay datos que hay que corregir antes de poder enviarlo.';
	}

	if (estado === 'ERROR') {
		return 'No se pudo enviar. Toque «Reintentar» cuando tenga señal.';
	}

	if (!datos.proximoIntento || datos.proximoIntento <= ahora) {
		return 'Se enviará en cuanto haya internet.';
	}

	const faltan = Math.ceil((datos.proximoIntento.getTime() - ahora.getTime()) / 60000);

	return faltan <= 1
		? 'Se reintentará en un momento.'
		: `Se reintentará en unos ${faltan} minutos.`;
}

/** ¿Esto todavía puede salir solo? Sirve para el aviso de «no desinstale». */
export function sigueEsperando(estado: EstadoRegistro): boolean {
	return estado === 'PENDIENTE' || estado === 'SINCRONIZANDO' || estado === 'ERROR';
}
