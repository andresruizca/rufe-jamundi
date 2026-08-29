<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router mínimo. Las rutas se declaran con comodines `{nombre}`, que se
 * exponen en Request::param().
 */
final class Router
{
    /** @var array<int,array{metodo:string,patron:string,handler:callable,rol:?array}> */
    private array $rutas = [];

    /** @param string[]|null $roles null = ruta pública */
    public function get(string $patron, callable $h, ?array $roles = null): void
    {
        $this->add('GET', $patron, $h, $roles);
    }

    /** @param string[]|null $roles */
    public function post(string $patron, callable $h, ?array $roles = null): void
    {
        $this->add('POST', $patron, $h, $roles);
    }

    /** @param string[]|null $roles */
    public function put(string $patron, callable $h, ?array $roles = null): void
    {
        $this->add('PUT', $patron, $h, $roles);
    }

    /** @param string[]|null $roles */
    public function delete(string $patron, callable $h, ?array $roles = null): void
    {
        $this->add('DELETE', $patron, $h, $roles);
    }

    /** @param string[]|null $roles */
    private function add(string $metodo, string $patron, callable $h, ?array $roles): void
    {
        $this->rutas[] = [
            'metodo'  => $metodo,
            'patron'  => '/'.trim($patron, '/'),
            'handler' => $h,
            'rol'     => $roles,
        ];
    }

    /**
     * El PATRÓN de la ruta que se está atendiendo, para el registro de errores.
     *
     * Es `/preinscripcion/fichas/{id}` y no `/preinscripcion/fichas/482`: el
     * patrón agrupa los fallos de una misma ruta y, sobre todo, no escribe en el
     * registro el identificador de la ficha de una familia concreta.
     */
    private ?string $despachada = null;

    public function rutaDespachada(): ?string
    {
        return $this->despachada;
    }

    /**
     * Resuelve y ejecuta. Distingue "no existe" (404) de "existe pero con otro
     * método" (405) para que un error de cliente no se confunda con una ruta
     * mal escrita.
     */
    public function despachar(Request $req): void
    {
        $rutaCoincide = false;

        foreach ($this->rutas as $ruta) {
            $params = $this->emparejar($ruta['patron'], $req->ruta);
            if ($params === null) {
                continue;
            }

            $rutaCoincide = true;
            if ($ruta['metodo'] !== $req->metodo) {
                continue;
            }

            $req->params = $params;
            $this->despachada = $ruta['patron'];

            // El control de acceso vive aquí y no dentro de cada controlador:
            // así ninguna ruta puede quedarse sin guardia por olvido.
            if ($ruta['rol'] !== null) {
                $usuario = Auth::exigirUsuario($req);
                if (! in_array($usuario['rol'], $ruta['rol'], true)) {
                    throw HttpError::prohibido();
                }
            }

            ($ruta['handler'])($req);

            return;
        }

        throw $rutaCoincide
            ? new HttpError('Método no permitido para esta ruta.', 405)
            : HttpError::noEncontrado('La ruta solicitada no existe.');
    }

    /** @return array<string,string>|null */
    private function emparejar(string $patron, string $ruta): ?array
    {
        if (! str_contains($patron, '{')) {
            return $patron === $ruta ? [] : null;
        }

        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $patron);
        $regex = '#^'.$regex.'$#';

        if (preg_match($regex, $ruta, $m) !== 1) {
            return null;
        }

        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
