<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autenticación por token opaco y modelo de roles.
 *
 * No se usa JWT a propósito: un JWT no se puede revocar sin mantener igualmente
 * una lista en base de datos, así que aquí el token es un valor aleatorio cuyo
 * SHA-256 se guarda en `sesiones`. Cerrar sesión o desactivar un usuario surte
 * efecto de inmediato, que es lo que se espera de un sistema con datos de
 * damnificados.
 */
final class Auth
{
    public const ADMINISTRADOR = 'ADMINISTRADOR';
    public const GESTOR        = 'GESTOR';
    public const VISUALIZACION = 'VISUALIZACION';

    /** Todos los roles válidos, en orden de mayor a menor privilegio. */
    public const ROLES = [self::ADMINISTRADOR, self::GESTOR, self::VISUALIZACION];

    /** Roles que pueden escribir datos. */
    public const ESCRITURA = [self::ADMINISTRADOR, self::GESTOR];

    /** Cualquier usuario autenticado. */
    public const TODOS = [self::ADMINISTRADOR, self::GESTOR, self::VISUALIZACION];

    /**
     * Capacidades por rol. El frontend las usa para mostrar u ocultar
     * controles; el backend sigue validando por su cuenta en cada ruta, porque
     * ocultar un botón no es una medida de seguridad.
     *
     * @var array<string,string[]>
     */
    private const CAPACIDADES = [
        self::ADMINISTRADOR => [
            'dashboard.ver',
            'datos.leer',
            'datos.escribir',
            'usuarios.ver',
            'usuarios.gestionar',
            'acerca.ver',
            'actualizaciones.ver',
        ],
        self::GESTOR => [
            'dashboard.ver',
            'datos.leer',
            'datos.escribir',
            'acerca.ver',
            'actualizaciones.ver',
        ],
        self::VISUALIZACION => [
            'dashboard.ver',
            'datos.leer',
            'acerca.ver',
        ],
    ];

    /** Etiquetas y descripción, para el módulo de administración. */
    public const DESCRIPCION_ROLES = [
        self::ADMINISTRADOR => [
            'etiqueta'    => 'Administrador',
            'descripcion' => 'Control total del sistema: lectura, escritura y gestión de usuarios.',
        ],
        self::GESTOR => [
            'etiqueta'    => 'Gestor',
            'descripcion' => 'Carga de datos: lectura y escritura, sin acceso a la gestión de usuarios.',
        ],
        self::VISUALIZACION => [
            'etiqueta'    => 'Visualización',
            'descripcion' => 'Solo acceso a visualizar los indicadores (KPI) y los tableros de análisis (BI).',
        ],
    ];

    private static ?array $usuario = null;

    /** @return string[] */
    public static function capacidades(string $rol): array
    {
        return self::CAPACIDADES[$rol] ?? [];
    }

    public static function esRolValido(string $rol): bool
    {
        return in_array($rol, self::ROLES, true);
    }

    /**
     * Usuario de la sesión, o null si el token falta, no existe, expiró o el
     * usuario fue desactivado.
     */
    public static function usuario(Request $req): ?array
    {
        if (self::$usuario !== null) {
            return self::$usuario;
        }

        $token = $req->token();
        if ($token === null || $token === '') {
            return null;
        }

        $fila = Db::first(
            'SELECT u.id, u.nombre, u.email, u.rol, u.activo, s.id AS sesion_id, s.expira_en
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :hash
              LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        if ($fila === null) {
            return null;
        }

        // Sesión vencida: se borra en el acto para que la tabla no acumule
        // basura sin depender de una tarea programada.
        if (strtotime((string) $fila['expira_en']) < time()) {
            Db::exec('DELETE FROM sesiones WHERE id = :id', ['id' => $fila['sesion_id']]);

            return null;
        }

        if ((int) $fila['activo'] !== 1) {
            return null;
        }

        self::$usuario = [
            'id'           => (int) $fila['id'],
            'nombre'       => (string) $fila['nombre'],
            'email'        => (string) $fila['email'],
            'rol'          => (string) $fila['rol'],
            'sesion_id'    => (int) $fila['sesion_id'],
            'capacidades'  => self::capacidades((string) $fila['rol']),
        ];

        return self::$usuario;
    }

    public static function exigirUsuario(Request $req): array
    {
        $usuario = self::usuario($req);
        if ($usuario === null) {
            throw HttpError::noAutenticado();
        }

        return $usuario;
    }

    /** Crea una sesión y devuelve el token en claro (única vez que existe). */
    public static function crearSesion(int $usuarioId, Request $req): array
    {
        $token = bin2hex(random_bytes(32));
        $horas = max(1, (int) Config::get('auth.duracion_horas', 12));
        $expira = date('Y-m-d H:i:s', time() + $horas * 3600);

        Db::exec(
            'INSERT INTO sesiones (usuario_id, token_hash, ip, user_agent, expira_en)
             VALUES (:uid, :hash, :ip, :ua, :exp)',
            [
                'uid'  => $usuarioId,
                'hash' => hash('sha256', $token),
                'ip'   => $req->ip(),
                'ua'   => $req->userAgent(),
                'exp'  => $expira,
            ]
        );

        return ['token' => $token, 'expira_en' => $expira];
    }

    public static function cerrarSesion(int $sesionId): void
    {
        Db::exec('DELETE FROM sesiones WHERE id = :id', ['id' => $sesionId]);
    }
}
