<?php

declare(strict_types=1);

namespace App\Push;

use App\Core\Db;

/**
 * Las claves con las que este servidor firma sus avisos.
 *
 * ── Qué es VAPID ─────────────────────────────────────────────────────────────
 *
 * Un navegador que acepta notificaciones entrega una dirección de envío. Esa
 * dirección es un secreto a medias: quien la consiga puede mandarle avisos a
 * esa persona. VAPID lo cierra — cada aviso va firmado con una clave privada
 * que solo tiene este servidor, y el navegador comprueba la firma contra la
 * clave pública con la que se suscribió.
 *
 * Sin esto, el día que una dirección se escape de un registro, alguien podría
 * mandarle notificaciones a un funcionario de la Alcaldía en nombre del
 * sistema. Con esto, no.
 *
 * ── Por qué en la base y no en config.php ────────────────────────────────────
 *
 * Porque este sistema se despliega sin consola, por la API de cPanel. Pedirle a
 * alguien que edite a mano un archivo de configuración en producción es pedirle
 * que rompa el sistema una de cada cinco veces, y una llave mal pegada aquí
 * deja los avisos mudos sin que nada lo diga.
 *
 * Se generan solas la primera vez. Y NO se regeneran nunca: cambiar la clave
 * pública invalida todas las suscripciones existentes, y habría que pedirle
 * permiso otra vez a cada funcionario, uno por uno.
 */
final class Vapid
{
    /** El asunto del token: a quién acudir si un servicio de push tiene queja. */
    public const SUJETO = 'mailto:gestionriesgo@jamundi.gov.co';

    /** Cuánto vale un token firmado. El máximo que admite la norma es 24 h. */
    private const VIGENCIA = 12 * 3600;

    /**
     * ¿Está ya montado esto?
     *
     * El código se despliega con el script; la migración la corre una persona
     * desde Administración, y entre las dos cosas pasan minutos o días. En ese
     * hueco, sin esta comprobación, pedir la clave devuelve un 500 —que es
     * mentira: no hay ningún error, falta un paso— y llena el registro de
     * errores de algo que no lo es. Un 500 falso es la forma más rápida de que
     * nadie mire el registro cuando haya uno de verdad.
     */
    public static function disponible(): bool
    {
        $fila = Db::first(
            "SELECT COUNT(*) AS n FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_claves'"
        );

        return (int) ($fila['n'] ?? 0) > 0;
    }

    /**
     * La clave pública, en el formato que espera el navegador.
     *
     * Es lo único de aquí que sale al navegador, y puede ser pública sin
     * riesgo: sirve para comprobar firmas, no para hacerlas.
     */
    public static function publica(): string
    {
        return self::par()['publica'];
    }

    /**
     * La cabecera `Authorization` para mandar un aviso a este destino.
     *
     * @param  string  $origen  el esquema y el host del servicio de push, que es
     *                          para quien se firma el token. Un token firmado
     *                          para Google no vale en Mozilla, a propósito: así
     *                          uno interceptado no sirve en otra parte.
     */
    public static function autorizacion(string $origen): string
    {
        $par = self::par();

        $cabecera = self::base64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $cuerpo = self::base64url((string) json_encode([
            'aud' => $origen,
            'exp' => time() + self::VIGENCIA,
            'sub' => self::SUJETO,
        ]));

        $firma = self::firmar($cabecera.'.'.$cuerpo, $par['privada_pem']);

        return 'vapid t='.$cabecera.'.'.$cuerpo.'.'.$firma.', k='.$par['publica'];
    }

    /**
     * Firma ES256 en el formato que pide la norma.
     *
     * ── El detalle que cuesta una tarde ──────────────────────────────────────
     *
     * `openssl_sign` devuelve la firma en DER, que es una estructura con
     * etiquetas y longitudes. La norma de los tokens web la quiere en crudo:
     * los dos números R y S pegados, de 32 bytes cada uno.
     *
     * Si se manda el DER tal cual, el servicio de push responde 401 sin decir
     * por qué, y desde fuera parece que la clave está mal.
     */
    private static function firmar(string $texto, string $privadaPem): string
    {
        $der = '';

        if (! openssl_sign($texto, $der, $privadaPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No se pudo firmar el aviso.');
        }

        return self::base64url(self::derACrudo($der));
    }

    /** DER (SEQUENCE de dos INTEGER) → R||S de 64 bytes. */
    public static function derACrudo(string $der): string
    {
        // 0x30 = SEQUENCE. El byte siguiente es la longitud del contenido.
        $pos = 2;

        // Longitud larga: 0x81 dice que la longitud ocupa un byte más.
        if (isset($der[1]) && ord($der[1]) > 0x80) {
            $pos = 3;
        }

        $numeros = [];

        for ($i = 0; $i < 2; $i++) {
            // 0x02 = INTEGER, y detrás su longitud.
            $largo = ord($der[$pos + 1]);
            $valor = substr($der, $pos + 2, $largo);
            $pos += 2 + $largo;

            // DER mete un 0x00 delante cuando el primer bit está encendido,
            // para que el número no se lea como negativo. Ahí sobra.
            $valor = ltrim($valor, "\x00");

            // Y a 32 bytes con ceros por delante: un número que empiece por
            // cero ocupa menos, y la norma los quiere del mismo largo siempre.
            $numeros[] = str_pad($valor, 32, "\x00", STR_PAD_LEFT);
        }

        return $numeros[0].$numeros[1];
    }

    /**
     * Base64 de la web: sin relleno y con `-_` en vez de `+/`.
     *
     * El base64 corriente lleva `+`, `/` y `=`, que en una cabecera HTTP y en
     * una URL significan otras cosas.
     */
    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * El par de claves, generándolo la primera vez.
     *
     * @return array{publica:string,privada_pem:string}
     */
    private static function par(): array
    {
        $guardado = self::leer();

        if ($guardado !== null) {
            return $guardado;
        }

        $recurso = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($recurso === false) {
            throw new \RuntimeException('Este servidor no puede generar claves EC (falta OpenSSL con prime256v1).');
        }

        $privadaPem = '';
        openssl_pkey_export($recurso, $privadaPem);

        $detalles = openssl_pkey_get_details($recurso);
        $ec = $detalles['ec'] ?? null;

        if (! is_array($ec) || ! isset($ec['x'], $ec['y'])) {
            throw new \RuntimeException('La clave generada no trae sus coordenadas.');
        }

        // Punto sin comprimir: 0x04 y detrás X e Y, de 32 bytes cada uno.
        $publica = self::base64url(
            "\x04"
            .str_pad((string) $ec['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad((string) $ec['y'], 32, "\x00", STR_PAD_LEFT)
        );

        // `INSERT IGNORE`: dos peticiones a la vez la primera mañana generarían
        // dos pares, y el segundo dejaría muda a la gente suscrita con el
        // primero. Gana quien llegue antes, y el otro relee.
        Db::exec(
            'INSERT IGNORE INTO push_claves (clave, valor) VALUES (:c1, :v1), (:c2, :v2)',
            ['c1' => 'publica', 'v1' => $publica, 'c2' => 'privada_pem', 'v2' => $privadaPem]
        );

        return self::leer() ?? ['publica' => $publica, 'privada_pem' => $privadaPem];
    }

    /** @return array{publica:string,privada_pem:string}|null */
    private static function leer(): ?array
    {
        $filas = Db::all('SELECT clave, valor FROM push_claves');
        $por = [];

        foreach ($filas as $f) {
            $por[(string) $f['clave']] = (string) $f['valor'];
        }

        if (($por['publica'] ?? '') === '' || ($por['privada_pem'] ?? '') === '') {
            return null;
        }

        return ['publica' => $por['publica'], 'privada_pem' => $por['privada_pem']];
    }
}
