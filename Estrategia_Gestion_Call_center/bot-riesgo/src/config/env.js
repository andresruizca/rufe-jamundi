// Configuración. Falla al arrancar si falta algo esencial: es preferible que
// el contenedor no levante a que levante mudo y nadie se entere.

const requerido = (nombre) => {
  const v = process.env[nombre];
  if (!v) {
    console.error(`[config] falta la variable ${nombre}`);
    process.exit(1);
  }
  return v;
};

export const env = {
  DATABASE_URL: requerido('DATABASE_URL'),
  PORT: Number(process.env.PORT || 3010),

  WASPHERE_BASE_URL: (process.env.WASPHERE_BASE_URL || '').replace(/\/+$/, ''),
  WASPHERE_API_TOKEN: process.env.WASPHERE_API_TOKEN || '',
  // PENDIENTE hasta escanear el QR. El bot arranca sin esto: recibe y registra
  // eventos, pero no puede responder. Se avisa en el arranque.
  WASPHERE_SESSION_ID: process.env.WASPHERE_SESSION_ID || '',
  WASPHERE_TIMEOUT_MS: Number(process.env.WASPHERE_TIMEOUT_MS || 30_000),

  WEBHOOK_SIGNING_SECRET: process.env.WEBHOOK_SIGNING_SECRET || ''
};

/** ¿Puede responder? Sin sesión vinculada, no. */
export const puedeEnviar = () =>
  !!(env.WASPHERE_BASE_URL && env.WASPHERE_API_TOKEN && env.WASPHERE_SESSION_ID);
