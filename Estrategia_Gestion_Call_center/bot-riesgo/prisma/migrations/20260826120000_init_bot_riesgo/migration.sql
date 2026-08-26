-- Esqueleto del bot de Gestión del Riesgo. Base propia, dos tablas.

CREATE TABLE "bot_sessions" (
    "id"           TEXT NOT NULL,
    "phone"        TEXT NOT NULL,
    "jid"          TEXT,
    "state"        TEXT NOT NULL DEFAULT 'NUEVA',
    "context"      JSONB,
    "muted_until"  TIMESTAMP(3),
    "last_seen_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"   TIMESTAMP(3) NOT NULL,

    CONSTRAINT "bot_sessions_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX "bot_sessions_phone_key"       ON "bot_sessions"("phone");
CREATE INDEX        "bot_sessions_last_seen_idx"   ON "bot_sessions"("last_seen_at");
CREATE INDEX        "bot_sessions_muted_until_idx" ON "bot_sessions"("muted_until");

CREATE TABLE "bot_events" (
    "id"           TEXT NOT NULL,
    "external_id"  TEXT,
    "phone"        TEXT,
    "direction"    TEXT NOT NULL DEFAULT 'in',
    "text"         TEXT,
    "raw"          JSONB,
    "state_before" TEXT,
    "state_after"  TEXT,
    "error"        TEXT,
    "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "bot_events_pkey" PRIMARY KEY ("id")
);

-- Único: si WaSphere reintenta el webhook, el mismo mensaje no se procesa
-- —ni se responde— dos veces.
CREATE UNIQUE INDEX "bot_events_external_id_key" ON "bot_events"("external_id");
CREATE INDEX        "bot_events_phone_idx"       ON "bot_events"("phone");
CREATE INDEX        "bot_events_created_at_idx"  ON "bot_events"("created_at");
