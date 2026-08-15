<?php

declare(strict_types=1);

/**
 * Punto de entrada único de la API.
 *
 * Sin Composer a propósito: el hosting no tiene acceso por consola, así que no
 * se puede ejecutar `composer install` en el servidor. Un autoloader PSR-4 de
 * diez líneas cubre exactamente la misma necesidad y el despliegue se reduce a
 * copiar archivos.
 */

use App\Controllers\AcercaController;
use App\Controllers\AuthController;
use App\Controllers\UsuariosController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/*
 * Raíz del backend. Admite dos disposiciones:
 *   • Desarrollo: backend/{public/index.php, src/, config.php} → la raíz es el
 *     directorio padre de public/.
 *   • Producción: todo aplanado dentro de la carpeta pública en api/ → la raíz
 *     es este mismo directorio.
 * Se aplana en producción porque el hosting solo tiene una carpeta para el
 * sitio y no se puede colocar código por encima del document root; src/,
 * database/ y config.php quedan protegidos por .htaccess (ver esos archivos).
 */
$raiz = is_dir(__DIR__.'/src') ? __DIR__ : dirname(__DIR__);

spl_autoload_register(static function (string $clase) use ($raiz): void {
    $prefijo = 'App\\';
    if (! str_starts_with($clase, $prefijo)) {
        return;
    }

    $relativa = str_replace('\\', '/', substr($clase, strlen($prefijo)));
    $archivo = $raiz.'/src/'.$relativa.'.php';

    if (is_file($archivo)) {
        require $archivo;
    }
});

try {
    Config::cargar($raiz.'/config.php');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set((string) Config::get('app.zona', 'America/Bogota'));

// En producción los errores se registran, nunca se imprimen: un aviso de PHP
// impreso antes del JSON rompe la respuesta y puede filtrar rutas del servidor.
$produccion = Config::esProduccion();
ini_set('display_errors', $produccion ? '0' : '1');
error_reporting(E_ALL);

Response::cors();

// El preflight se responde antes de tocar la base de datos: es una petición
// automática del navegador, sin cuerpo ni sesión.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$router = new Router;

$auth = new AuthController;
$usuarios = new UsuariosController;
$acerca = new AcercaController;

// ── Públicas ─────────────────────────────────────────────────────────────
$router->get('/health', static function (): void {
    Response::ok(['estado' => 'ok', 'hora' => date('c')]);
});

$router->post('/auth/login', [$auth, 'login']);

// ── Autenticadas (cualquier rol) ─────────────────────────────────────────
$router->get('/auth/me', [$auth, 'me'], Auth::TODOS);
$router->post('/auth/logout', [$auth, 'logout'], Auth::TODOS);
$router->post('/auth/password', [$auth, 'cambiarPassword'], Auth::TODOS);

$router->get('/acerca/sistema', [$acerca, 'sistema'], Auth::TODOS);
$router->get('/acerca/actualizaciones', [$acerca, 'actualizaciones'], Auth::TODOS);

// ── Solo ADMINISTRADOR ───────────────────────────────────────────────────
$soloAdmin = [Auth::ADMINISTRADOR];
$router->get('/usuarios', [$usuarios, 'listar'], $soloAdmin);
$router->post('/usuarios', [$usuarios, 'crear'], $soloAdmin);
$router->get('/usuarios/{id}', [$usuarios, 'ver'], $soloAdmin);
$router->put('/usuarios/{id}', [$usuarios, 'actualizar'], $soloAdmin);
$router->delete('/usuarios/{id}', [$usuarios, 'eliminar'], $soloAdmin);
$router->post('/usuarios/{id}/password', [$usuarios, 'restablecerPassword'], $soloAdmin);

try {
    $router->despachar(Request::desdeGlobales());
} catch (HttpError $e) {
    Response::error($e->getMessage(), $e->estado(), $e->errores());
} catch (Throwable $e) {
    error_log('[SGR] '.$e->getMessage().' en '.$e->getFile().':'.$e->getLine());

    // El detalle del error solo se devuelve fuera de producción: en el servidor
    // real puede contener credenciales o la estructura interna del sistema.
    Response::error(
        $produccion ? 'Ocurrió un error en el servidor.' : $e->getMessage(),
        500
    );
}
