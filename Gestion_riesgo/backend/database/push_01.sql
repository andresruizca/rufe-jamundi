-- Sistema de Gestión del Riesgo — Jamundí
-- Avisos al aparato: notificaciones push.
--
-- ── Para qué ─────────────────────────────────────────────────────────────────
--
-- El propio tablero mide un atasco que se llama «solicitudes demoradas»: las
-- que llevan más de tres días sin que nadie las abra. Una familia que acaba de
-- perder parte de su casa no espera tres días en silencio: llama al conmutador.
--
-- Este aviso existe para que esa espera no ocurra. Cuando entra una solicitud
-- ciudadana, a quien puede atenderla le suena el teléfono.
--
-- ── Qué NO viaja ─────────────────────────────────────────────────────────────
--
-- El aviso va SIN contenido. Ni un nombre, ni una cédula, ni un barrio.
--
-- No es una limitación técnica: es la decisión. Una notificación con datos pasa
-- por los servidores de Google o de Mozilla, y aunque va cifrada, sale del
-- control de la Alcaldía. Lo que se manda es un golpe en la puerta —«hay algo
-- nuevo»— y el dato se lee dentro del sistema, con sesión iniciada, como todo
-- lo demás.
--
-- Dos tablas, las dos nuevas. Nada existente cambia.

SET NAMES utf8mb4;

-- ── Las claves del servidor ──────────────────────────────────────────────────
--
-- VAPID: un par de claves con las que este servidor firma cada aviso, para que
-- el navegador sepa que viene de aquí y no de cualquiera que haya conseguido
-- una dirección de envío.
--
-- Viven en la base y no en `config.php` porque el sistema se despliega sin
-- consola: pedirle a alguien que edite a mano un archivo de configuración en
-- producción es pedirle que rompa el sistema una de cada cinco veces. El
-- servidor las genera solo la primera vez que hacen falta.
--
-- Una fila y solo una: `clave` es PRIMARY KEY.

CREATE TABLE IF NOT EXISTS push_claves (
  clave        VARCHAR(32)  NOT NULL,
  valor        TEXT         NOT NULL,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── A qué aparatos avisar ────────────────────────────────────────────────────
--
-- Una fila por navegador que dijo que sí. La misma persona puede tener tres: el
-- computador de la oficina, su teléfono y el de casa. Se le avisa a los tres,
-- porque no hay forma de saber cuál tiene delante.
--
-- `endpoint` es la dirección que da el navegador y es única en el mundo: si el
-- mismo aparato se vuelve a suscribir, se actualiza la fila en vez de crear
-- otra, y así no se manda el mismo aviso dos veces.
--
-- `p256dh` y `auth` son las claves del navegador. Hoy no se usan —el aviso va
-- sin contenido y por tanto sin cifrar— y se guardan igual: el día que haga
-- falta mandar algo dentro, volver a pedir permiso a cada funcionario sería
-- empezar de cero.
--
-- ON DELETE CASCADE: si se borra al usuario, sus aparatos se van con él. Un
-- aviso a la suscripción de alguien que ya no trabaja en la Alcaldía es una
-- filtración pequeña pero real.

CREATE TABLE IF NOT EXISTS push_suscripciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  endpoint       VARCHAR(512) NOT NULL,
  -- El hash de la dirección, porque una VARCHAR(512) no cabe en un índice
  -- único de MySQL 5.7 con utf8mb4. Es lo que impide la fila duplicada.
  endpoint_hash  CHAR(64)     NOT NULL,
  p256dh         VARCHAR(255) NOT NULL DEFAULT '',
  auth           VARCHAR(255) NOT NULL DEFAULT '',
  agente         VARCHAR(255) NOT NULL DEFAULT '',
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Cuándo se le mandó algo por última vez y cómo fue. Sin esto, una
  -- suscripción muerta se reintenta para siempre sin que nadie lo sepa.
  ultimo_envio   DATETIME     NULL DEFAULT NULL,
  ultimo_error   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uk_push_endpoint (endpoint_hash),
  KEY idx_push_usuario (usuario_id),
  CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
