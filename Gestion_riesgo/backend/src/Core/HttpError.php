<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Error con código HTTP. Lo que llega aquí es texto pensado para el usuario:
 * el front controller lo muestra tal cual, así que nunca debe traer detalles
 * internos (rutas, SQL, credenciales).
 */
final class HttpError extends RuntimeException
{
    /** @param array<string,string> $errores validación por campo */
    public function __construct(
        string $mensaje,
        private readonly int $estado = 400,
        private readonly array $errores = []
    ) {
        parent::__construct($mensaje);
    }

    public function estado(): int
    {
        return $this->estado;
    }

    /** @return array<string,string> */
    public function errores(): array
    {
        return $this->errores;
    }

    public static function noAutenticado(string $m = 'Necesitas iniciar sesión.'): self
    {
        return new self($m, 401);
    }

    public static function prohibido(string $m = 'No tienes permiso para esta acción.'): self
    {
        return new self($m, 403);
    }

    public static function noEncontrado(string $m = 'El recurso no existe.'): self
    {
        return new self($m, 404);
    }

    /** @param array<string,string> $errores */
    public static function validacion(array $errores, string $m = 'Revisa los datos enviados.'): self
    {
        return new self($m, 422, $errores);
    }
}
