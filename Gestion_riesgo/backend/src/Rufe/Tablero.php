<?php

declare(strict_types=1);

namespace App\Rufe;

use App\Core\Db;
use App\Riesgo\Recorrido;

/**
 * Las cifras del tablero, calculadas sobre la base oficial.
 *
 * Hasta hoy el tablero leía una hoja de Google en vivo desde el navegador de
 * cada persona. Eso tenía tres problemas a la vez: mostraba un censo distinto
 * del que usa el resto del sistema —dos pantallas del mismo sistema daban
 * cifras diferentes—, bajaba el censo entero al teléfono para sumarlo allí, y
 * dependía de que una hoja siguiera compartida como «cualquiera con el enlace»,
 * que es tanto como decir que el censo de damnificados era descargable por
 * quien tuviera la URL.
 *
 * Esta clase devuelve **exactamente la misma forma** que producía la hoja
 * (`total`, `asOf`, `barrios[]`, `hogares[]`), para que la interfaz del tablero
 * no cambie ni una línea: lo único que cambia es de dónde salen los números.
 *
 * ── Por qué se agrega en PHP y no en SQL ─────────────────────────────────────
 *
 * Son 1.400 fichas y 2.800 personas: cabe holgado en memoria y se lee de un
 * vistazo. La agrupación de barrios además necesita `Barrios::clave()`, que es
 * texto normalizado con alias — expresarla en SQL sería un CASE de cincuenta
 * líneas que nadie podría revisar ni probar por separado.
 */
final class Tablero
{
    /**
     * Los cortes de edad del tablero.
     *
     * Son los mismos que usaba el parser de la hoja, y no se tocan: cambiarlos
     * haría que las cifras de esta semana no se puedan comparar con las que la
     * Alcaldía ya reportó.
     */
    private const CORTES = [
        'Ninos' => 11,
        'Jovenes' => 28,
        'Adultos' => 59,
    ];

    /**
     * @return array<string,mixed> la misma forma que consumía el tablero
     */
    public static function dataset(): array
    {
        $reportes = Db::all(
            'SELECT id, radicado, zona, corregimiento, vereda_sector_barrio, direccion,
                    tipo_bien, estado_bien, forma_tenencia, alojamiento, observaciones,
                    visitada, quien_visito
               FROM rufe_reportes
              ORDER BY id'
        );

        $personas = Db::all(
            'SELECT reporte_id, genero, fecha_nacimiento FROM rufe_personas'
        );

        // Cuántas personas tiene cada hogar y cómo se reparten. Se recorre una
        // vez y se guarda por ficha: hacerlo por hogar serían mil cuatrocientas
        // pasadas sobre la misma lista.
        $porFicha = [];

        foreach ($personas as $p) {
            $id = (int) $p['reporte_id'];

            if (! isset($porFicha[$id])) {
                $porFicha[$id] = [
                    'total' => 0, 'M' => 0, 'F' => 0,
                    'Ninos' => 0, 'Jovenes' => 0, 'Adultos' => 0, 'AdultosMayores' => 0,
                ];
            }

            $porFicha[$id]['total']++;

            // Solo hombres y mujeres suman a las dos barras del tablero. Las
            // personas trans y las que el censo no preguntó cuentan en el total
            // pero no se reparten: inventarles una casilla sería peor que la
            // diferencia visible entre el total y la suma de las dos barras.
            $genero = (int) $p['genero'];

            if ($genero === 1) {
                $porFicha[$id]['M']++;
            } elseif ($genero === 2) {
                $porFicha[$id]['F']++;
            }

            $grupo = self::grupoDeEdad($p['fecha_nacimiento']);

            if ($grupo !== null) {
                $porFicha[$id][$grupo]++;
            }
        }

        $conteoNombres = [];

        foreach ($reportes as $r) {
            $nombre = (string) $r['vereda_sector_barrio'];
            $conteoNombres[$nombre] = ($conteoNombres[$nombre] ?? 0) + 1;
        }

        $grupos = Barrios::agrupar($conteoNombres);

        $barrios = [];
        $hogares = [];
        $total = 0;

        foreach ($reportes as $r) {
            $id = (int) $r['id'];
            $cuenta = $porFicha[$id] ?? [
                'total' => 0, 'M' => 0, 'F' => 0,
                'Ninos' => 0, 'Jovenes' => 0, 'Adultos' => 0, 'AdultosMayores' => 0,
            ];

            $clave = Barrios::clave((string) $r['vereda_sector_barrio']);
            $nombre = $grupos[$clave]['nombre'] ?? (string) $r['vereda_sector_barrio'];
            // El tablero dice «Urbana» y «Rural»; la base, «URBANO» y «RURAL».
            $zona = $r['zona'] === 'RURAL' ? 'Rural' : 'Urbana';

            if ($clave !== '') {
                if (! isset($barrios[$clave])) {
                    $barrios[$clave] = [
                        'name' => $nombre, 'total' => 0, 'M' => 0, 'F' => 0,
                        'Ninos' => 0, 'Jovenes' => 0, 'Adultos' => 0, 'AdultosMayores' => 0,
                        'zona' => $zona,
                    ];
                }

                foreach (['total', 'M', 'F', 'Ninos', 'Jovenes', 'Adultos', 'AdultosMayores'] as $campo) {
                    $barrios[$clave][$campo] += $cuenta[$campo];
                }
            }

            $total += $cuenta['total'];

            $hogares[] = [
                // El radicado y no el id interno: es lo que un funcionario puede
                // buscar en la bandeja para llegar a la ficha completa.
                'hogar' => (string) $r['radicado'],
                'barrio' => $nombre,
                'zona' => $zona,
                // La usa la sección Mapas, que saca el censo de esta misma
                // respuesta: es lo único con lo que puede ubicar un predio. El
                // tablero no la dibuja en ninguna parte, así que sigue viajando
                // de más a quien solo abre el tablero — para quitarla hay que
                // darle a Mapas su propia fuente primero, y eso es otro cambio.
                'direccion' => (string) $r['direccion'],
                'personas' => $cuenta['total'],
                'estadoBien' => Catalogos::ESTADOS_BIEN[$r['estado_bien']] ?? '',
                'tipoBien' => Catalogos::TIPOS_BIEN[$r['tipo_bien']]['etiqueta'] ?? '',
                'tenencia' => Catalogos::FORMAS_TENENCIA[$r['forma_tenencia']] ?? '',
                'visita' => self::siNoSinDato((string) $r['visitada']),
                'quienVisita' => (string) ($r['quien_visito'] ?? ''),
                'observacion' => (string) ($r['observaciones'] ?? ''),
                'evacuada' => $r['alojamiento'] === 'EVACUADO' ? 'SI' : 'NO',
            ];
        }

        return [
            'total' => $total,
            'asOf' => date('c'),
            'barrios' => array_values($barrios),
            'hogares' => $hogares,
            'warnings' => self::avisos($total, $personas),
            // El camino completo, que es lo que el tablero pasó a medir: no
            // cuánta gente entró por la primera puerta, sino dónde está cada
            // familia entre la primera y la última.
            'recorrido' => Recorrido::etapas(),
            'atascos' => Recorrido::atascos(),
        ];
    }

    /**
     * El grupo de edad, o null si el censo no trajo la fecha.
     *
     * La edad se calcula contra la FECHA DEL EVENTO y no contra hoy: si se
     * usara la fecha actual, un niño que cumple doce años cambiaría de grupo
     * solo, y la cifra que la Alcaldía reportó el mes pasado dejaría de
     * reproducirse.
     */
    public static function grupoDeEdad(?string $nacimiento): ?string
    {
        if ($nacimiento === null || trim($nacimiento) === '') {
            return null;
        }

        $fecha = strtotime($nacimiento);
        $evento = strtotime(Catalogos::FECHA_EVENTO_PREDETERMINADA);

        if ($fecha === false || $evento === false || $fecha > $evento) {
            return null;
        }

        $edad = (int) date('Y', $evento) - (int) date('Y', $fecha);

        if ((int) date('md', $evento) < (int) date('md', $fecha)) {
            $edad--;
        }

        if ($edad < 0 || $edad > 115) {
            return null;
        }

        foreach (self::CORTES as $grupo => $tope) {
            if ($edad <= $tope) {
                return $grupo;
            }
        }

        return 'AdultosMayores';
    }

    private static function siNoSinDato(string $valor): string
    {
        return match ($valor) {
            'SI' => 'SI',
            'NO' => 'NO',
            default => 'Sin dato',
        };
    }

    /**
     * Lo que el tablero tiene que advertir para no engañar a quien lo lee.
     *
     * Un indicador calculado sobre un tercio de la gente no está mal: está
     * incompleto, y la diferencia entre las dos cosas es justo lo que decide si
     * alguien lo copia a un informe para la Alcaldía.
     *
     * @param  list<array<string,mixed>>  $personas
     * @param  list<array<string,mixed>>  $reportes
     * @return list<string>
     */
    private static function avisos(int $total, array $personas): array
    {
        $avisos = [];

        $conEdad = 0;

        foreach ($personas as $p) {
            if (self::grupoDeEdad($p['fecha_nacimiento']) !== null) {
                $conEdad++;
            }
        }

        if ($total > 0 && $conEdad < $total) {
            $avisos[] = sprintf(
                'Los grupos de edad se calculan sobre %d de %d personas (%d%%): el censo en papel no trajo la fecha de nacimiento del resto.',
                $conEdad,
                $total,
                intdiv($conEdad * 100, $total)
            );
        }

        // El aviso de «cuántas fichas no dicen si se hizo la visita» se retiró
        // con la tarjeta que lo necesitaba. Esa columna del censo dejó de
        // llenarse: la visita de verdad es ahora la inspección de vivienda, que
        // tiene sus propios estados y su propia cola en el tablero.

        return $avisos;
    }
}
