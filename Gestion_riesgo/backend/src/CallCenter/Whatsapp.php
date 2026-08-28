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
     * Cuánto esperar antes de confirmar que el mensaje no fue rechazado.
     *
     * Medido contra el proveedor: un rechazo de plantilla pasa de `queued` a
     * `failed` en unos 400 ms. Segundo y medio da margen de sobra y la
     * operadora ni lo nota, porque ya está esperando la respuesta del botón.
     */
    private const ESPERA_CONFIRMACION_MS = 1500;

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
            // segundos. La clave lleva el minuto, no el día: dos pulsaciones
            // seguidas son un solo mensaje, pero un reintento un minuto después
            // sale de verdad.
            //
            // Con granularidad de día el proveedor devolvía SIEMPRE el envío
            // anterior, así que un fallo quedaba congelado: aunque se arreglara
            // la causa —una plantilla que Meta acababa de aprobar—, el botón
            // seguía devolviendo el mismo error hasta el día siguiente.
            'idempotencyKey' => 'rufe-'.$reporteId.'-'.gmdate('YmdHi'),
            'content' => [
                'templateId' => (string) Config::get('zavu.plantilla_id', ''),
                'templateVariables' => ['1' => $nombre],
            ],
        ];
    }

    /**
     * Qué significa la respuesta del proveedor.
     *
     * Pura y aparte del envío para poder comprobarla sin red, porque aquí vive
     * el detalle que casi cuesta caro: **un 2xx no significa que el mensaje
     * saliera**. Zavu acepta con 202 y `status: queued`, y si Meta lo rechaza
     * —plantilla sin aprobar, número sin WhatsApp— lo marca `failed` menos de
     * un segundo después.
     *
     * Dar por bueno el 202 haría que el hogar quedara registrado como
     * contactado sin que le llegara nada, y la operadora no volvería a
     * llamarlo. Por eso lo que manda es `status`, no el código HTTP.
     *
     * @param  array<string,mixed>  $datos
     * @return array{ok:bool, id:?string, estado:?string, error:?string}
     */
    public static function interpretarRespuesta(int $estado, array $datos): array
    {
        $m = is_array($datos['message'] ?? null) ? $datos['message'] : [];
        $id = isset($m['id']) ? (string) $m['id'] : null;
        $situacion = isset($m['status']) ? (string) $m['status'] : null;

        // `failed` manda por encima del código HTTP. Repetir el envío con la
        // misma clave de idempotencia devuelve el mensaje fallido anterior, y
        // puede venir con un 2xx: sin esta comprobación, el segundo intento
        // diría «enviado» y el primero decía «falló», por el mismo mensaje.
        if ($situacion === 'failed') {
            return ['ok' => false, 'id' => $id, 'estado' => $situacion, 'error' => self::motivoDelFallo($m, $datos, $estado)];
        }

        if ($estado < 200 || $estado >= 300) {
            return ['ok' => false, 'id' => $id, 'estado' => $situacion, 'error' => self::motivoDelFallo($m, $datos, $estado)];
        }

        return ['ok' => true, 'id' => $id, 'estado' => $situacion, 'error' => null];
    }

    /**
     * El motivo del fallo, en una frase que se le pueda enseñar a la operadora.
     *
     * NUNCA el JSON crudo. Volcar el objeto entero llena la pantalla de comillas
     * y llaves, se corta a la mitad y no dice qué pasó — que es exactamente lo
     * que la operadora necesita saber mientras tiene a alguien esperando.
     *
     * @param  array<string,mixed>  $m
     * @param  array<string,mixed>  $datos
     */
    private static function motivoDelFallo(array $m, array $datos, int $estado): string
    {
        foreach ([$m['errorMessage'] ?? null, $datos['message'] ?? null, $datos['error'] ?? null] as $c) {
            if (is_string($c) && trim($c) !== '') {
                return mb_substr(trim($c), 0, 300);
            }
        }

        $codigo = $m['errorCode'] ?? null;

        return is_string($codigo) && $codigo !== ''
            ? 'El proveedor rechazó el envío ('.$codigo.').'
            : 'El proveedor rechazó el envío (HTTP '.$estado.').';
    }

    /**
     * Manda el mensaje. Nunca lanza: devuelve el resultado para que el
     * llamador lo registre, salga bien o mal.
     *
     * Tras encolarlo espera un momento y vuelve a preguntar por él. Parece de
     * más y no lo es: los rechazos que importan —plantilla sin aprobar, número
     * sin WhatsApp— llegan en menos de un segundo, y sin esta segunda consulta
     * se registrarían como envíos correctos.
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array{ok:bool, id:?string, estado:?string, error:?string}
     */
    public static function enviar(array $cuerpo): array
    {
        [$estado, $datos, $fallo] = self::pedir('POST', '/messages', $cuerpo);

        if ($fallo !== null) {
            return ['ok' => false, 'id' => null, 'estado' => null, 'error' => $fallo];
        }

        $r = self::interpretarRespuesta($estado, $datos);

        // Ya se sabe que falló, o no hay id que consultar.
        if (! $r['ok'] || $r['id'] === null || $r['estado'] === 'delivered' || $r['estado'] === 'sent') {
            return $r;
        }

        usleep((int) (self::ESPERA_CONFIRMACION_MS * 1000));
        [$e2, $d2, $f2] = self::pedir('GET', '/messages/'.rawurlencode($r['id']), null);

        // Si la segunda consulta no sale, se conserva el resultado de la
        // primera: el mensaje se encoló de verdad y no hay motivo para decir
        // que falló solo porque no se pudo confirmar.
        if ($f2 !== null || $e2 < 200 || $e2 >= 300) {
            return $r;
        }

        return self::interpretarRespuesta($e2, $d2);
    }

    /**
     * Una llamada a la API. Devuelve [código, cuerpo, fallo de red o null].
     *
     * @param  array<string,mixed>|null  $cuerpo
     * @return array{0:int, 1:array<string,mixed>, 2:?string}
     */
    private static function pedir(string $metodo, string $ruta, ?array $cuerpo): array
    {
        $base = rtrim((string) Config::get('zavu.base_url', 'https://api.zavu.dev/v1'), '/');

        $ch = curl_init($base.$ruta);
        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) Config::get('zavu.timeout', 15),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.(string) Config::get('zavu.api_token', ''),
                'Zavu-Sender: '.(string) Config::get('zavu.sender_id', ''),
                'Content-Type: application/json',
            ],
        ];

        if ($metodo === 'POST') {
            $opciones[CURLOPT_POST] = true;
            $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorRed = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            return [0, [], 'No se pudo conectar con el proveedor'.($errorRed !== '' ? ': '.$errorRed : '')];
        }

        $datos = json_decode((string) $respuesta, true);

        return [$estado, is_array($datos) ? $datos : [], null];
    }
}
