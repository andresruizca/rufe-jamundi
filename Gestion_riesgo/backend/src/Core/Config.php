<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Configuración cargada una sola vez desde config.php. */
final class Config
{
    private static ?array $data = null;

    public static function cargar(string $ruta): void
    {
        if (! is_file($ruta)) {
            throw new RuntimeException(
                'Falta config.php. Copia config.example.php a config.php y complétalo.'
            );
        }

        self::$data = require $ruta;
    }

    /** Lectura con notación de puntos: Config::get('db.host'). */
    public static function get(string $clave, mixed $porDefecto = null): mixed
    {
        if (self::$data === null) {
            throw new RuntimeException('La configuración no se ha cargado.');
        }

        $valor = self::$data;
        foreach (explode('.', $clave) as $parte) {
            if (! is_array($valor) || ! array_key_exists($parte, $valor)) {
                return $porDefecto;
            }
            $valor = $valor[$parte];
        }

        return $valor;
    }

    public static function esProduccion(): bool
    {
        return self::get('app.entorno', 'produccion') !== 'local';
    }
}
