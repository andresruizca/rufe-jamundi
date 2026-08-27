<?php

declare(strict_types=1);

/**
 * La conciliación del RUD, corrida UNA VEZ desde el navegador.
 *
 * Existe por una razón concreta: el hosting de la Alcaldía no tiene consola.
 * `scripts/importar-rud.php` no se puede ejecutar allí, y abrir MySQL a
 * internet para correrlo desde fuera es peor idea que esta.
 *
 * ── Cómo se usa, y por qué así ───────────────────────────────────────────────
 *
 *   POST /api/<nombre>.php?clave=LA_CLAVE   con cuerpo (aunque sea vacío)
 *        &aplicar=1        escribe de verdad; sin esto es un ensayo
 *        &limite=150       cuántos hogares nuevos por llamada
 *
 * 1. **Se sube con otro nombre.** El Mod_Security de este hosting devuelve 406
 *    a cualquier POST contra un archivo que se llame «migrar.php» y parecidos.
 * 2. **POST con cuerpo.** Un POST sin cuerpo también lo bloquea.
 * 3. **Por lotes.** Mil cuatrocientas transacciones seguidas se pasan del
 *    tiempo máximo de PHP en un servidor compartido, y la petición muere sin
 *    decir por dónde iba. Con `limite` se llama varias veces; el importador es
 *    reanudable, así que cada llamada sigue donde quedó la anterior.
 * 4. **El Excel NO vive en el directorio público.** Son 3.184 nombres, cédulas
 *    y teléfonos de familias damnificadas: va en `sgr_almacen`, fuera de lo que
 *    Apache sirve, igual que las fotos.
 * 5. **La respuesta no trae datos personales**, solo cuentas y motivos. Lo que
 *    se responde por HTTP queda en registros que nadie limpia.
 *
 * Al terminar: **borre este archivo del servidor y vacíe `install_key`.**
 */

$raiz = is_dir(__DIR__.'/src') ? __DIR__ : dirname(__DIR__);

spl_autoload_register(static function (string $clase) use ($raiz): void {
    if (! str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = $raiz.'/src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});

use App\Core\Config;
use App\Rufe\ImportadorRud;

header('Content-Type: application/json; charset=utf-8');

function salir(int $estado, array $cuerpo): never
{
    http_response_code($estado);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

date_default_timezone_set('America/Bogota');
Config::cargar($raiz.'/config.php');

$esperada = (string) Config::get('install_key', '');

// Con la clave vacía este archivo queda inerte, que es lo que lo vuelve seguro
// de olvidar: vaciar `install_key` lo desactiva aunque alguien no lo borre.
if ($esperada === '' || ! hash_equals($esperada, (string) ($_GET['clave'] ?? ''))) {
    salir(403, ['ok' => false, 'message' => 'Clave de instalación incorrecta.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    salir(405, ['ok' => false, 'message' => 'Use POST para ejecutar la conciliación.']);
}

// Fuera del directorio público, junto a las evidencias.
$archivo = dirname($raiz, 2).'/sgr_almacen/rud-jamundi.xlsx';

if (! is_file($archivo)) {
    salir(404, ['ok' => false, 'message' => 'No se encontró el archivo del censo en el almacén.']);
}

$aplicar = ($_GET['aplicar'] ?? '') === '1';
$limite = max(0, (int) ($_GET['limite'] ?? 150));

@set_time_limit(0);
ignore_user_abort(true);

$inicio = microtime(true);
$r = ImportadorRud::conciliar($archivo, ['aplicar' => $aplicar, 'limite' => $limite]);

// Sin `revision`: esa lista lleva nombres y cédulas, y esto viaja por HTTP.
unset($r['revision']);

salir(200, [
    'ok' => true,
    'aplicado' => $aplicar,
    'segundos' => round(microtime(true) - $inicio, 1),
    'resumen' => $r,
]);
