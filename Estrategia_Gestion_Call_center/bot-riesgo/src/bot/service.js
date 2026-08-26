// Orquestador: sesión, idempotencia, límites, envío y handoff.
//
// El autómata (engine.js) es puro; acá vive todo lo que toca el mundo.

import { prisma } from '../config/database.js';
import { transicionar, HORAS_SILENCIO, NUEVA } from './engine.js';
import * as flujo from './flow.js';
import { enviarTexto } from '../wasphere/client.js';

/** Tras esta inactividad, la conversación vuelve a empezar de cero. */
const CADUCIDAD_MIN = 30;

/** Tope antiabuso por número. */
const LIMITE_MENSAJES = 20;
const LIMITE_VENTANA_MS = 10 * 60 * 1000;

/**
 * JID → teléfono.
 *
 *   573157729890@s.whatsapp.net  → teléfono real
 *   44736412909700@lid           → LID: identificador de privacidad de
 *                                  WhatsApp, NO es un número de teléfono.
 * Devuelve null para los LID: usarlos como teléfono no identificaría a nadie.
 */
function jidATelefono(jid) {
  if (!jid) return null;
  const s = String(jid);
  if (s.includes('@lid')) return null;
  const d = s.split('@')[0].split(':')[0].replace(/\D/g, '');
  return d || null;
}

/**
 * Extrae el mensaje de un evento de WaSphere.
 *
 * Formato real, confirmado del tráfico en producción de la referencia:
 *
 *   { event: "message.received",
 *     data: { from: "…@lid", senderPn: "573157729890@s.whatsapp.net",
 *             content: { text: "hola" },
 *             message: { key: { id, fromMe, senderPn, remoteJid },
 *                        message: { conversation: "hola" } },
 *             messageId, senderJid, senderLid, timestamp },
 *     sessionId, timestamp, deliveryId }
 *
 * `from` trae el LID, NO el teléfono: el número real vive en `senderPn` o
 * `senderJid`. Preferirlos es lo que permite identificar a la persona.
 *
 * Es deliberadamente tolerante: si no reconoce la forma devuelve null y el
 * evento crudo se guarda igual, que es como se descubre un formato nuevo.
 */
export function extraerMensaje(body) {
  if (!body || typeof body !== 'object') return null;

  const d = body.data ?? body.message ?? body.payload ?? body;
  const interno = d.message ?? {};
  const clave = interno.key ?? {};

  const telefono =
       jidATelefono(d.senderPn)
    ?? jidATelefono(d.senderJid)
    ?? jidATelefono(clave.senderPn)
    ?? jidATelefono(d.from)
    ?? jidATelefono(d.sender)
    ?? jidATelefono(clave.remoteJid)
    ?? jidATelefono(d.remoteJid ?? d.chatId);

  const texto =
       d.content?.text
    ?? interno.message?.conversation
    ?? interno.message?.extendedTextMessage?.text
    ?? d.text ?? d.body ?? d.caption
    ?? d.message?.conversation
    ?? null;

  const id = d.messageId ?? clave.id ?? d.id ?? body.id ?? null;
  const fromMe = clave.fromMe ?? d.fromMe ?? false;

  // Dirección EXACTA por la que llegó la conversación. Ver destinoDeRespuesta.
  const jid = [d.from, clave.remoteJid, d.senderLid, d.remoteJid, d.chatId]
    .map((v) => (v == null ? null : String(v)))
    .find((v) => v && v.includes('@')) ?? null;

  if (telefono || texto) {
    return {
      externalId: id ? String(id) : null,
      phone: telefono,
      jid,
      text: texto != null ? String(texto) : null,
      // Los ecos de mensajes propios NO deben procesarse: el bot se
      // respondería a sí mismo en un bucle.
      esEntrante: !fromMe,
      evento: body.event ?? null,
      esGrupo: d.isGroup === true
    };
  }

  // Baileys crudo: { messages: [ { key, message } ] }
  const m = Array.isArray(body.messages) ? body.messages[0] : null;
  if (m) {
    return {
      externalId: m.key?.id ? String(m.key.id) : null,
      phone: jidATelefono(m.key?.senderPn) ?? jidATelefono(m.key?.remoteJid),
      jid: m.key?.remoteJid ? String(m.key.remoteJid) : null,
      text: m.message?.conversation ?? m.message?.extendedTextMessage?.text ?? null,
      esEntrante: !m.key?.fromMe,
      evento: body.event ?? null,
      esGrupo: false
    };
  }

  return null;
}

/**
 * A qué dirección responder.
 *
 * WhatsApp direcciona por LID (`…@lid`) en vez de por teléfono cuando el
 * contacto tiene activada la privacidad de número; el teléfono real solo viaja
 * como metadato en `senderPn`.
 *
 * Responder al número en vez de al LID hace que Baileys trate cada dirección
 * como una identidad distinta: nuestro envío refresca la sesión Signal bajo la
 * identidad del teléfono, y el siguiente mensaje del ciudadano —cifrado contra
 * la identidad LID— ya no descifra. El proveedor lo entrega con `content: {}`.
 * Medido en la referencia: fallaba el mensaje siguiente a cada envío, 7 de 7.
 */
export const destinoDeRespuesta = ({ jid, phone }) => jid || phone;

/** ¿Superó el límite de mensajes por ventana? */
async function excedeLimite(phone) {
  const desde = new Date(Date.now() - LIMITE_VENTANA_MS);
  const n = await prisma.botEvent.count({
    where: { phone, direction: 'in', createdAt: { gte: desde } }
  });
  return n > LIMITE_MENSAJES;
}

/** Carga o crea la sesión, reiniciándola si caducó. */
async function cargarSesion(phone, jid) {
  const s = await prisma.botSession.findUnique({ where: { phone } });
  if (!s) {
    return prisma.botSession.create({
      data: { phone, jid, state: NUEVA, context: {} }
    });
  }

  const escalada = s.mutedUntil && new Date(s.mutedUntil) > new Date();
  const inactiva = Date.now() - new Date(s.lastSeenAt).getTime() > CADUCIDAD_MIN * 60_000;

  // Tras media hora la conversación empieza de nuevo: retomar un menú a medias
  // al día siguiente confunde más de lo que ayuda. Una sesión ESCALADA no se
  // reinicia sola: el agente humano sigue atendiendo.
  if (inactiva && !escalada) {
    return prisma.botSession.update({
      where: { id: s.id },
      data: { state: NUEVA, context: {}, jid: jid ?? s.jid }
    });
  }
  if (jid && jid !== s.jid) {
    return prisma.botSession.update({ where: { id: s.id }, data: { jid } });
  }
  return s;
}

/**
 * Procesa un mensaje entrante de punta a punta.
 * Nunca lanza: cualquier fallo se registra y la persona recibe un mensaje
 * neutro en vez de silencio.
 */
export async function procesarMensaje(msg) {
  const phone = String(msg.phone || '').replace(/\D/g, '');
  if (!phone) return { ok: false, motivo: 'sin_telefono' };

  // Idempotencia: el índice único sobre external_id hace que un reintento del
  // webhook choque acá y no vuelva a responder.
  let evento;
  try {
    evento = await prisma.botEvent.create({
      data: {
        externalId: msg.externalId || null,
        phone, direction: 'in',
        text: msg.text ? String(msg.text).slice(0, 2000) : null,
        raw: msg.raw ?? undefined
      }
    });
  } catch (e) {
    if (e?.code === 'P2002') return { ok: true, motivo: 'duplicado' };
    throw e;
  }

  if (await excedeLimite(phone)) return { ok: false, motivo: 'limite' };

  const sesion = await cargarSesion(phone, msg.jid ?? null);

  // ── HANDOFF ────────────────────────────────────────────────────────────
  // Silenciado tras escalar. El mensaje YA está en el Team Inbox de WaSphere
  // —llegó al mismo número— así que los agentes lo ven y responden desde ahí.
  // El handoff es, literalmente, dejar de hablar.
  if (sesion.mutedUntil && new Date(sesion.mutedUntil) > new Date()) {
    await prisma.botEvent.update({
      where: { id: evento.id },
      data: { stateBefore: sesion.state, stateAfter: sesion.state }
    }).catch(() => {});
    return { ok: true, motivo: 'atendido_por_agente' };
  }

  let resultado;
  try {
    resultado = transicionar({
      estado: sesion.state,
      texto: msg.text || '',
      contexto: sesion.context || {}
    });
  } catch (e) {
    console.error('[bot] fallo en la transición:', e);
    await prisma.botEvent.update({
      where: { id: evento.id }, data: { error: String(e.message).slice(0, 400) }
    }).catch(() => {});
    await enviar(phone, 'Tuvimos un problema procesando tu mensaje. Escribe "agente" y te ayudamos.', {
      destino: destinoDeRespuesta({ jid: msg.jid, phone })
    });
    return { ok: false, motivo: 'error_transicion' };
  }

  await prisma.botSession.update({
    where: { id: sesion.id },
    data: {
      state: resultado.estado,
      context: resultado.contexto ?? {},
      lastSeenAt: new Date(),
      ...(resultado.escalar ? { mutedUntil: new Date(Date.now() + HORAS_SILENCIO * 3600_000) } : {})
    }
  });
  await prisma.botEvent.update({
    where: { id: evento.id },
    data: { stateBefore: sesion.state, stateAfter: resultado.estado }
  }).catch(() => {});

  const destino = destinoDeRespuesta({ jid: msg.jid ?? sesion.jid, phone });
  for (const texto of resultado.respuestas || []) {
    await enviar(phone, texto, { destino });
  }

  if (resultado.escalar) await avisarDelEscalamiento(phone);

  return { ok: true, estado: resultado.estado, respuestas: resultado.respuestas?.length ?? 0 };
}

/**
 * Avisa que el mensaje llegó ilegible y pide reenviarlo.
 *
 * Pasa cuando el proveedor entrega el evento sin contenido descifrable. Es
 * preferible pedir que reescriba a quedarse callado: el silencio parece que el
 * bot se colgó.
 */
export async function responderIlegible(phone, jid = null) {
  const sesion = await prisma.botSession.findUnique({
    where: { phone }, select: { state: true, jid: true }
  }).catch(() => null);

  const texto = 'No me llegó bien tu mensaje. ' +
    (flujo.PRECISION_ILEGIBLE?.[sesion?.state] ?? '¿Puedes escribirlo de nuevo?');

  // El marcador se reserva ANTES de enviar. WaSphere tarda 4-12 s en despachar,
  // y marcar después deja abierta esa ventana: los mensajes que llegan mientras
  // tanto ven cero avisos y responden todos. Quien escribe la fila, envía.
  const marca = await prisma.botEvent.create({
    data: { phone, direction: 'out', text: texto, error: 'aviso_ilegible' }
  }).catch(() => null);

  // Un mensaje ilegible ES actividad: la persona está escribiendo aunque el
  // proveedor no lo descifre. Sin esto el bot se despediría por inactividad
  // justo mientras el otro insiste.
  await prisma.botSession.updateMany({
    where: { phone }, data: { lastSeenAt: new Date() }
  }).catch(() => {});

  const r = await enviar(phone, texto, {
    registrar: false,
    destino: destinoDeRespuesta({ jid: jid ?? sesion?.jid, phone })
  });

  if (marca && !r?.ok) {
    await prisma.botEvent.update({
      where: { id: marca.id },
      data: { error: `aviso_ilegible · ${String(r?.error || 'envío fallido').slice(0, 300)}` }
    }).catch(() => {});
  }
  return r;
}

/** Envía y deja rastro. `registrar:false` para quien ya escribió su fila. */
export async function enviar(phone, texto, { registrar = true, destino = null } = {}) {
  try {
    // Se envía al `destino` (el JID del hilo) pero se audita bajo el teléfono,
    // que es como se busca en el panel.
    const r = await enviarTexto(destino || phone, texto);
    if (!registrar) return r;
    await prisma.botEvent.create({
      data: {
        externalId: r?.id ? String(r.id) : null,
        phone, direction: 'out', text: String(texto).slice(0, 2000),
        error: r?.ok ? null : String(r?.error || 'envío fallido').slice(0, 400)
      }
    }).catch(() => {});
    return r;
  } catch (e) {
    console.error('[bot] no se pudo responder:', e.message);
    return { ok: false, error: e.message };
  }
}

/**
 * Deja constancia de que alguien está esperando a un agente.
 *
 * PUNTO DE INTEGRACIÓN: hoy solo escribe en la bitácora. Cuando exista el
 * backend del SGR, aquí va la notificación al equipo para que no dependa de
 * que un agente mire la bandeja por casualidad.
 */
async function avisarDelEscalamiento(phone) {
  try {
    await prisma.botEvent.create({
      data: { phone, direction: 'out', error: 'escalado_a_agente', text: null }
    });
    console.log(`[bot] escalado a agente humano: ${phone} — visible en el Team Inbox`);
  } catch (e) {
    console.error('[bot] no se pudo registrar el escalamiento:', e.message);
  }
}

export const _internals = { CADUCIDAD_MIN, LIMITE_MENSAJES, jidATelefono };
