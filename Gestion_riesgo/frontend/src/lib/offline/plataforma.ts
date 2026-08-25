// Qué sabe hacer ESTE navegador, que no es lo mismo que este aparato.
//
// `$lib/aparato` responde «¿teléfono, tableta o computador?», que sirve para
// hablarle bien a la persona. Esto responde otra cosa: qué puede prometerle el
// sistema, y eso lo decide el NAVEGADOR.
//
// La diferencia que importa es una: **Background Sync solo existe en Chrome y
// derivados**. Safari no lo implementa, y en un iPhone todos los navegadores son
// Safari por dentro —Chrome, Firefox y Edge de iOS usan WebKit por obligación
// de Apple—, así que en cualquier iPhone NO hay envío en segundo plano.
//
// Consecuencia práctica, y es la razón de este archivo: decirle a un censador
// con iPhone «se enviará sola aunque cierre la aplicación» es que la cierre
// tranquilo y que su ficha no llegue nunca. La ficha no se pierde —sigue en la
// cola— pero nadie la manda hasta que vuelva a abrir.
//
// Lo que NO se detecta aquí es el navegador por su nombre. Se pregunta por la
// capacidad concreta (`'sync' in registro`), que es lo único que no envejece:
// el día que Safari implemente Background Sync, esto lo dará por bueno solo.

/**
 * ¿Este navegador es Safari de iPhone o iPad?
 *
 * Solo para EXPLICAR. Nunca para decidir si se puede enviar en segundo plano:
 * eso se pregunta a la API, no al agente de usuario.
 */
export function esWebKitDeApple(): boolean {
	if (typeof navigator === 'undefined') return false;

	const ua = navigator.userAgent;

	// El iPad se anuncia como Mac desde iPadOS 13; lo delata el táctil.
	const esAparatoApple =
		/iPhone|iPod|iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);

	return esAparatoApple;
}

/**
 * ¿Este navegador entrega el evento `sync` con la aplicación cerrada?
 *
 * Es una SONDA: mira si la capacidad existe y no registra nada. Distinta de
 * `pedirEnvioEnSegundoPlano()` (`$lib/rufe-form/cola.ts`), que además apunta un
 * envío — eso lo hace el formulario cuando tiene algo que mandar, no el armazón
 * al arrancar.
 *
 * Se pregunta a la API y no al nombre del navegador: el día que Safari lo
 * implemente, esto lo dará por bueno sin que nadie toque nada.
 */
export async function haySincronizacionEnSegundoPlano(): Promise<boolean> {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return false;

	try {
		return 'sync' in (await navigator.serviceWorker.ready);
	} catch {
		return false;
	}
}

/**
 * Lo que hay que decirle a alguien cuya cola no sale sola.
 *
 * En iPhone se nombra el motivo —es de Safari, no del sistema— porque si no,
 * suena a que la aplicación está a medio hacer y el equipo pierde tiempo
 * buscando un fallo que no existe.
 */
export function porQueNoSaleSolo(): string {
	return esWebKitDeApple()
		? 'En iPhone y iPad, Safari no permite enviar en segundo plano.'
		: 'Este navegador no permite enviar en segundo plano.';
}
