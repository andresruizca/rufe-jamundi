-- Sistema de Gestión del Riesgo — Jamundí
-- La visita del censo, que vivía solo en la hoja de Google.
--
-- El formato RUFE en papel trae dos casillas que la digitalización a MySQL
-- nunca recogió: si se realizó la visita y quién la realizó. En la hoja hay 215
-- personas con esa casilla marcada. Al pasar el tablero a leer la base, ese
-- indicador se iría a cero — y cero no significa «ninguna visita», significa
-- «no lo sabemos», que es la clase de confusión con la que alguien reporta mal
-- a la Alcaldía.
--
-- Van aquí y no como inspecciones: son una casilla de un censo levantado en
-- papel, no una inspección técnica con su formato, su profesional a cargo y su
-- evaluación de daño. Mezclarlas dejaría dos ideas distintas de «visita» en el
-- mismo sistema.

SET NAMES utf8mb4;

SET @faltaVisita := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rufe_reportes'
     AND COLUMN_NAME = 'visitada'
);

-- «SIN_DATO» es el valor por omisión y no «NO» a propósito: la inmensa mayoría
-- de las fichas no trae la casilla diligenciada, y darlas por no visitadas
-- sería inventar un hecho sobre el trabajo de campo de otra gente.
SET @sql := IF(@faltaVisita,
  'ALTER TABLE rufe_reportes
     ADD COLUMN visitada ENUM(''SI'',''NO'',''SIN_DATO'') NOT NULL DEFAULT ''SIN_DATO''
       COMMENT ''Casilla del RUFE en papel: se realizo la visita''
       AFTER observaciones,
     ADD COLUMN quien_visito VARCHAR(120) NULL DEFAULT NULL
       COMMENT ''Quien realizo la visita, como lo escribio el censo''
       AFTER visitada',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
