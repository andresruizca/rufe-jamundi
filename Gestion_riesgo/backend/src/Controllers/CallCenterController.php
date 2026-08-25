<?php

declare(strict_types=1);

namespace App\Controllers;

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
    ];

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
                               AND p2.estado <> \'DESCARTADA\'
                             ORDER BY p2.creado_en DESC, p2.id DESC LIMIT 1)
        LEFT JOIN rufe_gestiones g
               ON g.id = (SELECT g2.id FROM rufe_gestiones g2
                           WHERE g2.reporte_id = r.id
                           ORDER BY g2.creado_en DESC, g2.id DESC LIMIT 1)
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
                    g.resultado AS ultimo_resultado, g.creado_en AS ultimo_en,
                    g.proxima_llamada, g.nota AS ultima_nota, g.usuario_email AS ultimo_por,
                    (SELECT COUNT(*) FROM rufe_gestiones gc WHERE gc.reporte_id = r.id) AS intentos
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
                SUM(pre.id IS NOT NULL) AS preinscritos,
                SUM(pre.id IS NULL AND g.id IS NOT NULL) AS contactados_sin_preinscribir,
                SUM(pre.id IS NULL AND g.id IS NULL) AS sin_llamar,
                SUM(pre.id IS NULL AND g.proxima_llamada IS NOT NULL
                    AND g.proxima_llamada <= CURDATE()) AS para_hoy,
                SUM(r.contacto_telefono IS NULL OR r.contacto_telefono = \'\') AS sin_telefono
               FROM rufe_reportes r '.self::CRUCE,
            ['jefe' => Catalogos::PARENTESCO_JEFE]
        ) ?? [];

        Response::ok([
            'resumen' => [
                'total'                        => (int) ($fila['total'] ?? 0),
                'preinscritos'                 => (int) ($fila['preinscritos'] ?? 0),
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
                'SELECT id, resultado, nota, proxima_llamada, enlace_enviado,
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

        if (! isset(self::RESULTADOS[$resultado])) {
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
                $where[] = 'pre.id IS NOT NULL';
                break;
            case 'contactado':
                $where[] = 'pre.id IS NULL AND g.id IS NOT NULL';
                break;
            case 'reintentar':
                $where[] = 'pre.id IS NULL AND g.proxima_llamada IS NOT NULL AND g.proxima_llamada <= CURDATE()';
                break;
            case 'todos':
                break;
            case 'pendiente':
            default:
                // Lo que falta por hacer: nadie lo ha llamado y no se ha
                // preinscrito.
                $where[] = 'pre.id IS NULL AND g.id IS NULL';
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
            'preinscrita'  => $f['preinscripcion_radicado'] !== null,
            'preinscripcion' => $f['preinscripcion_radicado'] === null ? null : [
                'radicado'  => $f['preinscripcion_radicado'],
                'creado_en' => $f['preinscripcion_en'],
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
