<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Push\Vapid;

/**
 * Quién quiere que le avisen, y en qué aparato.
 *
 * ── Qué se guarda de una persona aquí ────────────────────────────────────────
 *
 * Una dirección de envío que da su navegador, dos claves suyas y el nombre del
 * navegador. Nada más: ni su ubicación, ni qué mira, ni cuándo entra.
 *
 * La dirección es un secreto a medias —quien la tenga podría mandarle avisos—,
 * y por eso solo se acepta con sesión iniciada, cada uno solo puede borrar las
 * suyas, y ninguna respuesta de aquí devuelve la dirección completa.
 */
final class PushController
{
    /**
     * La clave pública del servidor.
     *
     * Sin sesión a propósito: el navegador la necesita ANTES de suscribirse, y
     * es pública por definición —sirve para comprobar firmas, no para hacerlas—.
     * Además, así el service worker puede pedirla sin token.
     */
    public function clavePublica(Request $req): void
    {
        self::exigirMontado();

        Response::ok(['clave' => Vapid::publica()]);
    }

    /**
     * Los avisos necesitan sus tablas, y esas las crea una migración.
     *
     * El código llega con el despliegue y la migración la corre una persona:
     * entre las dos cosas hay un hueco. Decirlo con un 503 y una frase es
     * infinitamente mejor que el 500 que salía antes, que no era verdad —no
     * había ningún error, faltaba un paso— y que enterraba el registro de
     * errores en ruido.
     */
    private static function exigirMontado(): void
    {
        if (! Vapid::disponible()) {
            throw new HttpError(
                'Los avisos todavía no están habilitados en este servidor. '
                    .'Falta correr la actualización desde Administración.',
                503
            );
        }
    }

    /**
     * Registrar este aparato.
     *
     * `ON DUPLICATE KEY`: el mismo navegador se re-suscribe solo de vez en
     * cuando —el servicio de push rota las direcciones—, y sin esto cada
     * rotación dejaría una fila muerta que se reintentaría para siempre.
     */
    public function suscribir(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        self::exigirMontado();

        $endpoint = trim((string) $req->texto('endpoint'));

        if ($endpoint === '' || ! str_starts_with($endpoint, 'https://')) {
            throw new HttpError('La dirección del aparato no es válida.', 422);
        }

        if (mb_strlen($endpoint) > 512) {
            throw new HttpError('La dirección del aparato es demasiado larga.', 422);
        }

        Db::exec(
            'INSERT INTO push_suscripciones
                    (usuario_id, endpoint, endpoint_hash, p256dh, auth, agente)
             VALUES (:usuario, :endpoint, :hash, :p256dh, :auth, :agente)
             ON DUPLICATE KEY UPDATE
                    usuario_id = VALUES(usuario_id),
                    endpoint = VALUES(endpoint),
                    p256dh = VALUES(p256dh),
                    auth = VALUES(auth),
                    agente = VALUES(agente),
                    ultimo_error = \'\'',
            [
                'usuario' => $usuario['id'],
                'endpoint' => $endpoint,
                'hash' => hash('sha256', $endpoint),
                'p256dh' => mb_substr((string) $req->texto('p256dh'), 0, 255),
                'auth' => mb_substr((string) $req->texto('auth'), 0, 255),
                // Para que un administrador pueda decirle a alguien «el aviso
                // está llegando a su computador, no a su teléfono».
                'agente' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );

        Response::ok(['suscrito' => true]);
    }

    /**
     * Dejar de recibir avisos en este aparato.
     *
     * `usuario_id` en el WHERE aunque la dirección sea única: sin él, quien
     * consiguiera la dirección de otra persona podría desactivarle los avisos.
     */
    public function desuscribir(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        self::exigirMontado();
        $endpoint = trim((string) $req->texto('endpoint'));

        if ($endpoint === '') {
            throw new HttpError('Falta la dirección del aparato.', 422);
        }

        Db::exec(
            'DELETE FROM push_suscripciones
              WHERE endpoint_hash = :hash AND usuario_id = :usuario',
            ['hash' => hash('sha256', $endpoint), 'usuario' => $usuario['id']]
        );

        Response::ok(['suscrito' => false]);
    }

    /**
     * En cuántos aparatos tiene avisos esta persona.
     *
     * Sirve para que la pantalla no prometa algo que no es: un navegador puede
     * decir «permiso concedido» y no tener suscripción —se borró la del
     * servidor, se restauró un respaldo—, y entonces el interruptor estaría
     * encendido sin que llegue nada.
     */
    public function mios(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        self::exigirMontado();

        $fila = Db::first(
            'SELECT COUNT(*) AS n FROM push_suscripciones WHERE usuario_id = :usuario',
            ['usuario' => $usuario['id']]
        );

        Response::ok(['aparatos' => (int) ($fila['n'] ?? 0)]);
    }
}
