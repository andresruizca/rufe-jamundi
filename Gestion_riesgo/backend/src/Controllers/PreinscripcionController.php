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
use App\Preinscripcion\Radicado;
use App\Preinscripcion\Validador;
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
 */
final class PreinscripcionController
{
    /** Solicitudes por IP y hora. Una familia manda una; un robot, miles. */
    private const MAX_ENVIOS_HORA = 5;

    /** Tope amplio de peticiones, incluidos los reintentos que no crean nada. */
    private const MAX_INTENTOS_HORA = 60;

    private const MAX_CARGAS_HORA = 10;

    /** Cuatro fotos por solicitud, con margen para reintentos por mala señal. */
    private const MAX_ARCHIVOS_HORA = 30;

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
            'aviso_version'  => Rufe::AVISO_VERSION,
            'limites'        => [
                'fotos_dano'       => Rufe::MAX_FOTOS_PREINSCRIPCION,
                'fotos_cedula'     => 1,
                'bytes_archivo'    => Rufe::MAX_BYTES_ARCHIVO,
                'bytes_carga'      => Rufe::MAX_BYTES_CARGA,
                'objetivo_bytes_foto' => Rufe::OBJETIVO_BYTES_FOTO,
                'extensiones'      => array_keys(Rufe::EXTENSIONES),
            ],
        ]);
    }

    /**
     * Abre una carga para las fotos de una solicitud, sin sesión.
     *
     * El token no se guarda en ninguna tabla: solo su SHA-256 acompaña a cada
     * archivo. Quien no lo tenga no puede ver ni adjuntar nada a esa carga, y
     * adivinarlo exige acertar 256 bits.
     *
     * Las cargas abandonadas caducan en dos horas y se purgan con el tráfico.
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

        Response::json([
            'ok' => true,
            'data' => [
                'carga' => bin2hex(random_bytes(32)),
                'maximo_archivos' => Rufe::MAX_FOTOS_PREINSCRIPCION + 1,
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

        // Reintento de un envío que ya entró: ocurre cuando la solicitud llegó
        // pero la respuesta se perdió por falta de cobertura. Se devuelve el
        // radicado original en vez de inscribir dos veces al mismo hogar.
        if ($envioId !== null) {
            $previo = Db::first(
                'SELECT radicado, creado_en FROM preinscripciones WHERE envio_id = :e',
                ['e' => $envioId]
            );

            if ($previo !== null) {
                Response::ok([
                    'radicado'    => $previo['radicado'],
                    'recibido_en' => date('c', strtotime((string) $previo['creado_en'])),
                    'reintento'   => true,
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
        $huella = Radicado::huella($datos['direccion'], $datos['documento']);

        // Ya existe una solicitud de esta misma vivienda: se devuelve la suya en
        // vez de crear otra. Que la familia se inscriba tres veces por nervios
        // no puede convertirse en tres turnos.
        $duplicada = Db::first(
            'SELECT radicado, creado_en FROM preinscripciones
              WHERE huella = :h AND estado <> :d
              ORDER BY id DESC LIMIT 1',
            ['h' => $huella, 'd' => 'DESCARTADA']
        );

        if ($duplicada !== null) {
            Response::ok([
                'radicado'    => $duplicada['radicado'],
                'recibido_en' => date('c', strtotime((string) $duplicada['creado_en'])),
                'duplicada'   => true,
            ]);

            return;
        }

        $carga = $req->texto('carga');

        $radicado = $this->guardar($datos, $huella, $envioId, $req, $carga === '' ? null : $carga);

        Response::json([
            'ok'   => true,
            'data' => ['radicado' => $radicado, 'recibido_en' => date('c')],
        ], 201);
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
                     direccion, corregimiento, vereda, latitud, longitud, precision_m,
                     descripcion_dano, autoriza_datos, aviso_version, autorizacion_en,
                     huella, estado, origen_hash)
                 VALUES
                    (:radicado, :envio_id, :nombre, :documento, :telefono, :correo,
                     :direccion, :corregimiento, :vereda, :latitud, :longitud, :precision_m,
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

            if ($carga !== null) {
                Archivos::adoptarPreinscripcion(Archivos::hashDeCarga($carga), $id);
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
        $estado = strtoupper($req->query('estado', '') ?? '');
        $where = '';
        $filtros = [];

        if (in_array($estado, ['RECIBIDA', 'EN_REVISION', 'CONVERTIDA', 'DESCARTADA'], true)) {
            $where = ' WHERE estado = :estado';
            $filtros['estado'] = $estado;
        }

        $pagina = max(1, (int) ($req->query('pagina', '1') ?? 1));
        $porPagina = 25;
        $desde = ($pagina - 1) * $porPagina;

        $total = (int) (Db::first("SELECT COUNT(*) AS n FROM preinscripciones{$where}", $filtros)['n'] ?? 0);

        $filas = Db::all(
            "SELECT id, radicado, nombre_completo, documento, telefono, direccion,
                    corregimiento, vereda, estado, inspeccion_id, creado_en
               FROM preinscripciones{$where}
              ORDER BY id DESC
              LIMIT {$porPagina} OFFSET {$desde}",
            $filtros
        );

        Response::ok([
            'preinscripciones' => $filas,
            'total'            => $total,
            'pagina'           => $pagina,
            'por_pagina'       => $porPagina,
        ]);
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
            'fotos' => Db::all(
                'SELECT id, nombre_original, extension, tamano_bytes, mime
                   FROM rufe_evidencias WHERE preinscripcion_id = :i ORDER BY id',
                ['i' => $id]
            ),
            'historial' => Db::all(
                'SELECT estado, nota, usuario_email, creado_en FROM preinscripcion_historial
                  WHERE preinscripcion_id = :i ORDER BY id',
                ['i' => $id]
            ),
        ]);
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

        $ficha = Db::first('SELECT id, radicado FROM preinscripciones WHERE id = :i', ['i' => $id]);
        if ($ficha === null) {
            throw HttpError::noEncontrado('No existe esa pre-inscripción.');
        }

        $actor = Auth::exigirUsuario($req);

        Db::exec('UPDATE preinscripciones SET estado = :e WHERE id = :i', ['e' => $estado, 'i' => $id]);
        Db::exec(
            'INSERT INTO preinscripcion_historial (preinscripcion_id, estado, nota, usuario_id, usuario_email)
             VALUES (:i, :e, :n, :u, :m)',
            ['i' => $id, 'e' => $estado, 'n' => $nota ?: null, 'u' => $actor['id'], 'm' => $actor['email']]
        );

        Auditoria::registrar(
            $req, 'preinscripcion.estado', $actor, 'preinscripciones', (string) $ficha['radicado'], $estado
        );

        Response::ok(['estado' => $estado]);
    }

    /** El `envio_id` que manda el navegador, si viene con la forma esperada. */
    private function envioId(Request $req): ?string
    {
        $valor = $req->texto('envio_id');

        return preg_match('/^[a-f0-9-]{16,40}$/i', $valor) === 1 ? $valor : null;
    }
}
