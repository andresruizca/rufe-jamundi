<?php

declare(strict_types=1);

namespace App\Rufe;

use App\Core\Db;
use Throwable;

/**
 * La conciliación del censo RUD con la base, hogar por hogar.
 *
 * Vive en una clase y no dentro del script de consola porque hay dos formas de
 * correrla —la consola en una máquina con el archivo a mano, y una vez por web
 * en el servidor, que no tiene consola— y duplicar esto sería duplicar las
 * decisiones sobre qué familia entra al censo y cuál no.
 *
 * ── Dos propiedades que hacen que se pueda correr sin miedo ──────────────────
 *
 * **Reanudable.** Cada hogar se reconoce por su huella —fecha del evento,
 * dirección y cédula del jefe—, la misma que calcula el formulario. Un hogar ya
 * importado se salta. Si la corrida se corta a la mitad porque el servidor
 * agotó su tiempo, volver a llamarla sigue donde iba sin duplicar a nadie.
 *
 * **Por lotes.** `limite` corta después de N hogares nuevos. En un hosting
 * compartido, mil cuatrocientas transacciones seguidas se pasan del tiempo
 * máximo de PHP y la petición muere sin decir por dónde iba.
 */
final class ImportadorRud
{
    /**
     * Concilia el archivo con la base.
     *
     * @param  array{aplicar?: bool, limite?: int}  $opciones
     * @return array<string,mixed>
     */
    public static function conciliar(string $archivo, array $opciones = []): array
    {
        $aplicar = (bool) ($opciones['aplicar'] ?? false);
        $limite = (int) ($opciones['limite'] ?? 0);

        $filas = LectorXlsx::filas($archivo, Rud::HOJA);

        /** @var array<string, list<array<string,string>>> $hogares */
        $hogares = [];

        foreach ($filas as $fila) {
            $familia = trim($fila['numero_formulario'] ?? '');

            if ($familia !== '') {
                $hogares[$familia][] = $fila;
            }
        }

        // Lo que ya está, para no duplicar a nadie. Se lee una sola vez y se va
        // actualizando en memoria: consultarlo por hogar serían tres mil
        // consultas para responder siempre lo mismo.
        $documentos = [];

        foreach (Db::all('SELECT numero_documento FROM rufe_personas WHERE numero_documento IS NOT NULL') as $f) {
            $documentos[(string) $f['numero_documento']] = true;
        }

        $huellas = [];

        foreach (Db::all('SELECT huella, radicado FROM rufe_reportes') as $f) {
            $huellas[(string) $f['huella']] = (string) $f['radicado'];
        }

        $resumen = [
            'personas_archivo' => count($filas),
            'hogares_archivo' => count($hogares),
            'base_fichas' => count($huellas),
            'importados' => 0,
            'personas_importadas' => 0,
            'ya_estaban' => 0,
            'pendientes' => 0,
            'revision' => [],
            'motivos' => [],
        ];

        foreach ($hogares as $familia => $personas) {
            // Se llegó al tope del lote: lo que queda se cuenta y se deja para
            // la siguiente llamada, que lo retomará exactamente aquí.
            if ($limite > 0 && $resumen['importados'] >= $limite) {
                $resumen['pendientes']++;

                continue;
            }

            $resultado = self::conciliarHogar((string) $familia, $personas, $huellas, $documentos, $aplicar);

            if ($resultado['estado'] === 'ya_estaba') {
                $resumen['ya_estaban']++;

                continue;
            }

            if ($resultado['estado'] === 'revision') {
                $resumen['revision'][] = $resultado['fila'];
                $motivo = $resultado['fila']['motivo'];
                $resumen['motivos'][$motivo] = ($resumen['motivos'][$motivo] ?? 0) + 1;

                continue;
            }

            $resumen['importados']++;
            $resumen['personas_importadas'] += $resultado['personas'];
        }

        arsort($resumen['motivos']);

        // El cuadre. Ningún hogar puede desaparecer sin explicación, y esta es
        // la línea que lo demuestra sin tener que releer el Excel.
        $resumen['cuadra'] = ($resumen['importados'] + $resumen['ya_estaban']
            + count($resumen['revision']) + $resumen['pendientes']) === $resumen['hogares_archivo'];

        return $resumen;
    }

    /**
     * @param  list<array<string,string>>  $personas
     * @param  array<string,string>  $huellas
     * @param  array<string,bool>  $documentos
     * @return array{estado: string, fila?: array<string,mixed>, personas?: int}
     */
    private static function conciliarHogar(
        string $familia,
        array $personas,
        array &$huellas,
        array &$documentos,
        bool $aplicar
    ): array {
        $personas = Rud::conJefePrimero($personas);
        $bien = Rud::desarmarBien($personas[0]['bienes_afectados'] ?? '');

        $aparte = static function (string $motivo) use ($familia, $personas): array {
            $primera = $personas[0] ?? [];

            return ['estado' => 'revision', 'fila' => [
                'numero_formulario' => $familia,
                'personas' => count($personas),
                'jefe' => trim(($primera['primer_nombre'] ?? '').' '.($primera['primer_apellido'] ?? '')),
                'documento' => $primera['numero_documento'] ?? '',
                'motivo' => $motivo,
            ]];
        };

        // Un hogar de una sola persona sin jefe marcado no es un dato que falte:
        // esa persona es la cabeza de su hogar. Con dos o más NO se asciende a
        // nadie — quién encabeza una familia no se decide desde un script.
        $jefeDeducido = Rud::jefeDeducible($personas);

        if (! $jefeDeducido && ! Rud::tieneJefe($personas)) {
            return $aparte('Ningún integrante está marcado como jefe de hogar');
        }

        $telefono = Rud::telefonoDe($personas);

        if ($telefono === '') {
            return $aparte('Ningún integrante dejó teléfono');
        }

        $direccion = Rud::direccionDe($bien);
        $vereda = Rud::veredaDe($bien);

        if ($direccion === '' || $vereda === '') {
            return $aparte('Sin dirección, vereda ni corregimiento');
        }

        $entrada = [
            'evento' => Catalogos::EVENTO_PREDETERMINADO,
            'fecha_evento' => Catalogos::FECHA_EVENTO_PREDETERMINADA,
            'zona' => Rud::zonaPorCorregimiento($bien['corregimiento']),
            'corregimiento' => Rud::corregimientoOficial($bien['corregimiento']) ?? '',
            'vereda_sector_barrio' => $vereda,
            'direccion' => $direccion,
            // El RUD no pregunta si la familia tuvo que evacuar. No se deduce.
            'alojamiento' => 'LUGAR_HABITUAL',
            'tipo_bien' => Rud::codigoPorEtiqueta(Catalogos::TIPOS_BIEN, $bien['bien']) ?? 'VIVIENDA',
            'forma_tenencia' => Rud::codigoPorEtiqueta(Catalogos::FORMAS_TENENCIA, $bien['tenencia']) ?? 'NO_INFORMA',
            'estado_bien' => Rud::codigoPorEtiqueta(Catalogos::ESTADOS_BIEN, $bien['estado']) ?? 'NO_INFORMA',
            'contacto_telefono' => $telefono,
            'contacto_correo' => '',
            'observaciones' => 'Importado del RUD, formulario '.$familia.'.',
            // El consentimiento se firmó en el censo en papel, no con un clic.
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
            // propio: es el mismo al que la Alcaldía va a llamar para citarlo.
            $suyo = preg_replace('/\D+/', '', $p['telefono'] ?? '') ?? '';

            $entrada['personas'][] = [
                'orden' => $i + 1,
                'nombres' => trim(($p['primer_nombre'] ?? '').' '.($p['segundo_nombre'] ?? '')),
                'apellidos' => trim(($p['primer_apellido'] ?? '').' '.($p['segundo_apellido'] ?? '')),
                'tipo_documento' => Rud::tipoDocumentoCoherente(is_int($declarado) ? $declarado : null, $numero),
                'numero_documento' => $numero,
                'documento_otro' => '',
                'parentesco' => $jefeDeducido
                    ? Catalogos::PARENTESCO_JEFE
                    : (Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, $p['parentesco'] ?? '') ?? 15),
                // Vacío no es «no tiene»: es «el censo no lo preguntó». Para eso
                // existen los códigos «No informa» en los dos catálogos.
                'genero' => Rud::codigoPorEtiqueta(Catalogos::GENEROS, $p['nombre_genero'] ?? '') ?? 4,
                'pertenencia_etnica' => Rud::codigoPorEtiqueta(Catalogos::ETNIAS, $p['etnia'] ?? '') ?? 7,
                'fecha_nacimiento' => Rud::fechaNacimiento($p['fecha_nacimiento'] ?? '') ?? '',
                'telefono' => $suyo !== '' ? $suyo : ($i === 0 ? $telefono : ''),
            ];
        }

        // Quien decide si esto es una ficha válida es el mismo validador del
        // formulario. Aquí no se repite ni una sola de sus reglas.
        $revisado = Validador::reporte($entrada);

        if ($revisado['errores'] !== []) {
            $primero = array_key_first($revisado['errores']);

            return $aparte($primero.': '.$revisado['errores'][$primero]);
        }

        $datos = $revisado['datos'];
        $documentoJefe = null;

        foreach ($datos['personas'] as $p) {
            if ((int) $p['parentesco'] === Catalogos::PARENTESCO_JEFE) {
                $documentoJefe = $p['numero_documento'];

                break;
            }
        }

        // La misma huella que calcula el formulario: es lo que hace que este
        // importador y «Nueva ficha» reconozcan el mismo hogar como el mismo.
        $huella = Radicado::huella($datos['fecha_evento'], $datos['direccion'], $documentoJefe);

        if (isset($huellas[$huella])) {
            return ['estado' => 'ya_estaba'];
        }

        foreach ($datos['personas'] as $p) {
            $doc = (string) ($p['numero_documento'] ?? '');

            if ($doc !== '' && isset($documentos[$doc])) {
                return $aparte('La cédula '.$doc.' ya está registrada en otra ficha');
            }
        }

        if ($aplicar) {
            $huellas[$huella] = self::guardar($datos, $huella);
        } else {
            $huellas[$huella] = '(ensayo)';
        }

        foreach ($datos['personas'] as $p) {
            $doc = (string) ($p['numero_documento'] ?? '');

            if ($doc !== '') {
                $documentos[$doc] = true;
            }
        }

        return ['estado' => 'importado', 'personas' => count($datos['personas'])];
    }

    /**
     * Inserta un hogar completo en una transacción.
     *
     * Cabecera y personas juntas o nada: un hogar a medias es peor que ninguno,
     * porque nadie sabría a quién le falta información.
     *
     * @param  array<string,mixed>  $datos
     */
    private static function guardar(array $datos, string $huella): string
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
                    // INTERNO y no PUBLICO: lo levantó un funcionario en campo
                    // con el formato en papel, no la familia desde su celular.
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
}
