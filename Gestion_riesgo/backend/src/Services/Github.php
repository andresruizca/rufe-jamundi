<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use Throwable;

/**
 * Lectura del historial del repositorio en GitHub.
 *
 * El token se usa solo desde el servidor y nunca se envía al navegador: si
 * viajara al frontend, cualquiera con las herramientas de desarrollo podría
 * leerlo y usarlo con los permisos de la cuenta.
 */
final class Github
{
    private const CACHE_CLAVE = 'github_commits_cache';
    private const CACHE_SEGUNDOS = 300;

    public function configurado(): bool
    {
        return Config::get('github.owner') !== '' && Config::get('github.repo') !== '';
    }

    public function repositorio(): array
    {
        return [
            'owner'  => (string) Config::get('github.owner', ''),
            'repo'   => (string) Config::get('github.repo', ''),
            'branch' => (string) Config::get('github.branch', 'main'),
            'url'    => sprintf(
                'https://github.com/%s/%s',
                (string) Config::get('github.owner', ''),
                (string) Config::get('github.repo', '')
            ),
        ];
    }

    /**
     * Commits recientes de la rama configurada, normalizados.
     *
     * Devuelve ['commits' => [...], 'error' => ?string, 'desde_cache' => bool].
     * Un fallo de red nunca lanza: la pestaña "Acerca de" debe seguir
     * mostrando la información del sistema aunque GitHub no responda.
     */
    public function commits(int $limite = 50, bool $forzar = false): array
    {
        if (! $this->configurado()) {
            return ['commits' => [], 'error' => 'El repositorio de GitHub no está configurado.', 'desde_cache' => false];
        }

        if (! $forzar) {
            $cache = $this->leerCache();
            if ($cache !== null) {
                return ['commits' => $cache, 'error' => null, 'desde_cache' => true];
            }
        }

        $repo = $this->repositorio();
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/commits?sha=%s&per_page=%d',
            rawurlencode($repo['owner']),
            rawurlencode($repo['repo']),
            rawurlencode($repo['branch']),
            max(1, min(100, $limite))
        );

        try {
            $cuerpo = $this->pedir($url);
        } catch (Throwable $e) {
            // Ante un fallo se sirve la caché vencida si existe: información
            // desactualizada es mucho más útil que una pantalla vacía.
            $cache = $this->leerCache(true);

            return [
                'commits'     => $cache ?? [],
                'error'       => $e->getMessage(),
                'desde_cache' => $cache !== null,
            ];
        }

        $items = json_decode($cuerpo, true);
        if (! is_array($items)) {
            return ['commits' => [], 'error' => 'GitHub devolvió una respuesta inesperada.', 'desde_cache' => false];
        }

        $commits = array_values(array_map([$this, 'normalizar'], $items));
        $this->guardarCache($commits);

        return ['commits' => $commits, 'error' => null, 'desde_cache' => false];
    }

    private function normalizar(array $item): array
    {
        $sha = (string) ($item['sha'] ?? '');
        $mensajeCompleto = (string) ($item['commit']['message'] ?? '');
        $lineas = preg_split('/\r?\n/', $mensajeCompleto) ?: [''];

        return [
            'sha'          => $sha,
            'sha_corto'    => substr($sha, 0, 7),
            'titulo'       => trim($lineas[0]),
            'descripcion'  => trim(implode("\n", array_slice($lineas, 1))),
            'autor_nombre' => (string) ($item['commit']['author']['name'] ?? 'Desconocido'),
            'autor_login'  => $item['author']['login'] ?? null,
            'autor_avatar' => $item['author']['avatar_url'] ?? null,
            'fecha'        => (string) ($item['commit']['author']['date'] ?? ''),
            'url'          => (string) ($item['html_url'] ?? ''),
        ];
    }

    /** @throws \RuntimeException */
    private function pedir(string $url): string
    {
        $token = (string) Config::get('github.token', '');
        $cabeceras = [
            'Accept: application/vnd.github+json',
            // GitHub rechaza con 403 cualquier petición sin User-Agent.
            'User-Agent: SGR-Jamundi',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $cabeceras[] = 'Authorization: Bearer '.$token;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $cabeceras,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $cuerpo = curl_exec($ch);
            $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errorCurl = curl_error($ch);
            curl_close($ch);

            if ($cuerpo === false) {
                throw new \RuntimeException('No se pudo contactar a GitHub: '.$errorCurl);
            }
            if ($estado >= 400) {
                throw new \RuntimeException("GitHub respondió {$estado}.");
            }

            return (string) $cuerpo;
        }

        $contexto = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $cabeceras), 'timeout' => 20],
        ]);
        $cuerpo = @file_get_contents($url, false, $contexto);
        if ($cuerpo === false) {
            throw new \RuntimeException('No se pudo contactar a GitHub.');
        }

        return $cuerpo;
    }

    private function leerCache(bool $ignorarVencimiento = false): ?array
    {
        try {
            $fila = Db::first('SELECT valor, actualizado_en FROM ajustes WHERE clave = :c', ['c' => self::CACHE_CLAVE]);
        } catch (Throwable) {
            return null;
        }

        if ($fila === null) {
            return null;
        }

        if (! $ignorarVencimiento) {
            $edad = time() - strtotime((string) $fila['actualizado_en']);
            if ($edad > self::CACHE_SEGUNDOS) {
                return null;
            }
        }

        $datos = json_decode((string) $fila['valor'], true);

        return is_array($datos) ? $datos : null;
    }

    private function guardarCache(array $commits): void
    {
        try {
            Db::exec(
                'INSERT INTO ajustes (clave, valor) VALUES (:c, :v)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_en = NOW()',
                ['c' => self::CACHE_CLAVE, 'v' => json_encode($commits, JSON_UNESCAPED_UNICODE)]
            );
        } catch (Throwable) {
            // Sin caché el módulo sigue funcionando, solo pega más a GitHub.
        }
    }
}
