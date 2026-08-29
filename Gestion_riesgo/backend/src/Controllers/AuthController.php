<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Limite;
use App\Core\Request;
use App\Core\Response;

final class AuthController
{
    /**
     * Cuántos intentos fallidos se toleran, y en cuánto tiempo.
     *
     * ── El agujero que esto cierra ───────────────────────────────────────────
     *
     * `/auth/login` es una de las trece rutas que se pueden llamar sin sesión, y
     * era la única sin freno: se podían probar contraseñas indefinidamente desde
     * una sola IP. Detrás de esa puerta hay nombres, teléfonos y direcciones de
     * mil trescientos hogares damnificados.
     *
     * ── Por qué dos cubetas y no una ────────────────────────────────────────
     *
     * Por IP se para a quien prueba mil contraseñas desde un sitio. Pero la
     * cubeta por CUENTA es la que de verdad protege a una persona concreta: sin
     * ella, repartir los intentos entre cincuenta direcciones —cosa trivial— deja
     * el freno por IP en nada mientras se martillea una sola cuenta.
     *
     * ── Y por qué solo cuentan los fallos ───────────────────────────────────
     *
     * Se comprueba ANTES de validar y se consume solo DESPUÉS de fallar. Si cada
     * entrada gastara cupo, una operadora que entra y sale varias veces en la
     * mañana —cosa que pasa— se quedaría fuera de su propio turno sin haberse
     * equivocado ni una vez.
     */
    private const MAX_FALLOS_IP = 10;

    private const MAX_FALLOS_CUENTA = 5;

    /** Quince minutos. Larga para molestar a un robot, corta para una persona. */
    private const VENTANA_FALLOS = 900;
    /**
     * Corta si esta IP o esta cuenta ya agotó sus intentos.
     *
     * Mira sin consumir. El mensaje no dice si el correo existe —sería el mismo
     * para uno inventado— y sí dice qué hacer: una persona que de verdad olvidó
     * su contraseña tiene que saber que espera quince minutos, no quedarse
     * mirando un error que no explica nada.
     */
    private function exigirCupo(string $accion, string $sujeto, int $maximo): void
    {
        if (Limite::usos($accion, $sujeto, self::VENTANA_FALLOS) < $maximo) {
            return;
        }

        header('Retry-After: '.self::VENTANA_FALLOS);

        throw new HttpError(
            'Demasiados intentos fallidos. Espere quince minutos y vuelva a intentarlo. '
            .'Si olvidó su contraseña, pídale a un administrador que se la restablezca.',
            429
        );
    }

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

        // Se MIRA el cupo, no se consume: quien acierta la contraseña no gasta
        // nada. La clave viaja ya con sal y hash dentro de `Limite`, así que el
        // correo no queda escrito en la tabla de límites.
        $this->exigirCupo('auth.login.ip', $req->ip(), self::MAX_FALLOS_IP);
        $this->exigirCupo('auth.login.cuenta', $email, self::MAX_FALLOS_CUENTA);

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
            // Se consume en las dos cubetas, exista el correo o no. Consumir solo
            // cuando el usuario existe convertiría el propio freno en un delator:
            // bastaría contar intentos hasta el bloqueo para saber qué correos
            // están registrados.
            Limite::consumir('auth.login.ip', $req->ip(), self::MAX_FALLOS_IP, self::VENTANA_FALLOS);
            Limite::consumir('auth.login.cuenta', $email, self::MAX_FALLOS_CUENTA, self::VENTANA_FALLOS);

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
