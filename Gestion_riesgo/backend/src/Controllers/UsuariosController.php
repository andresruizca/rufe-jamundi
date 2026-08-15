<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use PDOException;

/**
 * Administración → Gestión de usuarios del sistema.
 *
 * Todas las rutas están restringidas a ADMINISTRADOR desde el router. Aquí solo
 * se protege lo que el rol por sí solo no cubre: que un administrador no pueda
 * dejar al sistema sin administradores ni quitarse a sí mismo el acceso.
 */
final class UsuariosController
{
    private const CAMPOS = 'id, nombre, email, rol, activo, ultimo_acceso, creado_en, actualizado_en';

    public function listar(Request $req): void
    {
        $usuarios = Db::all('SELECT '.self::CAMPOS.' FROM usuarios ORDER BY nombre ASC');

        Response::ok([
            'usuarios' => array_map([$this, 'presentar'], $usuarios),
            'roles'    => $this->catalogoRoles(),
        ]);
    }

    public function ver(Request $req): void
    {
        Response::ok(['usuario' => $this->presentar($this->buscar((int) $req->param('id')))]);
    }

    public function crear(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);

        $nombre   = $req->texto('nombre');
        $email    = strtolower($req->texto('email'));
        $rol      = strtoupper($req->texto('rol', Auth::VISUALIZACION));
        $password = (string) $req->input('password', '');
        $activo   = $req->input('activo', true) ? 1 : 0;

        $errores = $this->validar($nombre, $email, $rol);
        if (strlen($password) < 10) {
            $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        }
        if ($errores !== []) {
            throw HttpError::validacion($errores);
        }

        try {
            Db::exec(
                'INSERT INTO usuarios (nombre, email, password_hash, rol, activo)
                 VALUES (:nombre, :email, :hash, :rol, :activo)',
                [
                    'nombre' => $nombre,
                    'email'  => $email,
                    'hash'   => password_hash($password, PASSWORD_BCRYPT),
                    'rol'    => $rol,
                    'activo' => $activo,
                ]
            );
        } catch (PDOException $e) {
            // 23000 = violación de la clave única del correo. Se traduce a un
            // error de validación en lugar de un 500.
            if ($e->getCode() === '23000') {
                throw HttpError::validacion(['email' => 'Ya existe un usuario con ese correo.']);
            }
            throw $e;
        }

        $id = Db::lastId();
        Auditoria::registrar($req, 'usuario.creado', $actor, 'usuarios', (string) $id, "{$email} como {$rol}");

        Response::json(['ok' => true, 'data' => ['usuario' => $this->presentar($this->buscar($id))]], 201);
    }

    public function actualizar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $usuario = $this->buscar($id);

        $nombre = $req->texto('nombre', (string) $usuario['nombre']);
        $email  = strtolower($req->texto('email', (string) $usuario['email']));
        $rol    = strtoupper($req->texto('rol', (string) $usuario['rol']));
        $activo = $req->input('activo', (bool) $usuario['activo']) ? 1 : 0;

        $errores = $this->validar($nombre, $email, $rol);
        if ($errores !== []) {
            throw HttpError::validacion($errores);
        }

        // Un administrador no puede quitarse a sí mismo el rol ni desactivarse:
        // lo habitual es que sea el único con acceso, y se quedaría fuera del
        // sistema sin forma de volver a entrar.
        if ($id === $actor['id']) {
            if ($rol !== Auth::ADMINISTRADOR) {
                throw HttpError::prohibido('No puedes cambiar tu propio rol de administrador.');
            }
            if ($activo === 0) {
                throw HttpError::prohibido('No puedes desactivar tu propia cuenta.');
            }
        }

        // El sistema debe conservar siempre al menos un administrador activo.
        $dejaDeSerAdmin = $usuario['rol'] === Auth::ADMINISTRADOR
            && ($rol !== Auth::ADMINISTRADOR || $activo === 0);
        if ($dejaDeSerAdmin && $this->contarAdministradoresActivos($id) === 0) {
            throw HttpError::prohibido(
                'Este es el último administrador activo. Asigna el rol a otra persona antes de cambiarlo.'
            );
        }

        try {
            Db::exec(
                'UPDATE usuarios SET nombre = :nombre, email = :email, rol = :rol, activo = :activo
                  WHERE id = :id',
                ['nombre' => $nombre, 'email' => $email, 'rol' => $rol, 'activo' => $activo, 'id' => $id]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpError::validacion(['email' => 'Ya existe un usuario con ese correo.']);
            }
            throw $e;
        }

        // Perder el acceso debe surtir efecto ya, no cuando venza el token.
        if ($activo === 0 || $rol !== $usuario['rol']) {
            Db::exec('DELETE FROM sesiones WHERE usuario_id = :id', ['id' => $id]);
        }

        Auditoria::registrar($req, 'usuario.actualizado', $actor, 'usuarios', (string) $id, "rol={$rol} activo={$activo}");

        Response::ok(['usuario' => $this->presentar($this->buscar($id))]);
    }

    /** Restablece la contraseña de otra persona (no exige la anterior). */
    public function restablecerPassword(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $this->buscar($id);

        $nueva = (string) $req->input('password', '');
        if (strlen($nueva) < 10) {
            throw HttpError::validacion(['password' => 'La contraseña debe tener al menos 10 caracteres.']);
        }

        Db::exec(
            'UPDATE usuarios SET password_hash = :h WHERE id = :id',
            ['h' => password_hash($nueva, PASSWORD_BCRYPT), 'id' => $id]
        );
        Db::exec('DELETE FROM sesiones WHERE usuario_id = :id', ['id' => $id]);

        Auditoria::registrar($req, 'usuario.password_restablecida', $actor, 'usuarios', (string) $id);

        Response::ok(['mensaje' => 'Contraseña restablecida. La persona deberá iniciar sesión de nuevo.']);
    }

    public function eliminar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $usuario = $this->buscar($id);

        if ($id === $actor['id']) {
            throw HttpError::prohibido('No puedes eliminar tu propia cuenta.');
        }

        if ($usuario['rol'] === Auth::ADMINISTRADOR && $this->contarAdministradoresActivos($id) === 0) {
            throw HttpError::prohibido('Es el último administrador activo del sistema.');
        }

        Db::exec('DELETE FROM usuarios WHERE id = :id', ['id' => $id]);
        Auditoria::registrar($req, 'usuario.eliminado', $actor, 'usuarios', (string) $id, (string) $usuario['email']);

        Response::ok(['mensaje' => 'Usuario eliminado.']);
    }

    // ── Apoyo ────────────────────────────────────────────────────────────

    /** Administradores activos SIN contar al usuario indicado. */
    private function contarAdministradoresActivos(int $excluyendoId): int
    {
        $fila = Db::first(
            'SELECT COUNT(*) AS total FROM usuarios
              WHERE rol = :rol AND activo = 1 AND id <> :id',
            ['rol' => Auth::ADMINISTRADOR, 'id' => $excluyendoId]
        );

        return (int) ($fila['total'] ?? 0);
    }

    private function buscar(int $id): array
    {
        $usuario = Db::first('SELECT '.self::CAMPOS.' FROM usuarios WHERE id = :id', ['id' => $id]);
        if ($usuario === null) {
            throw HttpError::noEncontrado('El usuario no existe.');
        }

        return $usuario;
    }

    /** @return array<string,string> */
    private function validar(string $nombre, string $email, string $rol): array
    {
        $errores = [];

        if (mb_strlen($nombre) < 3) {
            $errores['nombre'] = 'Escribe el nombre completo.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errores['email'] = 'El correo no es válido.';
        }
        if (! Auth::esRolValido($rol)) {
            $errores['rol'] = 'Selecciona un rol válido.';
        }

        return $errores;
    }

    private function presentar(array $u): array
    {
        return [
            'id'             => (int) $u['id'],
            'nombre'         => $u['nombre'],
            'email'          => $u['email'],
            'rol'            => $u['rol'],
            'rol_etiqueta'   => Auth::DESCRIPCION_ROLES[$u['rol']]['etiqueta'] ?? $u['rol'],
            'activo'         => (bool) $u['activo'],
            'ultimo_acceso'  => $u['ultimo_acceso'],
            'creado_en'      => $u['creado_en'],
        ];
    }

    private function catalogoRoles(): array
    {
        $salida = [];
        foreach (Auth::DESCRIPCION_ROLES as $clave => $info) {
            $salida[] = [
                'valor'        => $clave,
                'etiqueta'     => $info['etiqueta'],
                'descripcion'  => $info['descripcion'],
                'capacidades'  => Auth::capacidades($clave),
            ];
        }

        return $salida;
    }
}
