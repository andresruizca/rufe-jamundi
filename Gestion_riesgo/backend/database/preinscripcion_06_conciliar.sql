-- Sistema de Gestion del Riesgo - Jamundi
-- Conciliar los campos de la preinscripcion con los del censo RUFE.
--
-- El censo guarda el nombre partido en `nombres` y `apellidos`, y tambien lo
-- hacen el listado del hogar de la propia preinscripcion y el formulario de
-- quien no aparece en el censo. La tabla `preinscripciones` era la unica que
-- guardaba `nombre_completo` de una pieza: es la mas antigua de las cuatro, y
-- las que se escribieron despues ya no repitieron el patron.
--
-- No es cosmetico. Cuando el censo precargaba el formulario, el frontend unia
-- `nombres` y `apellidos` en una sola cadena y esa frontera se perdia para
-- siempre: nadie puede volver a partir ANDRES RUIZ CADAVID por regla, porque en
-- Colombia hay uno o dos nombres y dos apellidos sin forma de saber donde corta
-- cada caso.
--
-- ── Todo aditivo, y por que ─────────────────────────────────────────────────
--
-- Las migraciones de este sistema solo pueden ANADIR: ni UPDATE, ni MODIFY, ni
-- DROP. La regla existe porque una migracion que reescribe datos en produccion
-- no tiene vuelta atras, y aqui los datos son de familias damnificadas.
--
-- Asi que `nombre_completo` se queda. Sigue siendo NOT NULL y lo sigue
-- escribiendo el servidor con `nombres` y `apellidos` unidos, por dos motivos:
-- las solicitudes que llegan del APK sin conexion todavia mandan ese campo, y
-- las tres filas que ya existen en produccion no se pueden partir por programa.

SET NAMES utf8mb4;

-- ── El nombre, en dos ───────────────────────────────────────────────────────
--
-- Nulos a proposito: las filas anteriores no tienen como llenarlos, y ponerles
-- una division inventada seria peor que dejarlos vacios. Quien los lea sabe
-- que un nulo significa "esta solicitud es anterior a la conciliacion", y ahi
-- esta `nombre_completo` para mostrarla.

SET @falta := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'nombres'
);

SET @sql := IF(@falta,
  'ALTER TABLE preinscripciones
     ADD COLUMN nombres VARCHAR(120) NULL DEFAULT NULL AFTER nombre_completo,
     ADD COLUMN apellidos VARCHAR(120) NULL DEFAULT NULL AFTER nombres',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── El tipo de documento ────────────────────────────────────────────────────
--
-- El censo guarda `tipo_documento` con su catalogo; la preinscripcion solo
-- guardaba el numero. Cedula, tarjeta de identidad y pasaporte quedaban
-- indistinguibles, y al volver al censo habia que adivinarlo.
--
-- Nulo por lo mismo: en las filas anteriores no se pregunto.

SET @falta := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'tipo_documento'
);

SET @sql := IF(@falta,
  'ALTER TABLE preinscripciones
     ADD COLUMN tipo_documento TINYINT UNSIGNED NULL DEFAULT NULL AFTER documento',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── El barrio elegido de la lista oficial ───────────────────────────────────
--
-- `vereda` sigue guardando lo que la persona indico. Esta columna dice si eso
-- salio del catalogo del POT o lo escribio a mano.
--
-- Importa para Planeacion: lo escrito a mano es justamente lo que hay que
-- revisar, porque o es un barrio que falta en la lista de 2021 o es una grafia
-- nueva de uno que ya esta. Sin esta marca habria que volver a adivinarlo
-- comparando cadenas, que es de donde venimos.

SET @falta := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'barrio_del_catalogo'
);

SET @sql := IF(@falta,
  'ALTER TABLE preinscripciones
     ADD COLUMN barrio_del_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER vereda',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Zona: un solo vocabulario ───────────────────────────────────────────────
--
-- El censo dice URBANO y la preinscripcion decia URBANA. Hoy no falla porque
-- `Censo::hogarDe` lo traduce a mano al precargar, pero son dos verdades para
-- lo mismo: quien anada otro consumidor y no se acuerde de traducir rompe la
-- precarga en silencio.
--
-- El ENUM se ENSANCHA para admitir los dos. Es la unica excepcion que permiten
-- las reglas de migracion, y solo si la lista nueva contiene toda la anterior:
-- aqui se anade URBANO sin quitar URBANA. Desde ahora el servidor escribe
-- URBANO, y quien lee acepta las dos — las tres filas que ya existen siguen
-- diciendo URBANA y no se tocan.

SET @faltaUrbano := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'preinscripciones'
     AND COLUMN_NAME = 'zona'
     AND COLUMN_TYPE LIKE '%URBANO%'
);

SET @sql := IF(@faltaUrbano,
  'ALTER TABLE preinscripciones
     MODIFY COLUMN zona ENUM(''URBANA'',''RURAL'',''URBANO'') NULL DEFAULT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
