<?php

declare(strict_types=1);

/**
 * Conciliación del censo RUD (Excel) con la base, desde la consola.
 *
 * Uso:
 *   php scripts/importar-rud.php <archivo.xlsx> [--aplicar] [--limite=N] [--csv=ruta.csv]
 *
 * Sin `--aplicar` no escribe NADA: lee, traduce, agrupa, valida, cruza contra
 * lo que ya está en la base e imprime el mismo resumen que imprimiría al
 * hacerlo de verdad. Esa es la forma correcta de correrlo la primera vez.
 *
 * Toda la lógica vive en `Rufe\ImportadorRud`, que es la que corre también en
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
use App\Rufe\ImportadorRud;

date_default_timezone_set('America/Bogota');
Config::cargar($raiz.'/config.php');

$archivo = null;
$aplicar = false;
$limite = 0;
$csv = $raiz.'/scripts/revision-rud.csv';

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--aplicar') {
        $aplicar = true;
    } elseif (str_starts_with($a, '--limite=')) {
        $limite = (int) substr($a, 9);
    } elseif (str_starts_with($a, '--csv=')) {
        $csv = substr($a, 6);
    } elseif (! str_starts_with($a, '--')) {
        $archivo = $a;
    }
}

if ($archivo === null || ! is_file($archivo)) {
    fwrite(STDERR, "Uso: php scripts/importar-rud.php <archivo.xlsx> [--aplicar] [--limite=N] [--csv=ruta.csv]\n");
    exit(1);
}

echo "Archivo: {$archivo}\n";
echo $aplicar ? "Modo:    APLICAR (escribe en la base)\n" : "Modo:    ensayo (no escribe nada)\n";
echo str_repeat('─', 70), "\n";

$r = ImportadorRud::conciliar($archivo, ['aplicar' => $aplicar, 'limite' => $limite]);

echo 'Personas en el archivo: ', $r['personas_archivo'], "\n";
echo 'Hogares en el archivo:  ', $r['hogares_archivo'], "\n";
echo 'Fichas ya en la base:   ', $r['base_fichas'], "\n\n";
echo 'Hogares que entran:     ', $r['importados'], ' (', $r['personas_importadas'], " personas)\n";
echo 'Ya estaban:             ', $r['ya_estaban'], "\n";
echo 'A revisión:             ', count($r['revision']), "\n";

if ($r['pendientes'] > 0) {
    echo 'Sin tocar (tope):       ', $r['pendientes'], "\n";
}

echo "\n";

if ($r['motivos'] !== []) {
    echo "Por qué quedaron fuera:\n";

    foreach ($r['motivos'] as $motivo => $cuantos) {
        printf("  %5d  %s\n", $cuantos, $motivo);
    }

    echo "\n";
}

echo 'Cuadre: ', $r['cuadra'] ? "✓\n" : "✗ NO CUADRA\n";

if ($r['revision'] !== []) {
    $mano = fopen($csv, 'w');

    if ($mano !== false) {
        // BOM para que Excel abra las tildes bien: quien lee este archivo lo
        // abre en Excel, no en un editor de texto.
        fwrite($mano, "\xEF\xBB\xBF");
        fputcsv($mano, ['numero_formulario', 'personas', 'jefe_o_primero', 'documento', 'motivo'], ',', '"', '\\');

        foreach ($r['revision'] as $fila) {
            fputcsv($mano, $fila, ',', '"', '\\');
        }

        fclose($mano);
        echo "\nInforme de revisión: {$csv}\n";
    }
}

if (! $aplicar) {
    echo "\nNo se escribió nada. Con --aplicar se hace de verdad.\n";
}
