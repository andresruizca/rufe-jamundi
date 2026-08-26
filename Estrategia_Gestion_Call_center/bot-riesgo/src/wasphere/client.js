// Cliente de la API REST de wa-server.
//
//   POST {BASE}/api/sessions/{sessionId}/messages/text
//   header: x-api-token: <WASPHERE_API_TOKEN>
//   body:   { to, text }
//
// A diferencia de la Cloud API de Meta: NO hay ventana de 24 h ni plantillas
// aprobadas. El texto libre funciona siempre que la sesión esté conectada.

import { env, puedeEnviar } from '../config/env.js';

const CO = '57';

/**
 * Normaliza el destinatario.
 *
 * Un JID completo (`…@s.whatsapp.net`, `…@lid`) se pasa TAL CUAL: quitarle los
 * no-dígitos lo convertiría en un número inventado.
 */
export function normalizarDestino(bruto) {
  if (!bruto) return null;
  const s0 = String(bruto).trim();
  if (s0.includes('@')) return s0.length <= 60 ? s0 : null;

  let s = s0.replace(/\D/g, '');
  if (!s) return null;
  // Celular colombiano de 10 dígitos que empieza por 3 → se le antepone el 57.
  if (s.length === 10 && s.startsWith('3')) s = CO + s;
  return s;
}

let avisadoSinConfig = false;

/**
 * Envía texto libre por la sesión vinculada.
 * @returns {Promise<{ok:boolean, id?:string|null, error?:string, status?:number}>}
 */
export async function enviarTexto(destinoBruto, texto) {
  if (!puedeEnviar()) {
    if (!avisadoSinConfig) {
      console.warn('[wasphere] sin sesión vinculada (WASPHERE_SESSION_ID vacío) — el bot NO puede responder');
      avisadoSinConfig = true;
    }
    return { ok: false, error: 'sesion_no_vinculada' };
  }
  avisadoSinConfig = false;

  const to = normalizarDestino(destinoBruto);
  if (!to) return { ok: false, error: 'destinatario_invalido' };

  const url = `${env.WASPHERE_BASE_URL}/api/sessions/${encodeURIComponent(env.WASPHERE_SESSION_ID)}/messages/text`;
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'x-api-token': env.WASPHERE_API_TOKEN, 'Content-Type': 'application/json' },
      body: JSON.stringify({ to, text: texto }),
      // WaSphere retarda el despacho 4-12 s a propósito, para parecer humano y
      // no ganarse un baneo. Un timeout corto da error en envíos que SÍ salieron,
      // y el reintento duplica el mensaje.
      signal: AbortSignal.timeout(env.WASPHERE_TIMEOUT_MS)
    });

    const cuerpo = await res.json().catch(() => ({}));
    if (!res.ok) {
      const msg = cuerpo?.message || cuerpo?.error || `HTTP ${res.status}`;
      return { ok: false, error: typeof msg === 'string' ? msg : JSON.stringify(msg), status: res.status };
    }
    // El nombre del campo del id varía entre versiones.
    return { ok: true, id: cuerpo.id || cuerpo.messageId || cuerpo.key?.id || null, data: cuerpo };
  } catch (e) {
    return { ok: false, error: e.name === 'TimeoutError' ? 'timeout' : e.message };
  }
}
