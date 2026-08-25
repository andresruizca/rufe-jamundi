-- Sistema de Gestión del Riesgo — Jamundí
-- Call center: llevar a la gente del RUFE hasta la preinscripción.
--
-- El enlace del formulario ciudadano se le manda a quien YA está en la base del
-- RUFE y tiene que continuar el proceso. Mandarlo no es un gesto suelto: es una
-- campaña de llamadas, una por hogar. Sin dónde anotarlas, el turno de la tarde
-- vuelve a llamar a quien ya atendió el de la mañana, y nadie sabe cuánta gente
-- del censo llegó de verdad al formulario.

SET NAMES utf8mb4;

-- ── El rol de quien llama ────────────────────────────────────────────────────
--
-- Mismo caso que el inspector: para que un operador contratado para la campaña
-- pudiera trabajar habría que darle «Gestor», y eso le abre el censo entero, el
-- mapa y las inspecciones. Necesita un teléfono y un nombre, no un censo.
--
-- Sobre el ALTER: MySQL no tiene forma de añadir un valor a un ENUM que no sea
-- redefinirlo entero. La lista nueva contiene los cuatro valores anteriores en
-- el mismo orden y añade uno al final, así que ninguna fila cambia de valor ni
-- queda fuera de rango. La prueba «ninguna migración puede borrar datos»
-- comprueba exactamente eso.
--
-- Es idempotente: mira si el tipo de la columna ya menciona el valor nuevo.

SET @faltaRol := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'usuarios'
     AND COLUMN_NAME = 'rol'
     AND COLUMN_TYPE LIKE '%OPERADOR%'
);

SET @sql := IF(@faltaRol,
  'ALTER TABLE usuarios
     MODIFY COLUMN rol ENUM(''ADMINISTRADOR'',''GESTOR'',''VISUALIZACION'',''INSPECTOR'',''OPERADOR'')
       NOT NULL DEFAULT ''VISUALIZACION''',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Las llamadas ─────────────────────────────────────────────────────────────
--
-- Una fila por INTENTO, no una columna de estado en `rufe_reportes`.
--
-- Una columna solo recuerda lo último, y la pregunta del call center es
-- «¿cuántas veces se ha intentado y cuándo?». Quien reparte el trabajo del día
-- necesita distinguir a quien no contestó una vez de quien no contesta desde
-- hace una semana, y eso un estado no lo dice.
--
-- Y además no toca la tabla del censo. `rufe_reportes` guarda los datos del
-- hogar damnificado; una campaña de llamadas no tiene por qué escribir ahí.
--
-- El estado de cada hogar se DERIVA de su última gestión. No se almacena en
-- ninguna parte, así que no puede quedar desincronizado.

CREATE TABLE IF NOT EXISTS rufe_gestiones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporte_id      INT UNSIGNED    NOT NULL,

  -- Qué pasó en la llamada. `YA_DILIGENCIO` es lo que DICE la persona; que lo
  -- haya hecho de verdad lo decide el cruce por cédula contra
  -- `preinscripciones`, no esta columna.
  resultado       ENUM('CONTACTADO','NO_CONTESTA','NUMERO_ERRADO',
                       'VOLVER_A_LLAMAR','NO_INTERESA','YA_DILIGENCIO') NOT NULL,

  nota            VARCHAR(500)    NULL DEFAULT NULL,

  -- Cuándo volver a intentarlo. Es lo que convierte «no contestó» en trabajo
  -- para mañana en vez de en un hogar que se pierde.
  proxima_llamada DATE            NULL DEFAULT NULL,

  -- Si en esta llamada se le mandó el enlace. Sirve para distinguir a quien lo
  -- tiene y no lo ha usado de quien nunca lo recibió: son dos problemas
  -- distintos y se atienden distinto.
  enlace_enviado  TINYINT(1)      NOT NULL DEFAULT 0,

  -- Quién llamó. El correo se copia porque el usuario puede borrarse y la
  -- constancia de quién habló con un ciudadano no debe irse con él.
  usuario_id      INT UNSIGNED    NULL DEFAULT NULL,
  usuario_email   VARCHAR(180)    NULL DEFAULT NULL,

  creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_rufe_gestiones_reporte (reporte_id, creado_en),
  KEY idx_rufe_gestiones_proxima (proxima_llamada),
  CONSTRAINT fk_rufe_gestiones_reporte FOREIGN KEY (reporte_id)
    REFERENCES rufe_reportes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rufe_gestiones_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
