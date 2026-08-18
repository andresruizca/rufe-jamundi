/// <reference types="@sveltejs/kit" />
/// <reference lib="webworker" />

// Service Worker del Sistema de Gestión del Riesgo.
//
// Hace una sola cosa, y a propósito: enviar las fichas RUFE que quedaron en cola
// cuando vuelve la señal, aunque el censador ya haya cerrado el navegador. Ese
// es el escenario real de campo — se levanta la ficha en una vereda sin
// cobertura, se guarda el teléfono en el bolsillo y la señal vuelve tres horas
// después, camino al casco urbano.
//
// Lo que NO hace, y por qué:
//
// - NO cachea la aplicación para uso sin conexión. Sería la siguiente
//   funcionalidad natural, pero cachear mal es peor que no cachear: un
//   funcionario trabajando con una versión vieja del formulario, sin saberlo, es
//   un problema difícil de diagnosticar. Va aparte, con su propia decisión.
// - NO intercepta peticiones. Sin `fetch` no puede degradar nada que hoy
//   funcione.
//
// El Service Worker solo corre en contexto seguro. De ahí la cabecera HSTS del
// .htaccess: sin ella, quien entre por http:// se queda sin envío en segundo
// plano y sin enterarse.

import { version } from '$service-worker';
import {
	ETIQUETA_SYNC,
	borrarFotosDe,
	fichasPendientes,
	fotosDe,
	guardarFicha,
	tokenEspejado,
	type FichaEnCola,
	type FotoEnCola
} from '$lib/rufe-form/cola';

const sw = self as unknown as ServiceWorkerGlobalScope;

/** Misma resolución que el cliente de la API, pero sin `$app/environment`. */
const API_BASE = sw.location.hostname === 'localhost' || sw.location.hostname === '127.0.0.1'
	? 'http://localhost:8000'
	: '/api';

sw.addEventListener('install', () => {
	// Se activa de inmediato: no hay caché vieja que preservar, y esperar a que
	// se cierren las pestañas solo retrasaría el envío de fichas pendientes.
	void sw.skipWaiting();
});

sw.addEventListener('activate', (evento) => {
	evento.waitUntil(sw.clients.claim());
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
	if (evento.data?.tipo === 'enviar-pendientes') {
		evento.waitUntil?.(enviarPendientes());
		void enviarPendientes();
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
	const pendientes = await fichasPendientes();
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

	try {
		carga = await subirFotos(ficha, token);
	} catch (e) {
		return marcar(ficha, e);
	}

	let respuesta: Response;

	try {
		respuesta = await fetch(`${API_BASE}/rufe/reportes`, {
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

	let datos: { ok?: boolean; data?: { radicado?: string }; message?: string };

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
		ficha.actualizadoEn = Date.now();
		await guardarFicha(ficha);

		return 'rechazada';
	}

	ficha.estado = 'enviada';
	ficha.radicado = datos.data?.radicado;
	ficha.error = undefined;
	ficha.actualizadoEn = Date.now();
	await guardarFicha(ficha);
	await borrarFotosDe(ficha.envioId);

	return 'ok';
}

/** Abre una carga y sube las fotos que falten. Devuelve el token de la carga. */
async function subirFotos(ficha: FichaEnCola, token: string): Promise<string | null> {
	const fotos = await fotosDe(ficha.envioId);
	if (fotos.length === 0) return null;

	const carga = await abrirCarga(token);

	for (const foto of fotos) {
		if (foto.subida) continue;
		await subirFoto(carga, foto, token);
	}

	return carga;
}

async function abrirCarga(token: string): Promise<string> {
	const respuesta = await fetch(`${API_BASE}/rufe/cargas`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
		body: '{}'
	}).catch(() => {
		throw new ErrorDeRed();
	});

	if (!respuesta.ok) throw new ErrorDeRed();

	const datos = await respuesta.json().catch(() => {
		throw new ErrorDeRed();
	});

	return datos.data.carga as string;
}

async function subirFoto(carga: string, foto: FotoEnCola, token: string): Promise<void> {
	const cuerpo = new FormData();
	cuerpo.append('tipo', foto.tipo);
	cuerpo.append('archivo', foto.blob, foto.nombre);

	const respuesta = await fetch(`${API_BASE}/rufe/cargas/${carga}/archivos`, {
		method: 'POST',
		headers: { Authorization: `Bearer ${token}` },
		body: cuerpo
	}).catch(() => {
		throw new ErrorDeRed();
	});

	// Una foto rechazada por su formato no debe impedir que la ficha salga: el
	// dato del hogar vale mucho más que una evidencia. Se deja constancia y se
	// sigue.
	if (!respuesta.ok && respuesta.status < 500) return;
	if (!respuesta.ok) throw new ErrorDeRed();
}

class ErrorDeRed extends Error {}

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
