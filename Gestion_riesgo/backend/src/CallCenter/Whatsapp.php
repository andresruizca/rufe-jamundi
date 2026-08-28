<?php

declare(strict_types=1);

namespace App\CallCenter;

use App\Core\Config;

/**
 * Envío de WhatsApp por Zavu, para el botón del Call Center.
 *
 * Aparte del controlador y con la llamada de red aislada en un solo método,
 * por lo mismo que `planDeLimites()` se separó en pre-inscripción: aquí se
 * decide a qué número se escribe y con qué texto, y eso hay que poder probarlo
 * sin red y sin MySQL, que es lo que las pruebas de este proyecto no montan.
 *
 * ── Por qué plantilla y no texto libre ───────────────────────────────────────
 *
 * WhatsApp no deja escribirle libremente a quien no te ha escrito antes. Solo
 * se puede dentro de las 24 horas siguientes a un mensaje suyo; fuera de esa
 * ventana hace falta una plantilla aprobada por Meta y la personalización se
 * limita a sus variables.
 *
 * Casi ningún hogar del censo habrá escrito primero, así que este botón manda
 * SIEMPRE la plantilla. Intentar texto libre fallaría en casi todos los casos y
 * el error de Meta no dice claramente por qué.
 */
final class Whatsapp
{
    /** Prefijo de Colombia para móviles de diez dígitos. */
    private const INDICATIVO_CO = '57';

    /**
     * ¿Está configurado el envío? Con el token vacío, no.
     *
     * Se comprueba antes de tocar la red y antes de escribir nada: mientras
     * nadie ponga un token en config.php, el sistema se comporta como si este
     * archivo no existiera.
     */
    public static function configurado(): bool
    {
        return trim((string) Config::get('zavu.api_token', '')) !== ''
            && trim((string) Config::get('zavu.plantilla_id', '')) !== '';
    }

    /**
     * Teléfono a formato E.164, o null si no es utilizable.
     *
     * Un móvil colombiano de diez dígitos que empieza por 3 lleva el 57
     * delante. Un número que ya trae indicativo se respeta tal cual: añadirle
     * otro lo convierte en un número inventado, y el mensaje se va a un
     * desconocido — que en un censo de damnificados significa entregarle a un
     * tercero el dato de que alguien reportó daños.
     *
     * Los fijos se rechazan: WhatsApp es de móviles, y mandar la plantilla a un
     * fijo la cobra igual y no llega a nadie.
     */
    public static function normalizarTelefono(?string $crudo): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $crudo) ?? '';

        if ($d === '') {
            return null;
        }

        // Con indicativo ya puesto: 57 + diez dígitos que empiezan por 3.
        if (strlen($d) === 12 && str_starts_with($d, self::INDICATIVO_CO.'3')) {
            return '+'.$d;
        }

        // Móvil nacional de diez dígitos.
        if (strlen($d) === 10 && str_starts_with($d, '3')) {
            return '+'.self::INDICATIVO_CO.$d;
        }

        // Otro indicativo internacional. Se acepta con reservas: el censo es de
        // Jamundí, pero un familiar en el exterior puede ser el contacto.
        if (strlen($d) >= 11 && strlen($d) <= 15 && ! str_starts_with($d, self::INDICATIVO_CO)) {
            return '+'.$d;
        }

        return null;
    }

    /**
     * El nombre que se le pone a la plantilla.
     *
     * Solo el primer nombre y el primer apellido: «MARIA FERNANDA DE LOS
     * SANTOS PEREZ GOMEZ» en un saludo suena a base de datos, no a alguien
     * escribiéndole. Y en mayúsculas sostenidas, que es como suele venir del
     * censo, suena a grito.
     *
     * Si no hay nombre utilizable devuelve un tratamiento neutro en vez de
     * dejar el hueco vacío: Meta rechaza una variable vacía, y «Hola, .» es
     * peor que no personalizar.
     */
    public static function nombreParaSaludo(?string $nombres, ?string $apellidos): string
    {
        $limpiar = static function (?string $s): string {
            $s = trim(preg_replace('/\s+/', ' ', (string) $s) ?? '');

            return $s === '' ? '' : (explode(' ', $s)[0]);
        };

        $nombre = $limpiar($nombres);
        $apellido = $limpiar($apellidos);
        $completo = trim($nombre.' '.$apellido);

        if ($completo === '') {
            return 'ciudadano';
        }

        // «PEREZ» → «Perez». mb_convert_case respeta los acentos.
        return mb_convert_case($completo, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * El cuerpo exacto que se le manda a Zavu.
     *
     * Separado del envío para poder comprobarlo en las pruebas sin red. Es
     * donde vive el detalle que más caro sale olvidar.
     *
     * @return array<string,mixed>
     */
    public static function cuerpoDelMensaje(string $telefono, string $nombre, int $reporteId): array
    {
        return [
            'to' => $telefono,
            // OBLIGATORIO. Sin `channel`, un mensaje de texto se va por SMS en
            // vez de por WhatsApp, y no hay ningún error que lo delate: solo un
            // SMS cobrado que nadie esperaba.
            'channel' => 'whatsapp',
            'messageType' => 'template',
            // El doble clic es el fallo más probable de un botón que tarda dos
            // segundos. Con la fecha dentro, dos pulsaciones el mismo día son
            // un solo mensaje; mañana, si hace falta insistir, se puede.
            'idempotencyKey' => 'rufe-'.$reporteId.'-'.gmdate('Ymd'),
            'content' => [
                'templateId' => (string) Config::get('zavu.plantilla_id', ''),
                'templateVariables' => ['1' => $nombre],
            ],
        ];
    }

    /**
     * Manda el mensaje. Nunca lanza: devuelve el resultado para que el
     * llamador lo registre, salga bien o mal.
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array{ok:bool, id:?string, error:?string}
     */
    public static function enviar(array $cuerpo): array
    {
        $base = rtrim((string) Config::get('zavu.base_url', 'https://api.zavu.dev/v1'), '/');
        $token = (string) Config::get('zavu.api_token', '');
        $sender = (string) Config::get('zavu.sender_id', '');

        $ch = curl_init($base.'/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) Config::get('zavu.timeout', 15),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Zavu-Sender: '.$sender,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
        ]);

        $respuesta = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            return ['ok' => false, 'id' => null, 'error' => 'No se pudo conectar con el proveedor'.($fallo !== '' ? ': '.$fallo : '')];
        }

        $datos = json_decode((string) $respuesta, true);
        $datos = is_array($datos) ? $datos : [];

        if ($estado >= 200 && $estado < 300) {
            $msg = $datos['message'] ?? [];

            return ['ok' => true, 'id' => isset($msg['id']) ? (string) $msg['id'] : null, 'error' => null];
        }

        // El mensaje del proveedor se muestra a la operadora: si el número no
        // tiene WhatsApp o la plantilla no está aprobada, tiene que poder
        // saberlo sin llamar a soporte.
        $motivo = $datos['message'] ?? $datos['error'] ?? ('HTTP '.$estado);

        return ['ok' => false, 'id' => null, 'error' => mb_substr(is_string($motivo) ? $motivo : json_encode($motivo), 0, 300)];
    }
}
