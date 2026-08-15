<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;

final class AuthController
{
    public function login(Request $req): void
    {
        $email = strtolower($req->texto('email'));
        $password = (string) $req->input('password', '');

        if ($email === '' || $password === '') {
            throw HttpError::validacion([
                'email'    => $email === '' ? 'Escribe tu correo.' : '',
                'password' => $password === '' ? 'Escribe tu contraseña.' : '',
            ]);
        }

        $usuario = Db::first(
            'SELECT id, nombre, email, password_hash, rol, activo
               FROM usuarios WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        // Mismo mensaje y mismo costo tanto si el correo no existe como si la
        // contraseña es incorrecta: distinguirlos permitiría averiguar qué
        // correos están registrados. El hash falso mantiene el tiempo de
        // respuesta parejo cuando el usuario no existe.
        $hash = $usuario['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $correcto = password_verify($password, $hash);

        if ($usuario === null || ! $correcto) {
            Auditoria::registrar($req, 'login.fallido', null, 'usuarios', null, "Intento con {$email}");
            throw new HttpError('Correo o contraseña incorrectos.', 401);
        }

        if ((int) $usuario['activo'] !== 1) {
            Auditoria::registrar($req, 'login.inactivo', null, 'usuarios', (string) $usuario['id']);
            throw HttpError::prohibido('Tu cuenta está desactivada. Comunícate con un administrador.');
        }

        $sesion = Auth::crearSesion((int) $usuario['id'], $req);

        Db::exec('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id', ['id' => $usuario['id']]);

        Auditoria::registrar($req, 'login', [
            'id' => (int) $usuario['id'], 'email' => $usuario['email'],
        ]);

        Response::ok([
            'token'     => $sesion['token'],
            'expira_en' => $sesion['expira_en'],
            'usuario'   => [
                'id'          => (int) $usuario['id'],
                'nombre'      => $usuario['nombre'],
                'email'       => $usuario['email'],
                'rol'         => $usuario['rol'],
                'capacidades' => Auth::capacidades((string) $usuario['rol']),
            ],
        ]);
    }

    public function me(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        unset($usuario['sesion_id']);

        Response::ok(['usuario' => $usuario]);
    }

    public function logout(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        Auth::cerrarSesion($usuario['sesion_id']);
        Auditoria::registrar($req, 'logout', $usuario);

        Response::ok(['mensaje' => 'Sesión cerrada.']);
    }

    /** Cambio de la contraseña propia. Cualquier rol puede hacerlo. */
    public function cambiarPassword(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        $actual = (string) $req->input('password_actual', '');
        $nueva  = (string) $req->input('password_nueva', '');

        if (strlen($nueva) < 10) {
            throw HttpError::validacion(['password_nueva' => 'Debe tener al menos 10 caracteres.']);
        }

        $fila = Db::first('SELECT password_hash FROM usuarios WHERE id = :id', ['id' => $usuario['id']]);
        if ($fila === null || ! password_verify($actual, (string) $fila['password_hash'])) {
            throw HttpError::validacion(['password_actual' => 'La contraseña actual no es correcta.']);
        }

        Db::exec(
            'UPDATE usuarios SET password_hash = :h WHERE id = :id',
            ['h' => password_hash($nueva, PASSWORD_BCRYPT), 'id' => $usuario['id']]
        );

        // Cambiar la contraseña invalida las demás sesiones: si alguien había
        // entrado con la contraseña vieja, pierde el acceso aquí mismo.
        Db::exec(
            'DELETE FROM sesiones WHERE usuario_id = :uid AND id <> :sid',
            ['uid' => $usuario['id'], 'sid' => $usuario['sesion_id']]
        );

        Auditoria::registrar($req, 'password.cambiada', $usuario);

        Response::ok(['mensaje' => 'Contraseña actualizada.']);
    }
}
