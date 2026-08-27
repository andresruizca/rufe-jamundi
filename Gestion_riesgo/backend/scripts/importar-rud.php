<?php

declare(strict_types=1);

/**
 * Conciliación del censo RUD (Excel) con la base del Sistema de Gestión del Riesgo.
 *
 * Uso:
 *   php scripts/importar-rud.php <archivo.xlsx> [--aplicar] [--csv=ruta.csv]
 *
 * Sin `--aplicar` no escribe NADA: lee, traduce, agrupa, valida, cruza contra
 * lo que ya está en la base e imprime el mismo resumen que imprimiría al
 * hacerlo de verdad. Esa es la forma correcta de correrlo la primera vez.
 *
 * ── Por qué PHP y no un script suelto ────────────────────────────────────────
 *
 * Porque reutiliza tal cual lo que ya decide qué es una ficha válida:
 * `Rufe\Validador` (las mismas reglas del formulario «Nueva ficha»),
 * `Rufe\Catalogos` (los mismos catálogos) y `Rufe\Radicado` (el mismo radicado
 * y la misma huella). Reimplementar esas reglas en otro lenguaje sería la
 * primera fuente de divergencia entre lo que entra por el Excel y lo que entra
 * por el formulario — que es justo lo que esta conciliación tiene que evitar.
 *
 * ── Lo que este script NO hace ───────────────────────────────────────────────
 *
 * No inventa datos. Ningún hogar entra con un teléfono de relleno, una
 * dirección «Sin especificar» ni un jefe de hogar ascendido a dedo. Lo que no
 * pasa las validaciones sale en el CSV de revisión con el motivo exacto, para
 * que una persona lo complete. Un censo con datos inventados es peor que un
 * censo incompleto: el incompleto se nota.
 */

$raiz = dirname(__DIR__);

spl_autoload_register(static function (string $clase) use ($raiz): void {
    if (! str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = $raiz.'/src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});

use App\Core\Config;
use App\Core\Db;
use App\Rufe\Catalogos;
use App\Rufe\LectorXlsx;
use App\Rufe\Radicado;
use App\Rufe\Rud;
use App\Rufe\Validador;

date_default_timezone_set('America/Bogota');

Config::cargar($raiz.'/config.php');

// ── Argumentos ───────────────────────────────────────────────────────────────

$argumentos = array_slice($argv, 1);
$archivo = null;
$aplicar = false;
$csv = $raiz.'/scripts/revision-rud.csv';

foreach ($argumentos as $a) {
    if ($a === '--aplicar') {
        $aplicar = true;
    } elseif (str_starts_with($a, '--csv=')) {
        $csv = substr($a, 6);
    } elseif (! str_starts_with($a, '--')) {
        $archivo = $a;
    }
}

if ($archivo === null || ! is_file($archivo)) {
    fwrite(STDERR, "Uso: php scripts/importar-rud.php <archivo.xlsx> [--aplicar] [--csv=ruta.csv]\n");
    exit(1);
}

echo "Archivo: {$archivo}\n";
echo $aplicar ? "Modo:    APLICAR (escribe en la base)\n" : "Modo:    ensayo (no escribe nada)\n";
echo str_repeat('─', 70), "\n";

// ── Leer y agrupar por hogar ─────────────────────────────────────────────────

$filas = LectorXlsx::filas($archivo, Rud::HOJA);
echo 'Personas en el archivo: ', count($filas), "\n";

/** @var array<string, list<array<string,string>>> $hogares */
$hogares = [];

foreach ($filas as $fila) {
    $familia = trim($fila['numero_formulario'] ?? '');

    if ($familia === '') {
        continue;
    }

    $hogares[$familia][] = $fila;
}

echo 'Hogares en el archivo:  ', count($hogares), "\n\n";

// ── Lo que ya está en la base, para no duplicar a nadie ──────────────────────

$yaEnBase = [];

foreach (Db::all('SELECT numero_documento FROM rufe_personas WHERE numero_documento IS NOT NULL') as $f) {
    $yaEnBase[(string) $f['numero_documento']] = true;
}

$huellasEnBase = [];

foreach (Db::all('SELECT huella, radicado FROM rufe_reportes') as $f) {
    $huellasEnBase[(string) $f['huella']] = (string) $f['radicado'];
}

echo 'Ya en la base: ', count($huellasEnBase), ' fichas, ', count($yaEnBase), " documentos.\n\n";

// ── Conciliar hogar por hogar ────────────────────────────────────────────────

$importados = 0;
$personasImportadas = 0;
$revision = [];
$motivos = [];

/** Anota un hogar en el informe de revisión, sin tocar la base. */
$apartar = static function (string|int $familia, array $personas, string $motivo) use (&$revision, &$motivos): void {
    $primera = $personas[0] ?? [];
    $revision[] = [
        'numero_formulario' => $familia,
        'personas' => count($personas),
        'jefe' => trim(($primera['primer_nombre'] ?? '').' '.($primera['primer_apellido'] ?? '')),
        'documento' => $primera['numero_documento'] ?? '',
        'motivo' => $motivo,
    ];
    $motivos[$motivo] = ($motivos[$motivo] ?? 0) + 1;
};

foreach ($hogares as $familia => $personas) {
    $personas = Rud::conJefePrimero($personas);
    $bien = Rud::desarmarBien($personas[0]['bienes_afectados'] ?? '');

    // Un hogar de una sola persona sin jefe marcado no es un dato que falte:
    // esa persona es la cabeza de su hogar. Con dos o más, NO se asciende a
    // nadie — quién encabeza una familia no se decide desde un script.
    $jefeDeducido = Rud::jefeDeducible($personas);

    if (! $jefeDeducido && ! Rud::tieneJefe($personas)) {
        $apartar($familia, $personas, 'Ningún integrante está marcado como jefe de hogar');

        continue;
    }

    $telefono = Rud::telefonoDe($personas);

    if ($telefono === '') {
        $apartar($familia, $personas, 'Ningún integrante dejó teléfono');

        continue;
    }

    $direccion = Rud::direccionDe($bien);
    $vereda = Rud::veredaDe($bien);

    if ($direccion === '' || $vereda === '') {
        $apartar($familia, $personas, 'Sin dirección, vereda ni corregimiento');

        continue;
    }

    $corregimiento = Rud::corregimientoOficial($bien['corregimiento']);

    $entrada = [
        'evento' => Catalogos::EVENTO_PREDETERMINADO,
        'fecha_evento' => Catalogos::FECHA_EVENTO_PREDETERMINADA,
        'zona' => Rud::zonaPorCorregimiento($bien['corregimiento']),
        'corregimiento' => $corregimiento ?? '',
        'vereda_sector_barrio' => $vereda,
        'direccion' => $direccion,
        // El RUD no pregunta si la familia tuvo que evacuar. No se deduce: se
        // deja el valor que el propio formato usa cuando nadie lo informó.
        'alojamiento' => 'LUGAR_HABITUAL',
        'tipo_bien' => Rud::codigoPorEtiqueta(Catalogos::TIPOS_BIEN, $bien['bien']) ?? 'VIVIENDA',
        'forma_tenencia' => Rud::codigoPorEtiqueta(Catalogos::FORMAS_TENENCIA, $bien['tenencia']) ?? 'NO_INFORMA',
        'estado_bien' => Rud::codigoPorEtiqueta(Catalogos::ESTADOS_BIEN, $bien['estado']) ?? 'NO_INFORMA',
        'contacto_telefono' => $telefono,
        'contacto_correo' => '',
        'observaciones' => 'Importado del RUD, formulario '.$familia.'.',
        // El consentimiento se firmó en el censo en papel, no con un clic. Se
        // deja constancia de eso en el propio texto, para que nadie lo confunda
        // con el aviso del formulario digital.
        'autoriza_tratamiento' => true,
        'aviso_version' => Catalogos::AVISO_RUD,
        'tiene_afectacion_agro' => false,
        'agropecuario' => [],
        'personas' => [],
    ];

    foreach ($personas as $i => $p) {
        $declarado = Rud::codigoPorEtiqueta(Catalogos::TIPOS_DOCUMENTO, $p['tipo_documento'] ?? '');
        $numero = trim($p['numero_documento'] ?? '');

        // El teléfono del hogar se le asigna al jefe cuando él no dejó uno
        // propio. No es un número inventado: es el mismo al que la Alcaldía va
        // a llamar para citar a esa familia, y el validador lo exige en el jefe
        // porque es a quien se cita.
        $suyo = preg_replace('/\D+/', '', $p['telefono'] ?? '') ?? '';
        $esJefe = $i === 0;

        $entrada['personas'][] = [
            'orden' => $i + 1,
            'nombres' => trim(($p['primer_nombre'] ?? '').' '.($p['segundo_nombre'] ?? '')),
            'apellidos' => trim(($p['primer_apellido'] ?? '').' '.($p['segundo_apellido'] ?? '')),
            'tipo_documento' => Rud::tipoDocumentoCoherente(
                is_int($declarado) ? $declarado : null,
                $numero
            ),
            'numero_documento' => $numero,
            'documento_otro' => '',
            'parentesco' => $jefeDeducido
                ? Catalogos::PARENTESCO_JEFE
                : (Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, $p['parentesco'] ?? '') ?? 15),
            // Vacío no es «no tiene»: es «el censo no lo preguntó». Por eso
            // existen los códigos «No informa» en los dos catálogos.
            'genero' => Rud::codigoPorEtiqueta(Catalogos::GENEROS, $p['nombre_genero'] ?? '') ?? 4,
            'pertenencia_etnica' => Rud::codigoPorEtiqueta(Catalogos::ETNIAS, $p['etnia'] ?? '') ?? 7,
            'fecha_nacimiento' => Rud::fechaNacimiento($p['fecha_nacimiento'] ?? '') ?? '',
            'telefono' => $suyo !== '' ? $suyo : ($esJefe ? $telefono : ''),
        ];
    }

    // Quien decide si esto es una ficha válida es el mismo validador del
    // formulario. Aquí no se repite ni una sola de sus reglas.
    $revisado = Validador::reporte($entrada);

    if ($revisado['errores'] !== []) {
        $primero = array_key_first($revisado['errores']);
        $apartar($familia, $personas, $primero.': '.$revisado['errores'][$primero]);

        continue;
    }

    $datos = $revisado['datos'];

    // La misma huella que calcula el formulario: fecha del evento, dirección y
    // cédula del jefe de hogar. Es lo que hace que este script y «Nueva ficha»
    // reconozcan el mismo hogar como el mismo hogar.
    $documentoJefe = null;

    foreach ($datos['personas'] as $p) {
        if ((int) $p['parentesco'] === Catalogos::PARENTESCO_JEFE) {
            $documentoJefe = $p['numero_documento'];

            break;
        }
    }

    $huella = Radicado::huella($datos['fecha_evento'], $datos['direccion'], $documentoJefe);

    if (isset($huellasEnBase[$huella])) {
        $apartar($familia, $personas, 'Ya existe en la base como '.$huellasEnBase[$huella]);

        continue;
    }

    $repetido = null;

    foreach ($datos['personas'] as $p) {
        $doc = (string) ($p['numero_documento'] ?? '');

        if ($doc !== '' && isset($yaEnBase[$doc])) {
            $repetido = $doc;

            break;
        }
    }

    if ($repetido !== null) {
        $apartar($familia, $personas, 'La cédula '.$repetido.' ya está registrada en otra ficha');

        continue;
    }

    if ($aplicar) {
        $radicado = guardarHogar($datos, $huella);
        $huellasEnBase[$huella] = $radicado;
    }

    foreach ($datos['personas'] as $p) {
        $doc = (string) ($p['numero_documento'] ?? '');

        if ($doc !== '') {
            $yaEnBase[$doc] = true;
        }
    }

    $importados++;
    $personasImportadas += count($datos['personas']);
}

// ── Informe ──────────────────────────────────────────────────────────────────

echo 'Hogares que entran:   ', $importados, ' (', $personasImportadas, " personas)\n";
echo 'Hogares a revisión:   ', count($revision), "\n\n";

if ($motivos !== []) {
    arsort($motivos);
    echo "Por qué quedaron fuera:\n";

    foreach ($motivos as $motivo => $cuantos) {
        printf("  %5d  %s\n", $cuantos, $motivo);
    }

    echo "\n";
}

// Cuadre: ningún hogar puede desaparecer sin explicación.
$total = $importados + count($revision);
echo 'Cuadre: ', $importados, ' + ', count($revision), ' = ', $total,
     $total === count($hogares) ? " ✓\n" : " ✗ NO CUADRA con ".count($hogares)."\n";

if ($revision !== []) {
    $mano = fopen($csv, 'w');

    if ($mano !== false) {
        // BOM para que Excel abra las tildes bien: quien lee este archivo lo
        // abre en Excel, no en un editor de texto.
        fwrite($mano, "\xEF\xBB\xBF");
        fputcsv($mano, ["numero_formulario", "personas", "jefe_o_primero", "documento", "motivo"], ",", "\"", "\\");

        foreach ($revision as $r) {
            fputcsv($mano, $r, ",", "\"", "\\");
        }

        fclose($mano);
        echo "\nInforme de revisión: {$csv}\n";
    }
}

if (! $aplicar) {
    echo "\nNo se escribió nada. Con --aplicar se hace de verdad.\n";
}

/**
 * Inserta un hogar completo en una transacción.
 *
 * Cabecera y personas juntas o nada: un hogar a medias es peor que ninguno,
 * porque nadie sabría a quién le falta información.
 *
 * @param  array<string,mixed>  $datos
 */
function guardarHogar(array $datos, string $huella): string
{
    $pdo = Db::conn();
    $pdo->beginTransaction();

    try {
        $radicado = Radicado::generar();

        Db::exec(
            'INSERT INTO rufe_reportes
                (radicado, formato_version, estado, origen, departamento, municipio, evento,
                 fecha_evento, fecha_rufe, zona, corregimiento, vereda_sector_barrio, direccion,
                 alojamiento, forma_tenencia, estado_bien, tipo_bien, observaciones,
                 contacto_telefono, contacto_correo, autoriza_datos, autoriza_sensibles,
                 autorizacion_en, autorizacion_texto, huella)
             VALUES
                (:radicado, :formato_version, :estado, :origen, :departamento, :municipio, :evento,
                 :fecha_evento, :fecha_rufe, :zona, :corregimiento, :vereda, :direccion,
                 :alojamiento, :tenencia, :estado_bien, :tipo_bien, :observaciones,
                 :telefono, :correo, 1, 1, NOW(), :autorizacion, :huella)',
            [
                'radicado' => $radicado,
                'formato_version' => $datos['formato_version'],
                'estado' => 'RECIBIDO',
                // INTERNO y no PUBLICO: lo levantó un funcionario en campo con
                // el formato en papel, no la familia desde su celular.
                'origen' => 'INTERNO',
                'departamento' => $datos['departamento'],
                'municipio' => $datos['municipio'],
                'evento' => $datos['evento'],
                'fecha_evento' => $datos['fecha_evento'],
                'fecha_rufe' => $datos['fecha_rufe'],
                'zona' => $datos['zona'],
                'corregimiento' => $datos['corregimiento'],
                'vereda' => $datos['vereda_sector_barrio'],
                'direccion' => $datos['direccion'],
                'alojamiento' => $datos['alojamiento'],
                'tenencia' => $datos['forma_tenencia'],
                'estado_bien' => $datos['estado_bien'],
                'tipo_bien' => $datos['tipo_bien'],
                'observaciones' => $datos['observaciones'],
                'telefono' => $datos['contacto_telefono'],
                'correo' => $datos['contacto_correo'],
                'autorizacion' => $datos['autorizacion_texto'],
                'huella' => $huella,
            ]
        );

        $reporteId = (int) Db::lastId();

        foreach ($datos['personas'] as $p) {
            Db::exec(
                'INSERT INTO rufe_personas
                    (reporte_id, orden, nombres, apellidos, tipo_documento, numero_documento,
                     documento_otro, parentesco, genero, fecha_nacimiento, pertenencia_etnica, telefono)
                 VALUES (:r, :o, :n, :a, :td, :nd, :do, :pa, :ge, :fn, :pe, :te)',
                [
                    'r' => $reporteId,
                    'o' => $p['orden'],
                    'n' => $p['nombres'],
                    'a' => $p['apellidos'],
                    'td' => $p['tipo_documento'],
                    'nd' => $p['numero_documento'],
                    'do' => $p['documento_otro'],
                    'pa' => $p['parentesco'],
                    'ge' => $p['genero'],
                    'fn' => $p['fecha_nacimiento'],
                    'pe' => $p['pertenencia_etnica'],
                    'te' => $p['telefono'],
                ]
            );
        }

        $pdo->commit();

        return $radicado;
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }
}
