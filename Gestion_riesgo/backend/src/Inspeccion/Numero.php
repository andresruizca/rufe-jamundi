<?php

declare(strict_types=1);

namespace App\Inspeccion;

use App\Core\Db;
use RuntimeException;

/**
 * El «Ficha No.» del encabezado del formato.
 *
 * Formato: INSP-AAAA-XXXXXXXX (18 caracteres), calcado de
 * `Rufe\Radicado` y por las mismas razones:
 *
 *  • Los ocho caracteres finales son aleatorios y no correlativos. Un
 *    consecutivo revelaría cuántas inspecciones lleva el municipio y permitiría
 *    adivinar el número de la de al lado.
 *  • El alfabeto es Crockford Base32 —sin I, L, O ni U—, para que nadie
 *    confunda un 1 con una I al dictarlo por teléfono y para que no salgan
 *    palabras por casualidad.
 *
 * El prefijo cambia porque un número que empieza por INSP se distingue de un
 * radicado del censo de un vistazo, y estos dos documentos van a convivir en la
 * misma carpeta.
 */
final class Numero
{
    private const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LONGITUD = 8;

    private const INTENTOS = 8;

    public static function generar(): string
    {
        for ($i = 0; $i < self::INTENTOS; $i++) {
            $candidato = self::componer();

            $existe = Db::first(
                'SELECT id FROM inspeccion_viviendas WHERE numero = :n LIMIT 1',
                ['n' => $candidato]
            );

            if ($existe === null) {
                return $candidato;
            }
        }

        // Con 32^8 combinaciones esto solo ocurre si algo va muy mal. Preferimos
        // fallar a entregar dos inspecciones con el mismo número.
        throw new RuntimeException('No se pudo generar un número de ficha único.');
    }

    public static function componer(?int $ano = null): string
    {
        $sufijo = '';

        for ($i = 0; $i < self::LONGITUD; $i++) {
            $sufijo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return sprintf('INSP-%04d-%s', $ano ?? (int) date('Y'), $sufijo);
    }

    public static function esValido(string $numero): bool
    {
        return preg_match('/^INSP-\d{4}-['.self::ALFABETO.']{'.self::LONGITUD.'}$/', $numero) === 1;
    }

    /**
     * Huella anti-duplicado: la misma vivienda, del mismo propietario, en la
     * misma fecha.
     *
     * No es una restricción UNIQUE: una vivienda puede inspeccionarse otra vez
     * más adelante —tras un segundo evento, o para revisar una decisión— y eso
     * es legítimo. Sirve para avisar de que ya existe una, no para impedirla.
     */
    public static function huella(string $fecha, string $direccion, string $documentoPropietario): string
    {
        $normalizada = preg_replace('/\s+/u', ' ', mb_strtolower(trim($direccion))) ?? '';

        return hash('sha256', $fecha.'|'.$normalizada.'|'.$documentoPropietario);
    }
}
