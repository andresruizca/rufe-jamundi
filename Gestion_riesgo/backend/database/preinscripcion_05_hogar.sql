-- Sistema de Gestión del Riesgo — Jamundí
-- El núcleo familiar en la solicitud ciudadana, y de dónde salió.
--
-- Hasta ahora la pre-inscripción solo recogía al solicitante. El censo sí tiene
-- el hogar completo —lo levantó un funcionario en la visita—, así que cuando la
-- persona entra con una cédula que SÍ está en el RUFE, lo suyo es enseñarle lo
-- que ya se sabe de su casa y dejarle decir qué cambió: quién nació, quién se
-- fue, qué apellido quedó mal escrito.
--
-- ── Esto NO corrige el censo ─────────────────────────────────────────────────
--
-- Ni una fila de `rufe_personas` cambia por lo que se escriba aquí. Lo que el
-- ciudadano deja es una PROPUESTA, y un funcionario decide. El censo es lo que
-- un profesional levantó en campo con la casa delante; un formulario que se
-- llena desde un celular no puede sobrescribirlo sin que nadie mire, porque
-- entonces deja de haber con qué comparar cuando algo no cuadre.

SET NAMES utf8mb4;

-- ── De qué ficha del censo se precargó ───────────────────────────────────────
--
-- Sin esto, la bandeja no puede poner al lado lo que decía el censo y lo que
-- dice el ciudadano, que es justo lo que el funcionario necesita para decidir.
-- NULL en las solicitudes anteriores a este cambio y en las que no se
-- precargaron.

SET @faltaReporte := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'rufe_reporte_id'
);

SET @sql := IF(@faltaReporte,
  'ALTER TABLE preinscripciones
     ADD COLUMN rufe_reporte_id INT UNSIGNED NULL DEFAULT NULL
       COMMENT ''Ficha del censo de la que se precargaron los datos, si hubo''
       AFTER inspeccion_id',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Las personas del hogar, tal como las dejó el ciudadano ───────────────────
--
-- Una fila por persona, con la misma forma que `rufe_personas` para que
-- convertir la solicitud en ficha no exija traducir nada.
--
-- `rufe_persona_id` dice de qué persona del censo salió esta fila. Es la que
-- permite responder la única pregunta que importa en la bandeja: ¿esto es
-- alguien nuevo, o es alguien que ya estaba y a quien le cambiaron algo?
--
-- No hay clave foránea hacia `rufe_personas` a propósito: si un día se corrige
-- el censo y desaparece una fila, la solicitud tiene que seguir contando lo que
-- el ciudadano dijo. Una CASCADE aquí borraría la constancia.
--
-- `estado` lo calcula el SERVIDOR comparando contra el censo al recibir el
-- envío, nunca lo manda el navegador: si lo mandara, bastaría con mentir en una
-- casilla para que una corrección pasara por «igual» y nadie la revisara.

CREATE TABLE IF NOT EXISTS preinscripcion_personas (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  preinscripcion_id  INT UNSIGNED     NOT NULL,
  orden              TINYINT UNSIGNED NOT NULL,

  rufe_persona_id    BIGINT UNSIGNED  NULL DEFAULT NULL
                       COMMENT 'De que persona del censo salio, si salio de alguna',

  nombres            VARCHAR(120)     NOT NULL,
  apellidos          VARCHAR(120)     NOT NULL,
  tipo_documento     TINYINT UNSIGNED NULL DEFAULT NULL,
  numero_documento   VARCHAR(30)      NULL DEFAULT NULL,
  parentesco         TINYINT UNSIGNED NULL DEFAULT NULL,
  genero             TINYINT UNSIGNED NULL DEFAULT NULL,
  fecha_nacimiento   DATE             NULL DEFAULT NULL,

  -- IGUAL: vino del censo y no se tocó.
  -- CORREGIDA: vino del censo y el ciudadano cambió algo.
  -- NUEVA: no estaba en el censo.
  -- NO_VIVE_AQUI: estaba en el censo y el ciudadano dice que ya no vive ahí.
  --
  -- La cuarta existe porque el ciudadano NO puede borrar a nadie del listado.
  -- Quitar de un clic a una persona del censo de damnificados —y perder que
  -- alguna vez estuvo— no debería poder hacerse sin que un funcionario lo mire.
  estado             ENUM('IGUAL','CORREGIDA','NUEVA','NO_VIVE_AQUI')
                       NOT NULL DEFAULT 'NUEVA',

  creado_en          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_preinscripcion_personas_orden (preinscripcion_id, orden),
  KEY idx_preinscripcion_personas_rufe (rufe_persona_id),
  CONSTRAINT fk_preinscripcion_personas FOREIGN KEY (preinscripcion_id)
    REFERENCES preinscripciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
