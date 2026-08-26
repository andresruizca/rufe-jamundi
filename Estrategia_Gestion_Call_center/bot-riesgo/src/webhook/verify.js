// Verificación de la firma del webhook de WaSphere.
//
// Adaptado de isp-manager (`bot.webhook-auth.js`). Es utilidad genérica, y el
// formato real del proveedor costó descubrirlo en producción:
//
//   x-wasphere-signature: v1,sha256=<hex>
//   x-wasphere-timestamp: <epoch>
//   x-wasphere-delivery-id: <uuid>
//
// El HMAC-SHA256 usa WEBHOOK_SIGNING_SECRET como clave. El prefijo `v1,` es la
// trampa: quitar solo `sha256=` deja el `v1,` delante y NADA coincide nunca.
//
// FALLA CERRADO: con secreto configurado y sin coincidencia, se rechaza.

import crypto from 'node:crypto';

/** Cabeceras donde el proveedor puede poner la firma. La primera es la real. */
const CANDIDATAS = [
  'x-wasphere-signature',
  'x-webhook-signature', 'x-webhook-token', 'x-webhook-secret',
  'x-signature', 'x-hub-signature-256', 'signature', 'authorization'
];

/** Comparación en tiempo constante: no filtra el secreto por el tiempo de respuesta. */
function igualSeguro(a, b) {
  const A = Buffer.from(String(a ?? ''), 'utf8');
  const B = Buffer.from(String(b ?? ''), 'utf8');
  if (A.length !== B.length) return false;
  return crypto.timingSafeEqual(A, B);
}

/**
 * @param {Record<string,any>} headers
 * @param {Buffer|string|undefined} rawBody  cuerpo SIN parsear — el HMAC se
 *   calcula sobre los bytes exactos; re-serializar el JSON cambia el orden y
 *   los espacios, y la firma deja de coincidir.
 * @param {string} secreto
 * @returns {{ok:boolean, esquema?:string, header?:string, cabecerasRecibidas?:string[], firmaRecibida?:string|null, marcaDeTiempo?:string|null}}
 */
export function verificarFirma(headers = {}, rawBody, secreto) {
  if (!secreto) {
    // Sin secreto no se puede verificar. Se permite para no bloquear el primer
    // arranque, pero el llamador DEBE avisarlo: es configuración incompleta,
    // no un modo válido de operación.
    return { ok: true, esquema: 'sin_secreto' };
  }

  const cuerpo = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody ?? ''), 'utf8');
  const normalizar = (v) => String(Array.isArray(v) ? v[0] : v ?? '').trim();
  const hmac = (payload, enc) => crypto.createHmac('sha256', secreto).update(payload).digest(enc);

  // Muchos proveedores firman la marca de tiempo JUNTO al cuerpo, para que una
  // firma capturada no pueda reenviarse más tarde.
  const ts = normalizar(headers['x-wasphere-timestamp'] ?? headers['x-timestamp']);
  const cargas = [['cuerpo', cuerpo]];
  if (ts) {
    cargas.push(['ts.cuerpo', Buffer.concat([Buffer.from(ts + '.', 'utf8'), cuerpo])]);
    cargas.push(['ts+cuerpo', Buffer.concat([Buffer.from(ts, 'utf8'), cuerpo])]);
  }

  for (const nombre of CANDIDATAS) {
    const bruto = headers[nombre] ?? headers[nombre.toLowerCase()];
    if (bruto == null) continue;
    const valor = normalizar(bruto);
    if (!valor) continue;

    if (igualSeguro(valor, secreto)) return { ok: true, esquema: 'token_plano', header: nombre };
    if (/^bearer\s+/i.test(valor) && igualSeguro(valor.replace(/^bearer\s+/i, ''), secreto)) {
      return { ok: true, esquema: 'bearer', header: nombre };
    }

    // Se limpian AMBOS prefijos: el `vN,` y el `sha256=`.
    const sinPrefijo = valor
      .replace(/^v\d+\s*,\s*/i, '')
      .replace(/^(sha256|hmac-sha256)\s*=\s*/i, '')
      .trim();

    for (const [etiqueta, carga] of cargas) {
      if (igualSeguro(sinPrefijo, hmac(carga, 'hex'))) {
        return { ok: true, esquema: `hmac_sha256_hex(${etiqueta})`, header: nombre };
      }
      if (igualSeguro(sinPrefijo, hmac(carga, 'base64'))) {
        return { ok: true, esquema: `hmac_sha256_base64(${etiqueta})`, header: nombre };
      }
    }
  }

  // Nada coincidió. Se devuelven los NOMBRES de las cabeceras —nunca sus
  // valores—, más la firma y la marca de tiempo, que son valores derivados y
  // públicos: permiten calcular fuera de línea qué esquema usa el proveedor.
  const recibidas = Object.keys(headers).filter(
    (h) => !['host', 'connection', 'content-length', 'accept', 'accept-encoding', 'user-agent'].includes(h)
  );
  return {
    ok: false,
    cabecerasRecibidas: recibidas,
    firmaRecibida: normalizar(headers['x-wasphere-signature'] ?? headers['x-signature'] ?? '').slice(0, 120) || null,
    marcaDeTiempo: ts || null
  };
}

export const _internals = { CANDIDATAS };
