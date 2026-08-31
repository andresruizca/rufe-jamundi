/// <reference types="@sveltejs/kit" />
/// <reference lib="webworker" />

// Service Worker del Sistema de Gestión del Riesgo.
//
// Existe por un escenario concreto de campo: se levanta la ficha en una vereda
// sin cobertura, se guarda el teléfono en el bolsillo y la señal vuelve tres
// horas después, camino al casco urbano.
//
// Hace tres cosas para que eso funcione:
//
// - Guarda la aplicación al instalarse, para que se pueda abrir sin señal.
// - Guarda los catálogos del formulario, sin los cuales no hay nada que dibujar.
// - Envía las fichas de la cola cuando vuelve la conexión, aunque el censador ya
//   haya cerrado el navegador.
//
// Lo que sigue SIN guardarse, y esa es la regla que ordena todo: cualquier otra
// respuesta de la API. Llevan nombres, cédulas y direcciones de hogares
// damnificados, y servir eso rancio desde un teléfono sería un problema serio.
//
// El Service Worker solo corre en contexto seguro. De ahí la cabecera HSTS del
// .htaccess: sin ella, quien entre por http:// se queda sin envío en segundo
// plano y sin enterarse.

import { base, build, files, version } from '$service-worker';
import { RUTA_PLANTILLA } from '$lib/ficha-pdf/coordenadas';
import { RUTA_PLANTILLA as RUTA_INSPECCION } from '$lib/inspeccion-pdf/coordenadas';
import { seGuardaDeLaApi } from '$lib/offline/cacheables';
import { baseApi, ErrorDeRed, subirFotosDe } from '$lib/rufe-form/subida';
import {
	DESTINO,
	ETIQUETA_SYNC,
	borrarFotosDe,
	fichasPendientes,
	fotosDe,
	guardarFicha,
	tipoDe,
	tokenEspejado,
	type FichaEnCola,
	type FotoEnCola
} from '$lib/rufe-form/cola';

const sw = self as unknown as ServiceWorkerGlobalScope;

// ── La aplicación guardada en el teléfono ────────────────────────────────────
//
// Sin esto el sistema tenía una contradicción: se podía levantar una ficha sin
// señal, pero solo si la aplicación YA estaba abierta cuando se cayó la
// conexión. Un censador que la cerrara, o que llegara a una vereda sin datos, no
// tenía de dónde cargarla y no veía nada.
//
// El nombre de la caché lleva la versión del build. Al desplegar una versión
// nueva se crea otra caché y se borran las anteriores, así que es imposible
// quedarse servido de archivos viejos para siempre.

const CACHE = `sgr-${version}`;

/**
 * Caché aparte para las respuestas de la API que sí se pueden guardar.
 *
 * Hoy es UNA sola: los catálogos del formulario. Va separada del armazón para
 * que se vea de un vistazo qué datos vive el teléfono y para poder vaciarla sin
 * tocar la aplicación guardada.
 */
const CACHE_DATOS = `sgr-datos-${version}`;

/**
 * El armazón: lo que hay que tener guardado para que la aplicación arranque.
 *
 * Se deja fuera lo que no sirve sin conexión o que el servidor ni siquiera
 * entrega: `.htaccess` lo deniega Apache siempre, y `robots.txt` y la imagen de
 * vista previa solo los usan buscadores y redes sociales, que necesitan internet
 * por definición.
 */
const FUERA = [
	'.htaccess',
	'/robots.txt',
	'/og-sgr.jpg',
	// El formato oficial en blanco pesa 290 KB y solo lo necesita quien descarga
	// una ficha desde la bandeja, que es trabajo de escritorio. Descargarlo en la
	// instalación le costaría esos datos a cada censador que solo va a levantar
	// fichas. Se guarda igual la primera vez que alguien lo usa, así que a partir
	// de ahí la descarga funciona sin conexión.
	RUTA_PLANTILLA,
	// Y por lo mismo el formato de inspección, que pesa otros 220 KB.
	RUTA_INSPECCION,

	// Los iconos de la aplicación instalada. Desde que llevan el escudo oficial
	// en vez de tres letras sobre un cuadrado azul pesan unos 210 KB cada uno de
	// los grandes, y no los pide NUNCA la aplicación en marcha: los descarga el
	// sistema operativo al instalarla y a partir de ahí vive con su copia.
	// Guardarlos en el armazón le costaba medio mega de datos a cada censador
	// para dibujar algo que ya está en su pantalla de inicio.
	'/icono-512.png',
	'/icono-maskable-512.png',
	'/apple-touch-icon.png',

	// Y las capturas del manifiesto, por lo mismo y más: pesan cerca de medio
	// mega entre las dos y solo las mira el sistema operativo al ofrecer la
	// instalación, una vez. Guardarlas sería cobrarle esos datos a cada
	// censador para dibujar algo que él ya vio antes de instalar.
	'/captura-movil.png',
	'/captura-escritorio.png'
];

const ARMAZON = [`${base}/`, ...build, ...files].filter(
	(ruta) => !FUERA.some((f) => ruta.endsWith(f))
);

sw.addEventListener('install', (evento) => {
	const e = evento as ExtendableEvent;

	e.waitUntil(
		(async () => {
			const cache = await caches.open(CACHE);

			// Uno a uno y tolerando fallos: con `addAll`, un solo archivo que no
			// responda aborta la instalación entera y el teléfono se queda sin
			// aplicación guardada.
			await Promise.all(
				ARMAZON.map(async (ruta) => {
					try {
						const res = await fetch(ruta, { cache: 'no-cache' });
						if (res.ok) await cache.put(ruta, res);
					} catch {
						// Se seguirá pidiendo a la red cuando haga falta.
					}
				})
			);

			// ── Por qué YA NO se activa de inmediato ─────────────────────
			//
			// Antes había aquí un `skipWaiting()` sin condiciones, y eso rompía
			// las pestañas abiertas. La aplicación carga cada pantalla en un
			// archivo aparte y con el contenido en el nombre; al activarse la
			// versión nueva se borran las cachés viejas, y la pestaña que
			// seguía ejecutando la versión anterior pedía un archivo con el
			// nombre de antes: ya no está ni en la caché ni en el servidor.
			// Resultado, pantalla en blanco al pulsar cualquier enlace.
			//
			// Ahora la versión nueva espera, la pantalla avisa, y solo se
			// activa cuando la persona acepta (ver `aplicar-actualizacion`).
			//
			// Y no retrasa el envío de nada: la versión ANTERIOR sigue viva y
			// activa mientras tanto, con su cola y su `sync` funcionando. Lo
			// que espera es el cambio, no el trabajo.
			//
			// La excepción es la primera instalación: ahí no hay ninguna
			// pestaña que romper ni nada anterior que respetar, y hacerla
			// esperar dejaría la primera visita sin aplicación guardada.
			if (sw.registration.active === null) {
				await sw.skipWaiting();
			}
		})()
	);
});

sw.addEventListener('activate', (evento) => {
	const e = evento as ExtendableEvent;

	e.waitUntil(
		(async () => {
			// Fuera las cachés de versiones anteriores.
			await Promise.all(
				(await caches.keys())
					.filter((n) => n.startsWith('sgr-') && n !== CACHE && n !== CACHE_DATOS)
					.map((n) => caches.delete(n))
			);

			await sw.clients.claim();

			// Quien mira la versión nueva es la PÁGINA, vigilando el registro
			// (ver `+layout.svelte`): así se entera de que hay una esperando
			// antes de que se active, que es justo cuando hay que preguntar.
			// Este aviso queda para el caso en que se active sin que nadie la
			// haya aceptado —todas las pestañas cerradas—, y para las que
			// pudieran seguir abiertas con la versión anterior.
			await avisarALaPagina({ tipo: 'version-nueva', version });
		})()
	);
});

/**
 * De dónde sale cada cosa cuando el teléfono pide algo.
 *
 * Las reglas son distintas por tipo, y la más importante es la que NO cachea:
 * `/api/` lleva datos personales de hogares damnificados y no puede servirse
 * rancio. Sin señal falla, y de los envíos ya se encarga la cola.
 */
sw.addEventListener('fetch', (evento) => {
	const e = evento as FetchEvent;
	const peticion = e.request;

	if (peticion.method !== 'GET') return;

	const url = new URL(peticion.url);

	// Solo lo propio. Las hojas de Google y las tejas del mapa son de otros
	// dominios y necesitan datos frescos; que pasen de largo.
	if (url.origin !== sw.location.origin) return;

	if (url.pathname.startsWith('/api/')) {
		// De la API solo se guarda lo que está en la lista, y nada más.
		// La clave incluye la CONSULTA: `?estado=RECIBIDA` y `?estado=CONVERTIDA`
		// son dos bandejas distintas, y guardarlas bajo la misma ruta haría que
		// una filtrada enseñara los resultados de la otra.
		if (seGuardaDeLaApi(url.pathname)) {
			e.respondWith(responderConsulta(peticion, url.pathname + url.search));
		}

		return;
	}

	e.respondWith(responder(peticion, url));
});

/**
 * Cuánto vale una respuesta guardada.
 *
 * A las 24 h deja de servirse. Un dato de hace una semana sobre un hogar
 * damnificado —su estado, si ya se inspeccionó, si se le entregaron
 * materiales— es peor que no tener dato: se decide sobre una familia creyendo
 * saber algo que ya no es cierto.
 */
const VIGENCIA_MS = 24 * 60 * 60 * 1000;

/** Cuándo se guardó. La lee la página para poder decirlo en pantalla. */
const CABECERA_FECHA = 'X-SGR-Guardado';

/**
 * Una consulta al API: primero la red, y si no hay, la copia guardada.
 *
 * En ese orden porque un dato nuevo —una ficha que cambió de estado, un
 * corregimiento que se suma— debe verse en cuanto haya señal. La copia es la red
 * de seguridad para la vereda, no la fuente habitual.
 *
 * Lo guardado se marca con la fecha y caduca. Las dos cosas son deliberadas:
 * desde que el sistema entero funciona sin señal, en el aparato vive el censo
 * que esa persona consultó, y quien lo mira tiene derecho a saber de cuándo es.
 */
async function responderConsulta(peticion: Request, clave: string): Promise<Response> {
	const cache = await caches.open(CACHE_DATOS);

	try {
		const red = await fetch(peticion);

		if (red.ok) {
			// Se guarda una COPIA con la fecha añadida; lo que va a la página es la
			// respuesta de red tal cual. `Response` solo se puede leer una vez, de
			// ahí el `clone()`.
			const conFecha = new Response(red.clone().body, {
				status: red.status,
                statusText: red.statusText,
				headers: new Headers(red.headers)
			});

			conFecha.headers.set(CABECERA_FECHA, new Date().toISOString());
			void cache.put(clave, conFecha);
		}

		return red;
	} catch {
		const guardado = await cache.match(clave);

		if (guardado) {
			const cuando = guardado.headers.get(CABECERA_FECHA);
			const vieja = cuando !== null && Date.now() - Date.parse(cuando) > VIGENCIA_MS;

			// Sin fecha es de una versión anterior del Service Worker: se sirve, que
			// es lo que la persona espera, pero no se puede decir de cuándo.
			if (!vieja) return guardado;

			// Caducada: se tira. Dejarla ocupando sitio solo aplazaría el problema
			// hasta que alguien la viera creyendo que es de hoy.
			void cache.delete(clave);
		}

		throw new Error('Sin conexión y sin copia guardada.');
	}
}

/**
 * Vacía lo guardado del API.
 *
 * La pide la página al cerrar sesión, incluida la sesión que caduca sola. Es la
 * salvaguarda que impide que un teléfono prestado —o el de alguien que dejó el
 * contrato— conserve el censo que su antiguo dueño consultó.
 */
async function vaciarDatos(): Promise<void> {
	await caches.delete(CACHE_DATOS);
}

async function responder(peticion: Request, url: URL): Promise<Response> {
	const cache = await caches.open(CACHE);

	// Los archivos con hash en el nombre no cambian nunca: si cambia el
	// contenido, cambia el nombre. Se sirven de la caché sin preguntar.
	if (build.includes(url.pathname)) {
		const guardado = await cache.match(url.pathname);
		if (guardado) return guardado;
	}

	// Navegar a cualquier ruta devuelve el armazón. Se intenta primero la red
	// —así una versión nueva se ve en cuanto hay señal— y si no hay, el guardado.
	if (peticion.mode === 'navigate') {
		try {
			const red = await conTiempoLimite(peticion);
			if (red.ok) return red;
		} catch {
			// Sin señal, o tardando demasiado: se sigue con lo guardado.
		}

		const armazon = (await cache.match(`${base}/`)) ?? (await cache.match('/200.html'));
		if (armazon) return armazon;

		return sinConexion();
	}

	try {
		const red = await fetch(peticion);

		// Lo que se descargue bien se guarda para la próxima vez sin señal.
		if (red.ok && (build.includes(url.pathname) || files.includes(url.pathname))) {
			void cache.put(url.pathname, red.clone());
		}

		return red;
	} catch {
		const guardado = await cache.match(url.pathname);
		if (guardado) return guardado;

		// Lanzar aquí dejaba al navegador enseñando SU pantalla de error, la
		// del dinosaurio, que no dice nada de este sistema ni de que las
		// fichas guardadas siguen a salvo.
		return sinConexion();
	}
}

/** Cuánto se espera a la red antes de tirar de lo guardado. */
const ESPERA_RED_MS = 4000;

/**
 * Pedir a la red, pero sin quedarse esperando para siempre.
 *
 * ── Por qué hace falta ───────────────────────────────────────────────────────
 *
 * `fetch` solo se rechaza cuando la conexión FALLA. En una vereda con una raya
 * de señal no falla: se queda colgada, a veces minutos. Y como la respuesta de
 * la caché solo llegaba en el `catch`, la persona se quedaba mirando una
 * pantalla en blanco con la aplicación entera guardada en su propio teléfono.
 *
 * Cuatro segundos: bastante para una red lenta pero viva, poco para que alguien
 * de pie en un patio piense que se rompió.
 */
async function conTiempoLimite(peticion: Request): Promise<Response> {
	const corte = new AbortController();
	const reloj = setTimeout(() => corte.abort(), ESPERA_RED_MS);

	try {
		return await fetch(peticion, { signal: corte.signal });
	} finally {
		clearTimeout(reloj);
	}
}

/**
 * La última red: una página propia cuando no hay ni señal ni copia.
 *
 * Pasa poco —el armazón se guarda en la instalación— pero cuando pasa, lo que
 * la persona necesita saber es que lo suyo no se perdió.
 */
function sinConexion(): Response {
	return new Response(
		`<!doctype html>
<html lang="es-CO"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sin conexión — SGR Jamundí</title>
<style>
  body { margin:0; min-height:100vh; display:grid; place-items:center; padding:2rem;
         background:#0b1526; color:#eef3fb; text-align:center;
         font-family: system-ui, -apple-system, sans-serif; line-height:1.55 }
  h1 { font-size:1.25rem; margin:0 0 .6rem }
  p { margin:0 0 .5rem; color:#93a1bc; max-width:30rem }
  button { margin-top:1.2rem; padding:.6rem 1.2rem; border:0; border-radius:8px;
           background:#2e6fb0; color:#fff; font:inherit; font-weight:600; cursor:pointer }
</style></head>
<body><div>
  <h1>Sin conexión</h1>
  <p>No hay señal y esta pantalla todavía no estaba guardada en el aparato.</p>
  <p><strong>Lo que ya había registrado no se ha perdido</strong>: las fichas
     guardadas se envían solas en cuanto vuelva la cobertura.</p>
  <button onclick="location.reload()">Reintentar</button>
</div></body></html>`,
		{ status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
	);
}

// ── Avisos al aparato ───────────────────────────────────────────────────────
//
// El aviso llega VACÍO, a propósito: ni un nombre, ni una cédula, ni un barrio.
// Un aviso con contenido pasa por los servidores de Google o de Mozilla y,
// aunque va cifrado, el dato de una familia damnificada no tiene por qué salir
// de la Alcaldía para que a alguien le suene el teléfono. Esto es un golpe en
// la puerta; el dato se lee dentro del sistema, con sesión iniciada.
//
// El texto vive aquí y no viaja, así que decir «hay una solicitud nueva» no
// cuesta nada y no revela de quién.

const AVISO_ETIQUETA = 'sgr-solicitud-nueva';

sw.addEventListener('push', (evento) => {
	const e = evento as ExtendableEvent;

	// `userVisibleOnly` obliga a mostrar algo por cada aviso recibido: un
	// navegador que detecte pushes silenciosos retira el permiso. Así que aquí
	// SIEMPRE se dibuja una notificación, incluso si el aviso llegara raro.
	e.waitUntil(
		sw.registration.showNotification('Solicitud ciudadana nueva', {
			body: 'Una familia acaba de pedir la inspección de su vivienda.',
			icon: `${base}/icono-192.png`,
			badge: `${base}/icono-192.png`,
			// La misma etiqueta para todas: si entran cinco solicitudes mientras
			// el teléfono está guardado, se ve UNA notificación y no cinco.
			// Quien la abre las ve todas en la bandeja de todos modos.
			tag: AVISO_ETIQUETA,
			// Sin `renotify`: volver a sonar por cada una convertiría una tarde
			// movida en un motivo para desactivar los avisos.
			data: { ruta: `${base}/riesgo/preinscripciones` }
		})
	);
});

sw.addEventListener('notificationclick', (evento) => {
	const e = evento as ExtendableEvent & {
		notification: Notification & { data?: { ruta?: string } };
	};

	e.notification.close();

	const ruta = e.notification.data?.ruta ?? `${base}/`;

	e.waitUntil(
		(async () => {
			const abiertas = await sw.clients.matchAll({ type: 'window', includeUncontrolled: true });

			// Si el sistema ya está abierto se reutiliza esa ventana. Abrir una
			// segunda pestaña del mismo sistema es la forma más rápida de que
			// alguien pierda un formulario a medio llenar en la primera.
			for (const cliente of abiertas) {
				if (cliente.url.includes(ruta)) return cliente.focus();
			}

			for (const cliente of abiertas) {
				await cliente.navigate?.(ruta);

				return cliente.focus();
			}

			return sw.clients.openWindow(ruta);
		})()
	);
});

sw.addEventListener('sync', (evento) => {
	const e = evento as ExtendableEvent & { tag: string };

	if (e.tag === ETIQUETA_SYNC) {
		// waitUntil mantiene vivo el Service Worker hasta que la promesa termine.
		// Si se rechaza, el navegador reintenta con retroceso exponencial.
		e.waitUntil(enviarPendientes());
	}
});

// La página puede pedir un intento inmediato: al recuperar la conexión con la
// aplicación abierta no hay que esperar al evento del navegador.
sw.addEventListener('message', (evento) => {
	// La pantalla avisó de que hay versión nueva y la persona aceptó. Hasta
	// aquí la versión anterior seguía sirviendo, que es lo que impide que una
	// pestaña abierta se quede pidiendo archivos que ya no existen.
	if (evento.data?.tipo === 'aplicar-actualizacion') {
		void sw.skipWaiting();
	}

	if (evento.data?.tipo === 'enviar-pendientes') {
		evento.waitUntil?.(enviarPendientes());
		void enviarPendientes();
	}

	// Al cerrar sesión. `waitUntil` no es adorno: sin él el navegador puede
	// apagar el Service Worker antes de terminar de borrar, y el censo se
	// quedaría a medio vaciar en el aparato.
	if (evento.data?.tipo === 'vaciar-datos') {
		evento.waitUntil?.(vaciarDatos());
		void vaciarDatos();
	}
});

// ── Envío ────────────────────────────────────────────────────────────────────

/**
 * Recorre la cola y envía lo que pueda.
 *
 * Se lanza el error si algo queda pendiente por causas de red: eso hace que el
 * navegador vuelva a intentarlo. Si lo que falla es la validación o la sesión,
 * la ficha se marca y NO se pide reintento, porque reintentar lo mismo daría
 * igual y solo gastaría batería.
 */
async function enviarPendientes(): Promise<void> {
	// Las que el servidor ya rechazó se quedan quietas: reintentarlas en cada
	// evento de red gastaría batería sin cambiar nada. Solo el censador puede
	// desatascarlas, desde «Pendientes».
	const pendientes = (await fichasPendientes()).filter((f) => f.estado !== 'error');
	if (pendientes.length === 0) return;

	const token = await tokenEspejado();

	if (!token) {
		// Sin sesión no se puede enviar. Las fichas se quedan intactas y la
		// aplicación avisará al abrirse. No es un fallo de red: no se reintenta.
		await avisarALaPagina({ tipo: 'sesion-requerida', pendientes: pendientes.length });

		return;
	}

	let huboFalloDeRed = false;

	for (const ficha of pendientes) {
		const resultado = await enviarFicha(ficha, token);

		if (resultado === 'red') {
			huboFalloDeRed = true;
			break; // Sin red, seguir con las demás solo gasta batería.
		}

		if (resultado === 'sesion') {
			await avisarALaPagina({ tipo: 'sesion-requerida', pendientes: pendientes.length });

			return;
		}
	}

	await avisarALaPagina({ tipo: 'cola-cambiada' });

	if (huboFalloDeRed) {
		// Rechazar es lo que le pide al navegador que vuelva a intentarlo.
		throw new Error('Todavía no hay conexión.');
	}
}

type Resultado = 'ok' | 'red' | 'sesion' | 'rechazada';

async function enviarFicha(ficha: FichaEnCola, token: string): Promise<Resultado> {
	ficha.estado = 'enviando';
	ficha.intentos += 1;
	ficha.actualizadoEn = Date.now();
	await guardarFicha(ficha);

	// Las fotos van primero: el servidor las adopta al recibir la ficha, así que
	// si la ficha entrara antes se quedarían huérfanas hasta caducar.
	let carga: string | null = null;

	// Los dos formatos llevan fotos: el censo su documento y sus daños, la
	// inspección el registro fotográfico del numeral 11. `subirFotosDe` devuelve
	// null cuando la ficha no trae ninguna, así que no hace falta preguntar por
	// el tipo.
	try {
		carga = await subirFotosDe(ficha, token);
	} catch (e) {
		return marcar(ficha, e);
	}

	let respuesta: Response;

	try {
		// Cada formato va a su ruta. La tabla vive en `cola.ts` para que sumar un
		// tercero no obligue a tocar el Service Worker.
		respuesta = await fetch(`${baseApi()}${DESTINO[tipoDe(ficha)].ruta}`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
			body: JSON.stringify({ ...ficha.cuerpo, envio_id: ficha.envioId, ...(carga ? { carga } : {}) })
		});
	} catch {
		return marcar(ficha, new ErrorDeRed());
	}

	if (respuesta.status === 401) {
		ficha.estado = 'pendiente';
		ficha.error = 'La sesión venció. Inicie sesión para enviar las fichas pendientes.';
		await guardarFicha(ficha);

		return 'sesion';
	}

	if (respuesta.status >= 500 || respuesta.status === 429) {
		return marcar(ficha, new ErrorDeRed());
	}

	let datos: {
		ok?: boolean;
		data?: Record<string, unknown>;
		message?: string;
		errors?: Record<string, string>;
	};

	try {
		datos = await respuesta.json();
	} catch {
		return marcar(ficha, new ErrorDeRed());
	}

	if (!respuesta.ok || datos.ok === false) {
		// 4xx: los datos no sirven. Reintentar los mismos no cambiaría nada, así
		// que se marca para que una persona lo resuelva.
		ficha.estado = 'error';
		ficha.error = datos.message ?? 'El servidor rechazó la ficha.';
		ficha.errores = datos.errors && Object.keys(datos.errors).length > 0 ? datos.errors : undefined;
		ficha.actualizadoEn = Date.now();
		await guardarFicha(ficha);

		return 'rechazada';
	}

	ficha.estado = 'enviada';
	// El censo lo llama «radicado» y la inspección «número»; se guardan los dos
	// para que la pantalla de pendientes no tenga que saber cuál mirar.
	const identificador = datos.data?.[DESTINO[tipoDe(ficha)].clave];
	ficha.numero = typeof identificador === 'string' ? identificador : undefined;
	ficha.radicado = ficha.numero;
	ficha.error = undefined;
	ficha.errores = undefined;
	ficha.actualizadoEn = Date.now();
	await guardarFicha(ficha);
	await borrarFotosDe(ficha.envioId);

	return 'ok';
}

async function marcar(ficha: FichaEnCola, e: unknown): Promise<Resultado> {
	const esRed = e instanceof ErrorDeRed;

	ficha.estado = 'pendiente';
	ficha.error = esRed ? undefined : 'No se pudo enviar. Se reintentará.';
	ficha.actualizadoEn = Date.now();
	await guardarFicha(ficha);

	return esRed ? 'red' : 'red';
}

/** Si hay alguna pestaña abierta, se le cuenta lo que pasó. */
async function avisarALaPagina(mensaje: Record<string, unknown>): Promise<void> {
	const clientes = await sw.clients.matchAll({ includeUncontrolled: true, type: 'window' });

	for (const cliente of clientes) {
		cliente.postMessage({ origen: 'sgr-sw', version, ...mensaje });
	}
}
