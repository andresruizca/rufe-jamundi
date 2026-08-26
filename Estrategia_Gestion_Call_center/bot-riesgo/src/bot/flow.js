// ══════════════════════════════════════════════════════════════════════════
//  AQUÍ VA EL CONTENIDO DE GESTIÓN DEL RIESGO
// ══════════════════════════════════════════════════════════════════════════
//
// Este es el ÚNICO archivo que hay que llenar para poner el bot en servicio.
// El resto del esqueleto (webhook, firma, sesiones, idempotencia, límites,
// inactividad, envío, handoff) ya está resuelto y no debería tocarse.
//
// Nada de esto está definido todavía: se define contigo en la fase siguiente.
//
// ── Cómo se declara un flujo ─────────────────────────────────────────────
//
// INTENCIONES: diccionario de sinónimos. Se comparan por RAÍZ de palabra, así
// que "reporto", "reportar" y "reporte" caen en el mismo sitio sin listarlos
// todos. Un número suelto es la vía rápida del menú.
//
// NODOS: un objeto por estado. Cada nodo declara:
//   texto     — qué dice el bot al ENTRAR al nodo. Función de (contexto).
//   siguiente — mapa intención → estado destino, o función para casos que
//               dependan de lo escrito.
//   captura   — (opcional) guarda el texto crudo en contexto[clave] en vez de
//               interpretarlo como intención. Para nombres, direcciones, etc.
//   escalar   — true si entrar a este nodo silencia al bot y deja el hilo al
//               agente humano en el Team Inbox.
//
// El autómata (engine.js) NO conoce ningún nodo concreto: los lee de aquí.

/** Diccionario de intenciones. Vacío a propósito. */
export const INTENCIONES = {
  // Estas tres son transversales y conviene conservarlas en cualquier flujo.
  agente: ['agente', 'asesor', 'persona', 'humano', 'ayuda', 'hablar', 'operador'],
  volver: ['0', 'volver', 'menu', 'atras', 'regresar', 'inicio', 'opciones'],
  saludo: ['hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches'],
  // El motor las usa para resolver «¿sigues ahí?». Conservarlas.
  si: ['si', 'sí', 'sigo', 'aqui', 'aquí', 'presente', 'continuar', 'dale'],
  no: ['no', 'chao', 'adios', 'gracias', 'listo', 'terminar', 'salir']
};

/** Estado en el que arranca toda conversación nueva. */
export const ESTADO_INICIAL = 'INICIO';

/** Estado de handoff. El motor lo trata distinto: silencia al bot. */
export const ESTADO_ESCALADO = 'ESCALADO';

/**
 * Nodos del autómata.
 *
 * Los tres que están son el mínimo para que el esqueleto funcione de punta a
 * punta y se pueda probar el webhook completo. Se REEMPLAZAN por los flujos
 * reales, no se construye encima.
 */
export const NODOS = {
  INICIO: {
    texto: () =>
      'Hola 👋 Este es el canal de *Gestión del Riesgo*.\n\n' +
      '_(Los flujos de atención todavía no están configurados.)_\n\n' +
      'Escribe *agente* si necesitas hablar con una persona.',
    siguiente: {
      agente: 'ESCALADO'
    },
    // Cualquier otra cosa vuelve a INICIO y repite. Nunca se adivina.
    porDefecto: 'INICIO'
  },

  ESCALADO: {
    texto: () =>
      '*En un momento te atiende una persona del equipo.* 👤\n\n' +
      'No te respondo más por ahora para no interrumpir.',
    escalar: true,
    // Un nodo escalado no transiciona: el bot está callado.
    siguiente: {},
    porDefecto: 'ESCALADO'
  },

  CERRADA: {
    texto: () => 'Cierro la conversación. Cuando quieras, escribe de nuevo. 👋',
    siguiente: {},
    porDefecto: 'INICIO'
  }
};

/** Texto cuando no se reconoce lo que escribió. Nunca se adivina. */
export const NO_ENTENDI = 'No entendí. ';

/** Aviso de inactividad y despedida. */
export const TEXTO_SIGUE_AHI =
  '¿Sigues ahí? 🙂\n\nSi necesitas algo más, escribe. Si no, cierro la conversación en un momento.';
export const TEXTO_DESPEDIDA =
  'Cierro la conversación por inactividad.\n\nCuando quieras, escribe de nuevo. 👋';

/** Qué decir cuando el mensaje llegó ilegible, según el nodo en que estaba. */
export const PRECISION_ILEGIBLE = {
  // INICIO: '¿Puedes escribirlo de nuevo?',
};
