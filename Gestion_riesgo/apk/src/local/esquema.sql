-- Base local del APK ciudadano.
--
-- Vive en SQLite y no en IndexedDB por una razón concreta: WorkManager sincroniza
-- con la aplicación CERRADA, y en ese momento no hay WebView. El código que sube
-- es Kotlin, y Kotlin no puede leer IndexedDB. Todo lo que la sincronización
-- necesite tiene que estar aquí.
--
-- Por eso el esquema evita cualquier cosa que obligue a interpretar JSON desde
-- Kotlin más de lo imprescindible: las señales van en su propia tabla, no
-- serializadas dentro del registro.

-- ⚠ ESTE PRAGMA NO BASTA, Y NO ESTÁ AQUÍ POR SEGURIDAD.
--
-- En SQLite `foreign_keys` es POR CONEXIÓN, no del archivo. Ponerlo aquí solo
-- vale para la conexión que aplicó el esquema; la siguiente abre con las claves
-- foráneas APAGADAS y los ON DELETE CASCADE no ocurren.
--
-- Lo comprobé: borrando un registro desde otra conexión quedaron dos señales y
-- un adjunto huérfanos. Y eso importa de verdad, porque WorkManager sincroniza
-- desde Kotlin con su propia conexión: borraría el registro ya enviado y
-- dejaría filas apuntando a archivos que cree eliminados.
--
-- REGLA: todo código que abra esta base —TypeScript o Kotlin— emite
-- `PRAGMA foreign_keys = ON` justo después de abrir. `scripts/comprobar-esquema.mjs`
-- lo verifica.
PRAGMA foreign_keys = ON;

-- ── La solicitud ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS registros (
  id                    TEXT PRIMARY KEY,

  -- Lo que hace seguro reintentar. Se genera UNA vez, al guardar, y no cambia
  -- nunca: si el envío entró pero la respuesta se perdió, el servidor devuelve
  -- el radicado original en vez de inscribir dos veces a la misma familia.
  envio_id              TEXT NOT NULL UNIQUE,

  nombre_completo       TEXT NOT NULL,
  documento             TEXT NOT NULL,
  telefono              TEXT NOT NULL,
  correo                TEXT,

  zona                  TEXT NOT NULL,          -- URBANA | RURAL
  direccion             TEXT NOT NULL,
  vereda                TEXT,
  corregimiento         TEXT,
  latitud               REAL,
  longitud              REAL,
  precision_m           INTEGER,

  descripcion_dano      TEXT,

  autoriza_datos        INTEGER NOT NULL DEFAULT 0,
  -- Se guarda la versión que la persona TENÍA DELANTE al aceptar, no la que hoy
  -- diga el servidor. Es lo que prueba el consentimiento.
  aviso_version         TEXT NOT NULL,
  autorizacion_en       TEXT NOT NULL,

  -- PENDIENTE · SINCRONIZANDO · SINCRONIZADO · ERROR_VALIDACION · ERROR
  estado                TEXT NOT NULL DEFAULT 'PENDIENTE',

  -- El token de carga que abrió el servidor. Se conserva entre intentos: si la
  -- señal se cortó tras subir tres fotos, el siguiente intento las aprovecha en
  -- vez de volver a subirlas.
  carga                 TEXT,

  radicado              TEXT,
  error_ultimo          TEXT,
  intentos              INTEGER NOT NULL DEFAULT 0,
  ultimo_intento_en     TEXT,
  proximo_intento_en    TEXT,

  creado_en             TEXT NOT NULL,
  actualizado_en        TEXT NOT NULL,
  sincronizado_en       TEXT
);

CREATE INDEX IF NOT EXISTS idx_registros_estado ON registros(estado);
CREATE INDEX IF NOT EXISTS idx_registros_proximo ON registros(proximo_intento_en);

-- ── Lo que marcó en el paso 2 ───────────────────────────────────────────────
--
-- En su propia tabla y no como JSON dentro de `registros`: Kotlin tiene que
-- armar el arreglo `senales` del envío, y hacerlo leyendo filas es más simple y
-- menos frágil que interpretando una cadena.

CREATE TABLE IF NOT EXISTS registro_senales (
  registro_id  TEXT NOT NULL,
  codigo       TEXT NOT NULL,
  PRIMARY KEY (registro_id, codigo),
  FOREIGN KEY (registro_id) REFERENCES registros(id) ON DELETE CASCADE
);

-- ── Fotos y videos ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS adjuntos (
  id                 TEXT PRIMARY KEY,
  registro_id        TEXT NOT NULL,

  -- PRE_CEDULA · PRE_DANO · VIDEO
  -- Los dos primeros son los tipos que el servidor acepta tal cual.
  tipo               TEXT NOT NULL,

  ruta               TEXT NOT NULL,   -- en el sistema de archivos del teléfono
  mime               TEXT NOT NULL,
  bytes              INTEGER NOT NULL,
  segundos           INTEGER,

  -- Solo para videos. `categoria_id` puede quedar apuntando a una categoría que
  -- el servidor borró mientras el teléfono estaba sin señal; allá la clave
  -- foránea es ON DELETE SET NULL, así que el video se guarda igual.
  categoria_id       INTEGER,
  categoria_nombre   TEXT,

  -- PENDIENTE · SUBIENDO · SUBIDO · ERROR
  estado             TEXT NOT NULL DEFAULT 'PENDIENTE',

  -- El identificador que devolvió el servidor al reservar el video, y hasta qué
  -- trozo llegó. Con esto una subida cortada a la mitad continúa donde iba en
  -- vez de empezar de cero: en una vereda esa diferencia es real.
  video_id_servidor  INTEGER,
  trozos_totales     INTEGER,
  trozos_subidos     INTEGER NOT NULL DEFAULT 0,

  error_ultimo       TEXT,
  intentos           INTEGER NOT NULL DEFAULT 0,

  creado_en          TEXT NOT NULL,
  actualizado_en     TEXT NOT NULL,

  FOREIGN KEY (registro_id) REFERENCES registros(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_adjuntos_registro ON adjuntos(registro_id);
CREATE INDEX IF NOT EXISTS idx_adjuntos_estado ON adjuntos(estado);

-- ── Ajustes del aparato ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS ajustes (
  clave           TEXT PRIMARY KEY,
  valor           TEXT,
  actualizado_en  TEXT
);

-- `dispositivo_id` se genera al primer arranque y no identifica a nadie: es un
-- número aleatorio que solo sirve para que el servidor reparta su cuota por
-- aparato en vez de por IP. Sin él, una vereda entera comparte los cinco envíos
-- por hora de una sola conexión — ver docs/servidor-requerido.md.
INSERT OR IGNORE INTO ajustes (clave, valor) VALUES
  ('dispositivo_id', NULL),
  ('api_base', 'https://grj.oticjamundi.com/api'),
  ('catalogo_en', NULL),
  ('ultimo_sync_ok', NULL);

-- ── Bitácora de envío ───────────────────────────────────────────────────────
--
-- Una fila por INTENTO, no por registro.
--
-- `registros` guarda solo el último: `intentos`, `ultimo_intento_en`,
-- `error_ultimo`. Eso basta para decidir cuándo reintentar, pero no para
-- responder la pregunta que de verdad hace la gente —«¿cuándo se mandó lo mío?»—
-- ni la que hace quien atiende el teléfono: «¿se ha intentado siquiera?».
--
-- Con esto la persona puede ver «7:42 p.m. · sin señal · 8:15 p.m. · enviado» y
-- entender qué pasó, en vez de mirar un aviso que lleva horas igual.
--
-- La escribe `SyncWorker.kt` en cada intento. Se va por cascada con su registro.
CREATE TABLE IF NOT EXISTS bitacora (
  id            TEXT PRIMARY KEY,
  registro_id   TEXT NOT NULL,
  cuando        TEXT NOT NULL,
  -- INTENTO, SIN_CONEXION, ERROR, ENVIADO
  resultado     TEXT NOT NULL,
  -- Lo que se le puede enseñar a la persona. Nada de rastros técnicos.
  detalle       TEXT,
  FOREIGN KEY (registro_id) REFERENCES registros(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_bitacora_registro ON bitacora(registro_id, cuando);
