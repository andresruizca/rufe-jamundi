-- Sistema de Gestión del Riesgo — Jamundí
-- La cara de atrás de la cédula.
--
-- Se pedía una sola foto, y con la cédula colombiana esa foto no basta: los
-- datos que sirven para comprobar quién es están repartidos entre las dos
-- caras. Delante van el retrato, los nombres y el NUIP; detrás va la zona de
-- lectura mecánica, que es la que permite verificar que el número y la fecha de
-- nacimiento son los que el documento dice.
--
-- Va como tipo PROPIO y no como una segunda foto del mismo tipo. Con dos fotos
-- sueltas nadie puede saber si la persona subió las dos caras o dos veces la
-- misma, que es exactamente lo que pasa cuando se pide «suba 2 fotos» sin
-- decir cuál va en cada sitio. Con dos tipos, la pantalla pide cada cara en su
-- casilla y el servidor sabe cuál falta.
--
-- Sobre el ALTER: MySQL no sabe añadirle un valor a un ENUM que no sea
-- redefinirlo entero. La lista nueva contiene los cinco valores anteriores en
-- el mismo orden y añade uno al final, así que ninguna fila cambia de valor ni
-- queda fuera de rango. La prueba «ninguna migración puede borrar datos» lo
-- comprueba: solo admite un MODIFY de ENUM cuando la lista nueva es un
-- superconjunto de la anterior.

SET NAMES utf8mb4;

SET @faltaReverso := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rufe_evidencias'
     AND COLUMN_NAME = 'tipo'
     AND COLUMN_TYPE LIKE '%PRE_CEDULA_REVERSO%'
);

SET @sql := IF(@faltaReverso,
  'ALTER TABLE rufe_evidencias
     MODIFY COLUMN tipo ENUM(''DOCUMENTO'',''DANO'',''INSPECCION'',''PRE_CEDULA'',''PRE_DANO'',''PRE_CEDULA_REVERSO'')
       NOT NULL DEFAULT ''DANO''',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
