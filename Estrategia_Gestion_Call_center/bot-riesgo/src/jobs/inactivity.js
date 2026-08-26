// Cierre de conversaciones por inactividad. Dos etapas, para no cortar en seco
// a alguien que está buscando un dato:
//
//   5 min sin escribir       → «¿Sigues ahí?»
//   2 min más sin responder  → despedida y vuelta al nodo inicial
//
// NO toca las conversaciones escaladas: si un agente está atendiendo desde el
// Team Inbox, el bot no debe interrumpir con «¿sigues ahí?».

import cron from 'node-cron';
import { prisma } from '../config/database.js';
import { INACTIVIDAD_MIN, GRACIA_MIN, NUEVA } from '../bot/engine.js';
import * as flujo from '../bot/flow.js';
import { enviar, destinoDeRespuesta } from '../bot/service.js';

/** Una pasada. Exportada para poder probarla y dispararla a mano. */
export async function revisarInactividad(ahora = new Date()) {
  const cortePregunta = new Date(ahora.getTime() - INACTIVIDAD_MIN * 60_000);
  const corteCierre   = new Date(ahora.getTime() - GRACIA_MIN * 60_000);

  // Una sesión escalada tiene mutedUntil en el futuro: queda excluida.
  const sinAgente = { OR: [{ mutedUntil: null }, { mutedUntil: { lte: ahora } }] };

  let preguntadas = 0, cerradas = 0;

  // Etapa 2 primero: quien ya fue preguntado y sigue callado, se cierra.
  const porCerrar = await prisma.botSession.findMany({
    where: { state: 'ESPERA_INACTIVIDAD', lastSeenAt: { lt: corteCierre }, ...sinAgente },
    select: { id: true, phone: true, jid: true }
  });
  for (const s of porCerrar) {
    await enviar(s.phone, flujo.TEXTO_DESPEDIDA, { destino: destinoDeRespuesta(s) });
    await prisma.botSession.update({
      where: { id: s.id },
      data: { state: NUEVA, context: {} }
    }).catch(() => {});
    cerradas++;
  }

  // Etapa 1: conversación abierta y callada → «¿sigues ahí?».
  // Todos los nodos cuentan como conversación abierta, incluido el inicial:
  // quien vio el menú y no respondió también merece el «¿sigues ahí?». Los
  // escalados quedan fuera por el filtro de mutedUntil.
  const abiertos = Object.keys(flujo.NODOS).filter(
    (n) => n !== flujo.ESTADO_ESCALADO && n !== 'CERRADA'
  );
  if (abiertos.length) {
    const porPreguntar = await prisma.botSession.findMany({
      where: { state: { in: abiertos }, lastSeenAt: { lt: cortePregunta }, ...sinAgente },
      select: { id: true, phone: true, jid: true }
    });
    for (const s of porPreguntar) {
      await enviar(s.phone, flujo.TEXTO_SIGUE_AHI, { destino: destinoDeRespuesta(s) });
      // El estado cambia pero `lastSeenAt` NO: es el reloj de la gracia.
      await prisma.botSession.update({
        where: { id: s.id }, data: { state: 'ESPERA_INACTIVIDAD' }
      }).catch(() => {});
      preguntadas++;
    }
  }

  if (preguntadas || cerradas) {
    console.log(`[inactividad] preguntadas: ${preguntadas}, cerradas: ${cerradas}`);
  }
  return { preguntadas, cerradas };
}

/** Corre cada minuto. Es barato: solo mira las conversaciones abiertas. */
export function iniciarVigilanteDeInactividad() {
  cron.schedule('* * * * *', () => {
    revisarInactividad().catch((e) => console.error('[inactividad] fallo:', e.message));
  });
  console.log('[inactividad] vigilante activo (cada minuto)');
}
