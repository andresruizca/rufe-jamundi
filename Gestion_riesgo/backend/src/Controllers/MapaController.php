<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Rufe\Geocodificador;

/**
 * Ubicaciones para la sección Mapas.
 *
 * El navegador nunca habla con un servicio de geocodificación: pide aquí las
 * direcciones que necesita y recibe las que ya están resueltas. Las que no,
 * quedan anotadas como pendientes y las procesa un administrador por lotes.
 *
 * El reparto es deliberado. Geocodificar tiene cupo por segundo, a veces cuesta
 * dinero y necesita una clave que no puede viajar al navegador; y sobre todo, el
 * resultado es el mismo para todos, así que resolverlo una vez y guardarlo es lo
 * único sensato. Que sea por lotes y a mano es consecuencia del hosting: no hay
 * cron ni procesos en segundo plano.
 */
final class MapaController
{
    /** Cuántas direcciones acepta consultar de una vez. */
    private const MAX_CONSULTA = 3000;

    /** Cuántas se geocodifican por llamada, para no agotar el tiempo de PHP. */
    private const LOTE = 10;

    /**
     * Devuelve las coordenadas conocidas de una lista de direcciones y apunta
     * las desconocidas para geocodificarlas después.
     */
    public function ubicaciones(Request $req): void
    {
        $direcciones = $req->input('direcciones');

        if (! is_array($direcciones)) {
            throw HttpError::validacion(
                ['direcciones' => 'Envíe la lista de direcciones a ubicar.'],
                'Faltan las direcciones.'
            );
        }

        if (count($direcciones) > self::MAX_CONSULTA) {
            throw HttpError::validacion(
                ['direcciones' => 'Máximo '.self::MAX_CONSULTA.' direcciones por consulta.'],
                'Demasiadas direcciones.'
            );
        }

        // Una misma dirección escrita de diez formas distintas es una sola
        // consulta: la clave se calcula sobre la versión normalizada.
        //
        // Se guarda además qué texto original produjo cada clave, porque la
        // respuesta va indexada por el texto que envió el navegador. Si fuera
        // por clave, el frontend tendría que repetir esta normalización en
        // TypeScript, y este proyecto ya pagó una vez el precio de tener el
        // mismo algoritmo escrito dos veces.
        $porClave = [];
        $originales = [];

        foreach ($direcciones as $direccion) {
            if (! is_string($direccion) || ! Geocodificador::utilizable($direccion)) {
                continue;
            }
            $clave = Geocodificador::clave($direccion);
            $porClave[$clave] = Geocodificador::normalizar($direccion);
            $originales[$clave][] = $direccion;
        }

        if ($porClave === []) {
            Response::ok(['ubicaciones' => [], 'pendientes' => 0, 'descartadas' => count($direcciones)]);

            return;
        }

        $conocidas = $this->buscarPorClaves(array_keys($porClave));

        // Las que no estaban se anotan para el próximo lote. Se insertan sin
        // coordenadas: existir en la tabla es justamente lo que las marca como
        // pendientes.
        $nuevas = array_diff_key($porClave, $conocidas);
        foreach ($nuevas as $clave => $normalizada) {
            Db::exec(
                "INSERT INTO rufe_geocodificacion (clave, direccion, precision_geo)
                      VALUES (:c, :d, 'FALLIDA')
                 ON DUPLICATE KEY UPDATE clave = clave",
                ['c' => $clave, 'd' => $normalizada]
            );
        }

        $ubicaciones = [];
        $resueltas = 0;

        foreach ($conocidas as $clave => $fila) {
            if ($fila['latitud'] === null || ! Geocodificador::pintable((string) $fila['precision_geo'])) {
                continue;
            }

            $resueltas++;
            $punto = [
                'lat' => (float) $fila['latitud'],
                'lon' => (float) $fila['longitud'],
                'precision' => $fila['precision_geo'],
                'fuente' => $fila['fuente'],
            ];

            foreach ($originales[$clave] ?? [] as $textoOriginal) {
                $ubicaciones[$textoOriginal] = $punto;
            }
        }

        Response::ok([
            'ubicaciones' => $ubicaciones,
            'consultadas' => count($porClave),
            'pendientes' => count($porClave) - $resueltas,
            'descartadas' => count($direcciones) - count($porClave),
        ]);
    }

    /** Cuántas direcciones hay resueltas, pendientes y fallidas. */
    public function estado(Request $req): void
    {
        Auth::exigirUsuario($req);

        $filas = Db::all(
            'SELECT precision_geo, COUNT(*) AS total FROM rufe_geocodificacion GROUP BY precision_geo'
        );

        $porPrecision = [];
        foreach ($filas as $f) {
            $porPrecision[(string) $f['precision_geo']] = (int) $f['total'];
        }

        $pendientes = (int) (Db::first(
            'SELECT COUNT(*) AS t FROM rufe_geocodificacion
              WHERE latitud IS NULL AND intentos < :max',
            ['max' => Geocodificador::MAX_INTENTOS]
        )['t'] ?? 0);

        Response::ok([
            'por_precision' => $porPrecision,
            'pendientes' => $pendientes,
            'lote' => self::LOTE,
            'google_activo' => Geocodificador::hayGoogle(),
            'segundos_por_direccion' => Geocodificador::PAUSA_SEGUNDOS,
        ]);
    }

    /**
     * Geocodifica un lote de direcciones pendientes.
     *
     * Se llama repetidamente desde la pantalla de administración hasta que no
     * queden pendientes. El lote es pequeño a propósito: entre el segundo de
     * pausa que exige OpenStreetMap y el límite de ejecución de PHP en hosting
     * compartido, pedir más de una decena arriesga que el proceso se corte a la
     * mitad.
     */
    public function geocodificar(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);

        $pendientes = Db::all(
            'SELECT clave, direccion FROM rufe_geocodificacion
              WHERE latitud IS NULL AND intentos < :max
              ORDER BY intentos ASC, creado_en ASC
              LIMIT '.self::LOTE,
            ['max' => Geocodificador::MAX_INTENTOS]
        );

        $resueltas = 0;
        $fallidas = 0;

        foreach ($pendientes as $i => $fila) {
            // La política de OpenStreetMap exige no pasar de una petición por
            // segundo. La pausa va antes de cada consulta menos la primera.
            if ($i > 0) {
                sleep(Geocodificador::PAUSA_SEGUNDOS);
            }

            $punto = Geocodificador::resolver((string) $fila['direccion']);

            if ($punto === null) {
                Db::exec(
                    'UPDATE rufe_geocodificacion
                        SET intentos = intentos + 1, ultimo_intento = NOW()
                      WHERE clave = :c',
                    ['c' => $fila['clave']]
                );
                $fallidas++;

                continue;
            }

            Db::exec(
                'UPDATE rufe_geocodificacion
                    SET latitud = :lat, longitud = :lon, precision_geo = :p, fuente = :f,
                        etiqueta = :e, intentos = intentos + 1, ultimo_intento = NOW()
                  WHERE clave = :c',
                [
                    'lat' => $punto['lat'],
                    'lon' => $punto['lon'],
                    'p' => $punto['precision'],
                    'f' => $punto['fuente'],
                    'e' => $punto['etiqueta'],
                    'c' => $fila['clave'],
                ]
            );

            Geocodificador::pintable($punto['precision']) ? $resueltas++ : $fallidas++;
        }

        $quedan = (int) (Db::first(
            'SELECT COUNT(*) AS t FROM rufe_geocodificacion
              WHERE latitud IS NULL AND intentos < :max',
            ['max' => Geocodificador::MAX_INTENTOS]
        )['t'] ?? 0);

        // Queda constancia de la operación y de su tamaño, nunca de las
        // direcciones: son datos de ubicación de personas damnificadas.
        Auditoria::registrar(
            $req,
            'mapa.geocodificacion_ejecutada',
            $usuario,
            'rufe_geocodificacion',
            null,
            count($pendientes).' procesadas, '.$resueltas.' ubicadas'
        );

        Response::ok([
            'procesadas' => count($pendientes),
            'ubicadas' => $resueltas,
            'sin_ubicar' => $fallidas,
            'pendientes' => $quedan,
        ]);
    }

    /**
     * Corrige a mano un punto mal ubicado.
     *
     * Con direcciones de censo escritas a la carrera esto no es un caso raro
     * sino la mitad del trabajo, así que tiene que poder hacerse sin tocar la
     * base de datos por fuera.
     */
    public function corregir(Request $req): void
    {
        $usuario = Auth::exigirUsuario($req);
        $clave = $req->param('clave');

        $fila = Db::first('SELECT clave FROM rufe_geocodificacion WHERE clave = :c', ['c' => $clave]);
        if ($fila === null) {
            throw HttpError::noEncontrado('Esa dirección no está registrada.');
        }

        $lat = $req->input('latitud');
        $lon = $req->input('longitud');
        $errores = [];

        if (! is_numeric($lat) || ! is_numeric($lon)) {
            $errores['latitud'] = 'Indique la latitud y la longitud del punto.';
        } elseif (! Geocodificador::dentroDeJamundi((float) $lat, (float) $lon)) {
            $errores['latitud'] = 'Ese punto queda fuera de Jamundí.';
        }

        if ($errores !== []) {
            throw HttpError::validacion($errores, 'Revise el punto indicado.');
        }

        Db::exec(
            "UPDATE rufe_geocodificacion
                SET latitud = :lat, longitud = :lon, precision_geo = 'EXACTA',
                    fuente = 'MANUAL', ultimo_intento = NOW()
              WHERE clave = :c",
            ['lat' => (float) $lat, 'lon' => (float) $lon, 'c' => $clave]
        );

        Auditoria::registrar(
            $req,
            'mapa.ubicacion_corregida',
            $usuario,
            'rufe_geocodificacion',
            $clave
        );

        Response::ok(['clave' => $clave, 'precision' => 'EXACTA', 'fuente' => 'MANUAL']);
    }

    /**
     * Las filas ya guardadas de un conjunto de claves.
     *
     * Se consulta por bloques porque una lista de mil marcadores en un `IN`
     * revienta el límite de parámetros de la sentencia preparada.
     *
     * @param  list<string>  $claves
     * @return array<string, array<string,mixed>>
     */
    private function buscarPorClaves(array $claves): array
    {
        $encontradas = [];

        foreach (array_chunk($claves, 200) as $bloque) {
            $marcadores = [];
            $params = [];

            foreach ($bloque as $i => $clave) {
                $marcadores[] = ':k'.$i;
                $params['k'.$i] = $clave;
            }

            $filas = Db::all(
                'SELECT clave, latitud, longitud, precision_geo, fuente
                   FROM rufe_geocodificacion
                  WHERE clave IN ('.implode(',', $marcadores).')',
                $params
            );

            foreach ($filas as $fila) {
                $encontradas[(string) $fila['clave']] = $fila;
            }
        }

        return $encontradas;
    }
}
