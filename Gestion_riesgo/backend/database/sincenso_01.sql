-- Sistema de Gestión del Riesgo — Jamundí
-- Solicitudes de quien NO aparece en el censo (RUFE).
--
-- Hasta ahora, la cédula que la puerta de pre-inscripción no reconocía
-- terminaba en un mensaje con el teléfono de la línea de atención y nada más:
-- si de verdad necesitaba ayuda, todo el rastro de esa visita se perdía. Esto
-- abre una vía corta y pública para dejar lo mínimo —quién es, cómo
-- contactarlo, dónde queda y qué le pasó— y una bandeja donde el funcionario
-- decide si de ahí nace una ficha RUFE nueva.
--
-- NO es una pre-inscripción: quien llena esto no está censado todavía, así
-- que no hay ficha RUFE detrás y no se convierte en una inspección. Por eso
-- vive en su propia tabla y su propio estado, y solo se conecta con
-- `rufe_reportes` cuando alguien la convierte a mano.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS solicitudes_sin_censo (
  id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  radicado          VARCHAR(20)     NOT NULL,
  -- Lo que hace seguro reintentar sin señal: si la solicitud ya entró pero la
  -- respuesta se perdió, se devuelve el radicado original en vez de duplicar.
  envio_id          CHAR(36)        NOT NULL,

  -- La cédula que la puerta rechazó, de referencia: no se valida contra nada
  -- porque aquí no hay censo con qué compararla, y quien la escribe puede
  -- estar recordándola mal. Sirve para que el funcionario, al llamar, sepa
  -- qué número le dijo la persona la primera vez.
  documento         VARCHAR(20)     NULL DEFAULT NULL,

  -- Separados y no en un solo «nombre completo»: son los mismos dos campos de
  -- `rufe_personas`, para que si la solicitud se convierte, el jefe de hogar
  -- se precargue tal cual, sin adivinar dónde termina el nombre y empieza el
  -- apellido.
  nombres           VARCHAR(100)    NOT NULL,
  apellidos         VARCHAR(100)    NOT NULL,
  telefono          VARCHAR(20)     NOT NULL,

  zona              ENUM('URBANO','RURAL') NOT NULL,
  corregimiento     VARCHAR(120)    NULL DEFAULT NULL,
  vereda_sector_barrio VARCHAR(120) NULL DEFAULT NULL,
  direccion         VARCHAR(200)    NULL DEFAULT NULL,

  descripcion       TEXT            NULL DEFAULT NULL,

  -- Misma exigencia de la Ley 1581 que la pre-inscripción: sin un funcionario
  -- delante que la explique, lo que prueba el consentimiento es la versión
  -- del aviso que se le mostró y cuándo, no lo que hoy diga la pantalla.
  autoriza_datos    TINYINT(1)      NOT NULL DEFAULT 0,
  aviso_version     VARCHAR(40)     NOT NULL,
  autorizacion_en   DATETIME        NOT NULL,

  estado            ENUM('RECIBIDA','EN_REVISION','CONVERTIDA','DESCARTADA')
                    NOT NULL DEFAULT 'RECIBIDA',
  -- La ficha RUFE que nació de esto, si ya se convirtió. No hay clave foránea
  -- hacia una inspección ni hacia una pre-inscripción: esta solicitud nunca
  -- pasa por esas tablas.
  rufe_reporte_id   INT UNSIGNED    NULL DEFAULT NULL,

  origen_hash       CHAR(64)        NULL DEFAULT NULL COMMENT 'SHA-256 de la IP con sal: cuenta abusos sin guardar la IP',
  creado_en         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_sincenso_radicado (radicado),
  UNIQUE KEY uq_sincenso_envio (envio_id),
  KEY idx_sincenso_estado (estado),
  KEY idx_sincenso_documento (documento),
  CONSTRAINT fk_sincenso_rufe_reporte FOREIGN KEY (rufe_reporte_id)
    REFERENCES rufe_reportes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
