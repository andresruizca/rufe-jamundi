// Pedir —y poder retirar— los avisos al aparato.
//
// ── Qué avisa ────────────────────────────────────────────────────────────────
//
// Que entró una solicitud ciudadana. El propio tablero mide un atasco llamado
// «solicitudes demoradas»: las que llevan más de tres días sin que nadie las
// abra. Una familia que acaba de perder parte de su casa no espera tres días en
// silencio — llama al conmutador. Esto existe para que esa espera no ocurra.
//
// ── Qué NO viaja ─────────────────────────────────────────────────────────────
//
// El aviso va vacío: ni un nombre, ni una cédula, ni un barrio. El texto que se
// ve lo escribe el service worker con palabras que ya estaban en el teléfono.
// El dato se lee dentro del sistema, con sesión iniciada, como todo lo demás.

import { api } from '$lib/api/client';

export type EstadoAvisos =
	/** El navegador no sabe hacer esto. Safari de escritorio, navegadores viejos. */
	| 'no-soportado'
	/** Se puede pedir. */
	| 'sin-pedir'
	/** Dijo que sí y el servidor lo tiene apuntado. */
	| 'activos'
	/** La persona los bloqueó. Desde la página ya no se puede volver a pedir. */
	| 'bloqueados';

/**
 * ¿Este navegador puede?
 *
 * Las tres cosas hacen falta y no vienen juntas: iOS trae `Notification` desde
 * hace años pero solo entrega `PushManager` cuando la aplicación está
 * instalada en la pantalla de inicio (iOS 16.4 en adelante). Preguntar por una
 * sola dejaría a un iPhone prometiendo algo que no puede cumplir.
 */
export function sePuede(): boolean {
	if (typeof window === 'undefined') return false;

	return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
}

/**
 * En qué punto está esto ahora mismo.
 *
 * Se pregunta al navegador Y al servidor, porque pueden discrepar: un permiso
 * concedido hace un mes con la suscripción ya borrada del servidor —se restauró
 * un respaldo, la persona limpió los datos del sitio— dejaría el interruptor
 * encendido sin que llegue nada. Y un interruptor que miente sobre una alerta
 * es peor que no tener alerta.
 */
export async function estado(): Promise<EstadoAvisos> {
	if (!sePuede()) return 'no-soportado';
	if (Notification.permission === 'denied') return 'bloqueados';
	if (Notification.permission === 'default') return 'sin-pedir';

	try {
		// Con tiempo límite: si no hay service worker activo, `ready` no falla,
		// se queda esperando para siempre, y la pantalla nunca dibujaría el
		// interruptor.
		const registro = await registroListo();
		const suscripcion = await registro?.pushManager.getSubscription();

		if (!suscripcion) return 'sin-pedir';

		// ── Y volver a apuntarlo en el servidor ──────────────────────────
		//
		// El navegador y el servidor pueden discrepar, y discrepaban: este
		// aparato tenía su suscripción y el servidor no la conocía, así que la
		// pantalla decía «Avisos activados» mientras la prueba respondía «este
		// aparato no tiene los avisos activados». Las dos frases a la vez, una
		// debajo de la otra.
		//
		// Pasa cuando el navegador se suscribió y el envío al servidor no llegó
		// —sin señal a mitad, o el servidor sin su migración todavía— y también
		// cuando el navegador rota la dirección por su cuenta.
		//
		// Se vuelve a mandar en vez de solo detectarlo: el registro es
		// idempotente (`ON DUPLICATE KEY`), así que repetirlo no crea nada, y
		// arreglarlo en silencio es mejor que enseñarle a alguien un aviso que
		// no sabría qué hacer con él.
		await registrar(suscripcion);

		return 'activos';
	} catch {
		return 'sin-pedir';
	}
}

/**
 * Apuntar esta suscripción en el servidor.
 *
 * Idempotente por diseño: el servidor la reconoce por su dirección y actualiza
 * en vez de duplicar. Por eso se puede llamar cada vez que la pantalla arranca
 * sin miedo a llenar la tabla de filas repetidas.
 */
async function registrar(suscripcion: PushSubscription): Promise<void> {
	const datos = suscripcion.toJSON();

	await api.post('/push/suscripciones', {
		endpoint: suscripcion.endpoint,
		p256dh: datos.keys?.p256dh ?? '',
		auth: datos.keys?.auth ?? ''
	});
}

/**
 * Cómo quedó la cosa, y qué decirle a la persona si no quedó.
 *
 * Antes esto devolvía solo el estado, y todo fallo se convertía en
 * `'sin-pedir'` sin más. El botón volvía a apagarse solo, en silencio, que es
 * exactamente lo que alguien lee como «está roto» — y no tenía forma de saber
 * si le faltaba permiso, señal, o un paso en el servidor.
 */
export type Resultado = {
	estado: EstadoAvisos;
	/** Qué pasó, en palabras, cuando no quedó activado. */
	aviso?: string;
};

/**
 * Cuánto se espera al service worker antes de rendirse.
 *
 * `navigator.serviceWorker.ready` es una promesa que puede NO resolverse nunca
 * —no lanzar: quedarse colgada— si no hay un service worker activo para esta
 * pantalla. Un `await` sobre eso deja el botón girando para siempre, que es
 * justo lo que pasaba.
 */
const ESPERA_SW_MS = 8000;

async function registroListo(): Promise<ServiceWorkerRegistration | null> {
	return Promise.race([
		navigator.serviceWorker.ready,
		new Promise<null>((listo) => setTimeout(() => listo(null), ESPERA_SW_MS))
	]);
}

/**
 * Pedir permiso y registrar este aparato.
 *
 * No lanza NUNCA. Quien llama es un interruptor de una pantalla: una excepción
 * que se escape de aquí deja ese interruptor girando indefinidamente, sin nada
 * en pantalla que explique por qué.
 */
export async function activar(): Promise<Resultado> {
	if (!sePuede()) {
		return { estado: 'no-soportado' };
	}

	let permiso: NotificationPermission;

	try {
		// DENTRO del try, y no fuera como estaba. Safari viejo devuelve
		// `undefined` y espera una función; algunos navegadores lanzan si la
		// pantalla no está en un contexto seguro. Cualquiera de las dos cosas
		// se escapaba de aquí y colgaba el botón.
		permiso = await Notification.requestPermission();
	} catch {
		return { estado: 'sin-pedir', aviso: 'Este navegador no dejó pedir el permiso.' };
	}

	if (permiso === 'denied') {
		return { estado: 'bloqueados' };
	}

	if (permiso !== 'granted') {
		// Cerró la pregunta sin contestar. No es un error y no hay nada que
		// decir: puede volver a intentarlo cuando quiera.
		return { estado: 'sin-pedir' };
	}

	try {
		const { clave } = await api.get<{ clave: string }>('/push/clave-publica');

		const registro = await registroListo();

		if (registro === null) {
			return {
				estado: 'sin-pedir',
				aviso: 'La aplicación aún se está preparando. Recargue la página e intente de nuevo.'
			};
		}

		// Si ya había una, se reutiliza: volver a suscribir con otra clave
		// dejaría la anterior viva en el servicio de push y sin dueño aquí.
		const suscripcion =
			(await registro.pushManager.getSubscription()) ??
			(await registro.pushManager.subscribe({
				// Obligatorio en Chrome: un aviso que no muestre nada visible le
				// cuesta el permiso a toda la aplicación.
				userVisibleOnly: true,
				applicationServerKey: deBase64Url(clave)
			}));

		await registrar(suscripcion);

		return { estado: 'activos' };
	} catch (e) {
		// El mensaje del servidor se enseña tal cual cuando lo hay. El caso que
		// de verdad ocurre: los avisos están en el código pero su migración
		// todavía no se ha corrido, y el servidor lo dice con esas palabras.
		// Callarlo dejaba un botón que se apaga solo sin explicar nada.
		return {
			estado: 'sin-pedir',
			aviso: e instanceof Error && e.message !== '' ? e.message : 'No se pudieron activar los avisos.'
		};
	}
}

/**
 * Dejar de recibirlos en este aparato.
 *
 * Se le dice al servidor ANTES de soltar la suscripción en el navegador: al
 * revés, un fallo de red dejaría al servidor mandando avisos a una dirección
 * que ya no existe, y esa suscripción muerta se reintentaría durante semanas.
 */
export async function desactivar(): Promise<Resultado> {
	if (!sePuede()) {
		return { estado: 'no-soportado' };
	}

	try {
		const registro = await registroListo();
		const suscripcion = await registro?.pushManager.getSubscription();

		if (suscripcion) {
			await api.post('/push/suscripciones/baja', { endpoint: suscripcion.endpoint });
			await suscripcion.unsubscribe();
		}
	} catch {
		// Sin señal: se reintenta cuando la haya. El estado de abajo dirá la
		// verdad de todos modos, que es lo que el interruptor debe reflejar.
	}

	return { estado: await estado() };
}

/**
 * La clave del servidor, del texto que viaja a los bytes que pide el navegador.
 *
 * `applicationServerKey` no acepta la cadena: quiere los 65 bytes crudos. Y la
 * cadena viene en base64 de la web —sin relleno y con `-_`—, que `atob` no
 * entiende: hay que devolverle los `+/` y el `=` que le faltan.
 */
export function deBase64Url(clave: string): Uint8Array<ArrayBuffer> {
	const relleno = '='.repeat((4 - (clave.length % 4)) % 4);
	const normal = (clave + relleno).replace(/-/g, '+').replace(/_/g, '/');
	const crudo = atob(normal);

	// Sobre un `ArrayBuffer` explícito: `applicationServerKey` no acepta un
	// `Uint8Array` que pudiera estar sobre memoria compartida, y el tipo por
	// defecto no lo descarta.
	const bytes = new Uint8Array(new ArrayBuffer(crudo.length));

	for (let i = 0; i < crudo.length; i++) bytes[i] = crudo.charCodeAt(i);

	return bytes;
}

/**
 * Mandarse un aviso a uno mismo, para ver que llega.
 *
 * Un interruptor que dice «activado» y no se puede comprobar es un interruptor
 * en el que nadie confía — y con razón: entre el permiso del navegador, la
 * suscripción, la firma del servidor y el servicio de push hay cuatro sitios
 * donde esto se puede quedar callado sin que nada lo diga.
 */
export async function probar(): Promise<string> {
	try {
		const r = await api.post<{ enviados: number; nota: string }>('/push/prueba');

		return r.nota;
	} catch (e) {
		return e instanceof Error && e.message !== '' ? e.message : 'No se pudo enviar la prueba.';
	}
}
