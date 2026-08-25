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

    /**
     * El profesional que evalúa las viviendas: ingeniero o arquitecto con
     * tarjeta. Suele ser personal contratado para la emergencia, así que su
     * acceso llega hasta el formato de inspección y no más allá — darle Gestor
     * para que pudiera trabajar le abría el censo entero y el mapa.
     */
    public const INSPECTOR = 'INSPECTOR';

    /**
     * Quien llama a los hogares del censo para llevarlos hasta la
     * preinscripción.
     *
     * Es el mismo caso del inspector, y por la misma razón: suele ser personal
     * contratado para la campaña, y para que pudiera trabajar habría que darle
     * «Gestor» —que le abre el censo entero, el mapa y las inspecciones—.
     * Necesita un nombre y un teléfono, no un censo.
     */
    public const OPERADOR = 'OPERADOR';

    /** Todos los roles válidos, en orden de mayor a menor privilegio. */
    public const ROLES = [
        self::ADMINISTRADOR,
        self::GESTOR,
        self::INSPECTOR,
        self::OPERADOR,
        self::VISUALIZACION,
    ];

    /** Roles que pueden escribir datos del censo y decidir sobre las fichas. */
    public const ESCRITURA = [self::ADMINISTRADOR, self::GESTOR];

    /**
     * Cualquier usuario autenticado.
     *
     * Es lo que su nombre promete, así que el inspector entra: si no, no podría
     * ni abrir su sesión ni cerrarla. Lo que NO se puede hacer es apoyarse en
     * esta lista para proteger lectura de datos del censo — para eso está
     * `LECTURA_RUFE`.
     */
    public const TODOS = [
        self::ADMINISTRADOR,
        self::GESTOR,
        self::INSPECTOR,
        self::OPERADOR,
        self::VISUALIZACION,
    ];

    /**
     * Quién puede leer el censo, sus evidencias y el mapa.
     *
     * Existe porque `TODOS` dejó de servir para esto al entrar el inspector:
     * son fichas con nombres, cédulas y direcciones de hogares damnificados, y
     * su trabajo no las necesita.
     */
    public const LECTURA_RUFE = [self::ADMINISTRADOR, self::GESTOR, self::VISUALIZACION];

    /** Quién levanta y consulta inspecciones de vivienda. */
    public const INSPECCION = [self::ADMINISTRADOR, self::GESTOR, self::INSPECTOR];

    /**
     * Quién puede CONSULTAR una inspección ya levantada.
     *
     * Es `TODOS` menos el operador de call center, y existe por lo mismo que
     * `LECTURA_RUFE`: al entrar un rol nuevo, `TODOS` dejó de significar «todo
     * el que tiene sesión puede ver esto» y pasó a colar en las inspecciones a
     * alguien cuyo trabajo es marcar un número de teléfono. Una ficha de
     * inspección lleva el nombre, la cédula y la dirección de una familia.
     *
     * La prueba «el operador no lee el censo, ni el mapa, ni las inspecciones»
     * es la que descubrió la fuga, y la que la volverá a descubrir.
     */
    public const LECTURA_INSPECCION = [
        self::ADMINISTRADOR,
        self::GESTOR,
        self::INSPECTOR,
        self::VISUALIZACION,
    ];

    /**
     * Quién trabaja la campaña de llamadas sobre la base del RUFE.
     *
     * El operador NO está en `LECTURA_RUFE`: lo que ve es una lista de nombres
     * y teléfonos para llamar, no las fichas del censo con sus evidencias, sus
     * observaciones y las cédulas del resto del hogar.
     */
    public const CALL_CENTER = [self::ADMINISTRADOR, self::GESTOR, self::OPERADOR];

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
        self::INSPECTOR => [
            // Ni `dashboard.ver` ni `datos.leer`: su trabajo es el formato de
            // inspección, no la consulta del censo.
            'inspeccion.levantar',
            'acerca.ver',
        ],
        self::OPERADOR => [
            // Solo su lista de llamadas. Ni el tablero, ni el censo, ni el mapa.
            'callcenter.gestionar',
            'acerca.ver',
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
        self::INSPECTOR => [
            'etiqueta'    => 'Insp. de vivienda',
            'descripcion' => 'Profesional que evalúa las viviendas afectadas: solo el formato de '
                .'inspección y sus fichas. No accede al censo, al mapa ni a la aprobación.',
        ],
        self::OPERADOR => [
            'etiqueta'    => 'Operador de call center',
            'descripcion' => 'Llama a los hogares del RUFE para acompañarlos a diligenciar la '
                .'preinscripción y registra el resultado. Ve nombre, teléfono y barrio; no accede '
                .'al censo, al mapa ni a las inspecciones.',
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
            // El perfil del profesional viaja con la sesión para que el formato
            // de inspección llegue con el numeral 1 precargado, también sin señal.
            'SELECT u.id, u.nombre, u.email, u.rol, u.activo,
                    u.profesion, u.tarjeta_profesional, u.documento, u.documento_de,
                    u.telefono, u.direccion,
                    s.id AS sesion_id, s.expira_en
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
