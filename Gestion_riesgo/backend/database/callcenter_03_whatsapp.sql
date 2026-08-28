-- Sistema de Gestión del Riesgo — Jamundí
-- Call center, tercera vuelta: mandar el enlace por WhatsApp.
--
-- Muchos hogares no contestan el teléfono. El botón de WhatsApp le manda a esa
-- persona el mismo enlace que le leería la operadora, y el envío queda en el
-- historial del hogar como una gestión más.
--
-- Dos cambios, los dos aditivos. Ninguna fila existente cambia de significado.

SET NAMES utf8mb4;

-- ── Por qué canal se hizo la gestión ─────────────────────────────────────────
--
-- Un WhatsApp NO es una llamada, y la diferencia no es cosmética.
--
-- El módulo cuenta los intentos con COUNT(*) sobre esta tabla, y a los cinco da
-- el hogar por agotado. Si un envío sumara ahí, un hogar al que nadie ha
-- llamado aparecería como intentado cinco veces y saldría de la cola sin que
-- nadie hubiera hablado con él. Peor: la cifra de avance de la campaña es un
-- dato que se le reporta a la Alcaldía, y quedaría inflada sin que nada lo
-- delatara. Es el mismo tipo de error que ya causó el JOIN duplicando filas.
--
-- DEFAULT 'LLAMADA' deja correctamente marcadas todas las filas anteriores:
-- hasta hoy, toda gestión era una llamada.

SET @faltaCanal := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rufe_gestiones'
     AND COLUMN_NAME = 'canal'
);

SET @sql := IF(@faltaCanal,
  'ALTER TABLE rufe_gestiones
     ADD COLUMN canal VARCHAR(20) NOT NULL DEFAULT ''LLAMADA''
       COMMENT ''LLAMADA o WHATSAPP. Los WHATSAPP no cuentan como intentos de llamada''
       AFTER reporte_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Se consulta junto al reporte para poder excluir los envíos de los conteos
-- sin recorrer la tabla entera.
SET @faltaIdx := (
  SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rufe_gestiones'
     AND INDEX_NAME = 'idx_rufe_gestiones_canal'
);

SET @sql := IF(@faltaIdx,
  'ALTER TABLE rufe_gestiones
     ADD KEY idx_rufe_gestiones_canal (reporte_id, canal, creado_en)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Dos resultados nuevos ────────────────────────────────────────────────────
--
-- El fallido se guarda igual que el enviado, a propósito: un envío que falla y
-- no deja rastro hace que la siguiente operadora lo vuelva a intentar sin saber
-- que ya falló, y que nadie se entere de que un número está mal.

SET @faltaResultado := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rufe_gestiones'
     AND COLUMN_NAME = 'resultado'
     AND COLUMN_TYPE LIKE '%WHATSAPP_ENVIADO%'
);

SET @sql := IF(@faltaResultado,
  'ALTER TABLE rufe_gestiones
     MODIFY COLUMN resultado ENUM(''CONTACTADO'',''NO_CONTESTA'',''NUMERO_ERRADO'',
                                  ''VOLVER_A_LLAMAR'',''NO_INTERESA'',''YA_DILIGENCIO'',
                                  ''WHATSAPP_ENVIADO'',''WHATSAPP_FALLIDO'') NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
