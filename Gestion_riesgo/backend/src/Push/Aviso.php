<?php

declare(strict_types=1);

namespace App\Push;

use App\Core\Auth;
use App\Core\Db;

/**
 * Golpear la puerta del aparato de un funcionario.
 *
 * ── Qué se manda ─────────────────────────────────────────────────────────────
 *
 * Nada. El aviso va literalmente vacío.
 *
 * No es una limitación: es la decisión. Una notificación con contenido pasa por
 * los servidores de Google o de Mozilla, y aunque la norma la cifra de punta a
 * punta, el nombre de una familia damnificada no tiene por qué salir de la
 * Alcaldía para que a alguien le suene el teléfono. Lo que se manda es «hay
 * algo nuevo»; el dato se lee dentro del sistema, con sesión iniciada, como
 * todo lo demás.
 *
 * Y de paso se ahorra el cifrado de la norma, que es la parte que en PHP sin
 * Composer costaría cuatrocientas líneas imposibles de revisar.
 *
 * ── Por qué no rompe nada si falla ───────────────────────────────────────────
 *
 * Esto se llama justo después de que una familia mande su solicitud. Si el
 * servicio de push está caído, o tarda, o el token no le gusta, lo último que
 * puede pasar es que la solicitud se pierda. Cada envío tiene dos segundos y
 * cualquier fallo se traga: la notificación es una comodidad, la solicitud es
 * el trámite.
 */
final class Aviso
{
    /** Lo que se espera por aparato. Corto: el ciudadano está esperando su radicado. */
    private const ESPERA_SEGUNDOS = 2;

    /** Cuánto guarda el servicio el aviso si el aparato está apagado. */
    private const TTL = 6 * 3600;

    /**
     * Avisar a todos los aparatos de quien pueda atender esto.
     *
     * @param  list<string>  $roles  qué roles reciben el aviso
     * @return int  a cuántos aparatos se logró avisar
     */
    public static function aQuienesPuedan(array $roles = Auth::ESCRITURA): int
    {
        if ($roles === []) {
            return 0;
        }

        // Mientras falte la migración no hay a quién avisar ni con qué firmar.
        // Se calla: esto corre después de que una familia mande su solicitud y
        // no hay nadie mirando esta petición.
        if (! Vapid::disponible()) {
            return 0;
        }

        // Los roles no vienen de fuera nunca —son constantes de `Auth`— pero se
        // arman como marcadores igual: el día que alguien los pase desde una
        // petición, esta consulta no puede ser el agujero.
        $marcas = [];
        $params = [];

        foreach (array_values($roles) as $i => $rol) {
            $marcas[] = ':rol'.$i;
            $params['rol'.$i] = $rol;
        }

        $filas = Db::all(
            'SELECT s.id, s.endpoint
               FROM push_suscripciones s
               JOIN usuarios u ON u.id = s.usuario_id
              WHERE u.activo = 1 AND u.rol IN ('.implode(', ', $marcas).')',
            $params
        );

        $enviados = 0;

        foreach ($filas as $f) {
            if (self::aUno((int) $f['id'], (string) $f['endpoint'])) {
                $enviados++;
            }
        }

        return $enviados;
    }

    /**
     * Avisar solo a los aparatos de una persona.
     *
     * Es para que alguien pueda comprobar que esto llega de verdad sin esperar
     * a que una familia mande una solicitud — y sin molestar a los demás para
     * averiguarlo. Un interruptor que dice «activado» y no se puede probar es
     * un interruptor en el que nadie confía.
     *
     * @return int  a cuántos aparatos suyos se logró avisar
     */
    public static function aUsuario(int $usuarioId): int
    {
        if (! Vapid::disponible()) {
            return 0;
        }

        $filas = Db::all(
            'SELECT id, endpoint FROM push_suscripciones WHERE usuario_id = :usuario',
            ['usuario' => $usuarioId]
        );

        $enviados = 0;

        foreach ($filas as $f) {
            if (self::aUno((int) $f['id'], (string) $f['endpoint'])) {
                $enviados++;
            }
        }

        return $enviados;
    }

    /** Un aparato. `true` si el servicio de push lo aceptó. */
    private static function aUno(int $id, string $endpoint): bool
    {
        $partes = parse_url($endpoint);

        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])) {
            self::anotarError($id, 'dirección ilegible');

            return false;
        }

        $origen = $partes['scheme'].'://'.$partes['host'];

        try {
            $autorizacion = Vapid::autorizacion($origen);
        } catch (\Throwable $e) {
            self::anotarError($id, 'no se pudo firmar');

            return false;
        }

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::ESPERA_SEGUNDOS,
            CURLOPT_HTTPHEADER => [
                'Authorization: '.$autorizacion,
                'TTL: '.self::TTL,
                // Sin cuerpo hay que decirlo explícitamente: algunos servicios
                // rechazan un POST sin `Content-Length`.
                'Content-Length: 0',
                // Urgencia normal. `high` gasta batería y despierta el aparato
                // aunque esté ahorrando; esto puede esperar unos minutos.
                'Urgency: normal',
            ],
        ]);

        curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fallo = curl_error($ch);
        curl_close($ch);

        // 404 y 410: el navegador tiró esa suscripción —desinstalaron la
        // aplicación, borraron los datos del sitio—. Se borra aquí también.
        // Dejarla sería reintentar para siempre contra una puerta tapiada.
        if ($codigo === 404 || $codigo === 410) {
            Db::exec('DELETE FROM push_suscripciones WHERE id = :id', ['id' => $id]);

            return false;
        }

        if ($codigo >= 200 && $codigo < 300) {
            Db::exec(
                "UPDATE push_suscripciones
                    SET ultimo_envio = NOW(), ultimo_error = ''
                  WHERE id = :id",
                ['id' => $id]
            );

            return true;
        }

        self::anotarError($id, $fallo !== '' ? 'red: '.$fallo : 'respondió '.$codigo);

        return false;
    }

    /**
     * Dejar constancia de por qué no salió.
     *
     * Un aviso que no llega y no deja rastro es peor que no tener avisos: quien
     * lo espera cree que no hay solicitudes nuevas.
     */
    private static function anotarError(int $id, string $motivo): void
    {
        Db::exec(
            'UPDATE push_suscripciones SET ultimo_envio = NOW(), ultimo_error = :e WHERE id = :id',
            ['e' => mb_substr($motivo, 0, 255), 'id' => $id]
        );
    }
}
