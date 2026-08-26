// Webhook de WaSphere. Endpoint PÚBLICO.
//
// ACUSA RECIBO DE INMEDIATO y procesa en segundo plano: WaSphere reintenta si
// la respuesta tarda, y un reintento significa responder dos veces. La
// idempotencia por external_id lo cubre igual, pero es mejor no provocarlo.

import { Router } from 'express';
import { prisma } from '../config/database.js';
import { env } from '../config/env.js';
import { verificarFirma } from './verify.js';
import { extraerMensaje, procesarMensaje, responderIlegible } from '../bot/service.js';

export const webhookRouter = Router();

/** Deja constancia de un evento que no se procesó, con el motivo. */
const descartar = (datos) => prisma.botEvent.create({ data: datos }).catch(() => {});

webhookRouter.post('/', async (req, res) => {
  // Acuse inmediato: nada de trabajo antes de responder. Se responde 200
  // incluso ante firma inválida — un 401 le confirmaría a quien sondea que la
  // URL existe y espera un token.
  res.sendStatus(200);

  const body = req.body;

  const v = verificarFirma(req.headers, req.rawBody, env.WEBHOOK_SIGNING_SECRET);
  if (!v.ok) {
    console.warn('[webhook] evento rechazado: firma no reconocida. Cabeceras:',
      (v.cabecerasRecibidas || []).join(', '));
    await descartar({
      direction: 'in', raw: body,
      // Se guardan la firma y la marca de tiempo recibidas —valores derivados,
      // NO secretos— para poder calcular fuera de línea qué esquema usa el
      // proveedor si ninguno de los probados acierta.
      error: `firma no válida · sig=${v.firmaRecibida ?? '-'} · ts=${v.marcaDeTiempo ?? '-'} · cabeceras: ${(v.cabecerasRecibidas || []).join(', ')}`.slice(0, 480)
    });
    return;
  }
  if (v.esquema === 'sin_secreto') {
    console.warn('[webhook] SIN secreto configurado: cualquiera puede invocarlo. Configura WEBHOOK_SIGNING_SECRET.');
  }

  try {
    // Solo `message.received` es conversación. WaSphere manda además
    // message.sent, message.delivered, message.read, session.connected… que no
    // deben tratarse como formato roto ni provocar respuesta.
    const tipo = body?.event;
    if (tipo && tipo !== 'message.received') {
      await descartar({ direction: 'in', raw: body, error: `evento ignorado: ${String(tipo).slice(0, 60)}` });
      return;
    }

    const msg = extraerMensaje(body);
    if (!msg) {
      // Forma desconocida. Se guarda cruda: es así como se descubre el formato
      // real del proveedor, que su OpenAPI no documenta.
      await descartar({ direction: 'in', raw: body, error: 'formato no reconocido' });
      return;
    }

    // Los grupos quedan fuera: el bot atiende personas, y responder en un grupo
    // expondría el caso de alguien ante terceros.
    if (msg.esGrupo) {
      await descartar({ externalId: msg.externalId, phone: msg.phone, direction: 'in', text: msg.text, raw: body, error: 'mensaje de grupo, ignorado' });
      return;
    }

    // Ecos de mensajes propios: nunca se procesan, o el bot se responde solo.
    // Incluye lo que escriben los AGENTES desde el Team Inbox.
    if (!msg.esEntrante) {
      await descartar({ externalId: msg.externalId, phone: msg.phone, direction: 'in', raw: body, error: 'eco propio' });
      return;
    }

    // Mensaje entrante SIN contenido descifrable.
    //
    // WaSphere entrega a veces `message.received` con `content: {}` y
    // `message: null`: Baileys no logró descifrarlo. Sin esto el bot se queda
    // mudo y la persona cree que se colgó. Se pide reenviar, como máximo una
    // vez cada 2 minutos, para no convertir una racha de mensajes ilegibles en
    // una ráfaga de respuestas.
    if (!msg.text && msg.phone) {
      // Una reentrega del MISMO evento choca contra el índice único. Eso no es
      // un fallo: significa que ya se atendió, así que se corta acá.
      try {
        await prisma.botEvent.create({
          data: { externalId: msg.externalId, phone: msg.phone, direction: 'in', raw: body, error: 'sin contenido descifrable' }
        });
      } catch (e) {
        if (e?.code === 'P2002') return;
      }

      const desde = new Date(Date.now() - 2 * 60_000);
      const yaAvisado = await prisma.botEvent.count({
        where: { phone: msg.phone, direction: 'out', error: { startsWith: 'aviso_ilegible' }, createdAt: { gte: desde } }
      }).catch(() => 1);

      if (!yaAvisado) await responderIlegible(msg.phone, msg.jid);
      return;
    }

    if (!msg.phone) {
      await descartar({ externalId: msg.externalId, direction: 'in', text: msg.text, raw: body, error: 'sin teléfono identificable' });
      return;
    }

    await procesarMensaje({ ...msg, raw: body });
  } catch (e) {
    console.error('[webhook] fallo procesando el evento:', e);
  }
});

// ── Administración (red interna) ───────────────────────────────────────
export const adminRouter = Router();

/** ¿Está recibiendo? ¿Cuántas conversaciones? ¿Cuántas con agente? */
adminRouter.get('/estado', async (_req, res) => {
  try {
    const desde24h = new Date(Date.now() - 86_400_000);
    const [conversaciones, entrantes24h, conAgente, ultimo] = await Promise.all([
      prisma.botSession.count(),
      prisma.botEvent.count({ where: { direction: 'in', createdAt: { gte: desde24h } } }),
      prisma.botSession.count({ where: { mutedUntil: { gt: new Date() } } }),
      prisma.botEvent.findFirst({ where: { direction: 'in' }, orderBy: { createdAt: 'desc' }, select: { createdAt: true } })
    ]);
    res.json({
      ok: true,
      data: {
        conversaciones, entrantes24h, conAgente,
        ultimoMensaje: ultimo?.createdAt ?? null,
        // Si nunca llegó nada, lo más probable es que el webhook no apunte acá.
        recibiendo: !!ultimo
      }
    });
  } catch (e) { res.status(500).json({ ok: false, error: e.message }); }
});

/** Eventos crudos. Sirve para descubrir el formato real del proveedor. */
adminRouter.get('/eventos', async (req, res) => {
  try {
    const limite = Math.min(Number(req.query.limite) || 30, 100);
    res.json({
      ok: true,
      data: await prisma.botEvent.findMany({
        take: limite, orderBy: { createdAt: 'desc' },
        select: {
          id: true, phone: true, direction: true, text: true, error: true,
          stateBefore: true, stateAfter: true, createdAt: true,
          raw: req.query.crudo === '1'
        }
      })
    });
  } catch (e) { res.status(500).json({ ok: false, error: e.message }); }
});

/** Devuelve el bot a una conversación que estaba con un agente. */
adminRouter.post('/conversaciones/:id/reactivar', async (req, res) => {
  try {
    const { NUEVA } = await import('../bot/engine.js');
    await prisma.botSession.update({
      where: { id: req.params.id },
      data: { mutedUntil: null, state: NUEVA, context: {} }
    });
    res.json({ ok: true, message: 'El bot vuelve a atender esta conversación' });
  } catch (e) { res.status(400).json({ ok: false, error: e.message }); }
});
