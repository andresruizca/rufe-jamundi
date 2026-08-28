// El reloj del vigilante de inactividad. SIN dependencias, a propósito.
//
// Aquí vive la única decisión difícil de todo el vigilante: cuándo hay que
// escribirle a alguien que se quedó callado. Va aparte y sin importar nada
// —ni cron, ni base de datos, ni red— para poder comprobarla entera en
// milisegundos. Es la clase de código donde un signo cambiado hace que el bot
// escriba a destiempo a mil trescientas familias, y eso no se descubre
// mirándolo.

/** Silencio tras el que se pregunta si sigue ahí. */
export const MINUTOS_AVISO = Number(process.env.ZAVU_MINUTOS_AVISO || 5);

/** Silencio ADICIONAL, contado desde el aviso, antes de cerrar. */
export const MINUTOS_GRACIA = Number(process.env.ZAVU_MINUTOS_GRACIA || 2);

export const TEXTO_AVISO =
  '¿Sigues ahí? 🙂\n\nSi necesitas algo más, escribe. Si no, cierro la conversación en un momento.';

export const TEXTO_DESPEDIDA =
  'Cierro la conversación por inactividad.\n\nCuando quieras, escribe de nuevo. ' +
  'Gracias por comunicarte con Gestión del Riesgo de la Alcaldía de Jamundí 👋';

/**
 * Qué hacer con una conversación. No toca nada: devuelve la intención.
 *
 * `ultimo` es el último mensaje del hilo, tal como lo da Zavu. Si es NUESTRO
 * (`outbound`), significa que le respondimos y no ha vuelto a escribir, y su
 * `at` marca el principio del silencio. Si es suyo (`inbound`), está en
 * conversación y no hay nada que vigilar.
 *
 * @param {{direction:string, at:string|null}|null} ultimo
 * @param {'AVISADO'|'CERRADO'|null} etapa  lo que ya se le hizo
 * @param {Date} ahora
 * @returns {'nada'|'avisar'|'despedir'|'olvidar'}
 */
export function decidir(ultimo, etapa, ahora = new Date()) {
  if (!ultimo || !ultimo.at) return 'nada';

  // Escribió él: el silencio se rompió. Si había marca, se olvida — el reloj
  // vuelve a cero y podrá volver a avisarse si calla de nuevo.
  if (ultimo.direction === 'inbound') {
    return etapa ? 'olvidar' : 'nada';
  }

  const minutos = (ahora.getTime() - new Date(ultimo.at).getTime()) / 60_000;

  // Ya se despidió: no se le vuelve a escribir por mucho que siga callado.
  // Sin esto, cada pasada le mandaría otra despedida, un minuto tras otro.
  if (etapa === 'CERRADO') return 'nada';

  // Ya se le preguntó. La gracia cuenta desde ESE aviso, que es ahora el
  // último mensaje del hilo — no desde que empezó el silencio.
  if (etapa === 'AVISADO') {
    return minutos >= MINUTOS_GRACIA ? 'despedir' : 'nada';
  }

  return minutos >= MINUTOS_AVISO ? 'avisar' : 'nada';
}
