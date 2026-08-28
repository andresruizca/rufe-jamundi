// Cliente de la API de Zavu, para el vigilante de inactividad.
//
// Solo salidas. El contenedor vive en una IP privada, así que Zavu no puede
// llamarnos —por eso la conversación la maneja su motor de flujos y no este
// servicio—, pero nosotros sí podemos llamarle a él. Eso basta para vigilar el
// silencio y romperlo.

const BASE = (process.env.ZAVU_BASE_URL || 'https://api.zavu.dev/v1').replace(/\/+$/, '');
const TOKEN = process.env.ZAVU_API_TOKEN || '';
const SENDER = process.env.ZAVU_SENDER_ID || '';
const TIMEOUT = Number(process.env.ZAVU_TIMEOUT_MS || 15_000);

/** ¿Está configurado? Sin token, el vigilante no arranca. */
export const configurado = () => TOKEN !== '' && SENDER !== '';

async function pedir(metodo, ruta, cuerpo) {
  const res = await fetch(BASE + ruta, {
    method: metodo,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Zavu-Sender': SENDER,
      'Content-Type': 'application/json'
    },
    ...(cuerpo ? { body: JSON.stringify(cuerpo) } : {}),
    signal: AbortSignal.timeout(TIMEOUT)
  });

  const datos = await res.json().catch(() => ({}));

  return { estado: res.status, datos };
}

/**
 * Las conversaciones más recientes.
 *
 * `lastMessage.at` es la última actividad y `lastMessage.direction` dice quién
 * habló último. Con eso basta: si el último mensaje es NUESTRO y lleva rato,
 * es que el ciudadano no ha vuelto a escribir.
 *
 * @returns {Promise<Array<{id:string, contactIdentifier:string, ultimo:{direction:string, at:string, text:string}|null}>>}
 */
export async function conversaciones(limite = 50) {
  const { estado, datos } = await pedir('GET', `/conversations?limit=${limite}`);

  if (estado < 200 || estado >= 300) {
    throw new Error(`conversaciones: HTTP ${estado}`);
  }

  return (datos.items || []).map((c) => ({
    id: c.id,
    contactIdentifier: c.contactIdentifier,
    ultimo: c.lastMessage
      ? { direction: c.lastMessage.direction, at: c.lastMessage.at, text: c.lastMessage.text || '' }
      : null
  }));
}

/**
 * Manda texto libre.
 *
 * Sin plantilla ni aprobación de Meta: estos avisos salen minutos después de
 * que el ciudadano escribiera, así que su ventana de 24 horas está abierta y
 * el texto libre es válido. Es justo lo que hace posible esta función.
 */
export async function enviarTexto(to, text) {
  const { estado, datos } = await pedir('POST', '/messages', {
    to,
    channel: 'whatsapp',
    text
  });

  const m = datos.message || {};

  // Un 2xx no basta: el proveedor acepta con `queued` y marca `failed` poco
  // después si algo va mal. Aquí no se reconsulta —es un aviso, no una
  // gestión que alguien vaya a auditar— pero un `failed` inmediato sí se ve.
  if (m.status === 'failed' || estado < 200 || estado >= 300) {
    return { ok: false, error: m.errorMessage || `HTTP ${estado}` };
  }

  return { ok: true, id: m.id || null };
}
