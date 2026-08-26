// Bot de Gestión del Riesgo — servidor.
//
// Quinto servicio junto a los cuatro de WaSphere. Recibe los webhooks de
// wa-server y responde por su API REST. No modifica WaSphere en nada: los 3
// agentes siguen usando el Team Inbox nativo.

import express from 'express';
import { env, puedeEnviar } from './config/env.js';
import { prisma } from './config/database.js';
import { webhookRouter, adminRouter } from './webhook/routes.js';
import { iniciarVigilanteDeInactividad } from './jobs/inactivity.js';

const app = express();

// El cuerpo CRUDO es imprescindible: el HMAC se calcula sobre los bytes
// exactos que mandó el proveedor. Re-serializar el JSON cambia el orden de las
// claves y los espacios, y la firma deja de coincidir.
app.use(express.json({
  limit: '2mb',
  verify: (req, _res, buf) => { req.rawBody = buf; }
}));

app.get('/health', async (_req, res) => {
  try {
    await prisma.$queryRaw`SELECT 1`;
    res.json({ ok: true, baseDeDatos: 'ok', puedeEnviar: puedeEnviar() });
  } catch (e) {
    res.status(503).json({ ok: false, error: e.message });
  }
});

app.use('/webhook', webhookRouter);
app.use('/admin', adminRouter);

const servidor = app.listen(env.PORT, '0.0.0.0', () => {
  console.log(`[bot-riesgo] escuchando en :${env.PORT}`);
  console.log(`[bot-riesgo] webhook en POST /webhook`);
  if (!env.WEBHOOK_SIGNING_SECRET) {
    console.warn('[bot-riesgo] ⚠ WEBHOOK_SIGNING_SECRET vacío: el webhook NO verifica origen');
  }
  if (!puedeEnviar()) {
    console.warn('[bot-riesgo] ⚠ PENDIENTE: sesión de WhatsApp sin vincular (falta WASPHERE_SESSION_ID).');
    console.warn('[bot-riesgo]   El bot registra los eventos que lleguen, pero NO puede responder.');
    console.warn('[bot-riesgo]   Se vincula escaneando el QR desde el Dashboard cuando se defina el número.');
  }
  iniciarVigilanteDeInactividad();
});

// Apagado ordenado: sin esto, un `docker compose restart` corta conexiones a
// medio camino y deja filas a medias.
for (const señal of ['SIGTERM', 'SIGINT']) {
  process.on(señal, () => {
    console.log(`[bot-riesgo] ${señal} recibido, cerrando…`);
    servidor.close(async () => { await prisma.$disconnect(); process.exit(0); });
  });
}
