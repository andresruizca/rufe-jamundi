<?php

declare(strict_types=1);

namespace App\SinCenso;

use App\Core\Db;
use RuntimeException;

/**
 * El radicado que se le entrega a quien no aparece en el censo.
 *
 * Formato calcado de `Preinscripcion\Radicado` y `Rufe\Radicado`: Crockford
 * Base32 sin I, L, O ni U, ocho caracteres aleatorios y no correlativos.
 *
 * Prefijo propio —`SC`— y no `PRE`: aunque las dos series comparten formato,
 * son conceptos distintos (aquí no hay ficha RUFE detrás todavía) y un mismo
 * prefijo haría que un radicado de esta bandeja pareciera una pre-inscripción
 * al dictarlo por teléfono.
 */
final class Radicado
{
    private const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LONGITUD = 8;

    private const INTENTOS = 8;

    public static function generar(): string
    {
        for ($i = 0; $i < self::INTENTOS; $i++) {
            $candidato = self::componer();

            $existe = Db::first(
                'SELECT id FROM solicitudes_sin_censo WHERE radicado = :r LIMIT 1',
                ['r' => $candidato]
            );

            if ($existe === null) {
                return $candidato;
            }
        }

        // Con 32^8 combinaciones esto solo pasa si algo va muy mal. Preferimos
        // fallar a entregarle el mismo radicado a dos personas.
        throw new RuntimeException('No se pudo generar un radicado único.');
    }

    public static function componer(?int $ano = null): string
    {
        $sufijo = '';

        for ($i = 0; $i < self::LONGITUD; $i++) {
            $sufijo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return sprintf('SC-%04d-%s', $ano ?? (int) date('Y'), $sufijo);
    }

    public static function esValido(string $radicado): bool
    {
        return preg_match('/^SC-\d{4}-['.self::ALFABETO.']{'.self::LONGITUD.'}$/', $radicado) === 1;
    }
}
