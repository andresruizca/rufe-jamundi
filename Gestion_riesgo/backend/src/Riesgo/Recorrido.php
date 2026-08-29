<?php

declare(strict_types=1);

namespace App\Riesgo;

use App\Core\Db;
use App\Controllers\PreinscripcionController;
use App\Rufe\Catalogos;

/**
 * El camino que recorre una familia damnificada, de punta a punta.
 *
 * ── Qué es esto ──────────────────────────────────────────────────────────────
 *
 * Entra en el censo, alguien la llama, ella pide el turno, un ingeniero
 * inspecciona su vivienda y se decide. Cinco etapas, cinco tablas distintas, y
 * ningún identificador común que las ate: el censo tiene su ficha, la
 * preinscripción llega por la cédula que la persona escribió en el celular y la
 * inspección puede venir enlazada a la ficha o solo con el documento del
 * propietario.
 *
 * Cómo se atan esas tablas es la decisión más delicada del sistema. Si dos
 * pantallas la resuelven cada una por su cuenta, el call center dirá que
 * quedan mil por llamar y el tablero dirá otra cosa, las dos con aire de
 * verdad. Por eso las reglas viven aquí, en un solo sitio, y las usan las dos.
 *
 * ── Por qué en trozos y no un solo bloque ────────────────────────────────────
 *
 * El call center necesita saber quién TERMINÓ, así que solo mira inspecciones
 * aprobadas. El tablero necesita además saber a quién ya lo visitó el
 * ingeniero aunque su dictamen siga en trámite —esa gente no está esperando
 * visita, está esperando respuesta, y es una cola distinta—. Se comparte lo
 * que debe ser idéntico y cada uno añade lo suyo.
 */
final class Recorrido
{
    /**
     * El jefe de hogar de la ficha.
     *
     * Todo lo demás cuelga de él: la preinscripción se busca por su cédula y la
     * inspección también. `LIMIT 1` porque una ficha mal capturada puede tener
     * dos jefes, y sin él ese hogar saldría dos veces en todos los conteos.
     */
    public const JEFE = '
        LEFT JOIN rufe_personas jefe
               ON jefe.id = (SELECT j2.id FROM rufe_personas j2
                              WHERE j2.reporte_id = r.id AND j2.parentesco = :jefe
                              ORDER BY j2.orden ASC LIMIT 1)';

    /** Su solicitud más reciente, encontrada por la cédula del jefe de hogar. */
    public const PREINSCRIPCION = '
        LEFT JOIN preinscripciones pre
               ON pre.id = (SELECT p2.id FROM preinscripciones p2
                             WHERE p2.documento = jefe.numero_documento
                               AND jefe.numero_documento IS NOT NULL
                             ORDER BY p2.creado_en DESC, p2.id DESC LIMIT 1)';

    /**
     * La última llamada. Solo `LLAMADA`, nunca un WhatsApp.
     *
     * Mandar un mensaje no es haber hablado con nadie. Contarlo como contacto
     * daría por atendido a un hogar al que solo le llegó un enlace que quizá
     * no abrió.
     */
    public const ULTIMA_LLAMADA = '
        LEFT JOIN rufe_gestiones g
               ON g.id = (SELECT g2.id FROM rufe_gestiones g2
                           WHERE g2.reporte_id = r.id AND g2.canal = \'LLAMADA\'
                           ORDER BY g2.creado_en DESC, g2.id DESC LIMIT 1)';

    /**
     * Su inspección aprobada, si la tiene. El final del camino.
     *
     * Se cruza por la ficha O por la cédula del propietario porque
     * `rufe_reporte_id` admite nulos: una inspección capturada sin enlazar
     * existiría sin que nadie la viera, y esa familia seguiría recibiendo
     * llamadas después de haber terminado su trámite.
     */
    public const INSPECCION_APROBADA = '
        LEFT JOIN inspeccion_viviendas insp
               ON insp.id = (SELECT i2.id FROM inspeccion_viviendas i2
                              WHERE i2.estado = \'APROBADA\'
                                AND ('.self::ATA_INSPECCION.')
                              ORDER BY i2.id DESC LIMIT 1)';

    /**
     * Cualquier inspección suya, esté en el estado que esté.
     *
     * Es la etapa «ya lo visitó el ingeniero». Quien la tiene y no está
     * aprobada no espera una visita: espera un dictamen, y son dos atascos
     * distintos con dos responsables distintos.
     */
    public const INSPECCION_CUALQUIERA = '
        LEFT JOIN inspeccion_viviendas insp_toda
               ON insp_toda.id = (SELECT i3.id FROM inspeccion_viviendas i3
                                   WHERE i3.estado <> \'ARCHIVADA\'
                                     AND ('.self::ATA_INSPECCION_TODA.')
                                   ORDER BY i3.id DESC LIMIT 1)';

    /** Cómo se reconoce que una inspección es de este hogar. */
    private const ATA_INSPECCION = 'i2.rufe_reporte_id = r.id
                                    OR (jefe.numero_documento IS NOT NULL
                                        AND jefe.numero_documento <> \'\'
                                        AND i2.propietario_documento = jefe.numero_documento)';

    /** La misma regla, con el alias de la otra unión. */
    private const ATA_INSPECCION_TODA = 'i3.rufe_reporte_id = r.id
                                         OR (jefe.numero_documento IS NOT NULL
                                             AND jefe.numero_documento <> \'\'
                                             AND i3.propietario_documento = jefe.numero_documento)';

    /** Lo que comparten todas las pantallas que miran el recorrido. */
    public const CRUCE = self::JEFE.self::PREINSCRIPCION.self::ULTIMA_LLAMADA.self::INSPECCION_APROBADA;

    /**
     * Las cinco etapas, y con qué se reconoce cada una.
     *
     * En orden, y el orden importa: el tablero dibuja la caída de una a la
     * siguiente, y una etapa fuera de sitio convertiría un avance en una fuga.
     *
     * `null` en la primera porque «estar en el censo» no se comprueba: es
     * estar en la tabla.
     *
     * @var array<string,array{nombre:string,pie:string,condicion:?string}>
     */
    public const ETAPAS = [
        'censadas' => [
            'nombre' => 'En el censo',
            'pie' => 'Hogares del RUFE',
            'condicion' => null,
        ],
        'contactadas' => [
            'nombre' => 'Contactadas',
            'pie' => 'Alguien habló con ellas',
            'condicion' => 'g.id IS NOT NULL',
        ],
        'preinscritas' => [
            'nombre' => 'Preinscritas',
            'pie' => 'Pidieron el turno',
            'condicion' => "pre.id IS NOT NULL AND pre.estado <> 'DESCARTADA'",
        ],
        'inspeccionadas' => [
            'nombre' => 'Inspeccionadas',
            'pie' => 'Las visitó el ingeniero',
            'condicion' => 'insp_toda.id IS NOT NULL',
        ],
        'aprobadas' => [
            'nombre' => 'Inspección aprobada',
            'pie' => 'Terminaron el trámite',
            'condicion' => 'insp.id IS NOT NULL',
        ],
    ];

    /**
     * Cuántos hogares hay en cada etapa.
     *
     * Una sola consulta con una suma por etapa, y no cinco: son cinco lecturas
     * de la misma tabla con los mismos cruces, y separarlas solo serviría para
     * que dos de ellas se contradigan si alguien inserta algo entre medias.
     *
     * @return list<array{clave:string,nombre:string,pie:string,hogares:int}>
     */
    public static function etapas(): array
    {
        $columnas = [];

        foreach (self::ETAPAS as $clave => $etapa) {
            $condicion = $etapa['condicion'] ?? '1 = 1';
            $columnas[] = 'SUM('.$condicion.') AS '.$clave;
        }

        $fila = Db::first(
            'SELECT '.implode(', ', $columnas).'
               FROM rufe_reportes r '.self::CRUCE.self::INSPECCION_CUALQUIERA,
            ['jefe' => Catalogos::PARENTESCO_JEFE]
        ) ?? [];

        $salida = [];

        foreach (self::ETAPAS as $clave => $etapa) {
            $salida[] = [
                'clave' => $clave,
                'nombre' => $etapa['nombre'],
                'pie' => $etapa['pie'],
                // Sobre una base vacía `SUM` devuelve NULL, no cero.
                'hogares' => (int) ($fila[$clave] ?? 0),
            ];
        }

        return $salida;
    }

    /**
     * Dónde está atascado el proceso ahora mismo.
     *
     * No son cifras para mirar: son trabajo pendiente. Por eso cada una lleva
     * la ruta de la pantalla donde se resuelve — un atasco sin ruta es una
     * alarma que suena y no dice dónde ir, y con tres operadoras y un ingeniero
     * eso acaba en que no lo atiende nadie.
     *
     * `recorrido` cuenta sobre el cruce de arriba; `tabla`, sobre una tabla
     * suelta que no necesita saber nada del censo.
     *
     * @var list<array{clave:string,nombre:string,pie:string,ruta:string,nivel:string,fuente:string,condicion:string}>
     */
    public const ATASCOS = [
        [
            'clave' => 'sin_llamar',
            'nombre' => 'Faltan por llamar',
            'pie' => 'Nadie los ha contactado',
            'ruta' => '/riesgo/callcenter?cola=pendiente',
            'nivel' => 'critico',
            'fuente' => 'recorrido',
            'condicion' => 'g.id IS NULL AND insp.id IS NULL',
        ],
        [
            'clave' => 'solicitudes_demoradas',
            'nombre' => 'Solicitudes demoradas',
            'pie' => 'Más de '.PreinscripcionController::DIAS_DEMORA.' días sin atender',
            'ruta' => '/riesgo/preinscripciones',
            'nivel' => 'aviso',
            'fuente' => 'tabla',
            // El mismo umbral que usa la bandeja de solicitudes, sacado de allí
            // y no escrito otra vez: si mañana se afloja a cinco días, el
            // tablero no puede seguir alarmando a los tres.
            'condicion' => "SELECT COUNT(*) AS n FROM preinscripciones
                             WHERE estado = 'RECIBIDA'
                               AND creado_en < (NOW() - INTERVAL "
                                   .PreinscripcionController::DIAS_DEMORA." DAY)",
        ],
        [
            'clave' => 'inspecciones_sin_dictamen',
            'nombre' => 'Inspecciones sin dictamen',
            'pie' => 'El ingeniero ya fue; falta decidir',
            'ruta' => '/riesgo/inspecciones',
            'nivel' => 'aviso',
            'fuente' => 'tabla',
            'condicion' => "SELECT COUNT(*) AS n FROM inspeccion_viviendas
                             WHERE estado IN ('RECIBIDA', 'EN_VALIDACION')",
        ],
        [
            'clave' => 'fuera_del_censo',
            'nombre' => 'Dicen no estar en el censo',
            'pie' => 'Sin revisar todavía',
            'ruta' => '/riesgo/sin-censo',
            'nivel' => 'aviso',
            'fuente' => 'tabla',
            'condicion' => "SELECT COUNT(*) AS n FROM solicitudes_sin_censo
                             WHERE estado = 'RECIBIDA'",
        ],
        [
            'clave' => 'sin_telefono',
            'nombre' => 'Sin teléfono',
            'pie' => 'Hay que buscarlos por otra vía',
            'ruta' => '/riesgo/callcenter?cola=sin_telefono',
            'nivel' => 'aviso',
            'fuente' => 'recorrido',
            'condicion' => "(r.contacto_telefono IS NULL OR r.contacto_telefono = '')
                            AND (jefe.telefono IS NULL OR jefe.telefono = '')",
        ],
    ];

    /**
     * Los atascos tal como se declaran, sin tocar la base de datos.
     *
     * Existe para que una prueba pueda comprobar que ninguno se quedó sin
     * nombre ni sin pantalla a la que llevar.
     *
     * @return list<array<string,string>>
     */
    public static function atascosDeclarados(): array
    {
        return self::ATASCOS;
    }

    /**
     * Los atascos con su cifra.
     *
     * @return list<array{clave:string,nombre:string,pie:string,valor:int,ruta:string,nivel:string}>
     */
    public static function atascos(): array
    {
        $salida = [];

        foreach (self::ATASCOS as $a) {
            $valor = $a['fuente'] === 'recorrido'
                ? (int) (Db::first(
                    'SELECT COUNT(*) AS n FROM rufe_reportes r '
                        .self::CRUCE.self::INSPECCION_CUALQUIERA
                        .' WHERE '.$a['condicion'],
                    ['jefe' => Catalogos::PARENTESCO_JEFE]
                )['n'] ?? 0)
                : (int) (Db::first($a['condicion'])['n'] ?? 0);

            $salida[] = [
                'clave' => $a['clave'],
                'nombre' => $a['nombre'],
                'pie' => $a['pie'],
                'valor' => $valor,
                'ruta' => $a['ruta'],
                'nivel' => $a['nivel'],
            ];
        }

        return $salida;
    }
}
