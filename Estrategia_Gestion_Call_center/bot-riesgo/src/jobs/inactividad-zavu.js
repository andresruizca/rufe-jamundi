// Vigilante de silencio sobre las conversaciones de Zavu.
//
// ── Por qué existe ───────────────────────────────────────────────────────────
//
// Zavu no tiene temporizadores. Su documentación lo dice con todas las letras:
// «las sesiones no expiran». Un ciudadano que deja de responder a mitad
// mantiene su sesión abierta y retoma semanas después en el paso donde quedó.
// No hay pasos de espera, ni TTL, ni endpoint para reiniciar una sesión.
//
// Así que el reloj lo ponemos nosotros desde fuera: se consultan las
// conversaciones cada minuto y, cuando una lleva callada el rato acordado, se
// manda el aviso. Es texto libre y no plantilla, y eso NO es un descuido: el
// ciudadano escribió hace minutos, su ventana de 24 horas está abierta y por
// eso no hace falta que Meta apruebe nada.
//
// ── Cómo se sabe que está callado ────────────────────────────────────────────
//
// `lastMessage` es el último mensaje del hilo. Si es NUESTRO (`outbound`),
// significa que le respondimos y no ha vuelto a escribir; `at` es el momento de
// esa respuesta, o sea el principio de su silencio. Si es suyo (`inbound`),
// está en conversación y no hay nada que vigilar.
//
// ── Lo que este vigilante NO puede hacer ─────────────────────────────────────
//
// La despedida no reinicia la sesión del contacto en Zavu, porque no existe API
// para eso. En la práctica apenas se nota: quien elige una opción completa el
// flujo y su siguiente mensaje empieza de cero; solo queda a medias quien vio el
// menú y nunca respondió, y a ese su siguiente mensaje le vuelve a mostrar el
// menú, que es lo que corresponde.

import cron from 'node-cron';
import { prisma } from '../config/database.js';
import { conversaciones, enviarTexto, configurado } from '../zavu/client.js';
// El reloj vive aparte y sin dependencias, para poder probarlo sin red.
import { decidir, MINUTOS_AVISO, MINUTOS_GRACIA, TEXTO_AVISO, TEXTO_DESPEDIDA } from './reloj.js';

/** Una pasada. Exportada para poder dispararla a mano. */
export async function revisar(ahora = new Date()) {
  const cuenta = { avisadas: 0, cerradas: 0, olvidadas: 0, fallos: 0 };
  const hilos = await conversaciones();

  for (const h of hilos) {
    const marca = await prisma.zavuInactividad.findUnique({ where: { conversacion: h.id } })
      .catch(() => null);

    const accion = decidir(h.ultimo, marca?.etapa ?? null, ahora);

    if (accion === 'nada') continue;

    if (accion === 'olvidar') {
      await prisma.zavuInactividad.delete({ where: { conversacion: h.id } }).catch(() => {});
      cuenta.olvidadas++;
      continue;
    }

    const texto = accion === 'avisar' ? TEXTO_AVISO : TEXTO_DESPEDIDA;
    const etapa = accion === 'avisar' ? 'AVISADO' : 'CERRADO';

    // La marca se escribe ANTES de enviar. El envío tarda, y en esa ventana la
    // pasada siguiente vería la misma conversación sin marcar y avisaría otra
    // vez. Escribir primero convierte la fila en el permiso: quien la pone,
    // manda.
    await prisma.zavuInactividad.upsert({
      where: { conversacion: h.id },
      create: { conversacion: h.id, telefono: h.contactIdentifier, etapa },
      update: { etapa, actualizadoEn: ahora }
    });

    const r = await enviarTexto(h.contactIdentifier, texto);

    if (!r.ok) {
      cuenta.fallos++;
      console.warn(`[inactividad] no se pudo avisar a ${h.contactIdentifier}: ${r.error}`);
      continue;
    }

    if (accion === 'avisar') cuenta.avisadas++;
    else cuenta.cerradas++;
  }

  if (cuenta.avisadas || cuenta.cerradas || cuenta.fallos) {
    console.log(
      `[inactividad] avisadas: ${cuenta.avisadas}, cerradas: ${cuenta.cerradas}, ` +
      `olvidadas: ${cuenta.olvidadas}, fallos: ${cuenta.fallos}`
    );
  }

  return cuenta;
}

/** Corre cada minuto. Sin token configurado, no arranca. */
export function iniciarVigilanteZavu() {
  if (!configurado()) {
    console.warn('[inactividad] ZAVU_API_TOKEN o ZAVU_SENDER_ID sin definir: el vigilante NO arranca');

    return;
  }

  cron.schedule('* * * * *', () => {
    revisar().catch((e) => console.error('[inactividad] fallo en la pasada:', e.message));
  });

  console.log(
    `[inactividad] vigilante de Zavu activo — aviso a los ${MINUTOS_AVISO} min, ` +
    `cierre ${MINUTOS_GRACIA} min después`
  );
}
