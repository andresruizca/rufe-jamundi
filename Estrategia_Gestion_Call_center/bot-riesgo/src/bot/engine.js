// Autómata del bot. SIN IA.
//
// Función PURA de transición: (estado, texto, contexto) → (estado, contexto,
// respuestas). No toca base de datos, no envía nada, no mira el reloj. Todo el
// mundo exterior vive en service.js.
//
// Es lo que hace la conversación reproducible: misma entrada y mismo estado ⇒
// misma respuesta, siempre. En Gestión del Riesgo eso no es elegancia, es
// requisito: hay que poder demostrar qué se le respondió a un ciudadano.
//
// El motor NO conoce ningún nodo concreto. Los lee de flow.js.

import * as flujo from './flow.js';

/** Horas de silencio del bot tras un handoff a agente humano. */
export const HORAS_SILENCIO = 4;

/**
 * Estado transitorio que escribe el job de inactividad tras preguntar «¿sigues
 * ahí?». No es un nodo de flow.js: no tiene texto propio ni aparece en los
 * flujos. Vive acá porque es del motor, no del contenido.
 */
export const ESPERA_INACTIVIDAD = 'ESPERA_INACTIVIDAD';

/**
 * Conversación que todavía no empezó. Tampoco es un nodo de flow.js.
 *
 * Hace falta porque el nodo inicial es un nodo REAL —tiene texto y opciones—,
 * a diferencia de la referencia, donde INICIO saltaba enseguida a pedir la
 * cédula. Sin este centinela, quien está parado en el nodo inicial nunca podría
 * avanzar: cada mensaje se leería como «conversación nueva» y volvería a
 * saludar. Es el estado por defecto de una sesión recién creada o caducada.
 */
export const NUEVA = 'NUEVA';

/** Inactividad: se pregunta a los 5 min y se cierra 2 min después. */
export const INACTIVIDAD_MIN = 5;
export const GRACIA_MIN = 2;

// ── Reconocimiento de intención ────────────────────────────────────────
// No es un modelo: es un diccionario de sinónimos comparados por raíz de
// palabra. Nada se inventa.

export function normalizar(s) {
  return String(s ?? '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9ñ ]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Raíz aproximada: el español conjuga mucho ("reporto" vs "reportar"). */
const raiz = (w) => (w.length > 4 ? w.slice(0, 4) : w);

/**
 * Intención de un texto, o null si no reconoce nada — y entonces el bot repite
 * las opciones en vez de adivinar.
 * @param {string} texto
 */
export function detectarIntencion(texto) {
  const t = normalizar(texto);
  if (!t) return null;

  // Un número suelto es la vía rápida: es lo que el menú pide.
  if (/^\d$/.test(t)) {
    for (const [clave, lista] of Object.entries(flujo.INTENCIONES)) {
      if (lista.includes(t)) return clave;
    }
  }

  const raices = new Set(t.split(' ').filter(Boolean).map(raiz));

  let mejor = null, mejorPuntaje = 0;
  for (const [clave, lista] of Object.entries(flujo.INTENCIONES)) {
    let puntaje = 0;
    for (const frase of lista) {
      const n = normalizar(frase);
      if (!n || /^\d$/.test(n)) continue;
      if (t === n) { puntaje += 6; continue; }
      if (t.includes(n)) { puntaje += 2 + n.split(' ').length; continue; }
      const palabras = n.split(' ').filter((w) => w.length > 3);
      if (palabras.length && palabras.every((w) => raices.has(raiz(w)))) puntaje += 2;
    }
    if (puntaje > mejorPuntaje) { mejorPuntaje = puntaje; mejor = clave; }
  }
  return mejorPuntaje >= 2 ? mejor : null;
}

// ── Transición ─────────────────────────────────────────────────────────
/**
 * @param {object} p
 * @param {string} p.estado    nodo actual
 * @param {string} p.texto     lo que escribió el ciudadano
 * @param {any}    p.contexto
 * @returns {{estado:string, contexto:any, respuestas:string[], escalar:boolean}}
 */
export function transicionar({ estado, texto, contexto = {} }) {
  const ctx = { ...(contexto || {}) };
  const nodoActual = flujo.NODOS[estado] ?? flujo.NODOS[flujo.ESTADO_INICIAL];

  // Conversación nueva o recién cerrada: se entra al nodo inicial y se saluda,
  // sin interpretar lo que escribió (suele ser solo "hola"). Estar PARADO en el
  // nodo inicial es otra cosa y sí se interpreta — ver el comentario de NUEVA.
  if (estado === NUEVA || estado === 'CERRADA' ||
      (estado !== ESPERA_INACTIVIDAD && !flujo.NODOS[estado])) {
    return entrar(flujo.ESTADO_INICIAL, ctx, estado);
  }

  // ── Esperando confirmación de que sigue ahí ─────────────────────────
  // El job de inactividad deja la sesión en este estado; no es un nodo de
  // flow.js. Cualquier respuesta significa que sigue ahí: se retoma DONDE
  // ESTABA, no se reinicia. Reiniciar castigaría a quien tardó en contestar.
  if (estado === ESPERA_INACTIVIDAD) {
    const i = detectarIntencion(texto);
    if (i === 'no' || i === 'despedida') {
      return entrar('CERRADA', ctx, estado);
    }
    const previo = flujo.NODOS[ctx.estadoAnterior] ? ctx.estadoAnterior : flujo.ESTADO_INICIAL;
    // Si además escribió algo accionable, se atiende en vez de repetir el nodo.
    if (i) {
      const n = flujo.NODOS[previo];
      const destino = typeof n.siguiente === 'function'
        ? n.siguiente({ intencion: i, texto, contexto: ctx })
        : n.siguiente?.[i];
      if (destino && flujo.NODOS[destino]) return entrar(destino, ctx, estado);
    }
    return entrar(previo, ctx, estado);
  }

  // Un nodo escalado no transiciona: el agente humano tiene la palabra.
  if (nodoActual.escalar) {
    return { estado, contexto: ctx, respuestas: [], escalar: false };
  }

  // Captura literal: el nodo pide un dato, no una opción de menú.
  if (nodoActual.captura) {
    const valor = String(texto ?? '').trim();
    if (valor) {
      ctx[nodoActual.captura] = valor.slice(0, 500);
      const destino = typeof nodoActual.siguiente === 'function'
        ? nodoActual.siguiente({ texto: valor, contexto: ctx })
        : nodoActual.siguiente?.capturado;
      if (destino) return entrar(destino, ctx, estado);
    }
    return entrar(estado, ctx, estado, flujo.NO_ENTENDI);
  }

  const intencion = detectarIntencion(texto);

  // "Volver" y "saludo" son transversales: llevan al nodo inicial desde
  // cualquier parte, sin que cada nodo tenga que declararlo.
  if (intencion === 'volver' || intencion === 'saludo') {
    return entrar(flujo.ESTADO_INICIAL, ctx, estado);
  }

  const siguiente = typeof nodoActual.siguiente === 'function'
    ? nodoActual.siguiente({ intencion, texto, contexto: ctx })
    : nodoActual.siguiente?.[intencion];

  if (siguiente && flujo.NODOS[siguiente]) return entrar(siguiente, ctx, estado);

  // No se reconoció. Se repite el nodo actual con un prefijo. NUNCA se adivina.
  const porDefecto = nodoActual.porDefecto ?? estado;
  return entrar(porDefecto, ctx, estado, flujo.NO_ENTENDI);
}

/** Entra a un nodo: produce su texto y marca si escala. */
function entrar(destino, ctx, estadoPrevio, prefijo = '') {
  const nodo = flujo.NODOS[destino];
  if (destino !== estadoPrevio) ctx.estadoAnterior = estadoPrevio;

  const bruto = typeof nodo.texto === 'function' ? nodo.texto(ctx) : nodo.texto;
  const respuestas = (Array.isArray(bruto) ? bruto : [bruto]).filter(Boolean);
  if (prefijo && respuestas.length) respuestas[0] = prefijo + respuestas[0];

  return { estado: destino, contexto: ctx, respuestas, escalar: !!nodo.escalar };
}

export const ESTADO_INICIAL = flujo.ESTADO_INICIAL;
export const ESTADO_ESCALADO = flujo.ESTADO_ESCALADO;
