<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\Github;
use Throwable;

/**
 * Acerca de — dos pestañas:
 *   1. "Sistema": qué es, qué módulos tiene, con qué está hecho y en qué estado.
 *   2. "Actualizaciones del sistema": historial real del repositorio en GitHub,
 *      separado por quién hizo cada cambio.
 */
final class AcercaController
{
    /**
     * Personas del equipo. Un commit se atribuye por el usuario de GitHub
     * cuando viene (fiable, lo asigna GitHub) y, si no, por el nombre del
     * autor de git, que cada quien configura en su máquina y por eso admite
     * varias formas de escribirse.
     */
    private const EQUIPO = [
        'andres' => [
            'nombre'  => 'Andrés Ruiz',
            'rol'     => 'Desarrollo y despliegue',
            'logins'  => ['andresruizcadavid'],
            'alias'   => ['andres ruiz', 'andrés ruiz', 'andres ruiz cadavid', 'andresruizcadavid'],
        ],
        'milton' => [
            'nombre'  => 'Milton Peña',
            'rol'     => 'Desarrollo del tablero RUFE',
            'logins'  => ['miltonf10'],
            'alias'   => ['milton', 'milton peña', 'milton pena', 'miltonf10'],
        ],
    ];

    public function sistema(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);

        Response::ok([
            'aplicacion' => [
                'nombre'      => (string) Config::get('app.nombre'),
                'version'     => (string) Config::get('app.version'),
                'entorno'     => (string) Config::get('app.entorno'),
                'entidad'     => 'Alcaldía Municipal de Jamundí',
                'dependencia' => 'Oficina TIC — Gestión del Riesgo',
                'descripcion' => 'Plataforma de gestión del riesgo de desastres del municipio de Jamundí. '
                    .'Integra el tablero en vivo del RUFE (Registro Único de Familias Evacuadas) con la '
                    .'administración de usuarios y el control de versiones del sistema.',
            ],
            'modulos' => [
                [
                    'nombre'      => 'Dashboard',
                    'descripcion' => 'Tablero en vivo del RUFE: indicadores por barrio, zona y hogar, '
                        .'alimentado desde la hoja de cálculo del censo.',
                    'roles'       => ['Administrador', 'Gestor', 'Visualización'],
                ],
                [
                    'nombre'      => 'Acerca de',
                    'descripcion' => 'Esta página: descripción del sistema y bitácora de actualizaciones '
                        .'publicadas en el repositorio.',
                    'roles'       => ['Administrador', 'Gestor', 'Visualización'],
                ],
                [
                    'nombre'      => 'Gestión de usuarios',
                    'descripcion' => 'Alta, edición, activación y asignación de roles de las personas '
                        .'que usan el sistema.',
                    'roles'       => ['Administrador'],
                ],
            ],
            'roles' => array_map(
                static fn (string $clave) => [
                    'valor'       => $clave,
                    'etiqueta'    => Auth::DESCRIPCION_ROLES[$clave]['etiqueta'],
                    'descripcion' => Auth::DESCRIPCION_ROLES[$clave]['descripcion'],
                    'capacidades' => Auth::capacidades($clave),
                ],
                Auth::ROLES
            ),
            'tecnologia' => [
                ['capa' => 'Frontend', 'detalle' => 'SvelteKit 2 · Svelte 5 (runes) · Vite · build estático'],
                ['capa' => 'Backend',  'detalle' => 'API REST en PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' con PDO, sin dependencias externas'],
                ['capa' => 'Base de datos', 'detalle' => 'MySQL ('.$this->versionMysql().')'],
                ['capa' => 'Autenticación', 'detalle' => 'Token opaco por sesión, contraseñas con bcrypt'],
                ['capa' => 'Servidor', 'detalle' => 'Apache sobre hosting compartido cPanel'],
            ],
            'estado' => [
                'base_datos'      => $this->estadoBaseDatos(),
                'php'             => PHP_VERSION,
                'zona_horaria'    => (string) Config::get('app.zona'),
                'hora_servidor'   => date('c'),
                'usuarios_activos' => $this->contar('SELECT COUNT(*) AS t FROM usuarios WHERE activo = 1'),
                'sesiones_activas' => $this->contar('SELECT COUNT(*) AS t FROM sesiones WHERE expira_en > NOW()'),
            ],
            'repositorio' => (new Github)->repositorio(),
            'sesion' => [
                'rol'         => $usuario['rol'],
                'capacidades' => $usuario['capacidades'],
            ],
        ]);
    }

    public function actualizaciones(Request $req): void
    {
        Auth::exigirUsuario($req);

        $github = new Github;
        $forzar = $req->query('refrescar') === '1';
        $resultado = $github->commits(50, $forzar);

        $commits = array_map([$this, 'atribuir'], $resultado['commits']);

        Response::ok([
            'repositorio'  => $github->repositorio(),
            'fuentes'      => $github->fuentesPublicas(),
            'commits'      => $commits,
            'autores'      => $this->resumenPorAutor($commits),
            'total'        => count($commits),
            'desde_cache'  => $resultado['desde_cache'],
            'error'        => $resultado['error'],
            'consultado_en' => date('c'),
        ]);
    }

    // ── Apoyo ────────────────────────────────────────────────────────────

    /** Añade a cada commit la persona del equipo a la que corresponde. */
    private function atribuir(array $commit): array
    {
        $login = strtolower((string) ($commit['autor_login'] ?? ''));
        $nombre = strtolower(trim((string) $commit['autor_nombre']));

        foreach (self::EQUIPO as $clave => $persona) {
            $porLogin = $login !== '' && in_array($login, $persona['logins'], true);
            $porNombre = in_array($nombre, $persona['alias'], true);

            if ($porLogin || $porNombre) {
                $commit['equipo_clave'] = $clave;
                $commit['equipo_nombre'] = $persona['nombre'];
                $commit['equipo_rol'] = $persona['rol'];

                return $commit;
            }
        }

        // Autor no reconocido: se muestra igual, con su nombre de git. Ocultarlo
        // dejaría huecos inexplicables en la línea de tiempo.
        $commit['equipo_clave'] = 'otros';
        $commit['equipo_nombre'] = $commit['autor_nombre'] ?: 'Otro colaborador';
        $commit['equipo_rol'] = 'Colaborador';

        return $commit;
    }

    /** Conteo y última contribución por persona, para las tarjetas de resumen. */
    private function resumenPorAutor(array $commits): array
    {
        $resumen = [];

        foreach (self::EQUIPO as $clave => $persona) {
            $resumen[$clave] = [
                'clave'       => $clave,
                'nombre'      => $persona['nombre'],
                'rol'         => $persona['rol'],
                'login'       => $persona['logins'][0] ?? null,
                'avatar'      => null,
                'total'       => 0,
                'ultima_fecha' => null,
            ];
        }

        foreach ($commits as $commit) {
            $clave = (string) $commit['equipo_clave'];

            if (! isset($resumen[$clave])) {
                $resumen[$clave] = [
                    'clave'        => $clave,
                    'nombre'       => $commit['equipo_nombre'],
                    'rol'          => $commit['equipo_rol'],
                    'login'        => $commit['autor_login'],
                    'avatar'       => $commit['autor_avatar'],
                    'total'        => 0,
                    'ultima_fecha' => null,
                ];
            }

            $resumen[$clave]['total']++;
            $resumen[$clave]['avatar'] ??= $commit['autor_avatar'];

            // Los commits llegan de GitHub del más reciente al más antiguo, pero
            // no se da por hecho: se compara la fecha real.
            $fecha = (string) $commit['fecha'];
            $actual = $resumen[$clave]['ultima_fecha'];
            if ($actual === null || strtotime($fecha) > strtotime((string) $actual)) {
                $resumen[$clave]['ultima_fecha'] = $fecha;
            }
        }

        return array_values($resumen);
    }

    private function versionMysql(): string
    {
        try {
            $fila = Db::first('SELECT VERSION() AS v');

            return (string) ($fila['v'] ?? 'desconocida');
        } catch (Throwable) {
            return 'sin conexión';
        }
    }

    private function estadoBaseDatos(): array
    {
        try {
            Db::first('SELECT 1');

            return ['conectada' => true, 'nombre' => (string) Config::get('db.nombre')];
        } catch (Throwable) {
            return ['conectada' => false, 'nombre' => (string) Config::get('db.nombre')];
        }
    }

    private function contar(string $sql): int
    {
        try {
            $fila = Db::first($sql);

            return (int) ($fila['t'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
