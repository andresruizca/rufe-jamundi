// Cuándo se le pide a Android que envíe lo pendiente.
//
// El envío en sí lo hace `SyncWorker.kt`, con la aplicación cerrada si hace
// falta. Esto solo decide CUÁNDO despertarlo, y existe por una razón muy
// concreta: Android no ejecuta trabajo periódico más seguido que cada quince
// minutos.
//
// Sin estos avisos, alguien que llena el formulario, sale al patio donde hay
// señal y se queda mirando la pantalla puede esperar un cuarto de hora leyendo
// «se enviará en cuanto haya internet» con internet delante. Funciona, pero
// parece que no — y esa diferencia es la que hace que alguien desinstale.
//
// Tres momentos, que son los del plan:
//
//   1. Al recuperar la red.
//   2. Al volver a abrir la aplicación.
//   3. La tarea periódica, que la programa `SgrApplication` y sigue ahí como
//      red de seguridad para cuando nadie abre nada.

import { registerPlugin } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Network } from '@capacitor/network';

type Sincronizacion = {
	sincronizarAhora(): Promise<void>;
};

const Sincronizacion = registerPlugin<Sincronizacion>('Sincronizacion');

/**
 * Pide un envío inmediato. Nunca falla hacia fuera.
 *
 * En el navegador —`npm run dev`— el plugin no existe y esto no hace nada, que
 * es lo correcto: no hay Android que despertar.
 */
export async function sincronizarAhora(): Promise<void> {
	try {
		await Sincronizacion.sincronizarAhora();
	} catch {
		// Sin plugin nativo no hay nada que pedir. No es un error que contarle a
		// nadie: la tarea periódica sigue haciendo su trabajo.
	}
}

let yaEscuchando = false;

/**
 * Engancha los dos avisos. Idempotente: la llaman varias pantallas.
 *
 * Devuelve la función para soltarlos, aunque en la práctica viven lo que vive
 * la aplicación.
 */
export async function escucharParaSincronizar(): Promise<void> {
	if (yaEscuchando) return;
	yaEscuchando = true;

	try {
		// 1 · La red vuelve. Es el momento más valioso: el teléfono acaba de
		//     entrar en cobertura y lo pendiente puede salir ya.
		await Network.addListener('networkStatusChange', (estado) => {
			if (estado.connected) void sincronizarAhora();
		});

		// 2 · La aplicación vuelve al frente. Cubre el caso de quien la abre a
		//     propósito para ver si su solicitud ya salió.
		await App.addListener('appStateChange', (estado) => {
			if (estado.isActive) void sincronizarAhora();
		});

		// Y una vez al arrancar, por si ya había señal desde antes.
		const ahora = await Network.getStatus();
		if (ahora.connected) void sincronizarAhora();
	} catch {
		// En el navegador estos complementos no existen. La aplicación tiene que
		// seguir funcionando igual: `npm run dev` es donde se prueba el
		// formulario, y ahí no hay nada que sincronizar.
	}
}
