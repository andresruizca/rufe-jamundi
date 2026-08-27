-- Sistema de Gestión del Riesgo — Jamundí
-- Call center, segunda vuelta: por qué se rechazó, quién está llamando y el
-- guión que lee la operadora.
--
-- Las tres cosas nacen del mismo hecho: la campaña la trabajan TRES personas a
-- la vez, con teléfono IP, y hasta hoy el sistema estaba escrito como si fuera
-- una sola.

SET NAMES utf8mb4;

-- ── Por qué se descartó una solicitud ────────────────────────────────────────
--
-- Hasta hoy `estado = DESCARTADA` era una pared lisa: no distinguía «le faltó
-- la foto de la fachada» de «esta vivienda no está en zona afectada». Para el
-- call center esa diferencia lo es todo — la primera es una llamada más y la
-- familia entra; la segunda es una llamada que NO hay que hacer.
--
-- Sin el motivo, el cruce del call center tenía que ignorar las descartadas
-- enteras, y la familia volvía a la cola de «falta llamar» como si nunca se
-- hubiera preinscrito. La operadora la llamaba de cero, sin saber qué le faltó.
--
-- Es NULL para las que ya estaban descartadas antes de esta migración: no se
-- les puede inventar un motivo. El código las trata como subsanables, que es lo
-- prudente — volver a llamar a alguien que no lo necesitaba cuesta una llamada;
-- no llamar a quien sí, lo deja fuera de la ayuda.

SET @faltaMotivo := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'motivo_descarte'
);

SET @sql := IF(@faltaMotivo,
  'ALTER TABLE preinscripciones
     ADD COLUMN motivo_descarte ENUM(''DATOS_INCOMPLETOS'',''FALTA_EVIDENCIA'',''NO_APLICA'')
       NULL DEFAULT NULL
       COMMENT ''Por que el ingeniero la descarto. NULL en las anteriores a esta columna''
       AFTER estado',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Quién está llamando a quién, ahora mismo ─────────────────────────────────
--
-- Tres operadoras abren la misma pestaña «Falta llamar», ordenada igual para
-- las tres, y las tres ven el mismo hogar de primero. Sin esto, la primera
-- familia de la lista recibe tres llamadas seguidas de la Alcaldía diciéndole
-- lo mismo, y las tres operadoras pierden el turno.
--
-- Es un AVISO, no una reserva: la fila sigue siendo de quien quiera tomarla, y
-- solo se muestra «la está atendiendo Marcela, hace 2 minutos». Se decidió así
-- a propósito. Una reserva dura que se olvide de liberar deja hogares
-- congelados que nadie puede llamar hasta que caduquen, y el remedio sale más
-- caro que la enfermedad cuando el equipo es de tres personas que se ven.
--
-- Una fila por hogar, no un historial: aquí solo importa el presente. Lo que
-- pasó en cada llamada vive en `rufe_gestiones`, que sí es historial.
--
-- Las filas viejas no se borran ni hace falta: se leen solo las de los últimos
-- minutos. `actualizado_en` se refresca solo mientras la operadora tenga el
-- hogar abierto.

CREATE TABLE IF NOT EXISTS rufe_atenciones (
  reporte_id      INT UNSIGNED    NOT NULL,
  usuario_id      INT UNSIGNED    NULL DEFAULT NULL,
  usuario_email   VARCHAR(180)    NULL DEFAULT NULL,
  usuario_nombre  VARCHAR(180)    NULL DEFAULT NULL,
  actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (reporte_id),
  KEY idx_rufe_atenciones_fresco (actualizado_en),
  CONSTRAINT fk_rufe_atenciones_reporte FOREIGN KEY (reporte_id)
    REFERENCES rufe_reportes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rufe_atenciones_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── El guión de la llamada ───────────────────────────────────────────────────
--
-- Una fila por VERSIÓN, y la vigente es la última. No se actualiza en sitio:
-- un guión es lo que la Alcaldía le dice por teléfono a mil trescientas
-- familias, y cuando alguien pregunte «¿desde cuándo se les está diciendo
-- esto?» la respuesta tiene que estar escrita.
--
-- Esta tabla puede estar VACÍA y el sistema funciona igual: el guión
-- predeterminado vive en `CallCenter\Guion` y se sirve cuando aquí no hay nada.
-- Es a propósito — así ninguna operadora se queda sin guión a mitad de campaña
-- porque alguien borró un texto, y «restaurar el original» siempre es posible.
--
-- Por eso tampoco se siembra nada aquí: el texto lleva tildes, comillas y
-- puntos y coma, y el troceador de migraciones parte por ';'.

CREATE TABLE IF NOT EXISTS callcenter_guion (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  cuerpo          MEDIUMTEXT      NOT NULL,

  usuario_id      INT UNSIGNED    NULL DEFAULT NULL,
  usuario_email   VARCHAR(180)    NULL DEFAULT NULL,
  creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_callcenter_guion_reciente (creado_en),
  CONSTRAINT fk_callcenter_guion_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
