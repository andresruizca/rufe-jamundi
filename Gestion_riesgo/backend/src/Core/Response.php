<?php

declare(strict_types=1);

namespace App\Core;

/** Respuestas JSON. Todo lo que sale de la API pasa por aquí. */
final class Response
{
    public static function json(mixed $datos, int $estado = 200): void
    {
        http_response_code($estado);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function ok(mixed $datos = null): void
    {
        self::json(['ok' => true, 'data' => $datos]);
    }

    /** @param array<string,string> $errores */
    public static function error(string $mensaje, int $estado = 400, array $errores = []): void
    {
        $cuerpo = ['ok' => false, 'message' => $mensaje];
        if ($errores !== []) {
            $cuerpo['errors'] = $errores;
        }

        self::json($cuerpo, $estado);
    }

    public static function sinContenido(): void
    {
        http_response_code(204);
    }

    /**
     * Cabeceras CORS. Solo se responde con el origen si está en la lista
     * blanca: devolver `*` junto a credenciales lo rechaza el navegador, y
     * reflejar cualquier origen anula la protección.
     */
    public static function cors(): void
    {
        $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
        $permitidos = (array) Config::get('cors.origenes', []);

        if ($origen !== '' && in_array($origen, $permitidos, true)) {
            header('Access-Control-Allow-Origin: '.$origen);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }
}
