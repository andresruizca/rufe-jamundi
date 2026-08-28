<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Limite;
use App\Core\Request;
use App\Core\Response;
use App\SinCenso\Radicado;
use App\SinCenso\Validador;

/**
 * Quien la puerta de pre-inscripción rechazó porque su cédula no está en el
 * censo, pero puede necesitar ayuda igual.
 *
 * `crear()` es, junto con las de `PreinscripcionController`, una de las pocas
 * rutas de escritura sin sesión que existen en este sistema. Lleva encima las
 * mismas defensas: límite de tasa por IP, trampa antirrobot, idempotencia por
 * `envio_id` y autorización de datos obligatoria.
 *
 * A propósito NO hay ninguna ruta pública que devuelva estas solicitudes: es
 * el mismo razonamiento que en la pre-inscripción, y aquí es más delicado
 * todavía, porque nadie ha confirmado que la persona sea real.
 */
final class SinCensoController
{
    private const MAX_INTENTOS_HORA = 30;

    private const MAX_ENVIOS_HORA = 5;

    /** @var array<string,string> */
    public const ESTADOS = [
        'RECIBIDA' => 'Recibida',
        'EN_REVISION' => 'En revisión',
        'CONVERTIDA' => 'Convertida',
        'DESCARTADA' => 'Descartada',
    ];

    // ── Público ──────────────────────────────────────────────────────────────

    public function crear(Request $req): void
    {
        Limite::consumir(
            'sincenso.intento',
            $req->ip(),
            self::MAX_INTENTOS_HORA,
            3600,
            'Demasiadas solicitudes desde esta conexión. Espere unos minutos.'
        );

        $envioId = $this->envioId($req);

        // Reintento de un envío que ya entró, igual que en la pre-inscripción:
        // ocurre cuando la solicitud llegó pero la respuesta se perdió por
        // falta de cobertura.
        if ($envioId !== null) {
            $previo = Db::first(
                'SELECT radicado, creado_en FROM solicitudes_sin_censo WHERE envio_id = :e',
                ['e' => $envioId]
            );

            if ($previo !== null) {
                Response::ok([
                    'radicado' => $previo['radicado'],
                    'recibido_en' => date('c', strtotime((string) $previo['creado_en'])),
                    'reintento' => true,
                ]);

                return;
            }
        }

        // Trampa para robots: se responde 201 con un radicado que no existe.
        if ($req->texto('sitio_web') !== '') {
            Response::json([
                'ok' => true,
                'data' => ['radicado' => Radicado::componer(), 'recibido_en' => date('c')],
            ], 201);

            return;
        }

        Limite::consumir('sincenso.enviar', $req->ip(), self::MAX_ENVIOS_HORA, 3600);

        $revision = Validador::revisar($req->todo());
        if ($revision['errores'] !== []) {
            throw HttpError::validacion($revision['errores']);
        }

        $datos = $revision['datos'];
        $radicado = Radicado::generar();

        Db::exec(
            'INSERT INTO solicitudes_sin_censo
                (radicado, envio_id, documento, nombres, apellidos, telefono,
                 zona, corregimiento, vereda_sector_barrio, direccion, descripcion,
                 autoriza_datos, aviso_version, autorizacion_en, estado, origen_hash)
             VALUES
                (:radicado, :envio_id, :documento, :nombres, :apellidos, :telefono,
                 :zona, :corregimiento, :vereda, :direccion, :descripcion,
                 :autoriza, :aviso, NOW(), :estado, :origen)',
            [
                'radicado' => $radicado,
                'envio_id' => $envioId ?? bin2hex(random_bytes(18)),
                'documento' => $datos['documento'],
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'telefono' => $datos['telefono'],
                'zona' => $datos['zona'],
                'corregimiento' => $datos['corregimiento'],
                'vereda' => $datos['vereda_sector_barrio'],
                'direccion' => $datos['direccion'],
                'descripcion' => $datos['descripcion'],
                'autoriza' => $datos['autoriza_datos'],
                'aviso' => $datos['aviso_version'],
                'estado' => 'RECIBIDA',
                // La IP no se guarda: solo su hash con sal, igual que en la
                // pre-inscripción.
                'origen' => hash('sha256', $req->ip().'|'.Config::get('rufe.sal', '')),
            ]
        );

        Response::json([
            'ok' => true,
            'data' => ['radicado' => $radicado, 'recibido_en' => date('c')],
        ], 201);
    }

    // ── Interno (con sesión) ─────────────────────────────────────────────────

    public function listar(Request $req): void
    {
        $estado = strtoupper($req->query('estado', '') ?? '');
        $where = '';
        $params = [];

        if (isset(self::ESTADOS[$estado])) {
            $where = ' WHERE estado = :estado';
            $params['estado'] = $estado;
        }

        Response::ok([
            'solicitudes' => Db::all(
                "SELECT id, radicado, nombres, apellidos, telefono, zona, corregimiento,
                        vereda_sector_barrio, direccion, estado, rufe_reporte_id, creado_en
                   FROM solicitudes_sin_censo{$where}
                  ORDER BY id DESC",
                $params
            ),
            'estados' => self::ESTADOS,
        ]);
    }

    public function ver(Request $req): void
    {
        $id = (int) $req->param('id');
        $solicitud = Db::first(
            'SELECT s.*, r.radicado AS rufe_radicado
               FROM solicitudes_sin_censo s
               LEFT JOIN rufe_reportes r ON r.id = s.rufe_reporte_id
              WHERE s.id = :i',
            ['i' => $id]
        );

        if ($solicitud === null) {
            throw HttpError::noEncontrado('No existe esa solicitud.');
        }

        unset($solicitud['origen_hash']);

        Response::ok(['solicitud' => $solicitud, 'estados' => self::ESTADOS]);
    }

    public function cambiarEstado(Request $req): void
    {
        $id = (int) $req->param('id');
        $estado = strtoupper($req->texto('estado'));

        if (! isset(self::ESTADOS[$estado])) {
            throw HttpError::validacion(['estado' => 'Estado no válido.']);
        }

        $solicitud = Db::first('SELECT id, radicado FROM solicitudes_sin_censo WHERE id = :i', ['i' => $id]);
        if ($solicitud === null) {
            throw HttpError::noEncontrado('No existe esa solicitud.');
        }

        $rufeReporteId = null;

        if ($estado === 'CONVERTIDA') {
            // Se pide el RADICADO y no el id: es lo único que el funcionario
            // tiene delante al terminar de enviar la ficha nueva —la pantalla
            // de confirmación no muestra ningún número interno—, y es lo mismo
            // que ya sabe buscar en Reportes RUFE.
            $radicado = trim($req->texto('rufe_radicado'));
            $reporte = $radicado === '' ? null : Db::first(
                'SELECT id FROM rufe_reportes WHERE radicado = :r', ['r' => $radicado]
            );

            // No se marca convertida a ciegas: sin la ficha que nació de ella,
            // esta solicitud quedaría cerrada sin nada que explique en qué
            // terminó.
            if ($reporte === null) {
                throw HttpError::validacion([
                    'rufe_radicado' => 'Indique el radicado de la ficha RUFE que nació de esta solicitud.',
                ]);
            }

            $rufeReporteId = (int) $reporte['id'];
        }

        Db::exec(
            'UPDATE solicitudes_sin_censo SET estado = :e, rufe_reporte_id = :r WHERE id = :i',
            ['e' => $estado, 'r' => $rufeReporteId, 'i' => $id]
        );

        Auditoria::registrar(
            $req,
            'sincenso.estado',
            Auth::exigirUsuario($req),
            'solicitudes_sin_censo',
            (string) $solicitud['radicado'],
            $estado.($rufeReporteId !== null ? ' · reporte '.$rufeReporteId : '')
        );

        Response::ok(['estado' => $estado, 'rufe_reporte_id' => $rufeReporteId]);
    }

    /** El `envio_id` que manda el navegador, si viene con la forma esperada. */
    private function envioId(Request $req): ?string
    {
        $valor = $req->texto('envio_id');

        return preg_match('/^[a-f0-9-]{16,40}$/i', $valor) === 1 ? $valor : null;
    }
}
