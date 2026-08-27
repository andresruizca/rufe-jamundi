<?php

declare(strict_types=1);

namespace App\Rufe;

use App\Core\Db;
use Throwable;

/**
 * Recupera en la base lo que solo tiene la hoja de Google, cruzando por cédula.
 *
 * El censo se digitalizó dos veces —una a la hoja, otra al RUD— y cada
 * digitalización perdió cosas distintas. Las dos comparten la cédula, así que
 * lo que a una le falta se puede completar con la otra:
 *
 *   · la fecha de nacimiento, sin la cual los cuatro indicadores de edad del
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
 *
 * Vive en una clase porque hay dos formas de correrlo: la consola aquí y una
 * sola vez por web en el servidor, que no tiene SSH.
 */
final class ConciliadorHoja
{
    /**
     * Las columnas de la hoja del RUFE, por posición.
     *
     * La hoja no tiene un encabezado que se pueda leer con seguridad —son ocho
     * filas de membrete y encabezados partidos en dos niveles—, así que se va
     * por índice, igual que hace el tablero desde que existe.
     */
    private const COL = [
        'documento' => 8,
        'dia' => 11,
        'mes' => 12,
        'anio' => 13,
        'evacuada' => 20,
        'visita' => 21,
        'quienVisita' => 22,
    ];

    private const FILAS_ENCABEZADO = 8;

    /**
     * @return array<string,mixed>
     */
    public static function conciliar(string $archivo, bool $aplicar = false): array
    {
        $mano = fopen($archivo, 'r');

        if ($mano === false) {
            throw new \RuntimeException("No se pudo abrir «{$archivo}».");
        }

        $filas = [];
        $n = 0;

        while (($fila = fgetcsv($mano, 0, ',', '"', '\\')) !== false) {
            if ($n++ < self::FILAS_ENCABEZADO) {
                continue;
            }

            if (count($fila) > self::COL['quienVisita']) {
                $filas[] = $fila;
            }
        }

        fclose($mano);

        $personas = [];

        foreach (Db::all('SELECT id, reporte_id, numero_documento, fecha_nacimiento
                            FROM rufe_personas
                           WHERE numero_documento IS NOT NULL AND numero_documento <> \'\'') as $p) {
            $personas[(string) $p['numero_documento']] = $p;
        }

        $fichas = [];

        foreach (Db::all('SELECT id, radicado, alojamiento, visitada FROM rufe_reportes') as $r) {
            $fichas[(int) $r['id']] = $r;
        }

        $sinPareja = 0;
        $emparejadas = 0;
        $nacimientos = [];
        $porFicha = [];
        $discrepancias = [];

        foreach ($filas as $fila) {
            $documento = preg_replace('/\D+/', '', trim($fila[self::COL['documento']] ?? '')) ?? '';

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

            $fecha = self::fechaDe(
                $fila[self::COL['dia']] ?? '',
                $fila[self::COL['mes']] ?? '',
                $fila[self::COL['anio']] ?? ''
            );

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

            // Evacuación y visita son del HOGAR, no de la persona: la hoja las
            // repite en cada integrante y a veces solo la primera fila las
            // trae. Se toma el primer valor con contenido de esa ficha.
            $porFicha[$ficha] ??= ['evacuada' => '', 'visita' => '', 'quien' => ''];

            foreach ([['evacuada', self::COL['evacuada']], ['visita', self::COL['visita']]] as [$clave, $columna]) {
                $valor = strtoupper(trim($fila[$columna] ?? ''));

                if (($valor === 'SI' || $valor === 'NO') && $porFicha[$ficha][$clave] === '') {
                    $porFicha[$ficha][$clave] = $valor;
                }
            }

            $quien = trim($fila[self::COL['quienVisita']] ?? '');

            if ($quien !== '' && $porFicha[$ficha]['quien'] === '') {
                $porFicha[$ficha]['quien'] = mb_substr($quien, 0, 120);
            }
        }

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
                    'radicado' => $ficha['radicado'], 'documento' => '', 'campo' => 'evacuada',
                    'en_la_base' => 'EVACUADO', 'en_la_hoja' => 'NO',
                ];
            }

            if ($datos['visita'] !== '' && $ficha['visitada'] === 'SIN_DATO') {
                $visitas++;
            } elseif ($datos['visita'] !== '' && $ficha['visitada'] !== $datos['visita']) {
                $discrepancias[] = [
                    'radicado' => $ficha['radicado'], 'documento' => '', 'campo' => 'visitada',
                    'en_la_base' => (string) $ficha['visitada'], 'en_la_hoja' => $datos['visita'],
                ];
            }
        }

        if ($aplicar) {
            self::aplicar($nacimientos, $porFicha, $fichas);
        }

        return [
            'filas_hoja' => count($filas),
            'personas_base' => count($personas),
            'emparejadas' => $emparejadas,
            'sin_pareja' => $sinPareja,
            'nacimientos' => count($nacimientos),
            'evacuados' => $evacuadas,
            'visitas' => $visitas,
            'discrepancias' => $discrepancias,
        ];
    }

    /**
     * @param  array<int,string>  $nacimientos
     * @param  array<int,array<string,string>>  $porFicha
     * @param  array<int,array<string,mixed>>  $fichas
     */
    private static function aplicar(array $nacimientos, array $porFicha, array $fichas): void
    {
        $pdo = Db::conn();
        $pdo->beginTransaction();

        try {
            foreach ($nacimientos as $personaId => $fecha) {
                Db::exec(
                    'UPDATE rufe_personas SET fecha_nacimiento = :f
                      WHERE id = :i AND fecha_nacimiento IS NULL',
                    ['f' => $fecha, 'i' => $personaId]
                );
            }

            foreach ($porFicha as $id => $datos) {
                if (! isset($fichas[$id])) {
                    continue;
                }

                if ($datos['evacuada'] === 'SI') {
                    // Dos marcadores distintos para el mismo valor: con
                    // preparadas nativas, repetir `:a` en la misma sentencia es
                    // «Invalid parameter number» al preparar. Es el mismo fallo
                    // que dejó roto el buscador de la bandeja.
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
                        [
                            'v' => $datos['visita'],
                            'q' => $datos['quien'] !== '' ? $datos['quien'] : null,
                            'i' => $id,
                            'sin' => 'SIN_DATO',
                        ]
                    );
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * La fecha de nacimiento que la hoja parte en tres columnas.
     *
     * Devuelve null ante cualquier duda. Una fecha inventada aquí cambia de
     * grupo de edad a una persona real en un tablero que se le reporta a la
     * Alcaldía.
     */
    public static function fechaDe(string $dia, string $mes, string $anio): ?string
    {
        $d = (int) trim($dia);
        $m = (int) trim($mes);
        $a = (int) trim($anio);

        if ($a < 1900 || $a > (int) date('Y') || ! checkdate($m, $d, $a)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $a, $m, $d);
    }
}
