<?php

declare(strict_types=1);

/**
 * La conciliación con la hoja, corrida UNA VEZ desde el navegador.
 *
 * Mismo motivo que su hermano del RUD: el hosting de la Alcaldía no tiene
 * consola, así que `scripts/conciliar-tablero.php` no se puede ejecutar allí.
 *
 *   POST /api/<nombre>.php?clave=LA_CLAVE   con cuerpo (aunque sea vacío)
 *        &aplicar=1     escribe de verdad; sin esto es un ensayo
 *
 * Los mismos tres cuidados:
 *
 * 1. **Se sube con otro nombre y se llama por POST con cuerpo.** El
 *    Mod_Security de este hosting bloquea con 406 los POST sin cuerpo y los
 *    nombres que suenan a instalador.
 * 2. **El CSV NO vive en el directorio público.** Trae nombres, cédulas y
 *    teléfonos: va en `sgr_almacen`, fuera de lo que Apache sirve.
 * 3. **La respuesta no lleva datos personales**: solo cuentas. Las
 *    discrepancias se resumen por campo, sin cédulas ni radicados, porque lo
 *    que se responde por HTTP queda en registros que nadie limpia.
 *
 * Al terminar: **borre este archivo y vacíe `install_key`.**
 */

$raiz = is_dir(__DIR__.'/src') ? __DIR__ : dirname(__DIR__);

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
use App\Rufe\ConciliadorHoja;

header('Content-Type: application/json; charset=utf-8');

function salir(int $estado, array $cuerpo): never
{
    http_response_code($estado);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

date_default_timezone_set('America/Bogota');
Config::cargar($raiz.'/config.php');

$esperada = (string) Config::get('install_key', '');

// Con la clave vacía queda inerte: vaciar `install_key` lo desactiva aunque
// alguien olvide borrarlo.
if ($esperada === '' || ! hash_equals($esperada, (string) ($_GET['clave'] ?? ''))) {
    salir(403, ['ok' => false, 'message' => 'Clave de instalación incorrecta.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    salir(405, ['ok' => false, 'message' => 'Use POST para ejecutar la conciliación.']);
}

$archivo = dirname($raiz, 2).'/sgr_almacen/rufe-hoja.csv';

if (! is_file($archivo)) {
    salir(404, ['ok' => false, 'message' => 'No se encontró la hoja en el almacén.']);
}

@set_time_limit(0);
ignore_user_abort(true);

$inicio = microtime(true);
$r = ConciliadorHoja::conciliar($archivo, ($_GET['aplicar'] ?? '') === '1');

// Las discrepancias llevan cédula y radicado: se resumen por campo y se cuentan.
$porCampo = [];

foreach ($r['discrepancias'] as $d) {
    $porCampo[$d['campo']] = ($porCampo[$d['campo']] ?? 0) + 1;
}

$r['discrepancias'] = $porCampo;

salir(200, [
    'ok' => true,
    'aplicado' => ($_GET['aplicar'] ?? '') === '1',
    'segundos' => round(microtime(true) - $inicio, 1),
    'resumen' => $r,
]);
