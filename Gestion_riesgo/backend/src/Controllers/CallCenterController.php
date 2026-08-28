<?php

declare(strict_types=1);

namespace App\Controllers;

use App\CallCenter\Guion;
use App\CallCenter\Whatsapp;
use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Rufe\Catalogos;

/**
 * Call center: llevar a la gente del RUFE hasta la preinscripción.
 *
 * El enlace del formulario ciudadano se le manda a quien YA está en la base del
 * censo y tiene que continuar el proceso. Eso no es un gesto suelto: es una
 * campaña de llamadas, una por hogar. Sin dónde anotarlas, el turno de la tarde
 * vuelve a llamar a quien ya atendió el de la mañana.
 *
 * ── Lo que este controlador NO devuelve ──────────────────────────────────────
 *
 * Nada del expediente. Ni evidencias, ni observaciones, ni las cédulas del resto
 * del hogar, ni la dirección exacta. Solo lo que hace falta para marcar un
 * número y saber con quién se habla: nombre del jefe de hogar, teléfono, barrio
 * y zona. El rol OPERADOR existe justamente para no tener que abrir el censo
 * entero a alguien contratado para llamar por teléfono.
 */
final class CallCenterController
{
    private const POR_PAGINA = 40;
    private const MAX_POR_PAGINA = 200;

    /** Cuántos días se dejan de margen antes de dar por perdido un teléfono. */
    private const MAX_INTENTOS_UTILES = 5;

    /**
     * Cuánto dura el aviso de «la está atendiendo Marcela».
     *
     * La pantalla lo refresca cada minuto mientras la operadora tenga el hogar
     * abierto, así que este número es el margen para que un aviso no se quede
     * pegado cuando alguien cierra el navegador de golpe o se le va la luz.
     * Corto de más, el aviso parpadea durante una llamada larga; largo de más,
     * un hogar parece ocupado media hora después de que nadie lo mire.
     */
    private const MINUTOS_ATENCION = 6;

    /**
     * Por qué el ingeniero descartó una solicitud, y qué hace el call center.
     *
     * Los dos primeros motivos son subsanables: la familia SÍ tiene que volver
     * a recibir una llamada, y con algo concreto que decirle. El tercero no: es
     * la única forma de sacar a alguien de la campaña sin que el sistema vuelva
     * a ponerlo en la cola al día siguiente.
     *
     * @var array<string,array{etiqueta:string,llamar:bool,decirle:string}>
     */
    public const MOTIVOS_DESCARTE = [
        'DATOS_INCOMPLETOS' => [
            'etiqueta' => 'Faltaron datos',
            'llamar' => true,
            'decirle' => 'Le faltaron datos en el formulario. Hay que pedirle que vuelva a entrar y los complete.',
        ],
        'FALTA_EVIDENCIA' => [
            'etiqueta' => 'Faltó evidencia',
            'llamar' => true,
            'decirle' => 'Le faltaron fotos o videos de la vivienda. Hay que pedirle que vuelva a entrar y los suba.',
        ],
        'NO_APLICA' => [
            'etiqueta' => 'No aplica',
            'llamar' => false,
            'decirle' => 'El ingeniero determinó que el caso no aplica. No hay que volver a llamar.',
        ],
    ];

    /**
     * En qué situación está cada hogar frente a la preinscripción, en SQL.
     *
     * Se escriben una vez y se usan en la lista y en el resumen. Cuando estaban
     * copiadas en los dos sitios, la cifra de avance y la lista podían discrepar
     * —y es la cifra la que se le reporta a la Alcaldía—.
     *
     * `motivo_descarte IS NULL` cuenta como subsanable a propósito: son las
     * solicitudes descartadas antes de que existiera la columna, y no se les
     * puede inventar un motivo. Volver a llamar a quien no hacía falta cuesta
     * una llamada; dejar de llamar a quien sí, lo deja fuera de la ayuda.
     */
    private const PRE_ACTIVA = "pre.id IS NOT NULL AND pre.estado <> 'DESCARTADA'";

    private const PRE_SUBSANABLE = "pre.id IS NOT NULL AND pre.estado = 'DESCARTADA'
                                    AND (pre.motivo_descarte IS NULL OR pre.motivo_descarte <> 'NO_APLICA')";

    private const PRE_NO_APLICA = "pre.id IS NOT NULL AND pre.estado = 'DESCARTADA'
                                   AND pre.motivo_descarte = 'NO_APLICA'";

    /** Ni se preinscribió, ni tiene una solicitud descartada esperando. */
    private const SIN_PRE = 'pre.id IS NULL';

    /**
     * Cómo se responde una llamada, y qué significa cada respuesta.
     *
     * `YA_DILIGENCIO` es lo que DICE la persona. Que lo haya hecho de verdad lo
     * decide el cruce por cédula, no esta columna: mucha gente cree haberlo
     * terminado cuando cerró el navegador a mitad.
     *
     * @var array<string,string>
     */
    public const RESULTADOS = [
        'CONTACTADO'      => 'Se le explicó el formulario',
        'VOLVER_A_LLAMAR' => 'Pidió que se le llame después',
        'NO_CONTESTA'     => 'No contestó',
        'NUMERO_ERRADO'   => 'El número no corresponde',
        'NO_INTERESA'     => 'No quiere continuar',
        'YA_DILIGENCIO'   => 'Dice que ya lo diligenció',
        // Los dos de WhatsApp no los elige la operadora en el formulario de la
        // llamada: los escribe `enviarWhatsapp()`. Están aquí para que el
        // historial sepa nombrarlos.
        'WHATSAPP_ENVIADO' => 'Se le envió el formulario por WhatsApp',
        'WHATSAPP_FALLIDO' => 'No se pudo enviar el WhatsApp',
    ];

    /** Los resultados que una operadora puede registrar a mano. */
    public const RESULTADOS_DE_LLAMADA = [
        'CONTACTADO', 'VOLVER_A_LLAMAR', 'NO_CONTESTA',
        'NUMERO_ERRADO', 'NO_INTERESA', 'YA_DILIGENCIO',
    ];

    /**
     * Horas que deben pasar antes de volver a mandarle WhatsApp al mismo hogar.
     *
     * No es una preferencia de estilo. Son familias que acaban de perder parte
     * de su casa: recibir tres veces el mismo mensaje automático de la Alcaldía
     * es maltrato. Y con tres operadoras trabajando la misma lista, sin este
     * freno pasa el primer día.
     */
    private const HORAS_ENTRE_WHATSAPP = 24;

    /**
     * El cruce con las preinscripciones, que es el corazón del módulo.
     *
     * Se compara la cédula del jefe de hogar del censo contra la de la
     * preinscripción. Funciona sin normalizar nada porque las dos entran ya
     * limpias: `Preinscripcion\Validador` guarda el documento con
     * `preg_replace('/\D+/','')` y `Rufe\Validador` exige `^\d{4,30}$` para las
     * cédulas.
     *
     * Las descartadas no cuentan: una solicitud descartada es una persona que
     * sigue sin estar en el proceso, y volver a llamarla es lo correcto.
     *
     * ── Por qué son subconsultas y no un JOIN directo ────────────────────────
     *
     * Porque un JOIN multiplica filas, y aquí las dos puntas pueden repetirse:
     * una misma persona puede pre-inscribirse más de una vez —el esquema lo
     * permite a propósito, «una casa puede pre-inscribirse otra vez tras otro
     * evento»— y una ficha del censo podría traer dos personas marcadas como
     * jefe de hogar.
     *
     * Con JOIN, un hogar con dos preinscripciones salía DOS VECES en la lista y
     * se contaba DOS VECES en el resumen. Lo segundo es lo grave: la cifra de
     * avance de la campaña es un dato que se le reporta a la Alcaldía, y estaba
     * inflada sin que nada lo delatara. Lo primero se notó porque la pantalla se
     * quedaba cargando —dos filas con la misma clave rompen el dibujado—, que
     * fue la suerte de este fallo.
     *
     * Con `pre.id = (SELECT … LIMIT 1)` cada hogar aporta UNA fila, siempre.
     */
    private const CRUCE = '
        LEFT JOIN rufe_personas jefe
               ON jefe.id = (SELECT j2.id FROM rufe_personas j2
                              WHERE j2.reporte_id = r.id AND j2.parentesco = :jefe
                              ORDER BY j2.orden ASC LIMIT 1)
        LEFT JOIN preinscripciones pre
               ON pre.id = (SELECT p2.id FROM preinscripciones p2
                             WHERE p2.documento = jefe.numero_documento
                               AND jefe.numero_documento IS NOT NULL
                             ORDER BY p2.creado_en DESC, p2.id DESC LIMIT 1)
        LEFT JOIN rufe_gestiones g
               ON g.id = (SELECT g2.id FROM rufe_gestiones g2
                           WHERE g2.reporte_id = r.id AND g2.canal = \'LLAMADA\'
                           ORDER BY g2.creado_en DESC, g2.id DESC LIMIT 1)
        LEFT JOIN rufe_atenciones aten
               ON aten.reporte_id = r.id
              AND aten.actualizado_en > (NOW() - INTERVAL '.self::MINUTOS_ATENCION.' MINUTE)
    ';

    public function listar(Request $req): void
    {
        $estado = (string) ($req->query('estado') ?? 'pendiente');
        $pagina = max(1, (int) ($req->query('pagina') ?? '1'));
        $porPagina = min(
            self::MAX_POR_PAGINA,
            max(1, (int) ($req->query('por_pagina') ?? (string) self::POR_PAGINA))
        );

        [$filtro, $params] = $this->filtros($req, $estado);

        $total = (int) (Db::first(
            'SELECT COUNT(*) AS t FROM rufe_reportes r '.self::CRUCE.$filtro,
            $params
        )['t'] ?? 0);

        $filas = Db::all(
            'SELECT r.id, r.radicado, r.zona, r.corregimiento, r.vereda_sector_barrio,
                    r.fecha_evento, r.contacto_telefono,
                    jefe.nombres AS jefe_nombres, jefe.apellidos AS jefe_apellidos,
                    jefe.telefono AS jefe_telefono,
                    pre.radicado AS preinscripcion_radicado,
                    pre.creado_en AS preinscripcion_en,
                    pre.estado AS preinscripcion_estado,
                    pre.motivo_descarte AS preinscripcion_motivo,
                    aten.usuario_nombre AS atendida_por,
                    aten.usuario_id AS atendida_por_id,
                    aten.actualizado_en AS atendida_en,
                    g.resultado AS ultimo_resultado, g.creado_en AS ultimo_en,
                    g.proxima_llamada, g.nota AS ultima_nota, g.usuario_email AS ultimo_por,
                    (SELECT COUNT(*) FROM rufe_gestiones gc
                      WHERE gc.reporte_id = r.id AND gc.canal = \'LLAMADA\') AS intentos
               FROM rufe_reportes r '.self::CRUCE.$filtro.'
              ORDER BY '.$this->orden($estado).'
              LIMIT :limite OFFSET :salto',
            $params + ['limite' => $porPagina, 'salto' => ($pagina - 1) * $porPagina]
        );

        // Abrir la lista es ver nombres y teléfonos de hogares damnificados.
        // Queda constancia de quién lo hizo, igual que en la bandeja del censo.
        Auditoria::registrar(
            $req,
            'callcenter.lista',
            Auth::exigirUsuario($req),
            'rufe_reportes',
            null,
            $estado.': '.$total.' hogares'
        );

        Response::ok([
            'hogares' => array_map([$this, 'presentar'], $filas),
            'paginacion' => [
                'pagina'     => $pagina,
                'por_pagina' => $porPagina,
                'total'      => $total,
                'paginas'    => (int) ceil($total / $porPagina),
            ],
            'resultados' => self::RESULTADOS,
        ]);
    }

    /**
     * Las cifras de avance de la campaña.
     *
     * Van en una petición aparte de la lista porque no dependen de la página ni
     * del filtro: son el estado de TODO el censo, y cambiarían al pasar de
     * página si viajaran con ella.
     */
    public function resumen(Request $req): void
    {
        $fila = Db::first(
            'SELECT
                COUNT(*) AS total,
                SUM('.self::PRE_ACTIVA.') AS preinscritos,
                SUM('.self::PRE_SUBSANABLE.') AS por_subsanar,
                SUM('.self::PRE_NO_APLICA.') AS no_aplica,
                SUM('.self::SIN_PRE.' AND g.id IS NOT NULL) AS contactados_sin_preinscribir,
                SUM('.self::SIN_PRE.' AND g.id IS NULL) AS sin_llamar,
                SUM('.self::SIN_PRE.' AND g.proxima_llamada IS NOT NULL
                    AND g.proxima_llamada <= CURDATE()) AS para_hoy,
                SUM((r.contacto_telefono IS NULL OR r.contacto_telefono = \'\')
                    AND (jefe.telefono IS NULL OR jefe.telefono = \'\')) AS sin_telefono
               FROM rufe_reportes r '.self::CRUCE,
            ['jefe' => Catalogos::PARENTESCO_JEFE]
        ) ?? [];

        Response::ok([
            'resumen' => [
                'total'                        => (int) ($fila['total'] ?? 0),
                'preinscritos'                 => (int) ($fila['preinscritos'] ?? 0),
                'por_subsanar'                 => (int) ($fila['por_subsanar'] ?? 0),
                'no_aplica'                    => (int) ($fila['no_aplica'] ?? 0),
                'contactados_sin_preinscribir' => (int) ($fila['contactados_sin_preinscribir'] ?? 0),
                'sin_llamar'                   => (int) ($fila['sin_llamar'] ?? 0),
                'para_hoy'                     => (int) ($fila['para_hoy'] ?? 0),
                'sin_telefono'                 => (int) ($fila['sin_telefono'] ?? 0),
            ],
        ]);
    }

    /** El historial de llamadas de un hogar. Del más reciente al más antiguo. */
    public function historial(Request $req): void
    {
        $id = (int) $req->param('id');
        $this->exigirHogar($id);

        Response::ok([
            'gestiones' => Db::all(
                'SELECT id, canal, resultado, nota, proxima_llamada, enlace_enviado,
                        usuario_email, creado_en
                   FROM rufe_gestiones
                  WHERE reporte_id = :r
                  ORDER BY creado_en DESC, id DESC
                  LIMIT 50',
                ['r' => $id]
            ),
        ]);
    }

    /**
     * Anota lo que pasó en una llamada.
     *
     * NO toca `rufe_reportes`. Una campaña de llamadas no cambia el estado de
     * una ficha del censo: son dos procesos distintos y confundirlos haría que
     * llamar a alguien pareciera haber validado su expediente.
     */
    public function registrar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $this->exigirHogar($id);

        $resultado = strtoupper(trim($req->texto('resultado')));
        $errores = [];

        // Solo los de llamada. Los dos de WhatsApp los escribe
        // `enviarWhatsapp()` cuando el proveedor confirma; aceptarlos aquí
        // dejaría marcar como enviado un mensaje que nunca salió, y el hogar
        // quedaría esperando un enlace que nadie le mandó.
        if (! in_array($resultado, self::RESULTADOS_DE_LLAMADA, true)) {
            $errores['resultado'] = 'Indique cómo terminó la llamada.';
        }

        $proxima = trim($req->texto('proxima_llamada', ''));
        if ($proxima !== '' && ! $this->esFecha($proxima)) {
            $errores['proxima_llamada'] = 'Revise la fecha.';
        }

        // Sin fecha, «volver a llamar» es una intención que nadie recoge: el
        // hogar se queda fuera de «falta llamar» y fuera de «para hoy».
        if ($resultado === 'VOLVER_A_LLAMAR' && $proxima === '') {
            $errores['proxima_llamada'] = 'Indique cuándo volver a llamar.';
        }

        if ($errores !== []) {
            throw HttpError::validacion($errores);
        }

        Db::exec(
            'INSERT INTO rufe_gestiones
                (reporte_id, resultado, nota, proxima_llamada, enlace_enviado,
                 usuario_id, usuario_email)
             VALUES (:r, :res, :nota, :prox, :enlace, :uid, :email)',
            [
                'r'      => $id,
                'res'    => $resultado,
                'nota'   => ($n = mb_substr(trim($req->texto('nota', '')), 0, 500)) === '' ? null : $n,
                'prox'   => $proxima === '' ? null : $proxima,
                'enlace' => $req->input('enlace_enviado', false) ? 1 : 0,
                'uid'    => $actor['id'],
                'email'  => $actor['email'],
            ]
        );

        Auditoria::registrar($req, 'callcenter.gestion', $actor, 'rufe_gestiones', (string) $id, $resultado);

        Response::ok(['gestion' => ['id' => Db::lastId(), 'resultado' => $resultado]], 201);
    }

    /**
     * Le manda a este hogar, por WhatsApp, el enlace del formulario.
     *
     * Un botón, un hogar, una pulsación. No existe versión masiva a propósito:
     * mandarle a mil trescientas familias un mensaje automático sin que nadie
     * mire caso por caso es la clase de decisión que no debe caber en un clic.
     *
     * Manda la PLANTILLA aprobada, no texto libre — ver `CallCenter\Whatsapp`
     * para por qué WhatsApp no permite otra cosa con quien no te ha escrito.
     *
     * Registra SIEMPRE la gestión, salga bien o mal. Un envío que falla y no
     * deja rastro hace que la siguiente operadora lo repita sin saber que ya
     * falló, y que nadie se entere de que ese número está mal.
     */
    public function enviarWhatsapp(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $this->exigirHogar($id);

        // Con el token vacío esta función no existe. 503 y no 500: no es un
        // fallo, es que falta configurarla.
        if (! Whatsapp::configurado()) {
            throw new HttpError('El envío por WhatsApp no está configurado en este servidor.', 503);
        }

        $hogar = Db::first(
            'SELECT r.id, r.contacto_telefono,
                    jefe.nombres AS jefe_nombres, jefe.apellidos AS jefe_apellidos,
                    jefe.telefono AS jefe_telefono
               FROM rufe_reportes r
               LEFT JOIN rufe_personas jefe
                      ON jefe.id = (SELECT j2.id FROM rufe_personas j2
                                     WHERE j2.reporte_id = r.id AND j2.parentesco = :jefe
                                     ORDER BY j2.orden ASC LIMIT 1)
              WHERE r.id = :i',
            ['i' => $id, 'jefe' => Catalogos::PARENTESCO_JEFE]
        );

        // El del jefe de hogar primero: es la persona a la que va dirigido el
        // mensaje. El de contacto puede ser el de un vecino que reportó.
        $telefono = Whatsapp::normalizarTelefono($hogar['jefe_telefono'] ?? null)
            ?? Whatsapp::normalizarTelefono($hogar['contacto_telefono'] ?? null);

        if ($telefono === null) {
            throw HttpError::validacion([
                'telefono' => 'Este hogar no tiene un número de celular al que enviarle WhatsApp.',
            ]);
        }

        // ── No repetir ───────────────────────────────────────────────────
        $ultimo = Db::first(
            'SELECT creado_en FROM rufe_gestiones
              WHERE reporte_id = :r AND canal = :c AND resultado = :res
                AND creado_en > (NOW() - INTERVAL '.self::HORAS_ENTRE_WHATSAPP.' HOUR)
              ORDER BY creado_en DESC LIMIT 1',
            ['r' => $id, 'c' => 'WHATSAPP', 'res' => 'WHATSAPP_ENVIADO']
        );

        if ($ultimo !== null) {
            throw new HttpError(
                'A este hogar ya se le envió el WhatsApp el '.$ultimo['creado_en'].'. '
                .'Espere '.self::HORAS_ENTRE_WHATSAPP.' horas antes de repetirlo.',
                409
            );
        }

        $nombre = Whatsapp::nombreParaSaludo($hogar['jefe_nombres'] ?? null, $hogar['jefe_apellidos'] ?? null);
        $envio = Whatsapp::enviar(Whatsapp::cuerpoDelMensaje($telefono, $nombre, $id));

        Db::exec(
            'INSERT INTO rufe_gestiones
                (reporte_id, canal, resultado, nota, enlace_enviado, usuario_id, usuario_email)
             VALUES (:r, :c, :res, :nota, :enlace, :uid, :email)',
            [
                'r' => $id,
                'c' => 'WHATSAPP',
                'res' => $envio['ok'] ? 'WHATSAPP_ENVIADO' : 'WHATSAPP_FALLIDO',
                // El motivo del fallo se guarda para que se vea en el historial.
                // El teléfono NO: ya está en la ficha del hogar y repetirlo aquí
                // lo esparce por una tabla más.
                'nota' => $envio['ok'] ? null : mb_substr((string) $envio['error'], 0, 500),
                'enlace' => $envio['ok'] ? 1 : 0,
                'uid' => $actor['id'],
                'email' => $actor['email'],
            ]
        );

        Auditoria::registrar(
            $req, 'callcenter.whatsapp', $actor, 'rufe_gestiones', (string) $id,
            $envio['ok'] ? 'WHATSAPP_ENVIADO' : 'WHATSAPP_FALLIDO'
        );

        if (! $envio['ok']) {
            // 502 y no 500: el fallo es del proveedor, no de este sistema. La
            // gestión ya quedó registrada arriba.
            throw new HttpError('No se pudo enviar el WhatsApp: '.$envio['error'], 502);
        }

        Response::ok([
            'enviado' => true,
            'telefono' => $telefono,
            'nombre' => $nombre,
        ]);
    }

    /**
     * «Estoy llamando a este hogar».
     *
     * Tres operadoras abren la misma pestaña, ordenada igual para las tres, y
     * las tres ven el mismo hogar de primero. Sin esto, la primera familia de
     * la lista recibe tres llamadas seguidas de la Alcaldía diciéndole lo
     * mismo.
     *
     * Es un AVISO y no una reserva. La fila sigue siendo de quien quiera
     * tomarla; lo único que cambia es que las otras dos ven quién está en ella.
     * Se decidió así: una reserva que alguien se olvide de soltar congela
     * hogares que nadie puede llamar, y con un equipo de tres personas que se
     * ven la cara, el remedio sale más caro que la enfermedad.
     *
     * No queda en auditoría. Abrir una ficha para llamarla ya se audita al
     * listar, y esto se manda cada minuto mientras el panel esté abierto:
     * registrarlo llenaría la auditoría de ruido y taparía lo que sí importa.
     */
    public function atender(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $id = (int) $req->param('id');
        $this->exigirHogar($id);

        if ($req->input('soltar', false)) {
            Db::exec('DELETE FROM rufe_atenciones WHERE reporte_id = :r AND usuario_id = :u',
                ['r' => $id, 'u' => $actor['id']]);

            Response::ok(['atendiendo' => false]);

            return;
        }

        // Con la clave primaria en `reporte_id`, esto es a la vez «tomarlo» y
        // «sigo aquí»: la segunda operadora que lo abra desplaza el aviso, que
        // es lo correcto cuando la primera ya colgó y se fue a otra cosa.
        Db::exec(
            'INSERT INTO rufe_atenciones (reporte_id, usuario_id, usuario_email, usuario_nombre)
             VALUES (:r, :u, :e, :n)
             ON DUPLICATE KEY UPDATE
                usuario_id = VALUES(usuario_id),
                usuario_email = VALUES(usuario_email),
                usuario_nombre = VALUES(usuario_nombre),
                actualizado_en = NOW()',
            [
                'r' => $id,
                'u' => $actor['id'],
                'e' => $actor['email'],
                'n' => $actor['nombre'] ?? $actor['email'],
            ]
        );

        Response::ok(['atendiendo' => true, 'minutos' => self::MINUTOS_ATENCION]);
    }

    /**
     * Quién está atendiendo qué, ahora mismo.
     *
     * Va aparte de la lista y devuelve solo ids y nombres de operadora: la
     * pantalla lo pide cada pocos segundos, y recargar la lista entera para
     * refrescar un aviso borraría lo que otra persona esté escribiendo en el
     * formulario de anotación.
     */
    public function atenciones(Request $req): void
    {
        Auth::exigirUsuario($req);

        Response::ok([
            'atenciones' => Db::all(
                'SELECT reporte_id, usuario_id, usuario_nombre, actualizado_en
                   FROM rufe_atenciones
                  WHERE actualizado_en > (NOW() - INTERVAL :m MINUTE)',
                ['m' => self::MINUTOS_ATENCION]
            ),
            'minutos' => self::MINUTOS_ATENCION,
        ]);
    }

    /** El guión que la operadora tiene delante todo el turno. */
    public function guion(Request $req): void
    {
        Auth::exigirUsuario($req);

        Response::ok([
            'guion' => Guion::vigente(),
            'predeterminado' => Guion::PREDETERMINADO,
        ]);
    }

    /**
     * Reescribe el guión. Solo el administrador, y guardando la versión vieja.
     *
     * Un guión es lo que la Alcaldía le dice por teléfono a mil trescientas
     * familias. Cambiarlo sin dejar constancia de qué decía antes y desde
     * cuándo hace imposible responder la única pregunta que importa cuando algo
     * sale mal: «¿qué le dijeron exactamente a esta señora?».
     */
    public function guardarGuion(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);
        $cuerpo = trim($req->texto('cuerpo', ''));

        if (mb_strlen($cuerpo) < 40) {
            throw HttpError::validacion([
                'cuerpo' => 'El guión no puede quedar vacío. Si quiere volver al original, use «Restaurar el guión de la Alcaldía».',
            ]);
        }

        if (mb_strlen($cuerpo) > Guion::MAX_LARGO) {
            throw HttpError::validacion([
                'cuerpo' => 'El guión pasa de '.Guion::MAX_LARGO.' caracteres. Así de largo nadie lo lee durante una llamada.',
            ]);
        }

        Guion::guardar($cuerpo, $actor);

        Auditoria::registrar($req, 'callcenter.guion', $actor, 'callcenter_guion', null, 'Se reescribió el guión');

        Response::ok(['guion' => Guion::vigente()]);
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function exigirHogar(int $id): void
    {
        if ($id <= 0 || Db::first('SELECT id FROM rufe_reportes WHERE id = :i', ['i' => $id]) === null) {
            throw HttpError::noEncontrado('No existe ese hogar en el censo.');
        }
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filtros(Request $req, string $estado): array
    {
        $where = ['1 = 1'];
        $params = ['jefe' => Catalogos::PARENTESCO_JEFE];

        switch ($estado) {
            case 'preinscrito':
                $where[] = self::PRE_ACTIVA;
                break;
            case 'subsanar':
                // El ingeniero la descartó por algo que se arregla. Es la cola
                // más urgente de todas: son familias que ya hicieron el
                // esfuerzo de llenar el formulario y se quedaron a un paso.
                $where[] = self::PRE_SUBSANABLE;
                break;
            case 'no_aplica':
                $where[] = self::PRE_NO_APLICA;
                break;
            case 'contactado':
                $where[] = self::SIN_PRE.' AND g.id IS NOT NULL';
                break;
            case 'reintentar':
                $where[] = self::SIN_PRE.' AND g.proxima_llamada IS NOT NULL AND g.proxima_llamada <= CURDATE()';
                break;
            case 'todos':
                break;
            case 'pendiente':
            default:
                // Lo que falta por hacer: nadie lo ha llamado y no tiene
                // ninguna solicitud, ni buena ni descartada.
                $where[] = self::SIN_PRE.' AND g.id IS NULL';
                break;
        }

        $q = trim((string) ($req->query('q') ?? ''));
        if ($q !== '') {
            $where[] = "(CONCAT_WS(' ', jefe.nombres, jefe.apellidos) LIKE :q
                         OR r.contacto_telefono LIKE :q
                         OR jefe.telefono LIKE :q
                         OR r.radicado LIKE :q)";
            $params['q'] = '%'.$q.'%';
        }

        $zona = strtoupper(trim((string) ($req->query('zona') ?? '')));
        if ($zona === 'URBANO' || $zona === 'RURAL') {
            $where[] = 'r.zona = :zona';
            $params['zona'] = $zona;
        }

        $barrio = trim((string) ($req->query('barrio') ?? ''));
        if ($barrio !== '') {
            $where[] = 'r.vereda_sector_barrio = :barrio';
            $params['barrio'] = $barrio;
        }

        return [' WHERE '.implode(' AND ', $where), $params];
    }

    /**
     * En qué orden se atiende cada lista.
     *
     * No es el mismo para todas: en «falta llamar» lo urgente es lo más
     * reciente —una familia recién censada espera respuesta—, y en «volver a
     * llamar» lo urgente es lo que lleva más tiempo esperando.
     */
    private function orden(string $estado): string
    {
        return match ($estado) {
            'reintentar' => 'g.proxima_llamada ASC, r.creado_en ASC',
            'contactado' => 'g.creado_en DESC',
            'preinscrito' => 'pre.creado_en DESC',
            // Lo que lleva más tiempo descartado va primero: una familia que
            // espera desde hace una semana a que le digan qué le faltó es más
            // urgente que una descartada esta mañana.
            'subsanar' => 'pre.actualizado_en ASC',
            'no_aplica' => 'pre.actualizado_en DESC',
            default => 'r.creado_en DESC',
        };
    }

    private function esFecha(string $valor): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $d !== false && $d->format('Y-m-d') === $valor;
    }

    /**
     * @param  array<string,mixed>  $f
     * @return array<string,mixed>
     */
    private function presentar(array $f): array
    {
        $telefono = trim((string) ($f['contacto_telefono'] ?? '')) !== ''
            ? (string) $f['contacto_telefono']
            : (string) ($f['jefe_telefono'] ?? '');

        $nombre = trim(($f['jefe_nombres'] ?? '').' '.($f['jefe_apellidos'] ?? ''));

        $descarte = null;

        if (($f['preinscripcion_estado'] ?? null) === 'DESCARTADA') {
            $motivo = $f['preinscripcion_motivo'] === null ? null : (string) $f['preinscripcion_motivo'];

            // Sin motivo son las descartadas anteriores a que existiera la
            // columna. Se dice que no se sabe, y se llama: es lo prudente.
            $descarte = self::MOTIVOS_DESCARTE[$motivo] ?? [
                'etiqueta' => 'Descartada sin motivo anotado',
                'llamar' => true,
                'decirle' => 'Se descartó antes de que el sistema pidiera el motivo. Confirme con el ingeniero antes de llamar.',
            ];
            $descarte['motivo'] = $motivo;
        }

        return [
            'id'       => (int) $f['id'],
            'radicado' => $f['radicado'],
            // Sin jefe de hogar registrado la ficha sigue existiendo; se dice,
            // no se inventa un nombre.
            'nombre'   => $nombre !== '' ? $nombre : null,
            'telefono' => $telefono !== '' ? $telefono : null,
            'zona'     => $f['zona'],
            'lugar'    => trim(implode(' · ', array_filter([
                $f['corregimiento'] ?? null,
                $f['vereda_sector_barrio'] ?? null,
            ]))),
            'fecha_evento' => $f['fecha_evento'],
            // «Preinscrita» significa que su solicitud sigue viva. Una
            // descartada NO lo está, y llamarla igual que a las demás fue
            // exactamente el problema que este módulo tenía.
            'preinscrita'  => $f['preinscripcion_radicado'] !== null
                              && $f['preinscripcion_estado'] !== 'DESCARTADA',
            'preinscripcion' => $f['preinscripcion_radicado'] === null ? null : [
                'radicado'  => $f['preinscripcion_radicado'],
                'creado_en' => $f['preinscripcion_en'],
                'estado'    => $f['preinscripcion_estado'],
            ],
            // Lo que el ingeniero decidió, escrito para que la operadora lo
            // pueda leer en voz alta sin traducirlo.
            'descarte' => $descarte,
            // La única razón para NO marcar este número. Va como un campo
            // aparte y no deducido en la pantalla: si mañana se añade otro
            // motivo, se decide aquí y no en cinco sitios.
            'no_llamar' => $descarte !== null && $descarte['llamar'] === false,
            // Quién lo tiene abierto ahora mismo. Es un aviso entre las tres
            // operadoras, no una reserva: la fila sigue estando disponible.
            'atendida' => $f['atendida_por'] === null ? null : [
                'quien' => (string) $f['atendida_por'],
                'usuario_id' => $f['atendida_por_id'] === null ? null : (int) $f['atendida_por_id'],
                'desde' => $f['atendida_en'],
            ],
            'intentos' => (int) $f['intentos'],
            'agotado'  => (int) $f['intentos'] >= self::MAX_INTENTOS_UTILES,
            'ultima'   => $f['ultimo_resultado'] === null ? null : [
                'resultado'  => $f['ultimo_resultado'],
                'etiqueta'   => self::RESULTADOS[$f['ultimo_resultado']] ?? $f['ultimo_resultado'],
                'creado_en'  => $f['ultimo_en'],
                'nota'       => $f['ultima_nota'],
                'por'        => $f['ultimo_por'],
            ],
            'proxima_llamada' => $f['proxima_llamada'],
        ];
    }
}
