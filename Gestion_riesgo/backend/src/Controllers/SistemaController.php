<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Migrador;
use App\Sistema\Actualizador;

/**
 * Actualización del sistema desde GitHub.
 *
 * Solo Administrador: aplicar una versión nueva reescribe el código del sitio y
 * corre migraciones. Es la acción de mayor privilegio del sistema, por encima
 * incluso de gestionar usuarios.
 */
final class SistemaController
{
    public function estado(Request $req): void
    {
        Response::ok((new Actualizador)->estado());
    }

    /**
     * Poner la base al día, y NADA más.
     *
     * ── Por qué existe, teniendo `actualizar()` al lado ──────────────────────
     *
     * Porque `actualizar()` se baja un paquete de GitHub y reescribe el código
     * del sitio entero. Eso es lo correcto cuando se quiere justamente eso, y
     * es un martillo enorme cuando lo único que falta es crear dos tablas.
     *
     * El caso que lo pide es el de siempre: el código se despliega con el
     * script y las migraciones no —a propósito, porque reescribir el esquema de
     * una base con datos de familias damnificadas sin poder mirar el resultado
     * es peor que acordarse a mano—. Entre las dos cosas hay un hueco, y hasta
     * hoy la única forma de cerrarlo era el martillo.
     *
     * Es seguro de repetir: las migraciones de este sistema solo añaden —hay
     * pruebas que rechazan un UPDATE, un MODIFY o un DROP dentro de
     * database/— y todas llevan IF NOT EXISTS. Correrlo dos veces no hace nada
     * la segunda.
     */
    public function migrar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);

        $raiz = is_dir(dirname(__DIR__, 2).'/database')
            ? dirname(__DIR__, 2)
            : dirname(__DIR__, 3);

        $antes = Migrador::tablas();
        $aplicados = Migrador::aplicar($raiz.'/database');
        $despues = Migrador::tablas();

        $nuevas = array_values(array_diff($despues, $antes));

        Auditoria::registrar(
            $req,
            'sistema.migraciones_aplicadas',
            $actor,
            'base',
            null,
            // Las tablas nuevas y no la lista entera de archivos: es lo que
            // de verdad cambió, y es lo que alguien querrá leer dentro de seis
            // meses preguntándose cuándo apareció una tabla.
            $nuevas === [] ? 'sin cambios' : implode(' ', $nuevas)
        );

        Response::ok([
            'archivos' => $aplicados,
            'tablas_nuevas' => $nuevas,
            'tablas' => count($despues),
        ]);
    }

    public function actualizar(Request $req): void
    {
        $actor = Auth::exigirUsuario($req);

        // Correr migraciones es lo correcto por omisión: el código nuevo suele
        // dar por hecho el esquema nuevo, y saltárselas deja el sitio en pie
        // pero roto. Se puede desactivar para separar los dos pasos cuando un
        // cambio de esquema es delicado.
        $migrar = $req->input('migrar', true) !== false;

        Auditoria::registrar($req, 'sistema.actualizacion_iniciada', $actor, 'despliegues', null, null);

        $resultado = (new Actualizador)->aplicar($actor, $migrar);

        $estados = array_map(
            static fn (array $r): string => $r['destino'].'='.$r['estado'],
            $resultado['resultados']
        );

        Auditoria::registrar(
            $req,
            'sistema.actualizacion_aplicada',
            $actor,
            'despliegues',
            $resultado['commit']['corto'],
            implode(' ', $estados)
        );

        Response::ok($resultado);
    }
}
