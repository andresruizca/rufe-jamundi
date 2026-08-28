-- Marca de qué se le ha hecho ya a una conversación silenciosa de Zavu.
--
-- El vigilante corre cada minuto y la API de Zavu no recuerda nada: sin esta
-- tabla, una conversación callada recibiría el mismo «¿sigues ahí?» sesenta
-- veces por hora.

CREATE TABLE "zavu_inactividad" (
    "conversacion"   TEXT NOT NULL,
    "telefono"       TEXT,
    "etapa"          TEXT NOT NULL,
    "actualizado_en" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "creado_en"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "zavu_inactividad_pkey" PRIMARY KEY ("conversacion")
);

CREATE INDEX "zavu_inactividad_actualizado_en_idx" ON "zavu_inactividad"("actualizado_en");
