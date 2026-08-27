<?php

declare(strict_types=1);

/**
 * Recupera en la base lo que solo tiene la hoja de Google, desde la consola.
 *
 * Uso:
 *   php scripts/conciliar-tablero.php <hoja-rufe.csv> [--aplicar] [--csv=informe.csv]
 *
 * Toda la lógica vive en `Rufe\ConciliadorHoja`, que es la que corre también en
 * el servidor —donde no hay consola—. Aquí solo están los argumentos y cómo se
 * imprime el resultado.
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
use App\Rufe\ConciliadorHoja;

date_default_timezone_set('America/Bogota');
Config::cargar($raiz.'/config.php');

$archivo = null;
$aplicar = false;
$informe = $raiz.'/scripts/discrepancias-tablero.csv';

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--aplicar') {
        $aplicar = true;
    } elseif (str_starts_with($a, '--csv=')) {
        $informe = substr($a, 6);
    } elseif (! str_starts_with($a, '--')) {
        $archivo = $a;
    }
}

if ($archivo === null || ! is_file($archivo)) {
    fwrite(STDERR, "Uso: php scripts/conciliar-tablero.php <hoja-rufe.csv> [--aplicar]\n");
    exit(1);
}

echo "Hoja:  {$archivo}\n";
echo $aplicar ? "Modo:  APLICAR\n" : "Modo:  ensayo (no escribe nada)\n";
echo str_repeat('─', 70), "\n";

$r = ConciliadorHoja::conciliar($archivo, $aplicar);

echo 'Filas de la hoja:                          ', $r['filas_hoja'], "\n";
echo 'Personas en la base con cédula:            ', $r['personas_base'], "\n\n";
echo 'Emparejadas por cédula:                    ', $r['emparejadas'], "\n";
echo 'De la hoja que no están en la base:        ', $r['sin_pareja'], "\n\n";
echo 'Fechas de nacimiento completadas:          ', $r['nacimientos'], "\n";
echo 'Hogares que pasan a evacuados:             ', $r['evacuados'], "\n";
echo 'Hogares que ganan el dato de visita:       ', $r['visitas'], "\n";
echo 'Discrepancias (no se tocan):               ', count($r['discrepancias']), "\n";

if ($r['discrepancias'] !== []) {
    $mano = fopen($informe, 'w');

    if ($mano !== false) {
        // BOM para que Excel abra las tildes bien.
        fwrite($mano, "\xEF\xBB\xBF");
        fputcsv($mano, ['radicado', 'documento', 'campo', 'en_la_base', 'en_la_hoja'], ',', '"', '\\');

        foreach ($r['discrepancias'] as $d) {
            fputcsv($mano, $d, ',', '"', '\\');
        }

        fclose($mano);
        echo "\nInforme de discrepancias: {$informe}\n";
    }
}

echo $aplicar ? "\nAplicado.\n" : "\nNo se escribió nada. Con --aplicar se hace de verdad.\n";
