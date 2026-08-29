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
     * Cuántas cifras hay que escribir antes de buscar por número.
     *
     * Con una sola, «1» casaría con casi todos los teléfonos y todas las cédulas
     * del censo: la primera tecla llenaría la pantalla de ruido y la operadora
     * tendría que esperar a que se dibujaran mil filas para nada. Con tres ya se
     * parece a algo que alguien busca de verdad.
     *
     * Por debajo de eso no se deja de buscar: se busca por nombre y por
     * radicado, que es lo que un texto tan corto puede querer decir.
     */
    private const MIN_DIGITOS_BUSQUEDA = 3;

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

    /**
     * Terminó: tiene la inspección de vivienda APROBADA.
     *
     * ── Por qué la campaña termina aquí y no en el formulario ────────────────
     *
     * Antes, un hogar salía de la cola de llamadas en cuanto se preinscribía, y
     * eso medía lo que no era. Estar en el RUFE es el REQUISITO para que le
     * hagan la inspección; llenar el formulario es pedir el turno. Ninguna de
     * las dos cosas es haber recibido ayuda.
     *
     * A quien se preinscribió y sigue esperando al ingeniero todavía le puede
     * pasar de todo —que le falte evidencia, que no lo encuentren en la
     * dirección, que se le venza el plazo— y dejarlo fuera de la cola era
     * dejarlo sin nadie que lo acompañe justo en la mitad del trámite. Los que
     * no hay que llamar son los que ya tienen la inspección aprobada.
     *
     * ── Y por qué también se cruza por cédula ────────────────────────────────
     *
     * `rufe_reporte_id` admite nulos: una inspección capturada sin enlazar a su
     * ficha del censo existiría sin que este cruce la viera, y esa familia
     * seguiría recibiendo llamadas después de terminar. Se mira también el
     * documento del propietario contra el del jefe de hogar, igual que ya se
     * hace con la preinscripción.
     */
    private const TERMINADO = 'insp.id IS NOT NULL';

    /** Todavía no ha terminado. */
    private const SIN_TERMINAR = 'insp.id IS NULL';

    /**
     * Fuera de la campaña de llamadas.
     *
     * Dos motivos, y solo dos: ya terminó, o el ingeniero dictaminó que no
     * aplica. `COALESCE` y no `pre.motivo_descarte <> ...` porque en SQL la
     * negación de un NULL es NULL, y esa fila desaparecería de todas las listas
     * sin que nadie lo notara.
     */
    private const NO_APLICA_YA = "pre.id IS NOT NULL AND pre.estado = 'DESCARTADA'
                                  AND COALESCE(pre.motivo_descarte, '') = 'NO_APLICA'";

    /** Sigue habiendo algo que hacer con este hogar. */
    private const EN_CAMPANA = 'insp.id IS NULL AND NOT ('.self::NO_APLICA_YA.')';

    /**
     * Por teléfono no se llega a esta familia.
     *
     * Ni el contacto de la ficha ni el del jefe de hogar tienen número. Se mira
     * el vacío además del nulo porque el censo se levantó a mano y una casilla
     * que el funcionario dejó en blanco llega como cadena vacía, no como NULL.
     *
     * Es una constante y no una expresión escrita dentro del resumen —donde
     * estaba— porque ahora la usan dos consultas: la que cuenta la tarjeta y la
     * que lista la cola. Si fueran dos copias, el día que alguien ajuste una la
     * tarjeta empezaría a prometer un número que la lista no da, y eso es lo
     * que hace que una operadora deje de creerle al tablero.
     */
    private const SIN_TELEFONO = "(r.contacto_telefono IS NULL OR r.contacto_telefono = '')
                                  AND (jefe.telefono IS NULL OR jefe.telefono = '')";

    /**
     * Cada cola de la lista con la condición que la define.
     *
     * ── Por qué una tabla y no un `switch` ───────────────────────────────────
     *
     * Porque las tarjetas del resumen son clicables: cada cifra promete que al
     * pulsarla saldrán ESOS hogares. Mientras el resumen sumaba por un lado y
     * el filtro decidía por otro, nada impedía que se separaran; solo coincidían
     * porque quien las escribió puso cuidado.
     *
     * Ahora las dos consultas se construyen desde aquí. No pueden discrepar
     * porque no hay dos sitios.
     *
     * Es `public` para que una prueba pueda recorrerla: ver `COLA_DE_CIFRA`.
     *
     * @var array<string,string>
     */
    public const CONDICION_DE_COLA = [
        'todos'        => '1 = 1',
        'terminado'    => self::TERMINADO,
        'preinscrito'  => self::PRE_ACTIVA.' AND '.self::SIN_TERMINAR,
        'subsanar'     => self::PRE_SUBSANABLE.' AND '.self::SIN_TERMINAR,
        'no_aplica'    => self::PRE_NO_APLICA,
        'contactado'   => self::EN_CAMPANA.' AND g.id IS NOT NULL',
        'reintentar'   => self::EN_CAMPANA.' AND g.proxima_llamada IS NOT NULL
                          AND g.proxima_llamada <= CURDATE()',
        'pendiente'    => self::EN_CAMPANA.' AND g.id IS NULL',
        'sin_telefono' => self::SIN_TELEFONO,
    ];

    /**
     * Qué cola contiene exactamente a la gente que cuenta cada tarjeta.
     *
     * Es el contrato de la pantalla: pulsar una tarjeta abre su cola, y el
     * conteo de la lista tiene que dar el número que la tarjeta prometió.
     *
     * Toda cifra del resumen sale de aquí, así que una cifra nueva sin cola no
     * se puede añadir por descuido: no habría de dónde sacarla.
     *
     * @var array<string,string>
     */
    public const COLA_DE_CIFRA = [
        'total'                        => 'todos',
        'terminados'                   => 'terminado',
        'preinscritos'                 => 'preinscrito',
        'por_subsanar'                 => 'subsanar',
        'no_aplica'                    => 'no_aplica',
        'contactados_sin_preinscribir' => 'contactado',
        'sin_llamar'                   => 'pendiente',
        'para_hoy'                     => 'reintentar',
        'sin_telefono'                 => 'sin_telefono',
    ];

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
     * A partir de cuántas horas repetir el WhatsApp deja de necesitar aviso.
     *
     * No es un muro: es el punto en que el sistema pregunta «ya se le envió
     * hace un rato, ¿seguro?». La operadora puede repetirlo igual, porque hay
     * motivos buenos —el primero no llegó, la persona lo borró, cambió de
     * teléfono— y quien tiene a la familia al aparato sabe cuál es el caso.
     *
     * Antes era un bloqueo duro de 24 horas. La razón escrita entonces sigue
     * siendo cierta y por eso el aviso se queda: son familias que acaban de
     * perder parte de su casa, y recibir tres veces el mismo mensaje automático
     * de la Alcaldía es maltrato. Lo que cambió es quién decide. El freno de
     * verdad ahora es que se VEA: debajo del número está la lista de envíos con
     * su fecha y su hora, así que las tres operadoras saben lo que ya se mandó
     * en vez de descubrirlo chocando.
     */
    private const HORAS_ENTRE_WHATSAPP = 24;

    /**
     * Minutos en que un segundo envío no se acepta ni pidiéndolo.
     *
     * Esto sí es un muro, y no es una política: es un seguro contra el doble
     * clic y contra dos operadoras pulsando el botón en el mismo segundo sobre
     * la misma fila. Ninguna de las dos cosas es una decisión de nadie, y las
     * dos mandan dos mensajes idénticos a la misma familia.
     */
    private const MINUTOS_ANTIRREBOTE = 2;

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
        LEFT JOIN inspeccion_viviendas insp
               ON insp.id = (SELECT i2.id FROM inspeccion_viviendas i2
                              WHERE i2.estado = \'APROBADA\'
                                AND (i2.rufe_reporte_id = r.id
                                     OR (jefe.numero_documento IS NOT NULL
                                         AND jefe.numero_documento <> \'\'
                                         AND i2.propietario_documento = jefe.numero_documento))
                              ORDER BY i2.id DESC LIMIT 1)
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
                    insp.numero AS inspeccion_numero,
                    insp.fecha_evaluacion AS inspeccion_en,
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
            'en_otras_listas' => $this->enOtrasListas($req, $estado, $total),
        ]);
    }

    /**
     * Cuántos hogares encuentra esta búsqueda FUERA de la pestaña abierta.
     *
     * ── El fallo que esto cierra ─────────────────────────────────────────────
     *
     * La búsqueda estaba encerrada en la pestaña. Una operadora en «Falta
     * llamar» escribía una cédula, no salía nada, y la conclusión natural
     * —«esta familia no está en el censo»— era falsa: el hogar estaba ahí, en
     * «Ya se preinscribieron», que es justo la respuesta que ella necesitaba
     * darle a quien tenía al teléfono.
     *
     * Pasó de verdad el 28 de agosto de 2026 con la cédula 16844290: la ficha
     * existía, la búsqueda la encontraba en «Todos» y devolvía cero en la
     * pestaña abierta. Es el error más caro de esta pantalla, porque no se
     * parece a un error: se parece a una respuesta.
     *
     * ── Por qué avisar y no ampliar la búsqueda ──────────────────────────────
     *
     * Ignorar la pestaña cuando hay texto haría que buscar signifique una cosa
     * distinta según lo escrito, y la operadora perdería la lista en la que
     * estaba trabajando. Se prefiere respetar la pestaña y decir en voz alta lo
     * que hay fuera, con un camino para ir a verlo.
     *
     * Solo cuesta una consulta más, y solo cuando de verdad se está buscando.
     */
    private function enOtrasListas(Request $req, string $estado, int $enEsta): int
    {
        if (trim((string) ($req->query('q') ?? '')) === '' || $estado === 'todos') {
            return 0;
        }

        [$filtro, $params] = $this->filtros($req, 'todos');

        $enTodas = (int) (Db::first(
            'SELECT COUNT(*) AS t FROM rufe_reportes r '.self::CRUCE.$filtro,
            $params
        )['t'] ?? 0);

        return max(0, $enTodas - $enEsta);
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
        // Las cifras se arman desde `COLA_DE_CIFRA`, no a mano. Cada tarjeta se
        // suma con la MISMA condición con la que se filtra su cola, así que el
        // número que promete es el que la lista da al pulsarla.
        $columnas = [];

        foreach (self::COLA_DE_CIFRA as $cifra => $cola) {
            // `SUM(condicion)` y no `COUNT`: en MySQL una comparación vale 1 o 0,
            // así que sumarla cuenta las filas que la cumplen. Para «todos» la
            // condición es `1 = 1` y la suma acaba siendo el total, que es
            // justamente lo que esa tarjeta dice.
            $columnas[] = 'SUM('.self::CONDICION_DE_COLA[$cola].') AS '.$cifra;
        }

        $fila = Db::first(
            'SELECT '.implode(",\n                ", $columnas)
                .' FROM rufe_reportes r '.self::CRUCE,
            ['jefe' => Catalogos::PARENTESCO_JEFE]
        ) ?? [];

        $resumen = [];

        foreach (array_keys(self::COLA_DE_CIFRA) as $cifra) {
            // Sobre una base vacía `SUM` devuelve NULL, no cero. Sin esto la
            // pantalla dibujaría huecos donde tiene que decir 0.
            $resumen[$cifra] = (int) ($fila[$cifra] ?? 0);
        }

        Response::ok(['resumen' => $resumen]);
    }

    /** El historial de llamadas de un hogar. Del más reciente al más antiguo. */
    /**
     * Los WhatsApp que se le han mandado a este hogar, con fecha y hora.
     *
     * Va aparte del historial completo porque se dibuja en otro sitio y en otro
     * momento: debajo del número, nada más abrir la llamada, sin que la
     * operadora tenga que desplegar nada.
     *
     * Es lo que sustituye al bloqueo de 24 horas. Con tres operadoras sobre la
     * misma lista, el freno útil no es prohibir: es que se vea lo que ya se
     * mandó antes de volver a mandarlo.
     *
     * @return list<array<string,mixed>>
     */
    private function enviosWhatsapp(int $id): array
    {
        return array_map(
            static fn (array $g): array => [
                'cuando'    => $g['creado_en'],
                'ok'        => $g['resultado'] === 'WHATSAPP_ENVIADO',
                // Quién lo mandó: entre tres operadoras, «ya se le envió» sin
                // decir quién obliga a preguntar en voz alta por la oficina.
                'quien'     => $g['usuario_email'],
                // Por qué falló, si falló. Un número que no existe en WhatsApp
                // hay que saberlo, no reintentarlo cinco veces.
                'error'     => $g['nota'],
            ],
            Db::all(
                'SELECT resultado, nota, usuario_email, creado_en
                   FROM rufe_gestiones
                  WHERE reporte_id = :r AND canal = :c
                  ORDER BY creado_en DESC, id DESC
                  LIMIT 20',
                ['r' => $id, 'c' => 'WHATSAPP']
            )
        );
    }

    public function enviosDeWhatsapp(Request $req): void
    {
        $id = (int) $req->param('id');
        $this->exigirHogar($id);

        Response::ok(['envios' => $this->enviosWhatsapp($id)]);
    }

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

        // ── Repetir: se avisa, no se prohíbe ─────────────────────────────
        $ultimo = Db::first(
            'SELECT creado_en FROM rufe_gestiones
              WHERE reporte_id = :r AND canal = :c AND resultado = :res
              ORDER BY creado_en DESC LIMIT 1',
            ['r' => $id, 'c' => 'WHATSAPP', 'res' => 'WHATSAPP_ENVIADO']
        );

        if ($ultimo !== null) {
            $hace = (time() - strtotime((string) $ultimo['creado_en'])) / 60;

            // El seguro contra el doble clic. No se puede saltar: nadie decide
            // mandar dos veces el mismo mensaje con dos minutos de diferencia.
            if ($hace < self::MINUTOS_ANTIRREBOTE) {
                throw new HttpError(
                    'Se le acaba de enviar el WhatsApp a este hogar, hace menos de '
                    .self::MINUTOS_ANTIRREBOTE.' minutos. Espere un momento.',
                    409
                );
            }

            // Dentro de las horas de cortesía se pregunta una vez. Con
            // `repetir` la operadora ya contestó que sí.
            if ($hace < self::HORAS_ENTRE_WHATSAPP * 60 && $req->texto('repetir') !== '1') {
                throw new HttpError(
                    'A este hogar ya se le envió el WhatsApp el '.$ultimo['creado_en'].'. '
                    .'Confirme si quiere enviárselo otra vez.',
                    409
                );
            }
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
            // El reporte actualizado viaja de vuelta: la pantalla lo dibuja sin
            // una segunda petición, y así la operadora ve su propio envío en la
            // lista en el mismo momento en que le confirmamos que salió.
            'envios' => $this->enviosWhatsapp($id),
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
            // El número al que hay que mandar al ciudadano. Viaja aparte del
            // texto porque la pantalla lo pinta grande y con botón de copiar:
            // es el mismo en las mil trescientas llamadas, y leerlo de un
            // párrafo trescientas veces al día es la fricción que hay que
            // quitar. Si el administrador reescribe el guión, esto no cambia.
            'whatsapp_oficial' => Guion::WHATSAPP_OFICIAL,
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

        // La condición sale de `CONDICION_DE_COLA`, que es de donde también sale
        // la cifra de la tarjeta que abre esta cola. Antes había aquí un
        // `switch` con las condiciones escritas otra vez: coincidían con las del
        // resumen por cuidado de quien las escribió, no porque nada lo obligara.
        //
        // Lo desconocido cae en «falta llamar», que es el trabajo del día. Una
        // cola inventada en la URL no puede dejar la pantalla en blanco ni,
        // peor, enseñar el censo entero.
        $cola = isset(self::CONDICION_DE_COLA[$estado]) ? $estado : 'pendiente';

        // Entre paréntesis: las condiciones se encadenan con AND y alguna lleva
        // OR dentro. Sin ellos, añadir mañana una cola con un OR arriba del todo
        // ampliaría la lista en silencio en vez de acotarla.
        $where[] = '('.self::CONDICION_DE_COLA[$cola].')';

        [$busqueda, $paramsBusqueda] = self::condicionDeBusqueda((string) ($req->query('q') ?? ''));

        if ($busqueda !== '') {
            $where[] = $busqueda;
            $params += $paramsBusqueda;
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
     * Qué se compara cuando la operadora escribe en el buscador.
     *
     * ── Por qué no basta un LIKE sobre la columna ────────────────────────────
     *
     * Antes se comparaba el texto tal cual contra el teléfono guardado. Un
     * teléfono se escribe de cinco maneras —`3136416997`, `313 641 6997`,
     * `+57 313 641 6997`, con guiones, con paréntesis— y la ficha guarda la que
     * escribió el funcionario que visitó la casa. La operadora escribía el
     * número que le acababan de dictar y no salía nada, aunque estuviera ahí.
     *
     * Peor todavía: la lista MUESTRA el número agrupado en tres bloques. Quien
     * copiaba lo que veía y lo pegaba en el buscador tampoco encontraba nada.
     *
     * Ahora se comparan cifras contra cifras: se le quitan los separadores a lo
     * escrito y también a la columna. Lo mismo vale para la cédula, que la gente
     * dicta con puntos («dieciséis punto doscientos treinta y cuatro…»).
     *
     * ── Y por qué la cédula de cualquiera de la casa ─────────────────────────
     *
     * Quien contesta el teléfono no siempre es el jefe de hogar: es el hijo, o
     * la nuera. Si dice su cédula y el buscador solo mirara la del jefe, la
     * operadora concluiría que esa familia no está en el censo, que es
     * justamente el error que más caro sale en esta pantalla. El teléfono va en
     * la misma bolsa por lo mismo: el número de la ficha puede ser el del hijo.
     *
     * ── Nombre y radicado se comparan tal cual ───────────────────────────────
     *
     * Llevan letras, y el radicado lleva además guiones que SÍ son parte del
     * dato: `RUFE-2026-ZZ3C191Q`. Quitárselos rompería la única búsqueda que
     * hoy funciona sin margen de error.
     *
     * Es `public static` y sin base de datos a propósito: así una prueba puede
     * fijar qué se compara y con qué, que es lo único que decide si una familia
     * aparece o no cuando la operadora la busca.
     *
     * @return array{0:string,1:array<string,string>}  la condición y sus valores.
     *                                                 Condición vacía si no hay
     *                                                 nada que buscar.
     */
    public static function condicionDeBusqueda(string $q): array
    {
        $q = trim($q);

        if ($q === '') {
            return ['', []];
        }

        $partes = [
            "CONCAT_WS(' ', jefe.nombres, jefe.apellidos) LIKE :qnombre",
            'r.radicado LIKE :qradicado',
        ];

        $params = [
            'qnombre' => '%'.$q.'%',
            'qradicado' => '%'.$q.'%',
        ];

        $digitos = self::digitosBuscables($q);

        if (strlen($digitos) >= self::MIN_DIGITOS_BUSQUEDA) {
            $partes[] = self::soloDigitos('r.contacto_telefono').' LIKE :qcontacto';

            // La subconsulta arranca por `reporte_id`, que es la primera columna
            // de `uq_rufe_personas_orden`: mira las personas de esa casa, no la
            // tabla entera.
            $partes[] = 'EXISTS (SELECT 1 FROM rufe_personas pb
                                  WHERE pb.reporte_id = r.id
                                    AND ('.self::soloDigitos('pb.numero_documento').' LIKE :qdocumento
                                         OR '.self::soloDigitos('pb.telefono').' LIKE :qtelefono))';

            $params['qcontacto'] = '%'.$digitos.'%';
            $params['qdocumento'] = '%'.$digitos.'%';
            $params['qtelefono'] = '%'.$digitos.'%';
        }

        return ['('.implode(' OR ', $partes).')', $params];
    }

    /**
     * Las cifras de lo escrito, con el indicativo del país fuera.
     *
     * `+57 313 641 6997` y `313 641 6997` son el mismo teléfono, pero las
     * fichas del censo guardan casi siempre el corto. Sin quitar el `57`, quien
     * escribe el número completo —como lo trae WhatsApp, o como se lo dictan—
     * no encontraba a nadie, aunque la familia estuviera ahí.
     *
     * Se quita solo cuando quedan doce cifras que empiezan por 57, que es
     * exactamente un número colombiano con indicativo: fijo o celular, los dos
     * tienen diez. Ninguna cédula colombiana llega a doce cifras, así que esto
     * no puede comerse el principio de una.
     */
    private static function digitosBuscables(string $q): string
    {
        $digitos = (string) preg_replace('/\D+/', '', $q);

        if (strlen($digitos) === 12 && str_starts_with($digitos, '57')) {
            return substr($digitos, 2);
        }

        return $digitos;
    }

    /**
     * La misma columna sin lo que no es una cifra.
     *
     * MySQL 5.7 no tiene `REGEXP_REPLACE` —llegó en la 8—, así que se encadenan
     * `REPLACE`. Son seis separadores, los que de verdad aparecen escritos en un
     * teléfono o en una cédula colombiana.
     *
     * Esto no puede usar un índice, y da igual: la comparación ya era
     * `LIKE '%…%'`, que tampoco lo usaba, y el censo son mil cuatrocientos
     * hogares.
     */
    private static function soloDigitos(string $columna): string
    {
        // Esta cadena entra en el SQL sin pasar por ningún marcador. Hoy solo se
        // llama con literales de este archivo; la comprobación está para que
        // siga siendo verdad el día que alguien la llame desde otro sitio.
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $columna) !== 1) {
            throw new \InvalidArgumentException('Columna no válida para la búsqueda: '.$columna);
        }

        $sql = "COALESCE($columna, '')";

        foreach ([' ', '-', '.', '(', ')', '+'] as $separador) {
            $sql = "REPLACE($sql, '$separador', '')";
        }

        return $sql;
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
            // Terminó: el ingeniero le aprobó la inspección de vivienda. Es lo
            // único que saca a un hogar de la campaña por haber llegado al
            // final; todo lo demás son etapas del camino.
            'inspeccion' => ($f['inspeccion_numero'] ?? null) === null ? null : [
                'numero' => (string) $f['inspeccion_numero'],
                'fecha'  => $f['inspeccion_en'],
            ],
            // Las razones para NO marcar este número. Van como un campo aparte
            // y no deducidas en la pantalla: si mañana se añade otra, se decide
            // aquí y no en cinco sitios.
            'no_llamar' => ($f['inspeccion_numero'] ?? null) !== null
                           || ($descarte !== null && $descarte['llamar'] === false),
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
