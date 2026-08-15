<?php

declare(strict_types=1);

namespace App\Core;

/** Petición HTTP entrante, ya normalizada. */
final class Request
{
    private array $cuerpo;

    /** @param array<string,string> $params comodines de la ruta */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public array $params = []
    ) {
        $this->cuerpo = $this->leerCuerpo();
    }

    public static function desdeGlobales(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Con la API en un subdirectorio, REQUEST_URI trae el prefijo del
        // directorio; se descuenta para que las rutas del router sean siempre
        // absolutas respecto a la raíz de la API.
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base));
        }

        return new self($metodo, '/'.trim($ruta, '/'));
    }

    /** El cuerpo llega como JSON; los formularios clásicos no se usan. */
    private function leerCuerpo(): array
    {
        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }

        $datos = json_decode($crudo, true);

        return is_array($datos) ? $datos : [];
    }

    public function input(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->cuerpo[$clave] ?? $porDefecto;
    }

    public function texto(string $clave, string $porDefecto = ''): string
    {
        $v = $this->cuerpo[$clave] ?? $porDefecto;

        return is_scalar($v) ? trim((string) $v) : $porDefecto;
    }

    public function query(string $clave, ?string $porDefecto = null): ?string
    {
        $v = $_GET[$clave] ?? $porDefecto;

        return is_scalar($v) ? (string) $v : $porDefecto;
    }

    public function param(string $clave): string
    {
        return $this->params[$clave] ?? '';
    }

    /** Token Bearer, o null. */
    public function token(): ?string
    {
        $cabecera = $this->cabecera('Authorization');
        if ($cabecera === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($cabecera), $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    public function cabecera(string $nombre): ?string
    {
        $clave = 'HTTP_'.str_replace('-', '_', strtoupper($nombre));
        if (isset($_SERVER[$clave])) {
            return (string) $_SERVER[$clave];
        }

        // Algunos Apache de hosting compartido no propagan Authorization a
        // $_SERVER; getallheaders() sí la ve.
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $nombre) === 0) {
                    return (string) $v;
                }
            }
        }

        return null;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
