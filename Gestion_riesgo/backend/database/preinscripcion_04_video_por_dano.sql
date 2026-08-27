-- Sistema de Gestión del Riesgo — Jamundí
-- Un video por cada daño marcado, en vez de un video largo de toda la casa.
--
-- El modelo anterior era un catálogo suelto de categorías —«Baño», «Fachada»—
-- que se le pedían a todo el mundo por igual. Dos problemas, y el segundo es
-- el que manda:
--
--  1. Un solo video largo no se sube. Dos minutos ya son 24 trozos de 1 MiB en
--     una conexión de vereda; cinco minutos son sesenta, y basta con que la
--     señal se caiga en el trozo cuarenta para que la persona lo vuelva a
--     intentar desde donde iba una y otra vez hasta que abandona.
--  2. Se le pedía grabar lo que no tiene roto. Alguien con una grieta en un
--     muro no sabe qué grabar de «Baño», y graba cualquier cosa. Quien revisa
--     recibe un video que no le dice nada del daño.
--
-- Ahora cada categoría cuelga de una señal de daño: si la persona marcó
-- «Paredes agrietadas», se le pide EL video de las paredes agrietadas, con la
-- instrucción de qué enfocar. Si no la marcó, no se le pide nada de eso.

SET NAMES utf8mb4;

-- ── La columna que ata el video al daño ──────────────────────────────────────
--
-- Idempotente: MySQL 5.7 no admite `ADD COLUMN IF NOT EXISTS`, así que se
-- pregunta al catálogo del propio servidor antes de tocar nada.
--
-- Se deja NULL para las categorías que ya existían. NO se borran: una solicitud
-- de hace semanas puede tener un video grabado contra una de ellas, y sin la
-- fila no habría forma de saber qué se le pidió a esa persona. Lo que se hace
-- es dejar de pedirlas —el formulario solo muestra las que tienen señal—, que
-- es reversible y no pierde nada.

SET @faltaSenal := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'categorias_video'
     AND COLUMN_NAME = 'senal'
);

-- La clave es ÚNICA y no un índice cualquiera: es lo que hace que sembrar las
-- ocho categorías se pueda repetir sin duplicarlas. En MySQL una clave única
-- admite varios NULL, así que las categorías antiguas —que no cuelgan de ningún
-- daño— conviven sin estorbarse.

SET @sql := IF(@faltaSenal,
  'ALTER TABLE categorias_video
     ADD COLUMN senal VARCHAR(40) NULL DEFAULT NULL
       COMMENT ''Codigo de Senales::CATALOGO. Solo se pide si la persona marco ese dano''
       AFTER instruccion,
     ADD UNIQUE KEY uq_categorias_video_senal (senal)',
  'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Una categoría por señal ──────────────────────────────────────────────────
--
-- Las ocho señales de `Preinscripcion\Senales::CATALOGO`. Se siembran aquí y no
-- desde la pantalla de administración porque sin ellas el paso de videos queda
-- vacío, y el formulario en producción no tiene ninguna categoría útil.
--
-- Idempotente por `senal`: volver a aplicar la migración no duplica nada. Y NO
-- se actualiza lo que ya existe: si alguien afinó una instrucción desde la
-- administración, esto no se la pisa.
--
-- Las instrucciones dicen también qué NO hacer. Quien las lee acaba de perder
-- parte de su casa y va a grabar un techo caído o unos cables sueltos: pedirle
-- que no se suba al techo y que no toque los cables es parte del formulario,
-- no una nota al pie.

INSERT IGNORE INTO categorias_video
  (nombre, instruccion, senal, orden, obligatoria, segundos_min, segundos_max, activa)
VALUES
  ('Paredes agrietadas', 'Grabe la grieta de cerca y después aléjese para que se vea la pared entera. Pase despacio, de un extremo de la grieta al otro.', 'PARED_AGRIETADA', 10, 1, 5, 120, 1),
  ('Paredes caídas o inclinadas', 'Grabe el muro caído o torcido desde donde sea seguro pararse. No se acerque si puede venirse abajo más.', 'PARED_CAIDA', 20, 1, 5, 120, 1),
  ('Columnas o vigas partidas', 'Grabe la columna o la viga dañada de arriba abajo. Si se ven los fierros, deténgase unos segundos en ese punto.', 'COLUMNA_DANADA', 30, 1, 5, 120, 1),
  ('Tejas rotas o corridas', 'Grabe el techo desde el patio y, si se puede entrar sin riesgo, también desde adentro. No se suba al techo.', 'TECHO_TEJAS', 40, 1, 5, 120, 1),
  ('Techo caído', 'Grabe la parte del techo que se vino abajo, desde afuera. Entre a grabar solo si es seguro hacerlo.', 'TECHO_CAIDO', 50, 1, 5, 120, 1),
  ('Piso agrietado o hundido', 'Grabe el piso caminando despacio por encima de la parte rajada o hundida, para que se note el desnivel.', 'PISO_DANADO', 60, 1, 5, 120, 1),
  ('Tubería rota o fugas de agua', 'Grabe por dónde sale el agua o dónde se rompió la tubería. Si el daño es del tanque o del pozo, grabe ese punto.', 'AGUA_DANADA', 70, 1, 5, 120, 1),
  ('Instalación eléctrica dañada', 'Grabe los cables sueltos o rotos desde lejos. No los toque ni se acerque, aunque parezcan apagados.', 'LUZ_DANADA', 80, 1, 5, 120, 1);
