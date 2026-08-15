-- Sistema de Gestión del Riesgo — Jamundí
-- Esquema MySQL 5.7+/8.0. Idempotente: se puede ejecutar varias veces.

SET NAMES utf8mb4;

-- Usuarios del sistema.
-- El rol se guarda como ENUM y no en una tabla aparte: son tres roles fijos
-- definidos por la Alcaldía, no un catálogo que el usuario administre. Una
-- tabla `roles` añadiría un JOIN en cada petición autenticada sin ganar nada.
CREATE TABLE IF NOT EXISTS usuarios (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(120)    NOT NULL,
  email           VARCHAR(180)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  rol             ENUM('ADMINISTRADOR','GESTOR','VISUALIZACION') NOT NULL DEFAULT 'VISUALIZACION',
  activo          TINYINT(1)      NOT NULL DEFAULT 1,
  ultimo_acceso   DATETIME        NULL DEFAULT NULL,
  creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  KEY idx_usuarios_rol (rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones. Se guarda el SHA-256 del token, nunca el token en claro: si la
-- base se filtra, los tokens robados no sirven para suplantar a nadie.
CREATE TABLE IF NOT EXISTS sesiones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED    NOT NULL,
  token_hash    CHAR(64)        NOT NULL,
  ip            VARCHAR(45)     NULL DEFAULT NULL,
  user_agent    VARCHAR(255)    NULL DEFAULT NULL,
  expira_en     DATETIME        NOT NULL,
  creado_en     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sesiones_token (token_hash),
  KEY idx_sesiones_usuario (usuario_id),
  KEY idx_sesiones_expira (expira_en),
  CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de auditoría. Quién hizo qué y sobre qué registro.
CREATE TABLE IF NOT EXISTS auditoria (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED    NULL DEFAULT NULL,
  usuario_email VARCHAR(180)    NULL DEFAULT NULL,
  accion        VARCHAR(60)     NOT NULL,
  entidad       VARCHAR(60)     NULL DEFAULT NULL,
  entidad_id    VARCHAR(60)     NULL DEFAULT NULL,
  detalle       TEXT            NULL DEFAULT NULL,
  ip            VARCHAR(45)     NULL DEFAULT NULL,
  creado_en     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_auditoria_usuario (usuario_id),
  KEY idx_auditoria_creado (creado_en),
  CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes del sistema (clave/valor). Usado por "Acerca de" para la versión
-- desplegada y la configuración del repositorio de GitHub.
CREATE TABLE IF NOT EXISTS ajustes (
  clave           VARCHAR(80)   NOT NULL,
  valor           TEXT          NULL DEFAULT NULL,
  actualizado_en  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
