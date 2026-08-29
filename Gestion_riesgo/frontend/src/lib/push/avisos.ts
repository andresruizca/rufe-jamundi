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
		const registro = await navigator.serviceWorker.ready;
		const suscripcion = await registro.pushManager.getSubscription();

		return suscripcion ? 'activos' : 'sin-pedir';
	} catch {
		return 'sin-pedir';
	}
}

/**
 * Pedir permiso y registrar este aparato.
 *
 * Devuelve el estado en que quedó. No lanza: quien llama es un interruptor de
 * una pantalla, y una excepción ahí solo produciría una pantalla rota por algo
 * que es una comodidad.
 */
export async function activar(): Promise<EstadoAvisos> {
	if (!sePuede()) return 'no-soportado';

	// El permiso se pide desde el gesto de la persona, no al cargar la página.
	// Un navegador que ve la pregunta sin que nadie haya pulsado nada la
	// rechaza solo, y algunos no vuelven a preguntar nunca más.
	const permiso = await Notification.requestPermission();

	if (permiso !== 'granted') return permiso === 'denied' ? 'bloqueados' : 'sin-pedir';

	try {
		const { clave } = await api.get<{ clave: string }>('/push/clave-publica');
		const registro = await navigator.serviceWorker.ready;

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

		const datos = suscripcion.toJSON();

		await api.post('/push/suscripciones', {
			endpoint: suscripcion.endpoint,
			p256dh: datos.keys?.p256dh ?? '',
			auth: datos.keys?.auth ?? ''
		});

		return 'activos';
	} catch {
		return 'sin-pedir';
	}
}

/**
 * Dejar de recibirlos en este aparato.
 *
 * Se le dice al servidor ANTES de soltar la suscripción en el navegador: al
 * revés, un fallo de red dejaría al servidor mandando avisos a una dirección
 * que ya no existe, y esa suscripción muerta se reintentaría durante semanas.
 */
export async function desactivar(): Promise<EstadoAvisos> {
	if (!sePuede()) return 'no-soportado';

	try {
		const registro = await navigator.serviceWorker.ready;
		const suscripcion = await registro.pushManager.getSubscription();

		if (suscripcion) {
			await api.post('/push/suscripciones/baja', { endpoint: suscripcion.endpoint });
			await suscripcion.unsubscribe();
		}
	} catch {
		// Sin señal: el interruptor vuelve a su sitio y se reintenta luego.
	}

	return estado();
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
