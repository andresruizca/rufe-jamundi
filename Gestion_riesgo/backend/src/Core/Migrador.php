<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Aplica archivos .sql del directorio database/.
 *
 * El hosting no da consola ni permite una herramienta de migraciones, así que
 * el esquema se aplica desde el navegador. Todo archivo aplicado aquí debe ser
 * idempotente (CREATE TABLE IF NOT EXISTS): esta clase no lleva registro de qué
 * se ejecutó, la seguridad de repetir viene del propio SQL.
 */
final class Migrador
{
    /**
     * Orden de aplicación. No es alfabético a propósito: rufe.sql declara claves
     * foráneas contra `usuarios`, así que schema.sql tiene que ir antes.
     *
     * @var list<string>
     */
    public const ARCHIVOS = [
        'schema.sql',
        'rufe.sql',
        'rufe_02_evidencias_y_envio.sql',
        'sistema_01_despliegues.sql',
        'mapa_01_geocodificacion.sql',
        // Va después de rufe.sql: declara una clave foránea contra
        // `rufe_reportes` y añade columnas a `rufe_evidencias`.
        'inspeccion_01_viviendas.sql',
        // Añade el punto GPS a la inspección, como el que ya toma el censo.
        'inspeccion_02_ubicacion.sql',
        // Añade el rol del profesional que inspecciona y sus datos.
        'sistema_02_rol_inspector.sql',
        // Va después de la inspección: apunta a `inspeccion_viviendas`.
        'preinscripcion_01.sql',
        // Amplía `rufe_evidencias.tipo`, que se quedó con los dos valores del
        // censo mientras el código ya usaba tres más.
        'evidencias_03_tipos.sql',
        // Catálogo de categorías de video y los videos ciudadanos.
        'preinscripcion_02_video.sql',
        // Zona urbana/rural y las señales de daño que marca el ciudadano.
        'preinscripcion_03_pasos.sql',
        // Las dos casillas de visita del RUFE en papel, que la digitalización
        // a MySQL nunca recogió y que sí estaban en la hoja de Google.
        'rufe_05_visita.sql',
        // Un video por cada daño marcado, en vez de un catálogo suelto que se
        // le pedía a todo el mundo. Va después de preinscripcion_02_video.sql,
        // que es quien crea `categorias_video`.
        'preinscripcion_04_video_por_dano.sql',
        // El rol de call center y el registro de llamadas. Va al final: apunta
        // a `rufe_reportes` y a `usuarios`, que ya existen mucho antes.
        'callcenter_01.sql',
        // El motivo del descarte, quién está llamando y el guión editable. Va
        // después de callcenter_01.sql y de preinscripcion_01.sql: añade una
        // columna a `preinscripciones` y apunta a `rufe_reportes`.
        'callcenter_02.sql',
        // El canal de la gestión y el envío del enlace por WhatsApp. Va después
        // de callcenter_01.sql, que es quien crea `rufe_gestiones`.
        'callcenter_03_whatsapp.sql',
        // Concilia los campos del formulario ciudadano con los del censo: el
        // nombre en dos, el tipo de documento y un solo vocabulario para la
        // zona. Va despues de preinscripcion_01.sql y de _03_pasos.sql, que son
        // quienes crean la tabla y su columna `zona`.
        'preinscripcion_06_conciliar.sql',
        // Solicitudes de quien no aparece en el censo. Va al final: apunta a
        // `rufe_reportes`, que ya existe mucho antes.
        'sincenso_01.sql',
        // El núcleo familiar dentro de la solicitud ciudadana. Va al final:
        // añade una columna a `preinscripciones` y una tabla que apunta a ella.
        'preinscripcion_05_hogar.sql',
        // La cara de atrás de la cédula. Va después de evidencias_03_tipos.sql,
        // que es quien dejó el ENUM con los cinco valores anteriores.
        'evidencias_04_cedula_reverso.sql',
        // Avisos al aparato. Va al último: apunta a `usuarios`, que existe
        // desde el principio, y no lo necesita nadie más.
        'push_01.sql',
    ];

    /**
     * @param  list<string>  $archivos
     * @return list<string> nombres aplicados, en orden
     */
    public static function aplicar(string $directorio, array $archivos = self::ARCHIVOS): array
    {
        $pdo = Db::conn();
        $aplicados = [];

        foreach ($archivos as $archivo) {
            $ruta = $directorio.'/'.$archivo;
            $sql = is_file($ruta) ? file_get_contents($ruta) : false;
            if ($sql === false) {
                throw new \RuntimeException("No se encontró database/{$archivo}.");
            }

            foreach (self::sentencias($sql) as $sentencia) {
                $pdo->exec($sentencia);
            }

            $aplicados[] = $archivo;
        }

        return $aplicados;
    }

    /**
     * PDO::exec no acepta varias sentencias por llamada cuando las preparadas
     * no se emulan, así que hay que partir el archivo.
     *
     * Los comentarios se quitan ANTES de partir por ';'. Si se filtrara por
     * "la sentencia empieza con --", se descartaría todo bloque precedido por un
     * comentario, que aquí es prácticamente cada CREATE TABLE.
     *
     * Consecuencia asumida: ningún archivo de database/ puede llevar un ';' ni
     * un '--' dentro de un literal de cadena.
     *
     * @return list<string>
     */
    public static function sentencias(string $sql): array
    {
        $sinComentarios = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', explode(';', $sinComentarios)),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /** @return list<string> */
    public static function tablas(): array
    {
        return array_map(
            static fn (array $f): string => (string) reset($f),
            Db::all('SHOW TABLES')
        );
    }
}
