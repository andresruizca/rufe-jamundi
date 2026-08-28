<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Limite;
use App\Core\Reintento;
use App\Core\Request;
use App\Core\Response;
use App\Preinscripcion\Censo;
use App\Preinscripcion\Radicado;
use App\Preinscripcion\Senales;
use App\Preinscripcion\Validador;
use App\Preinscripcion\Videos;
use App\Rufe\Archivos;
use App\Rufe\Catalogos as Rufe;
use Throwable;

/**
 * Pre-inscripción ciudadana para la inspección de viviendas afectadas.
 *
 * `crear()` y `catalogos()` son las ÚNICAS rutas de escritura y lectura de este
 * sistema que no exigen sesión, aparte del login. Eso las convierte en la
 * superficie más expuesta que existe aquí, y por eso llevan encima todo lo que
 * el censo aplica a un funcionario y algo más:
 *
 *  • Límite de tasa por IP —no por usuario, que aquí no lo hay—.
 *  • Trampa antirrobot: el campo `sitio_web` está oculto por CSS y una persona
 *    nunca lo ve. Se responde como si todo hubiera ido bien, con un radicado
 *    que no existe, para no enseñarle al autor del robot qué lo delató.
 *  • Idempotencia por `envio_id`: reintentar sin señal no duplica el hogar.
 *  • Autorización de datos obligatoria, con la versión del aviso guardada.
 *
 * Y una ausencia deliberada: NO hay ninguna ruta pública que devuelva
 * pre-inscripciones. Consultar por radicado sería un buscador de damnificados
 * para cualquiera que probara combinaciones.
 *
 * La única grieta en esa regla es `verificar()`, que responde sí o no sobre una
 * cédula porque el flujo lo exige: la pre-inscripción es la continuación del
 * censo y no un formulario abierto. Está acotada hasta donde se puede sin
 * romperla — solo un booleano, por POST, con doble límite de tasa— y el
 * razonamiento completo está en `Preinscripcion\Censo`.
 */
final class PreinscripcionController
{
    /** Solicitudes por IP y hora. Una familia manda una; un robot, miles. */
    /**
     * Cuántas se pueden borrar de una vez.
     *
     * No es una cifra caprichosa: cada borrado toca disco. Sin tope, un lote
     * grande borra archivos hasta que PHP agota su tiempo y la respuesta se
     * pierde a mitad — y quien está delante no sabe qué se borró.
     */
    private const MAX_BORRADO_LOTE = 50;

    /**
     * A partir de cuántos días una solicitud sin atender cuenta como demorada.
     *
     * Tres. No es un número redondo por gusto: quien manda la solicitud acaba
     * de perder parte de su casa, y a los tres días sin respuesta ya está
     * llamando al conmutador. El número existe para que el atasco se vea antes
     * de que llame.
     */
    private const DIAS_DEMORA = 3;

    private const MAX_ENVIOS_HORA = 5;

    /**
     * Cuántas cédulas se pueden consultar desde una conexión.
     *
     * Dos ventanas a propósito. La de la hora deja margen para el celular
     * compartido de una vereda —varias familias, un solo teléfono— y para quien
     * se equivoca al teclear. La del día es la que de verdad cierra la puerta a
     * recorrer cédulas: sin ella, un límite por hora se sortea esperando.
     */
    private const MAX_VERIFICACIONES_HORA = 15;

    private const MAX_VERIFICACIONES_DIA = 40;

    /**
     * Los mismos dos límites, pero contando por ciudadano y no por conexión.
     *
     * El canal de WhatsApp consulta esto desde UN solo servidor, así que por IP
     * los mil trescientos hogares comparten una única cubeta: en la consulta 16
     * de la hora el bot deja de funcionar para todo el mundo. La cubeta correcta
     * no es la conexión, es la persona que está escribiendo — y esa la sabe el
     * bot, no el servidor.
     *
     * Un poco más estrechos que los de IP a propósito: una IP puede ser el
     * celular compartido de una vereda; un número de WhatsApp es una persona.
     */
    private const MAX_VERIFICACIONES_ORIGEN_HORA = 10;

    private const MAX_VERIFICACIONES_ORIGEN_DIA = 30;

    /**
     * Techo global del canal, y la única defensa si el secreto se filtra.
     *
     * `X-RUFE-Origen` lo dice el propio bot. Quien robe el secreto puede
     * inventar un número distinto en cada petición y saltarse los dos límites de
     * arriba sin esfuerzo; esto es lo único que acota el daño.
     *
     * 300 por hora son cinco consultas por segundo sostenidas —de sobra para
     * tres agentes— y convierten enumerar decenas de miles de cédulas en un
     * trabajo de meses que además queda a la vista en `rufe_limite`. No subirlo
     * «por si acaso»: subirlo es acortar esos meses.
     */
    private const MAX_VERIFICACIONES_SERVICIO_HORA = 300;

    /**
     * Cuántas veces se puede consultar UNA MISMA cédula desde el canal firmado.
     *
     * Es la cubeta de repuesto. La plataforma del bot no le pasa al endpoint
     * ningún identificador del ciudadano —sus flujos no exponen el teléfono de
     * quien escribe—, así que cuando no llega ninguno no hay forma de contar
     * «por persona» y esto es lo único que queda por debajo del techo global.
     *
     * Frena el machaqueo sobre una cédula concreta, que es el caso real: el
     * vecino que quiere saber si el de al lado está en el censo. NO frena
     * recorrer cédulas distintas — para eso está el techo global, y por eso el
     * techo no es opcional.
     */
    private const MAX_VERIFICACIONES_BOT_CEDULA_DIA = 20;

    /**
     * La identidad con la que se cuenta el techo global.
     *
     * Constante y no la IP del bot: si el servidor del canal cambia de IP —o
     * hay dos— el techo tiene que seguir siendo uno solo.
     */
    private const IDENTIDAD_SERVICIO = 'bot';

    /**
     * A dónde llamar cuando la cédula no aparece.
     *
     * Escrito una sola vez: este número es lo ÚNICO que se lleva quien no puede
     * continuar, y una errata aquí deja a una familia damnificada sin salida.
     */
    private const LINEA_ATENCION = 'Comuníquese con la línea de atención de Gestión del Riesgo de Jamundí: PBX 602 519 0969, extensión 2070.';

    /** Tope amplio de peticiones, incluidos los reintentos que no crean nada. */
    private const MAX_INTENTOS_HORA = 60;

    private const MAX_CARGAS_HORA = 10;

    /** Once fotos por solicitud —diez del daño y la cédula—, con margen de reintento. */
    private const MAX_ARCHIVOS_HORA = 70;

    private const MAX_VIDEOS_HORA = 20;

    /**
     * Ocho videos de hasta veinticuatro trozos, con margen de reintento.
     *
     * Un video de dos minutos son 24 trozos de 1 MiB. Ocho videos son 192, y en
     * una conexión rural cada trozo puede necesitar dos o tres intentos. Con el
     * tope anterior —300— una familia con cinco daños marcados se quedaba sin
     * cupo a mitad de la tercera subida, y el formulario le decía que había
     * hecho demasiadas peticiones.
     */
    private const MAX_TROZOS_HORA = 900;

    // ── Público ──────────────────────────────────────────────────────────────

    /**
     * Lo que el formulario ciudadano necesita para dibujarse.
     *
     * Solo catálogos: corregimientos, límites de archivo y la versión vigente
     * del aviso de privacidad. Nada que identifique a nadie.
     */
    public function catalogos(Request $req): void
    {
        header('Cache-Control: public, max-age=3600');

        Response::ok([
            'corregimientos' => Rufe::CORREGIMIENTOS,
            'zonas'          => Validador::ZONAS,
            // Para el listado del hogar: sin ellos, el formulario no puede
            // dibujar «hijo(a)», «mujer» ni «tarjeta de identidad» y la persona
            // tendría que escribirlos a mano. No son datos de nadie: son las
            // mismas listas fijas que ve el funcionario en el censo.
            'parentescos'    => Rufe::PARENTESCOS,
            'generos'        => Rufe::GENEROS,
            'tipos_documento' => Rufe::TIPOS_DOCUMENTO,
            'parentesco_jefe' => Rufe::PARENTESCO_JEFE,
            // Las señales de daño que el ciudadano puede reconocer a ojo. Van
            // en el catálogo y no escritas en la pantalla para que el servidor
            // y el formulario no puedan discrepar sobre qué códigos existen.
            'senales'        => Senales::paraApi(),
            'aviso_version'  => Rufe::AVISO_VERSION,
            // Las categorías ACTIVAS que cuelgan de un daño, en su orden. El
            // formulario las cachea en el teléfono para que el checklist
            // funcione también sin señal, y solo le enseña a cada persona las
            // de los daños que marcó.
            //
            // `senal IS NOT NULL` deja fuera a las categorías del modelo
            // anterior, que se le pedían a todo el mundo por igual. No se
            // borran —pueden tener videos grabados detrás— pero ya no se le
            // piden a nadie.
            'categorias_video' => array_map(
                static fn (array $c): array => [
                    'id' => (int) $c['id'],
                    'nombre' => $c['nombre'],
                    'instruccion' => $c['instruccion'],
                    'senal' => $c['senal'],
                    'obligatoria' => (bool) $c['obligatoria'],
                    'segundos_min' => (int) $c['segundos_min'],
                    'segundos_max' => (int) $c['segundos_max'],
                ],
                Db::all('SELECT * FROM categorias_video
                          WHERE activa = 1 AND senal IS NOT NULL
                          ORDER BY orden ASC, id ASC')
            ),
            'video' => [
                'bytes_trozo' => Videos::BYTES_TROZO,
                'max_bytes'   => Videos::MAX_BYTES_VIDEO,
                'max_videos'  => Videos::MAX_VIDEOS_POR_CARGA,
            ],
            'limites'        => [
                'fotos_dano'       => Rufe::MAX_FOTOS_PREINSCRIPCION,
                'fotos_cedula'     => 1,
                'fotos_cedula_reverso' => 1,
                'bytes_archivo'    => Rufe::MAX_BYTES_ARCHIVO,
                'bytes_carga'      => Rufe::MAX_BYTES_CARGA,
                'objetivo_bytes_foto' => Rufe::OBJETIVO_BYTES_FOTO,
                'extensiones'      => array_keys(Rufe::EXTENSIONES),
            ],
        ]);
    }

    /**
     * ¿Esta cédula está en el censo? Es la primera pantalla del formulario.
     *
     * Responde `{habilitado: bool}` y nada más. Ver `Censo` para por qué eso es
     * todo lo que puede responder y qué se hace para que no sea un buscador de
     * damnificados.
     *
     * Devuelve 200 en los dos casos: que la cédula no esté no es un error de la
     * persona, y un 404 aquí solo serviría para que un robot distinguiera los
     * casos por el código en vez de por el cuerpo.
     */
    public function verificar(Request $req): void
    {
        // El formato se comprueba ANTES de gastar cubeta. Un «12ab» no llega a
        // preguntarle nada al censo, así que cobrárselo solo servía para que
        // quien se equivoca tecleando se quedara sin intentos. Enumerar sigue
        // costando lo mismo: para eso hay que mandar cédulas bien formadas.
        $documento = Censo::normalizar($req->texto('documento'));

        if (! Censo::pareceCedula($documento)) {
            throw HttpError::validacion([
                'documento' => 'Escriba su número de cédula, sin puntos ni espacios.',
            ]);
        }

        foreach (self::planDeLimites(
            $req->ip(),
            $req->cabecera('X-RUFE-Servicio'),
            $req->cabecera('X-RUFE-Origen'),
            (string) Config::get('rufe.servicio_secreto', ''),
            self::llegoPorHttps()
        ) as $l) {
            try {
                Limite::consumir($l['accion'], $l['identidad'], $l['maximo'], $l['ventana'], $l['mensaje']);
            } catch (HttpError $e) {
                // Que el canal entero toque su techo no es algo que el ciudadano
                // pueda resolver esperando: o hay tres veces más tráfico del
                // previsto, o alguien está usando el secreto para enumerar. Se
                // deja constancia sin un solo dato personal — ni el número de
                // quien escribía, ni la cédula que consultaba.
                if ($l['global']) {
                    error_log(
                        'SGR: el canal de servicio agotó su techo de '
                        .self::MAX_VERIFICACIONES_SERVICIO_HORA.' consultas por hora.'
                    );
                }

                throw $e;
            }
        }

        Response::ok([
            'habilitado' => Censo::estaInscrito($documento),
            'linea_atencion' => self::LINEA_ATENCION,
        ]);
    }

    /**
     * Los datos que el censo ya tiene del hogar de esta cédula.
     *
     * Solo se llega aquí **después de subir la foto de la cédula**, y eso es
     * toda la protección de esta ruta. La de arriba responde un booleano porque
     * es gratis preguntarle; esta enseña nombre, teléfono, dirección y quién
     * vive en la casa, así que preguntar tiene que costar algo.
     *
     * Lo que cuesta: subir una imagen por cada cédula que se pruebe, y que esa
     * imagen quede guardada en el servidor atada al intento. Una imagen se
     * falsifica —esto no es una verificación de identidad— pero convierte un
     * recorrido gratuito y silencioso del censo en uno caro y con rastro.
     *
     * La foto no se desperdicia: es la misma que el formulario pedía más
     * adelante, y viaja con la solicitud cuando se envía. Al ciudadano no se le
     * pide nada de más, solo antes.
     */
    public function datosCenso(Request $req): void
    {
        // Las mismas dos cubetas que la verificación, con las mismas claves: sin
        // esto, esta ruta sería la puerta de atrás del límite de la otra.
        foreach (self::planDeLimites(
            $req->ip(),
            $req->cabecera('X-RUFE-Servicio'),
            $req->cabecera('X-RUFE-Origen'),
            (string) Config::get('rufe.servicio_secreto', ''),
            self::llegoPorHttps()
        ) as $l) {
            Limite::consumir($l['accion'], $l['identidad'], $l['maximo'], $l['ventana'], $l['mensaje']);
        }

        $documento = Censo::normalizar($req->texto('documento'));

        if (! Censo::pareceCedula($documento)) {
            throw HttpError::validacion([
                'documento' => 'Escriba su número de cédula, sin puntos ni espacios.',
            ]);
        }

        $carga = Archivos::hashDeCarga((string) $req->texto('carga', ''));

        $foto = Db::first(
            'SELECT id FROM rufe_evidencias
              WHERE carga_hash = :c AND tipo = :t AND reporte_id IS NULL
              LIMIT 1',
            ['c' => $carga, 't' => 'PRE_CEDULA']
        );

        if ($foto === null) {
            throw HttpError::validacion([
                'foto' => 'Suba primero la foto de su cédula para que podamos mostrarle sus datos.',
            ]);
        }

        $hogar = Censo::hogarDe($documento);

        // Mismo cuerpo cuando no hay ficha que cuando la hay vacía: esta ruta no
        // debe servir para distinguir casos que la de arriba ya resolvió con un
        // booleano.
        Response::ok(['hogar' => $hogar]);
    }

    /**
     * Qué cubetas gasta esta consulta: las de la conexión, o las del ciudadano.
     *
     * Va aparte de `verificar()` y devuelve un plan en vez de ejecutarlo para
     * que se pueda comprobar sin base de datos. Aquí se decide quién queda
     * limitado y con qué números: es la clase de cosa que hay que poder probar
     * sin montar MySQL, y las pruebas de este proyecto no lo montan.
     *
     * ── Cuándo se usa la vía del servicio ────────────────────────────────────
     *
     * Las tres condiciones, todas obligatorias:
     *
     * 1. **Hay secreto configurado.** Con `servicio_secreto` vacío esta vía no
     *    existe: nadie la habilita por accidente, y el sistema se comporta
     *    exactamente como antes de que se escribiera este método.
     * 2. **El secreto coincide**, comparado con `hash_equals`. Un `===` corta en
     *    el primer byte distinto y deja adivinar el secreto midiendo tiempos.
     * 3. **Viene por HTTPS.** Un secreto compartido que viaja en claro deja de
     *    ser un secreto en el primer salto de red.
     *
     * ── Y qué pasa si no se cumplen ──────────────────────────────────────────
     *
     * Se cae al límite por IP, el de siempre. **Nunca un 401.** Un 401 le
     * confirmaría a quien está tanteando que acertó el nombre de la cabecera, y
     * eso es justo lo que no puede saber: sin esa confirmación, probar
     * cabeceras al azar es indistinguible de no probar nada.
     *
     * @return list<array{accion:string,identidad:string,maximo:int,ventana:int,mensaje:string,global:bool}>
     */
    public static function planDeLimites(
        string $ip,
        ?string $cabeceraSecreto,
        ?string $cabeceraOrigen,
        string $secretoConfigurado,
        bool $porHttps
    ): array {
        // El origen es el número de WhatsApp del ciudadano. Se normaliza igual
        // que una cédula —solo dígitos— para que «+57 318 333 3510» y
        // «573183335103» no sean dos personas distintas con dos cubetas.
        $origen = Censo::normalizar((string) $cabeceraOrigen);
        $enviado = (string) $cabeceraSecreto;

        $esServicio = $secretoConfigurado !== ''
            && $enviado !== ''
            && $porHttps
            && hash_equals($secretoConfigurado, $enviado)
            && $origen !== '';

        if (! $esServicio) {
            return [
                [
                    'accion' => 'preinscripcion.verificar.hora',
                    'identidad' => $ip,
                    'maximo' => self::MAX_VERIFICACIONES_HORA,
                    'ventana' => 3600,
                    'mensaje' => 'Ha consultado demasiadas veces desde esta conexión. Espere unos minutos.',
                    'global' => false,
                ],
                [
                    'accion' => 'preinscripcion.verificar.dia',
                    'identidad' => $ip,
                    'maximo' => self::MAX_VERIFICACIONES_DIA,
                    'ventana' => 86400,
                    'mensaje' => 'Ha consultado demasiadas veces desde esta conexión. '.self::LINEA_ATENCION,
                    'global' => false,
                ],
            ];
        }

        // Sustituyen a los de IP, no se suman: por IP el canal entero es una
        // sola conexión, que es exactamente el problema que esto resuelve.
        //
        // El techo global va el ÚLTIMO a propósito. Así solo lo gasta una
        // consulta que ya pasó los límites de su ciudadano: quien insiste desde
        // su WhatsApp se choca con su propia cubeta y no se lleva por delante la
        // del canal.
        return [
            [
                'accion' => 'preinscripcion.verificar.origen.hora',
                'identidad' => $origen,
                'maximo' => self::MAX_VERIFICACIONES_ORIGEN_HORA,
                'ventana' => 3600,
                // El mensaje de la web habla de «esta conexión», y para quien
                // escribe por WhatsApp eso es falso: no comparte conexión con
                // nadie. Decirle algo falso en un canal de emergencias hace que
                // deje de creerse lo demás.
                'mensaje' => 'Ha consultado demasiadas veces. Espere unos minutos e intente de nuevo.',
                'global' => false,
            ],
            [
                'accion' => 'preinscripcion.verificar.origen.dia',
                'identidad' => $origen,
                'maximo' => self::MAX_VERIFICACIONES_ORIGEN_DIA,
                'ventana' => 86400,
                'mensaje' => 'Ha consultado demasiadas veces. '.self::LINEA_ATENCION,
                'global' => false,
            ],
            [
                'accion' => 'preinscripcion.verificar.servicio.hora',
                'identidad' => self::IDENTIDAD_SERVICIO,
                'maximo' => self::MAX_VERIFICACIONES_SERVICIO_HORA,
                'ventana' => 3600,
                // Sin texto propio: el 429 con `Retry-After` que ya emite
                // `Limite` es el correcto. Este caso no lo arregla el ciudadano.
                'mensaje' => 'El servicio está recibiendo demasiadas consultas. Intente de nuevo en unos minutos.',
                'global' => true,
            ],
        ];
    }

    /**
     * La misma consulta, pero para el bot de WhatsApp, que firma en vez de
     * mandar un secreto en una cabecera.
     *
     * Existe aparte de `verificar()` por una limitación de la plataforma del
     * bot: sus herramientas **no pueden añadir cabeceras propias**. No hay
     * manera de que mande `X-RUFE-Servicio` ni `X-RUFE-Origen`. Lo único que
     * envía es una firma HMAC del cuerpo, y esa firma prueba exactamente lo
     * mismo que probaba el secreto en la cabecera: que quien llama conoce el
     * secreto compartido. Mejor, de hecho — la firma cubre también el cuerpo,
     * así que nadie puede reutilizarla para consultar otra cédula.
     *
     * Responde PLANO —`{"habilitado":"si"}`— y no con la envoltura
     * `{ok,data}` del resto de la API. No es descuido: el motor de flujos del
     * bot guarda la respuesta en una variable y la compara como texto, y no
     * está documentado que sepa bajar por campos anidados. `{{rufe.habilitado}}`
     * funciona seguro; `{{rufe.data.habilitado}}` es una apuesta.
     *
     * Y «si»/«no» en vez de true/false por lo mismo: la comparación del motor
     * es textual, y cómo serializa un booleano cada versión es justo la clase
     * de detalle que rompe en producción un martes.
     *
     * La INFORMACIÓN que da es idéntica a la de `verificar()`: si esa cédula
     * está en el censo, y nada más. Cambia el envoltorio, nunca el contenido.
     */
    public function verificarBot(Request $req): void
    {
        $secreto = (string) Config::get('rufe.bot_secreto', '');

        // Sin secreto configurado la ruta NO EXISTE. Un 404 y no un 403: hasta
        // que alguien la habilite a conciencia, quien la busque no debe poder
        // distinguirla de una ruta que nunca se escribió.
        if ($secreto === '' || ! self::llegoPorHttps()) {
            throw new HttpError('No encontrado', 404);
        }

        // Mismo 404 ante una firma que no cuadra, por lo mismo: un 401 le
        // confirmaría a quien tantea que la ruta está ahí y espera una firma.
        if (! self::firmaValida($req->cuerpoCrudo(), $req->cabecera('X-Zavu-Signature'), $secreto)) {
            throw new HttpError('No encontrado', 404);
        }

        // El formato antes de gastar cubeta, igual que en la web: quien teclea
        // mal no debe quedarse sin intentos.
        $cuerpo = $req->todo();
        $documento = Censo::normalizar((string) self::buscarEnCuerpo($cuerpo, ['documento']));

        if (! Censo::pareceCedula($documento)) {
            // La plataforma no documenta cómo envuelve los parámetros de una
            // herramienta, y en la primera prueba real no venían en la raíz. Se
            // registran los NOMBRES de las claves recibidas —nunca sus valores,
            // que incluyen la cédula— para poder ajustar sin adivinar.
            error_log('SGR: el bot mandó un cuerpo sin cédula reconocible. Claves: '.implode(', ', self::formaDelCuerpo($cuerpo)));

            throw HttpError::validacion([
                'documento' => 'Escriba su número de cédula, sin puntos ni espacios.',
            ]);
        }

        $origen = self::origenDelBot($cuerpo);

        // Sin identificador del ciudadano los límites bajan a contar por cédula,
        // que frena a quien insiste sobre una pero no a quien recorre muchas. No
        // es un detalle menor, así que queda constancia de la forma del cuerpo
        // —solo los NOMBRES de las claves— para poder corregir la extracción.
        //
        // El aviso se apaga solo: en cuanto se acierte con la clave, deja de
        // escribirse. Si nunca aparece en el log, es que siempre se identifica.
        if ($origen === null) {
            error_log('SGR: el bot no manda identificador de ciudadano; se limita por cédula. Claves: '.implode(', ', self::formaDelCuerpo($cuerpo)));
        }

        foreach (self::planDeLimitesFirmado($origen, $documento) as $l) {
            try {
                Limite::consumir($l['accion'], $l['identidad'], $l['maximo'], $l['ventana'], $l['mensaje']);
            } catch (HttpError $e) {
                if ($l['global']) {
                    error_log(
                        'SGR: el canal de servicio agotó su techo de '
                        .self::MAX_VERIFICACIONES_SERVICIO_HORA.' consultas por hora.'
                    );
                }

                throw $e;
            }
        }

        Response::json(['habilitado' => Censo::estaInscrito($documento) ? 'si' : 'no']);
    }

    /**
     * ¿La firma del cuerpo cuadra con el secreto compartido?
     *
     * Se aceptan dos formatos a propósito. El documentado para herramientas es
     * el hexadecimal pelado; el de los webhooks de la misma plataforma es
     * `t=<epoch>,v2=<hex>` sobre `"{t}.{cuerpo}"`. La documentación de la
     * primera es escueta y las dos conviven, así que se prueban ambas en vez de
     * apostar por una.
     *
     * No es precaución teórica: en el proveedor anterior la firma llegaba como
     * `v1,sha256=<hex>` y quitar solo `sha256=` dejaba el `v1,` delante, con lo
     * que NADA coincidía nunca. El canal estuvo mudo días por eso, y el síntoma
     * —«firma inválida»— no señalaba a la causa.
     *
     * `hash_equals` porque una comparación normal corta en el primer byte
     * distinto y deja adivinar la firma midiendo tiempos.
     */
    public static function firmaValida(string $crudo, ?string $cabecera, string $secreto): bool
    {
        $valor = trim((string) $cabecera);

        if ($secreto === '' || $valor === '') {
            return false;
        }

        // Formato con marca de tiempo: t=<epoch>,v2=<hex>
        if (str_contains($valor, '=') && str_contains($valor, ',')) {
            $marca = null;
            $firmas = [];

            foreach (explode(',', $valor) as $parte) {
                $trozo = explode('=', trim($parte), 2);
                if (count($trozo) !== 2) {
                    continue;
                }
                [$clave, $dato] = $trozo;
                if ($clave === 't') {
                    $marca = $dato;
                } else {
                    $firmas[] = $dato;
                }
            }

            foreach ($firmas as $firma) {
                // Con marca: se firma "{t}.{cuerpo}". Sin ella, el cuerpo solo.
                $candidatos = $marca === null ? [$crudo] : [$marca.'.'.$crudo, $crudo];
                foreach ($candidatos as $carga) {
                    if (hash_equals(hash_hmac('sha256', $carga, $secreto), $firma)) {
                        return true;
                    }
                }
            }

            return false;
        }

        // Formato documentado para herramientas: el hexadecimal, a secas.
        return hash_equals(hash_hmac('sha256', $crudo, $secreto), $valor);
    }

    /**
     * El identificador del ciudadano dentro del cuerpo que manda el bot, si es
     * que manda alguno.
     *
     * La plataforma no documenta qué contexto acompaña a la llamada de una
     * herramienta, así que se buscan los nombres plausibles y se acepta el
     * primero que aparezca. Si no viene ninguno, se devuelve null y los límites
     * se cuentan de otra forma — ver `planDeLimitesFirmado()`.
     *
     * Se prefiere el identificador de conversación al teléfono: sirve igual
     * para contar y no obliga a guardar un número de móvil, ni siquiera
     * hasheado, para algo que no lo necesita.
     *
     * @param  array<string,mixed>  $cuerpo
     */
    public static function origenDelBot(array $cuerpo): ?string
    {
        return self::buscarEnCuerpo($cuerpo, ['conversationId', 'contactId', 'sessionId', 'from', 'phone']);
    }

    /**
     * El primero de esos campos que aparezca en el cuerpo, esté donde esté.
     *
     * Busca por niveles y no solo en la raíz porque la plataforma del bot
     * envuelve los parámetros de la herramienta y no documenta cómo. En la
     * primera prueba real `documento` no llegó en la raíz, y adivinar el
     * envoltorio es exactamente lo que deja el canal mudo cuando el proveedor
     * lo cambia de versión.
     *
     * Dos reglas que no se contradicen, pero que hay que aplicar en el orden
     * correcto:
     *
     *  • Entre claves DISTINTAS manda la preferencia. `conversationId` gana a
     *    `from` esté donde esté cada uno: cuenta igual para limitar y no obliga
     *    a manejar un número de móvil.
     *  • Para la MISMA clave gana la más externa, que es la del llamador y no
     *    un eco dentro del envoltorio.
     *
     * De ahí que el recorrido sea clave por clave, y por niveles dentro de cada
     * una. Al revés —por niveles y probando las claves dentro— la profundidad
     * pesaría más que la preferencia y un `from` en la raíz le ganaría a un
     * `conversationId` un nivel más abajo.
     *
     * @param  array<string,mixed>  $cuerpo
     * @param  list<string>  $claves  en orden de preferencia
     */
    public static function buscarEnCuerpo(array $cuerpo, array $claves, int $profundidad = 4): ?string
    {
        foreach ($claves as $clave) {
            $nivel = [$cuerpo];

            for ($i = 0; $i < $profundidad && $nivel !== []; $i++) {
                foreach ($nivel as $nodo) {
                    $v = $nodo[$clave] ?? null;
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        return trim((string) $v);
                    }
                }

                $siguiente = [];
                foreach ($nivel as $nodo) {
                    foreach ($nodo as $v) {
                        if (is_array($v)) {
                            $siguiente[] = $v;
                        }
                    }
                }
                $nivel = $siguiente;
            }
        }

        return null;
    }

    /**
     * Los nombres de las claves del cuerpo, hasta dos niveles. NUNCA los
     * valores: ahí viaja la cédula.
     *
     * @param  array<string,mixed>  $cuerpo
     * @return list<string>
     */
    public static function formaDelCuerpo(array $cuerpo): array
    {
        $forma = [];

        foreach ($cuerpo as $k => $v) {
            if (is_array($v)) {
                foreach (array_keys($v) as $k2) {
                    $forma[] = $k.'.'.$k2;
                }
            } else {
                $forma[] = (string) $k;
            }
        }

        return $forma;
    }

    /**
     * Qué cubetas gasta una consulta ya autenticada por firma.
     *
     * Pura y aparte, por lo mismo que `planDeLimites()`: para poder comprobar
     * las cifras sin montar MySQL.
     *
     * Con identificador del ciudadano se usan las MISMAS cubetas que la vía de
     * `X-RUFE-Origen`, a propósito: es la misma persona contada igual, venga
     * por donde venga, y no dos presupuestos que se suman.
     *
     * Sin él solo queda la cédula y el techo. Es más débil y conviene decirlo
     * claro: por cédula se frena a quien insiste sobre una, no a quien recorre
     * muchas. Mientras el bot no mande un identificador, **el techo global es
     * la única defensa real contra enumerar el censo** con el secreto en la
     * mano. Es la razón de que vaya siempre, en las dos ramas.
     *
     * @return list<array{accion:string,identidad:string,maximo:int,ventana:int,mensaje:string,global:bool}>
     */
    public static function planDeLimitesFirmado(?string $origen, string $documento): array
    {
        $identidad = $origen === null ? '' : Censo::normalizar($origen);
        // Un identificador de conversación puede no ser numérico; si al
        // normalizar como cédula no queda nada, se usa tal cual.
        if ($identidad === '' && $origen !== null) {
            $identidad = $origen;
        }

        $plan = [];

        if ($identidad !== '') {
            $plan[] = [
                'accion' => 'preinscripcion.verificar.origen.hora',
                'identidad' => $identidad,
                'maximo' => self::MAX_VERIFICACIONES_ORIGEN_HORA,
                'ventana' => 3600,
                'mensaje' => 'Ha consultado demasiadas veces. Espere unos minutos e intente de nuevo.',
                'global' => false,
            ];
            $plan[] = [
                'accion' => 'preinscripcion.verificar.origen.dia',
                'identidad' => $identidad,
                'maximo' => self::MAX_VERIFICACIONES_ORIGEN_DIA,
                'ventana' => 86400,
                'mensaje' => 'Ha consultado demasiadas veces. '.self::LINEA_ATENCION,
                'global' => false,
            ];
        } else {
            $plan[] = [
                'accion' => 'preinscripcion.verificar.bot.cedula.dia',
                'identidad' => $documento,
                'maximo' => self::MAX_VERIFICACIONES_BOT_CEDULA_DIA,
                'ventana' => 86400,
                'mensaje' => 'Ha consultado demasiadas veces esta cédula. '.self::LINEA_ATENCION,
                'global' => false,
            ];
        }

        // El techo va el ÚLTIMO y va SIEMPRE: así solo lo gasta una consulta
        // que ya pasó su propia cubeta, y ninguna rama se queda sin él.
        $plan[] = [
            'accion' => 'preinscripcion.verificar.servicio.hora',
            'identidad' => self::IDENTIDAD_SERVICIO,
            'maximo' => self::MAX_VERIFICACIONES_SERVICIO_HORA,
            'ventana' => 3600,
            'mensaje' => 'El servicio está recibiendo demasiadas consultas. Intente de nuevo en unos minutos.',
            'global' => true,
        ];

        return $plan;
    }

    /**
     * ¿La petición llegó cifrada?
     *
     * El `.htaccess` ya redirige todo a HTTPS, pero eso es configuración que
     * alguien puede cambiar sin darse cuenta de que de ella depende un secreto
     * compartido. Se comprueba también aquí.
     *
     * `X-Forwarded-Proto` porque en hosting compartido el PHP suele estar
     * detrás de un proxy que termina el TLS: sin mirarla, `HTTPS` viene vacía
     * en peticiones que sí llegaron cifradas.
     */
    private static function llegoPorHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * Guarda el hogar que dejó el ciudadano, y en qué cambió respecto del censo.
     *
     * ── Por qué el censo se vuelve a leer aquí ───────────────────────────────
     *
     * El navegador manda un `rufe_persona_id` por cada persona que precargó.
     * Eso es una PISTA, no una autoridad: aceptarla tal cual dejaría que
     * alguien atribuyera a su solicitud a una persona de otro hogar, o que
     * marcara como «igual» una fila que cambió entera.
     *
     * Así que se relee la ficha por la cédula del solicitante y solo cuentan
     * los ids que de verdad pertenecen a ESE hogar. Lo que no case se guarda
     * como persona nueva, que es lo honesto: alguien que el censo no tenía.
     *
     * @param  array<string,mixed>  $datos
     */
    private function guardarHogar(int $id, array $datos): void
    {
        $personas = $datos['personas'] ?? [];

        if ($personas === []) {
            return;
        }

        $hogar = Censo::hogarDe((string) $datos['documento']);
        $delCenso = [];

        if ($hogar !== null) {
            foreach ($hogar['personas'] as $p) {
                $delCenso[(int) $p['id']] = $p;
            }

            Db::exec(
                'UPDATE preinscripciones SET rufe_reporte_id = :r WHERE id = :i',
                ['r' => $hogar['reporte_id'], 'i' => $id]
            );
        }

        foreach ($personas as $p) {
            $origen = $delCenso[(int) ($p['rufe_persona_id'] ?? 0)] ?? null;

            Db::exec(
                'INSERT INTO preinscripcion_personas
                    (preinscripcion_id, orden, rufe_persona_id, nombres, apellidos,
                     tipo_documento, numero_documento, parentesco, genero,
                     fecha_nacimiento, estado)
                 VALUES (:p, :orden, :rufe, :nombres, :apellidos, :tipo, :doc,
                         :parentesco, :genero, :nacimiento, :estado)',
                [
                    'p' => $id,
                    'orden' => $p['orden'],
                    'rufe' => $origen === null ? null : (int) $origen['id'],
                    'nombres' => $p['nombres'],
                    'apellidos' => $p['apellidos'],
                    'tipo' => $p['tipo_documento'],
                    'doc' => $p['numero_documento'],
                    'parentesco' => $p['parentesco'],
                    'genero' => $p['genero'],
                    'nacimiento' => $p['fecha_nacimiento'],
                    'estado' => Censo::estadoDePersona($p, $origen, (bool) $p['no_vive_aqui']),
                ]
            );
        }
    }

    /**
     * Abre una carga para las fotos de una solicitud, sin sesión.
     *
     * El token no se guarda en ninguna tabla: solo su SHA-256 acompaña a cada
     * archivo. Quien no lo tenga no puede ver ni adjuntar nada a esa carga, y
     * adivinarlo exige acertar 256 bits.
     *
     * Las cargas abandonadas caducan en doce horas y se purgan con el tráfico.
     * Sin eso, un endpoint público de subida es alojamiento gratuito.
     */
    public function abrirCarga(Request $req): void
    {
        Limite::consumir(
            'preinscripcion.carga',
            $req->ip(),
            self::MAX_CARGAS_HORA,
            3600,
            'Demasiados intentos desde esta conexión. Espere unos minutos.'
        );

        Archivos::purgarCargasCaducadas();

        // Los videos se purgan AQUÍ y no solo al iniciar uno nuevo.
        //
        // Estaban solo en `iniciarVideo`, y eso los dejaba casi sin limpiar: una
        // carga se abre cada vez que alguien entra al formulario, pero un video
        // se empieza a subir en muy pocas de esas visitas. El resultado en
        // producción fue que las fotos huérfanas desaparecían solas y los videos
        // huérfanos —que pesan mil veces más— seguían ahí un día después,
        // ocupando el disco de un hosting compartido.
        Videos::purgarCaducados();

        Response::json([
            'ok' => true,
            'data' => [
                'carga' => bin2hex(random_bytes(32)),
                // Los daños más las DOS caras de la cédula.
                'maximo_archivos' => Rufe::MAX_FOTOS_PREINSCRIPCION + 2,
                'maximo_bytes' => Rufe::MAX_BYTES_ARCHIVO,
            ],
        ], 201);
    }

    /** Una foto por petición, para poder mostrar progreso y reintentar solo la que falló. */
    public function subirArchivo(Request $req): void
    {
        Limite::consumir(
            'preinscripcion.archivo',
            $req->ip(),
            self::MAX_ARCHIVOS_HORA,
            3600,
            'Demasiadas fotos desde esta conexión. Espere unos minutos.'
        );

        $archivo = $req->archivo('archivo');
        if ($archivo === null) {
            throw HttpError::validacion(['archivo' => 'No se recibió ninguna foto.']);
        }

        // El tipo llega del cliente pero se filtra contra una lista blanca: sin
        // ella, una solicitud ciudadana podría reclamar el cupo de diez fotos
        // del registro fotográfico de una inspección.
        $tipo = $req->campo('tipo', 'PRE_DANO');
        if (! in_array($tipo, Rufe::TIPOS_PREINSCRIPCION, true)) {
            throw HttpError::validacion(['archivo' => 'Tipo de archivo no reconocido.']);
        }

        $guardado = Archivos::guardarEnCarga($archivo, Archivos::hashDeCarga($req->param('carga')), $tipo);

        Response::json(['ok' => true, 'data' => ['archivo' => $guardado]], 201);
    }

    public function eliminarArchivo(Request $req): void
    {
        $id = (int) $req->param('id');
        if ($id <= 0) {
            throw HttpError::noEncontrado('El archivo no existe.');
        }

        Archivos::eliminarDeCarga(Archivos::hashDeCarga($req->param('carga')), $id);

        Response::sinContenido();
    }

    /** Reserva un video y devuelve cuántos trozos hay que mandar. */
    public function iniciarVideo(Request $req): void
    {
        Limite::consumir(
            'preinscripcion.video',
            $req->ip(),
            self::MAX_VIDEOS_HORA,
            3600,
            'Demasiados videos desde esta conexión. Espere unos minutos.'
        );

        Videos::purgarCaducados();

        $categoria = (int) $req->texto('categoria_id');

        Response::json([
            'ok' => true,
            'data' => Videos::iniciar(
                Archivos::hashDeCarga($req->param('carga')),
                $categoria > 0 ? $categoria : null,
                $req->texto('mime'),
                (int) $req->texto('bytes'),
                (int) $req->texto('segundos')
            ),
        ], 201);
    }

    /** Un trozo del video. Llegan en orden y se pegan al final del archivo. */
    public function subirTrozo(Request $req): void
    {
        Limite::consumir(
            'preinscripcion.trozo',
            $req->ip(),
            self::MAX_TROZOS_HORA,
            3600,
            'Demasiadas peticiones desde esta conexión. Espere unos minutos.'
        );

        $trozo = $req->archivo('trozo');
        if ($trozo === null) {
            throw HttpError::validacion(['video' => 'No se recibió el trozo del video.']);
        }

        Response::ok(Videos::recibirTrozo(
            Archivos::hashDeCarga($req->param('carga')),
            (int) $req->param('id'),
            (int) $req->campo('indice', '-1'),
            $trozo
        ));
    }

    public function crear(Request $req): void
    {
        Limite::consumir(
            'preinscripcion.intento',
            $req->ip(),
            self::MAX_INTENTOS_HORA,
            3600,
            'Demasiadas solicitudes desde esta conexión. Espere unos minutos.'
        );

        $envioId = $this->envioId($req);

        // Se lee aquí y no al final porque los dos atajos de más abajo
        // —reintento y duplicada— también tienen que adoptar los archivos.
        $carga = $req->texto('carga');
        $carga = $carga === '' ? null : $carga;

        // Reintento de un envío que ya entró: ocurre cuando la solicitud llegó
        // pero la respuesta se perdió por falta de cobertura. Se devuelve el
        // radicado original en vez de inscribir dos veces al mismo hogar.
        if ($envioId !== null) {
            $previo = Db::first(
                'SELECT id, radicado, creado_en FROM preinscripciones WHERE envio_id = :e',
                ['e' => $envioId]
            );

            if ($previo !== null) {
                Response::ok([
                    'radicado'    => $previo['radicado'],
                    'recibido_en' => date('c', strtotime((string) $previo['creado_en'])),
                    'reintento'   => true,
                    // Un reintento suele traer la misma carga ya adoptada, y
                    // entonces esto no encuentra nada y devuelve cero. Pero si
                    // el teléfono perdió la señal a mitad y volvió a subir las
                    // fotos con otra carga, aquí es donde entran.
                    'archivos_agregados' => $this->adjuntarA((int) $previo['id'], $carga),
                ]);

                return;
            }
        }

        // Trampa para robots. Se responde 201 con un radicado inventado: quien
        // lo llenó no es una persona, y decirle «te descubrí» solo sirve para
        // que afine el robot.
        if ($req->texto('sitio_web') !== '') {
            Response::json([
                'ok'   => true,
                'data' => ['radicado' => Radicado::componer(), 'recibido_en' => date('c')],
            ], 201);

            return;
        }

        Limite::consumir('preinscripcion.enviar', $req->ip(), self::MAX_ENVIOS_HORA, 3600);

        $revision = Validador::revisar($req->todo());
        if ($revision['errores'] !== []) {
            throw HttpError::validacion($revision['errores']);
        }

        $datos = $revision['datos'];

        // La misma puerta que la primera pantalla, otra vez y aquí de verdad.
        //
        // Lo de allí es comodidad: avisa antes de que la persona llene cuatro
        // pasos para nada. Quien decide es esto, porque la ruta es pública y
        // saltarse el navegador es trivial.
        if (! Censo::estaInscrito($datos['documento'])) {
            throw HttpError::validacion([
                'documento' => 'Esta cédula no aparece en el censo (RUFE). '.self::LINEA_ATENCION,
            ]);
        }

        $huella = Radicado::huella($datos['direccion'], $datos['documento']);

        // Ya existe una solicitud de esta misma vivienda: se devuelve la suya en
        // vez de crear otra. Que la familia se inscriba tres veces por nervios
        // no puede convertirse en tres turnos.
        $duplicada = Db::first(
            'SELECT id, radicado, creado_en FROM preinscripciones
              WHERE huella = :h AND estado <> :d
              ORDER BY id DESC LIMIT 1',
            ['h' => $huella, 'd' => 'DESCARTADA']
        );

        if ($duplicada !== null) {
            Response::ok([
                'radicado'    => $duplicada['radicado'],
                'recibido_en' => date('c', strtotime((string) $duplicada['creado_en'])),
                'duplicada'   => true,
                // Lo nuevo se SUMA a la solicitud que ya existía. Antes se
                // tiraba: quien volvía a inscribirse justamente porque esta vez
                // sí había podido grabar el video recibía «ya estaba
                // registrada» y perdía el video, sin enterarse.
                'archivos_agregados' => $this->adjuntarA((int) $duplicada['id'], $carga),
            ]);

            return;
        }

        try {
            $radicado = $this->guardar($datos, $huella, $envioId, $req, $carga);
        } catch (\PDOException $e) {
            // Dos envíos de la misma familia cruzados en el aire. Ver `Reintento`.
            // Es el formulario donde más probable es: se llena desde un celular
            // con la señal que quede, y la cola de envío reintenta sola.
            $previo = Reintento::filaPrevia(
                $e,
                $envioId,
                'SELECT id, radicado, creado_en FROM preinscripciones WHERE envio_id = :e'
            );

            if ($previo === null) {
                throw $e;
            }

            Response::ok([
                'radicado'    => $previo['radicado'],
                'recibido_en' => date('c', strtotime((string) $previo['creado_en'])),
                'reintento'   => true,
                // Las fotos y los videos de ESTA carga van a la solicitud que
                // ganó la carrera. Sin esto se quedarían huérfanos en temporal/
                // hasta que la purga se los llevara, que es el mismo agujero que
                // ya se cerró en los otros dos atajos de arriba.
                'archivos_agregados' => $this->adjuntarA((int) $previo['id'], $carga),
            ]);

            return;
        }

        Response::json([
            'ok'   => true,
            'data' => ['radicado' => $radicado, 'recibido_en' => date('c')],
        ], 201);
    }

    /**
     * Suma a una solicitud que ya existe los archivos de un reenvío.
     *
     * Antes esto no existía y los dos atajos de `crear()` —el reintento sin
     * señal y la solicitud duplicada— devolvían el radicado y se marchaban sin
     * tocar la carga. Las fotos y los videos recién subidos se quedaban
     * huérfanos en `temporal/` y la purga se los llevaba al caducar la carga.
     *
     * El caso que lo hace grave: una familia se inscribe, y días más tarde
     * vuelve a inscribirse porque esta vez sí consiguió grabar el video del
     * daño. El servidor le contestaba «su vivienda ya estaba registrada» —con
     * razón— y le tiraba el video, sin decírselo. Justo la evidencia que valía
     * la pena.
     *
     * Devuelve cuántos archivos se sumaron, para poder decírselo en pantalla.
     */
    private function adjuntarA(int $preinscripcionId, ?string $carga): int
    {
        if ($carga === null) {
            return 0;
        }

        $hash = Archivos::hashDeCarga($carga);

        $pdo = Db::conn();
        $pdo->beginTransaction();

        try {
            $sumados = Archivos::adoptarPreinscripcion($hash, $preinscripcionId)
                + Videos::adoptar($hash, $preinscripcionId);

            // Que quede constancia de que la ficha creció después de recibida:
            // quien la revisó ayer y la vio sin videos tiene que poder entender
            // por qué hoy tiene dos.
            if ($sumados > 0) {
                Db::exec(
                    'INSERT INTO preinscripcion_historial (preinscripcion_id, estado, nota)
                     SELECT id, estado, :n FROM preinscripciones WHERE id = :i',
                    [
                        'n' => $sumados === 1
                            ? 'El ciudadano volvió a enviar el formulario y se agregó 1 archivo.'
                            : "El ciudadano volvió a enviar el formulario y se agregaron {$sumados} archivos.",
                        'i' => $preinscripcionId,
                    ]
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $sumados;
    }

    /**
     * @param  array<string,mixed>  $datos
     */
    private function guardar(
        array $datos,
        string $huella,
        ?string $envioId,
        Request $req,
        ?string $carga
    ): string {
        $radicado = Radicado::generar();

        // En una transacción: sin ella, un fallo al adoptar las fotos dejaría la
        // solicitud escrita y sus fotos huérfanas hasta caducar, y la familia
        // creería que mandó las evidencias.
        $pdo = Db::conn();
        $pdo->beginTransaction();

        try {
            Db::exec(
                'INSERT INTO preinscripciones
                    (radicado, envio_id, nombre_completo, documento, telefono, correo,
                     direccion, zona, corregimiento, vereda, latitud, longitud, precision_m,
                     descripcion_dano, autoriza_datos, aviso_version, autorizacion_en,
                     huella, estado, origen_hash)
                 VALUES
                    (:radicado, :envio_id, :nombre, :documento, :telefono, :correo,
                     :direccion, :zona, :corregimiento, :vereda, :latitud, :longitud, :precision_m,
                     :descripcion, :autoriza, :aviso, NOW(),
                     :huella, :estado, :origen)',
                [
                    'radicado'      => $radicado,
                    'envio_id'      => $envioId ?? bin2hex(random_bytes(18)),
                    'nombre'        => $datos['nombre_completo'],
                    'documento'     => $datos['documento'],
                    'telefono'      => $datos['telefono'],
                    'correo'        => $datos['correo'],
                    'direccion'     => $datos['direccion'],
                    'zona'          => $datos['zona'],
                    'corregimiento' => $datos['corregimiento'],
                    'vereda'        => $datos['vereda'],
                    'latitud'       => $datos['latitud'],
                    'longitud'      => $datos['longitud'],
                    'precision_m'   => $datos['precision_m'],
                    'descripcion'   => $datos['descripcion_dano'],
                    'autoriza'      => $datos['autoriza_datos'],
                    'aviso'         => $datos['aviso_version'],
                    'huella'        => $huella,
                    'estado'        => 'RECIBIDA',
                    // La IP no se guarda: solo su hash con sal, que basta para
                    // contar abusos y no conserva un dato que la atención no
                    // necesita.
                    'origen'        => hash('sha256', $req->ip().'|'.Config::get('rufe.sal', '')),
                ]
            );

            $id = Db::lastId();

            $this->guardarHogar($id, $datos);

            // La etiqueta se copia tal como se le mostró a la persona: si algún
            // día se reescribe un texto del catálogo, el expediente tiene que
            // seguir diciendo qué fue lo que marcó.
            foreach ($datos['senales'] as $codigo) {
                Db::exec(
                    'INSERT INTO preinscripcion_senales (preinscripcion_id, codigo, etiqueta)
                     VALUES (:p, :c, :e)',
                    ['p' => $id, 'c' => $codigo, 'e' => Senales::etiqueta($codigo)]
                );
            }

            if ($carga !== null) {
                $hash = Archivos::hashDeCarga($carga);
                Archivos::adoptarPreinscripcion($hash, $id);
                Videos::adoptar($hash, $id);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $radicado;
    }

    // ── Interno (con sesión) ─────────────────────────────────────────────────

    public function listar(Request $req): void
    {
        // Mantenimiento montado en el tráfico, igual que la purga de cargas
        // caducadas: aquí no hay consola ni tareas programadas. Recoloca los
        // videos que quedaron en `temporal/` de cuando la adopción no los movía.
        // Con tope, para que abrir la bandeja no se vuelva un trabajo largo.
        Videos::reubicarPendientes();

        $estado = strtoupper($req->query('estado', '') ?? '');
        $condiciones = [];
        $filtros = [];

        if (in_array($estado, ['RECIBIDA', 'EN_REVISION', 'CONVERTIDA', 'DESCARTADA'], true)) {
            $condiciones[] = 'estado = :estado';
            $filtros['estado'] = $estado;
        }

        [$condicionBusqueda, $paramsBusqueda] = self::busqueda((string) ($req->query('q', '') ?? ''));

        if ($condicionBusqueda !== '') {
            $condiciones[] = $condicionBusqueda;
            $filtros += $paramsBusqueda;
        }

        $where = $condiciones === [] ? '' : ' WHERE '.implode(' AND ', $condiciones);

        $pagina = max(1, (int) ($req->query('pagina', '1') ?? 1));
        $porPagina = 25;
        $desde = ($pagina - 1) * $porPagina;

        $total = (int) (Db::first("SELECT COUNT(*) AS n FROM preinscripciones{$where}", $filtros)['n'] ?? 0);

        $filas = Db::all(
            "SELECT id, radicado, nombre_completo, documento, telefono, correo, direccion,
                    zona, corregimiento, vereda, latitud, longitud, estado, motivo_descarte,
                    inspeccion_id, creado_en
               FROM preinscripciones{$where}
              ORDER BY id DESC
              LIMIT {$porPagina} OFFSET {$desde}",
            $filtros
        );

        Response::ok([
            'preinscripciones' => $this->conLoQueMandaron($filas),
            'total'            => $total,
            'pagina'           => $pagina,
            'por_pagina'       => $porPagina,
            // Los motivos viven en el call center porque es allí donde
            // cambian el trabajo de alguien: son la diferencia entre volver a
            // llamar a una familia y no volver a marcarle nunca.
            'motivos'          => CallCenterController::MOTIVOS_DESCARTE,
        ]);
    }

    /**
     * La condición del buscador de la bandeja.
     *
     * Quien atiende esta bandeja recibe llamadas: «soy fulano, mandé la
     * solicitud la semana pasada». Sin buscador había que ir pasando páginas.
     *
     * Se busca por cédula exacta, por nombre en cualquier orden, por teléfono,
     * por radicado y por dirección o barrio. Cada una tiene su motivo:
     *
     *  • **Cédula exacta, no por trozos.** Un documento parcial devolvería
     *    decenas de familias ajenas y convertiría el buscador en una forma de
     *    pasear por el censo de damnificados.
     *  • **Nombre en cualquier orden.** «garcía juan» encuentra a «Juan Pérez
     *    García»: quien llama dice su nombre como le sale, no como está escrito
     *    en la casilla. Las tildes y las mayúsculas las resuelve sola la
     *    colación de la tabla.
     *  • **Cada marcador aparece UNA vez.** Con preparadas nativas, repetir el
     *    nombre de un marcador es «Invalid parameter number» al preparar — el
     *    fallo que dejó roto el buscador del censo durante semanas.
     *
     * @return array{0: string, 1: array<string,string>}
     */
    private static function busqueda(string $texto): array
    {
        $texto = trim($texto);

        if ($texto === '') {
            return ['', []];
        }

        $partes = [];
        $params = [];

        $comodin = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $texto).'%';

        foreach (['radicado', 'direccion', 'vereda', 'corregimiento', 'correo'] as $i => $columna) {
            $clave = 'b'.$i;
            $partes[] = "{$columna} LIKE :{$clave}";
            $params[$clave] = $comodin;
        }

        $soloDigitos = preg_replace('/\D+/', '', $texto) ?? '';

        if (strlen($soloDigitos) >= 4) {
            $partes[] = 'documento = :doc';
            $params['doc'] = $soloDigitos;

            // El teléfono sí por trozos: la gente da los últimos cuatro dígitos
            // cuando no recuerda el número entero.
            $partes[] = 'telefono LIKE :tel';
            $params['tel'] = '%'.$soloDigitos.'%';
        }

        $palabras = array_values(array_filter(
            preg_split('/\s+/u', $texto) ?: [],
            static fn (string $p): bool => mb_strlen($p) >= 2 && preg_match('/^\d+$/', $p) !== 1
        ));

        if ($palabras !== []) {
            $porNombre = [];

            foreach (array_slice($palabras, 0, 4) as $i => $palabra) {
                $clave = 'n'.$i;
                $porNombre[] = "nombre_completo LIKE :{$clave}";
                $params[$clave] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $palabra).'%';
            }

            $partes[] = '('.implode(' AND ', $porNombre).')';
        }

        return ['('.implode(' OR ', $partes).')', $params];
    }

    /**
     * Cómo va el proceso, en cinco cifras.
     *
     * Las pestañas dicen en qué estado está cada solicitud, pero no si el
     * trabajo avanza o se acumula. Para eso hacen falta tres cosas que la
     * tabla no muestra:
     *
     *  • **Cuántas entraron hoy y esta semana**, que es el ritmo de llegada.
     *  • **Cuántas llevan más de tres días sin atender.** Es el único número
     *    que delata un atasco: cien solicitudes sin atender no dicen nada si
     *    llegaron esta mañana, y son un problema serio si llevan una semana.
     *  • **Cuántas terminaron en inspección.** Es la razón de ser de la
     *    bandeja; sin esa cifra nadie sabe si el embudo está funcionando.
     */
    public function resumen(Request $req): void
    {
        Auth::exigirUsuario($req);

        $porEstado = [];

        foreach (Db::all('SELECT estado, COUNT(*) AS n FROM preinscripciones GROUP BY estado') as $f) {
            $porEstado[(string) $f['estado']] = (int) $f['n'];
        }

        $cuenta = static fn (string $sql, array $p = []): int => (int) (Db::first(
            'SELECT COUNT(*) AS n FROM preinscripciones WHERE '.$sql,
            $p
        )['n'] ?? 0);

        Response::ok([
            'por_estado' => $porEstado,
            'total' => array_sum($porEstado),
            'hoy' => $cuenta('DATE(creado_en) = CURDATE()'),
            'semana' => $cuenta('creado_en >= (NOW() - INTERVAL 7 DAY)'),
            // El atasco: sin atender y con más de tres días encima.
            'demoradas' => $cuenta(
                "estado = 'RECIBIDA' AND creado_en < (NOW() - INTERVAL :dias DAY)",
                ['dias' => self::DIAS_DEMORA]
            ),
            'dias_demora' => self::DIAS_DEMORA,
            'mas_antigua_sin_atender' => Db::first(
                "SELECT creado_en FROM preinscripciones WHERE estado = 'RECIBIDA'
                  ORDER BY creado_en ASC LIMIT 1"
            )['creado_en'] ?? null,
        ]);
    }

    /**
     * Añade a cada fila del listado lo que el ciudadano adjuntó.
     *
     * Quien abre la bandeja está decidiendo a qué casa ir primero, y esa
     * decisión cambia por completo según lo que venga con la solicitud: no es
     * lo mismo un renglón de texto que uno con cuatro señales de daño, foto de
     * la cédula, tres fotos del muro y un video. Antes había que entrar una por
     * una para saberlo.
     *
     * Son TRES consultas para toda la página, no tres por fila. Con 25 filas la
     * versión ingenua son 75 consultas por pantalla, y esto corre en un hosting
     * compartido: no es una optimización prematura, es la diferencia entre que
     * la bandeja abra o que caduque. Medido: la página entera se sirve con el
     * mismo número de consultas tenga 1 fila o 25.
     *
     * @param  list<array<string,mixed>>  $filas
     * @return list<array<string,mixed>>
     */
    private function conLoQueMandaron(array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        // Vienen de la base de datos y se fuerzan a entero: no hay forma de que
        // llegue aquí nada que no sea un número.
        $ids = array_map(static fn (array $f): int => (int) $f['id'], $filas);
        $lista = implode(',', $ids);

        $senales = [];
        foreach (Db::all("SELECT preinscripcion_id, codigo, etiqueta
                            FROM preinscripcion_senales
                           WHERE preinscripcion_id IN ({$lista})
                           ORDER BY id") as $s) {
            $senales[(int) $s['preinscripcion_id']][] = [
                'codigo' => $s['codigo'],
                'etiqueta' => $s['etiqueta'],
                // El dibujo se resuelve contra el catálogo de hoy; la etiqueta
                // es la que se guardó y no se toca. Ver `Senales::icono`.
                'icono' => Senales::icono((string) $s['codigo']),
            ];
        }

        // Fotos y videos en una sola pasada. La cédula se cuenta aparte porque
        // es la que dice si la solicitud se puede verificar.
        $adjuntos = [];
        foreach (Db::all("SELECT preinscripcion_id,
                                 SUM(tipo = 'PRE_CEDULA') AS cedulas,
                                 SUM(tipo <> 'PRE_CEDULA') AS fotos
                            FROM rufe_evidencias
                           WHERE preinscripcion_id IN ({$lista})
                           GROUP BY preinscripcion_id") as $a) {
            $adjuntos[(int) $a['preinscripcion_id']] = [
                'cedula' => (int) $a['cedulas'] > 0,
                'fotos'  => (int) $a['fotos'],
            ];
        }

        $videos = [];
        foreach (Db::all("SELECT preinscripcion_id, COUNT(*) AS n
                            FROM preinscripcion_videos
                           WHERE preinscripcion_id IN ({$lista})
                             AND ruta_relativa <> ''
                           GROUP BY preinscripcion_id") as $v) {
            // Solo los que conservan archivo: anunciar «2 videos» de una
            // solicitud cuyos videos ya se purgaron mandaría a alguien a
            // abrirla para no encontrar nada.
            $videos[(int) $v['preinscripcion_id']] = (int) $v['n'];
        }

        foreach ($filas as $i => $fila) {
            $id = (int) $fila['id'];

            $filas[$i]['senales'] = $senales[$id] ?? [];
            $filas[$i]['fotos'] = $adjuntos[$id]['fotos'] ?? 0;
            $filas[$i]['cedula'] = $adjuntos[$id]['cedula'] ?? false;
            $filas[$i]['videos'] = $videos[$id] ?? 0;
            // Que exista el punto GPS, no cuál es: en un listado no hace falta
            // y son las coordenadas de la casa de una familia.
            $filas[$i]['ubicada'] = $fila['latitud'] !== null && $fila['longitud'] !== null;

            unset($filas[$i]['latitud'], $filas[$i]['longitud']);
        }

        return $filas;
    }

    public function ver(Request $req): void
    {
        $id = (int) $req->param('id');
        $ficha = Db::first('SELECT * FROM preinscripciones WHERE id = :i', ['i' => $id]);

        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        // El hash de origen no sale nunca: no le sirve a nadie en pantalla y es
        // lo más cercano a un dato de conexión que guardamos.
        unset($ficha['origen_hash']);

        Response::ok([
            'preinscripcion' => $ficha,
            'motivos' => CallCenterController::MOTIVOS_DESCARTE,
            'fotos' => Db::all(
                'SELECT id, nombre_original, extension, tamano_bytes, mime
                   FROM rufe_evidencias WHERE preinscripcion_id = :i ORDER BY id',
                ['i' => $id]
            ),
            'senales' => array_map(
                static fn (array $s): array => [
                    'codigo' => $s['codigo'],
                    'etiqueta' => $s['etiqueta'],
                    'icono' => Senales::icono((string) $s['codigo']),
                ],
                Db::all(
                    'SELECT codigo, etiqueta FROM preinscripcion_senales
                      WHERE preinscripcion_id = :i ORDER BY id',
                    ['i' => $id]
                )
            ),
            'videos' => Videos::deSolicitud($id),
            // El hogar que dejó el ciudadano, con lo que decía el censo al lado
            // cuando cambió. Sin esa comparación, «corregida» no le dice nada a
            // quien tiene que decidir: lo que necesita ver es qué decía antes y
            // qué dice ahora.
            'hogar' => $this->hogarDeSolicitud($id),
            'historial' => Db::all(
                'SELECT estado, nota, usuario_email, creado_en FROM preinscripcion_historial
                  WHERE preinscripcion_id = :i ORDER BY id',
                ['i' => $id]
            ),
        ]);
    }

    /**
     * El hogar de una solicitud, con lo que decía el censo al lado.
     *
     * `rufe_personas` se lee por el id que se guardó, no por la cédula: si el
     * ciudadano corrigió justamente la cédula, buscar por ella no encontraría
     * nada y la corrección más importante sería la única invisible.
     *
     * @return list<array<string,mixed>>
     */
    private function hogarDeSolicitud(int $id): array
    {
        $filas = Db::all(
            'SELECT pp.id, pp.orden, pp.estado, pp.rufe_persona_id,
                    pp.nombres, pp.apellidos, pp.tipo_documento, pp.numero_documento,
                    pp.parentesco, pp.genero, pp.fecha_nacimiento,
                    rp.nombres AS censo_nombres, rp.apellidos AS censo_apellidos,
                    rp.numero_documento AS censo_documento,
                    rp.tipo_documento AS censo_tipo_documento,
                    rp.parentesco AS censo_parentesco, rp.genero AS censo_genero,
                    rp.fecha_nacimiento AS censo_nacimiento
               FROM preinscripcion_personas pp
               LEFT JOIN rufe_personas rp ON rp.id = pp.rufe_persona_id
              WHERE pp.preinscripcion_id = :i
              ORDER BY pp.orden ASC, pp.id ASC',
            ['i' => $id]
        );

        return array_map(
            static fn (array $f): array => [
                'id' => (int) $f['id'],
                'estado' => (string) $f['estado'],
                'nombres' => (string) $f['nombres'],
                'apellidos' => (string) $f['apellidos'],
                'numero_documento' => (string) ($f['numero_documento'] ?? ''),
                'tipo_documento' => Rufe::TIPOS_DOCUMENTO[(int) $f['tipo_documento']] ?? '',
                'parentesco' => Rufe::PARENTESCOS[(int) $f['parentesco']] ?? '',
                'genero' => Rufe::GENEROS[(int) $f['genero']] ?? '',
                'fecha_nacimiento' => (string) ($f['fecha_nacimiento'] ?? ''),
                // Solo cuando hay algo con qué comparar. En una persona nueva
                // esto va nulo y la pantalla no dibuja una columna vacía.
                'censo' => $f['rufe_persona_id'] === null ? null : [
                    'nombres' => (string) ($f['censo_nombres'] ?? ''),
                    'apellidos' => (string) ($f['censo_apellidos'] ?? ''),
                    'numero_documento' => (string) ($f['censo_documento'] ?? ''),
                    'tipo_documento' => Rufe::TIPOS_DOCUMENTO[(int) $f['censo_tipo_documento']] ?? '',
                    'parentesco' => Rufe::PARENTESCOS[(int) $f['censo_parentesco']] ?? '',
                    'genero' => Rufe::GENEROS[(int) $f['censo_genero']] ?? '',
                    'fecha_nacimiento' => (string) ($f['censo_nacimiento'] ?? ''),
                ],
            ],
            $filas
        );
    }

    /** Una foto de la solicitud. Vive fuera del docroot y exige sesión. */
    public function descargarFoto(Request $req): void
    {
        $id = (int) $req->param('id');
        $ficha = Db::first('SELECT id, radicado FROM preinscripciones WHERE id = :i', ['i' => $id]);

        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        // Se exige que la foto sea DE ESTA solicitud, no solo que exista: sin
        // esa condición el identificador de una foto ajena bastaría para verla.
        $fila = Db::first(
            'SELECT * FROM rufe_evidencias WHERE id = :f AND preinscripcion_id = :i',
            ['f' => (int) $req->param('foto'), 'i' => $ficha['id']]
        );

        if ($fila === null) {
            throw HttpError::noEncontrado('El archivo no existe.');
        }

        Auditoria::registrar(
            $req,
            'preinscripcion.foto_descargada',
            Auth::exigirUsuario($req),
            'preinscripciones',
            (string) $ficha['radicado'],
            'foto '.$fila['id']
        );

        Archivos::emitir($fila);
    }

    /** Un video de la solicitud, para verlo desde la bandeja. */
    public function descargarVideo(Request $req): void
    {
        $id = (int) $req->param('id');
        $ficha = Db::first('SELECT id, radicado FROM preinscripciones WHERE id = :i', ['i' => $id]);

        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        // Que el video sea DE ESTA solicitud, no solo que exista: sin esa
        // condición el identificador de uno ajeno bastaría para verlo.
        $fila = Db::first(
            'SELECT * FROM preinscripcion_videos
              WHERE id = :v AND preinscripcion_id = :i AND ruta_relativa <> ""',
            ['v' => (int) $req->param('video'), 'i' => $ficha['id']]
        );

        if ($fila === null) {
            throw HttpError::noEncontrado('Ese video ya no está disponible.');
        }

        Auditoria::registrar(
            $req,
            'preinscripcion.video_visto',
            Auth::exigirUsuario($req),
            'preinscripciones',
            (string) $ficha['radicado'],
            'video '.$fila['id']
        );

        Archivos::emitir($fila);
    }

    /**
     * Borra una solicitud, sus archivos y todo su rastro.
     *
     * Es la única operación de este sistema que destruye datos de un ciudadano,
     * y por eso lleva encima todo lo que se pudo poner:
     *
     *  • Solo Administrador. El Gestor descarta, que es lo que necesita para
     *    trabajar; hacer desaparecer una solicitud es otra cosa.
     *  • Una CONVERTIDA no se borra. Ninguna inspección guarda de qué solicitud
     *    nació, así que borrarla dejaría una ficha de inspección —de la que
     *    depende una entrega de materiales— sin forma de explicar por qué se
     *    hizo esa visita. La inspección sobreviviría; el motivo, no.
     *  • Se exige un motivo y queda en la auditoría junto al radicado y al
     *    nombre. Lo que desaparece es el dato personal, no la constancia de que
     *    existió y de quién decidió quitarlo.
     *
     * Los archivos se borran del disco a mano DESPUÉS de que la base de datos
     * confirme. Las claves foráneas se llevan las filas en cascada pero no
     * tocan el disco: sin esto, la foto de la cédula de una persona seguiría
     * ahí para siempre, sin ninguna fila que la nombrara y sin nadie que
     * supiera que hay que borrarla.
     */
    public function eliminar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');

        $ficha = Db::first(
            'SELECT id, radicado, nombre_completo, estado, inspeccion_id
               FROM preinscripciones WHERE id = :i',
            ['i' => $id]
        );

        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        if ($ficha['estado'] === 'CONVERTIDA') {
            throw HttpError::prohibido(
                'Esta solicitud ya se convirtió en inspección y no se puede borrar. '
                .'Es lo único que explica por qué se hizo esa visita.'
            );
        }

        $motivo = trim($req->texto('motivo'));
        if (mb_strlen($motivo) < 5) {
            throw HttpError::validacion([
                'motivo' => 'Escriba por qué se borra. Queda en la auditoría.',
            ]);
        }

        $borrados = $this->borrarFicha($req, $actor, $ficha, $motivo);

        Response::ok([
            'mensaje' => 'La solicitud '.$ficha['radicado'].' se eliminó.',
            'archivos_borrados' => $borrados,
        ]);
    }

    /**
     * Borra varias de una vez.
     *
     * Nace de una necesidad real —una campaña de prueba, un evento duplicado—,
     * pero es la operación más destructiva del sistema: se lleva fotos de
     * cédulas y videos de viviendas que nadie va a volver a tomar. Por eso
     * repite TODAS las reglas del borrado de una y añade dos suyas.
     *
     *  • Mismo rol, mismo motivo obligatorio, misma constancia por solicitud.
     *  • Las CONVERTIDAS se SALTAN, no rompen el lote. Rechazar las treinta
     *    porque una ya se inspeccionó obligaría a buscarla a mano; se borran las
     *    otras y se dice cuáles quedaron y por qué.
     *  • Un tope por petición. Sin él, un lote de mil borra archivos hasta que
     *    PHP se queda sin tiempo y la respuesta se pierde: quien está delante no
     *    sabría qué se borró y qué no. Con el tope, la respuesta siempre llega.
     */
    public function eliminarLote(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);

        $ids = $req->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        // Enteros, únicos y positivos. Lo que llegue raro no se interpreta.
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $ids),
            static fn (int $v): bool => $v > 0
        )));

        $errores = [];

        if ($ids === []) {
            $errores['ids'] = 'Seleccione al menos una solicitud.';
        } elseif (count($ids) > self::MAX_BORRADO_LOTE) {
            $errores['ids'] = 'Máximo '.self::MAX_BORRADO_LOTE.' solicitudes por vez.';
        }

        $motivo = trim($req->texto('motivo'));
        if (mb_strlen($motivo) < 5) {
            $errores['motivo'] = 'Escriba por qué se borran. Queda en la auditoría.';
        }

        if ($errores !== []) {
            throw HttpError::validacion($errores);
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $fichas = Db::all(
            'SELECT id, radicado, nombre_completo, estado
               FROM preinscripciones WHERE id IN ('.$marcas.')',
            $ids
        );

        $porId = [];
        foreach ($fichas as $f) {
            $porId[(int) $f['id']] = $f;
        }

        $eliminadas = [];
        $conservadas = [];
        $archivos = 0;

        // Se recorre el orden que mandó quien pidió el borrado, no el de la
        // base: así el informe se lee en el mismo orden en que se seleccionó.
        foreach ($ids as $id) {
            $ficha = $porId[$id] ?? null;

            if ($ficha === null) {
                $conservadas[] = [
                    'id'     => $id,
                    'motivo' => 'Ya no existe. Puede que otra persona la borrara antes.',
                ];

                continue;
            }

            if ($ficha['estado'] === 'CONVERTIDA') {
                $conservadas[] = [
                    'id'       => $id,
                    'radicado' => $ficha['radicado'],
                    'motivo'   => 'Ya se convirtió en inspección: es lo único que explica esa visita.',
                ];

                continue;
            }

            $archivos += $this->borrarFicha($req, $actor, $ficha, $motivo);
            $eliminadas[] = $ficha['radicado'];
        }

        Response::ok([
            'eliminadas'        => $eliminadas,
            'conservadas'       => $conservadas,
            'archivos_borrados' => $archivos,
            'mensaje'           => count($eliminadas) === 1
                ? 'Se eliminó 1 solicitud.'
                : 'Se eliminaron '.count($eliminadas).' solicitudes.',
        ]);
    }

    /**
     * Borra UNA solicitud: sus filas, sus archivos y su constancia.
     *
     * Está aparte para que el borrado en lote use exactamente esto y no una
     * copia. Dos borrados con reglas parecidas acaban divergiendo, y el que se
     * quede atrás será el que deje la foto de una cédula en el disco.
     *
     * Quien llama ya comprobó el rol, el estado y el motivo.
     *
     * @param  array<string,mixed>  $ficha
     * @param  array<string,mixed>  $actor
     * @return int archivos borrados del disco
     */
    private function borrarFicha(Request $req, array $actor, array $ficha, string $motivo): int
    {
        $id = (int) $ficha['id'];

        // Las rutas se recogen ANTES: en cuanto se borre la fila, la cascada se
        // lleva las de los archivos y ya no habría forma de saber qué borrar.
        $rutas = array_merge(
            array_column(Db::all(
                'SELECT ruta_relativa FROM rufe_evidencias WHERE preinscripcion_id = :i',
                ['i' => $id]
            ), 'ruta_relativa'),
            array_column(Db::all(
                "SELECT ruta_relativa FROM preinscripcion_videos
                  WHERE preinscripcion_id = :i AND ruta_relativa <> ''",
                ['i' => $id]
            ), 'ruta_relativa')
        );

        Db::exec('DELETE FROM preinscripciones WHERE id = :i', ['i' => $id]);

        // Después del borrado, no antes: si la consulta fallara, unos archivos
        // ya destruidos dejarían una solicitud viva y sin evidencias.
        foreach ($rutas as $ruta) {
            $absoluta = Archivos::base().'/'.$ruta;

            if (is_file($absoluta)) {
                @unlink($absoluta);
                @rmdir(dirname($absoluta));
            }
        }

        // Una anotación POR SOLICITUD, también en el lote. Una sola línea que
        // dijera «se borraron 30» no dejaría constancia de CUÁLES, y la
        // constancia de que existió es lo único que queda de esa persona.
        Auditoria::registrar(
            $req,
            'preinscripcion.eliminada',
            $actor,
            'preinscripciones',
            (string) $ficha['radicado'],
            mb_substr(
                $ficha['nombre_completo'].' · '.count($rutas).' archivo(s) · motivo: '.$motivo,
                0,
                500
            )
        );

        return count($rutas);
    }

    public function cambiarEstado(Request $req): void
    {
        $id = (int) $req->param('id');
        $estado = strtoupper($req->texto('estado'));
        $nota = mb_substr($req->texto('nota'), 0, 500);

        if (! in_array($estado, ['RECIBIDA', 'EN_REVISION', 'CONVERTIDA', 'DESCARTADA'], true)) {
            throw HttpError::validacion(['estado' => 'Estado no válido.']);
        }

        // «Convertida» no se marca a mano: la pone el sistema cuando de verdad
        // nace una inspección de esta solicitud. Dejarla aquí permitiría cerrar
        // una solicitud diciendo que se atendió sin que exista la ficha.
        if ($estado === 'CONVERTIDA') {
            throw HttpError::validacion([
                'estado' => 'Una solicitud se marca como convertida al crear la inspección, no a mano.',
            ]);
        }

        // Descartar sin decir por qué deja a la familia sin saber qué pasó con
        // su solicitud, y a quien atiende el teléfono sin nada que responder.
        if ($estado === 'DESCARTADA' && trim($nota) === '') {
            throw HttpError::validacion(['nota' => 'Explique por qué se descarta.']);
        }

        // El motivo, aparte de la nota, porque de él depende una decisión
        // automática: si esta familia vuelve o no a la cola del call center.
        //
        // La nota es texto libre y sirve para que la operadora sepa qué
        // decirle; el motivo es lo que el sistema puede leer. Con solo la nota,
        // alguien tendría que leer mil trescientos textos para saber a quién
        // hay que volver a llamar.
        $motivo = strtoupper(trim($req->texto('motivo', '')));

        if ($estado === 'DESCARTADA' && ! isset(CallCenterController::MOTIVOS_DESCARTE[$motivo])) {
            throw HttpError::validacion([
                'motivo' => 'Indique si le faltaron datos, le faltó evidencia, o el caso no aplica.',
            ]);
        }

        $ficha = Db::first('SELECT id, radicado FROM preinscripciones WHERE id = :i', ['i' => $id]);
        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        $actor = Auth::exigirUsuario($req);

        // El motivo se limpia al salir de DESCARTADA: si la solicitud vuelve a
        // revisión, arrastrar el motivo viejo haría que el call center la
        // siguiera tratando como rechazada.
        Db::exec(
            'UPDATE preinscripciones SET estado = :e, motivo_descarte = :mo WHERE id = :i',
            ['e' => $estado, 'mo' => $estado === 'DESCARTADA' ? $motivo : null, 'i' => $id]
        );
        Db::exec(
            'INSERT INTO preinscripcion_historial (preinscripcion_id, estado, nota, usuario_id, usuario_email)
             VALUES (:i, :e, :n, :u, :m)',
            ['i' => $id, 'e' => $estado, 'n' => $nota ?: null, 'u' => $actor['id'], 'm' => $actor['email']]
        );

        // Los videos ocupan cien veces más que una foto y la cuenta es compartida
        // con los demás sitios de la Alcaldía. Se borran al decidir la
        // solicitud; la fila queda como constancia de que existieron.
        if ($estado === 'DESCARTADA') {
            Videos::purgarDeSolicitud($id);
        }

        Auditoria::registrar(
            $req, 'preinscripcion.estado', $actor, 'preinscripciones', (string) $ficha['radicado'], $estado
        );

        Response::ok(['estado' => $estado, 'motivo' => $estado === 'DESCARTADA' ? $motivo : null]);
    }

    /** El `envio_id` que manda el navegador, si viene con la forma esperada. */
    private function envioId(Request $req): ?string
    {
        $valor = $req->texto('envio_id');

        return preg_match('/^[a-f0-9-]{16,40}$/i', $valor) === 1 ? $valor : null;
    }
}
