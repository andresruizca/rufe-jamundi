<?php

declare(strict_types=1);

/**
 * Recupera en la base lo que solo tiene la hoja de Google, cruzando por cédula.
 *
 * Uso:
 *   php scripts/conciliar-tablero.php <hoja-rufe.csv> [--aplicar] [--csv=informe.csv]
 *
 * El censo se digitalizó dos veces: una a la hoja y otra al RUD. Cada
 * digitalización perdió cosas distintas, y las dos comparten la cédula. Esto
 * completa la base con tres datos que el RUD no trajo y la hoja sí:
 *
 *   · la fecha de nacimiento —la base la tiene para el 30 % de la gente, la
 *     hoja para el 60 %—, sin la cual los cuatro indicadores de edad del
 *     tablero cuentan la mitad de la población;
 *   · si el hogar fue evacuado;
 *   · si se hizo la visita y quién la hizo.
 *
 * ── La regla que no se negocia ───────────────────────────────────────────────
 *
 * **Solo se llena lo vacío.** Si la base ya dice algo y la hoja dice otra cosa,
 * eso NO se resuelve solo: va al informe de discrepancias con las dos versiones
 * al lado, para que una persona decida. Un script que elige entre dos fuentes
 * contradictorias está inventando, aunque acierte.
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

date_default_timezone_set('America/Bogota');
Config::cargar($raiz.'/config.php');

/**
 * Las columnas de la hoja del RUFE, por posición.
 *
 * La hoja no tiene un encabezado que se pueda leer con seguridad —son ocho
 * filas de membrete y encabezados partidos en dos niveles—, así que se va por
 * índice, igual que hace el tablero desde que existe.
 */
const COL = [
    'hogar' => 1,
    'barrio' => 3,
    'documento' => 8,
    'dia' => 11,
    'mes' => 12,
    'anio' => 13,
    'evacuada' => 20,
    'visita' => 21,
    'quienVisita' => 22,
];

const FILAS_ENCABEZADO = 8;

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

// ── Leer la hoja ─────────────────────────────────────────────────────────────

$mano = fopen($archivo, 'r');
$filas = [];
$n = 0;

while (($fila = fgetcsv($mano, 0, ',', '"', '\\')) !== false) {
    if ($n++ < FILAS_ENCABEZADO) {
        continue;
    }

    if (count($fila) > COL['quienVisita']) {
        $filas[] = $fila;
    }
}

fclose($mano);

echo 'Filas de la hoja: ', count($filas), "\n";

// ── Lo que hay en la base, indexado por cédula ───────────────────────────────

$personas = [];

foreach (Db::all('SELECT id, reporte_id, numero_documento, fecha_nacimiento FROM rufe_personas
                   WHERE numero_documento IS NOT NULL AND numero_documento <> \'\'') as $p) {
    $personas[(string) $p['numero_documento']] = $p;
}

$fichas = [];

foreach (Db::all('SELECT id, radicado, alojamiento, visitada, quien_visito FROM rufe_reportes') as $r) {
    $fichas[(int) $r['id']] = $r;
}

echo 'Personas en la base con cédula: ', count($personas), "\n\n";

// ── Cruzar ───────────────────────────────────────────────────────────────────

$sinPareja = 0;
$emparejadas = 0;
$nacimientos = [];      // persona_id => fecha
$porFicha = [];         // reporte_id => ['evacuada' => .., 'visita' => .., 'quien' => ..]
$discrepancias = [];

foreach ($filas as $fila) {
    $documento = preg_replace('/\D+/', '', trim($fila[COL['documento']] ?? '')) ?? '';

    if ($documento === '') {
        continue;
    }

    if (! isset($personas[$documento])) {
        $sinPareja++;

        continue;
    }

    $emparejadas++;
    $persona = $personas[$documento];
    $ficha = (int) $persona['reporte_id'];

    // Fecha de nacimiento: la hoja la guarda en tres columnas.
    $fecha = fechaDe($fila[COL['dia']] ?? '', $fila[COL['mes']] ?? '', $fila[COL['anio']] ?? '');

    if ($fecha !== null) {
        if ($persona['fecha_nacimiento'] === null) {
            $nacimientos[(int) $persona['id']] = $fecha;
        } elseif (substr((string) $persona['fecha_nacimiento'], 0, 10) !== $fecha) {
            $discrepancias[] = [
                'radicado' => $fichas[$ficha]['radicado'] ?? '',
                'documento' => $documento,
                'campo' => 'fecha_nacimiento',
                'en_la_base' => substr((string) $persona['fecha_nacimiento'], 0, 10),
                'en_la_hoja' => $fecha,
            ];
        }
    }

    // Evacuación y visita son del HOGAR, no de la persona: la hoja las repite
    // en cada integrante y a veces solo la primera fila las trae. Se toma el
    // primer valor con contenido que aparezca para esa ficha.
    $porFicha[$ficha] ??= ['evacuada' => '', 'visita' => '', 'quien' => ''];

    foreach ([['evacuada', COL['evacuada']], ['visita', COL['visita']]] as [$clave, $columna]) {
        $valor = strtoupper(trim($fila[$columna] ?? ''));

        if (($valor === 'SI' || $valor === 'NO') && $porFicha[$ficha][$clave] === '') {
            $porFicha[$ficha][$clave] = $valor;
        }
    }

    $quien = trim($fila[COL['quienVisita']] ?? '');

    if ($quien !== '' && $porFicha[$ficha]['quien'] === '') {
        $porFicha[$ficha]['quien'] = mb_substr($quien, 0, 120);
    }
}

echo 'Personas de la hoja emparejadas por cédula: ', $emparejadas, "\n";
echo 'Personas de la hoja que no están en la base: ', $sinPareja, "\n\n";

// ── Qué se completaría ───────────────────────────────────────────────────────

$evacuadas = 0;
$visitas = 0;

foreach ($porFicha as $id => $datos) {
    $ficha = $fichas[$id] ?? null;

    if ($ficha === null) {
        continue;
    }

    if ($datos['evacuada'] === 'SI' && $ficha['alojamiento'] !== 'EVACUADO') {
        $evacuadas++;
    } elseif ($datos['evacuada'] === 'NO' && $ficha['alojamiento'] === 'EVACUADO') {
        $discrepancias[] = [
            'radicado' => $ficha['radicado'],
            'documento' => '',
            'campo' => 'evacuada',
            'en_la_base' => 'EVACUADO',
            'en_la_hoja' => 'NO',
        ];
    }

    if ($datos['visita'] !== '' && $ficha['visitada'] === 'SIN_DATO') {
        $visitas++;
    } elseif ($datos['visita'] !== '' && $ficha['visitada'] !== $datos['visita']) {
        $discrepancias[] = [
            'radicado' => $ficha['radicado'],
            'documento' => '',
            'campo' => 'visitada',
            'en_la_base' => (string) $ficha['visitada'],
            'en_la_hoja' => $datos['visita'],
        ];
    }
}

echo 'Fechas de nacimiento que se completarían: ', count($nacimientos), "\n";
echo 'Hogares que pasarían a evacuados:         ', $evacuadas, "\n";
echo 'Hogares que ganarían el dato de visita:   ', $visitas, "\n";
echo 'Discrepancias (no se tocan):              ', count($discrepancias), "\n";

// ── Aplicar ──────────────────────────────────────────────────────────────────

if ($aplicar) {
    $pdo = Db::conn();
    $pdo->beginTransaction();

    try {
        foreach ($nacimientos as $personaId => $fecha) {
            Db::exec(
                'UPDATE rufe_personas SET fecha_nacimiento = :f WHERE id = :i AND fecha_nacimiento IS NULL',
                ['f' => $fecha, 'i' => $personaId]
            );
        }

        foreach ($porFicha as $id => $datos) {
            if (! isset($fichas[$id])) {
                continue;
            }

            if ($datos['evacuada'] === 'SI') {
                // Dos marcadores distintos para el mismo valor: con preparadas
                // nativas, repetir `:a` en la misma sentencia es «Invalid
                // parameter number» al preparar. Es el mismo fallo que dejó
                // roto el buscador de la bandeja durante semanas.
                Db::exec(
                    'UPDATE rufe_reportes SET alojamiento = :nuevo
                      WHERE id = :i AND alojamiento <> :actual',
                    ['nuevo' => 'EVACUADO', 'actual' => 'EVACUADO', 'i' => $id]
                );
            }

            if ($datos['visita'] !== '') {
                Db::exec(
                    'UPDATE rufe_reportes SET visitada = :v, quien_visito = :q
                      WHERE id = :i AND visitada = :sin',
                    ['v' => $datos['visita'], 'q' => $datos['quien'] ?: null, 'i' => $id, 'sin' => 'SIN_DATO']
                );
            }
        }

        $pdo->commit();
        echo "\nAplicado.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }
} else {
    echo "\nNo se escribió nada. Con --aplicar se hace de verdad.\n";
}

if ($discrepancias !== []) {
    $mano = fopen($informe, 'w');

    if ($mano !== false) {
        fwrite($mano, "\xEF\xBB\xBF");
        fputcsv($mano, ['radicado', 'documento', 'campo', 'en_la_base', 'en_la_hoja'], ',', '"', '\\');

        foreach ($discrepancias as $d) {
            fputcsv($mano, $d, ',', '"', '\\');
        }

        fclose($mano);
        echo "Informe de discrepancias: {$informe}\n";
    }
}

/**
 * La fecha de nacimiento que la hoja parte en tres columnas.
 *
 * Devuelve null ante cualquier duda. Una fecha inventada aquí cambia de grupo
 * de edad a una persona real en un tablero que se reporta a la Alcaldía.
 */
function fechaDe(string $dia, string $mes, string $anio): ?string
{
    $d = (int) trim($dia);
    $m = (int) trim($mes);
    $a = (int) trim($anio);

    if ($a < 1900 || $a > (int) date('Y') || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
        return null;
    }

    if (! checkdate($m, $d, $a)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $a, $m, $d);
}
