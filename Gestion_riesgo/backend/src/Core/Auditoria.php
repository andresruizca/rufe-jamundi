<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Bitácora de acciones sensibles. */
final class Auditoria
{
    public static function registrar(
        Request $req,
        string $accion,
        ?array $usuario = null,
        ?string $entidad = null,
        ?string $entidadId = null,
        ?string $detalle = null
    ): void {
        try {
            Db::exec(
                'INSERT INTO auditoria (usuario_id, usuario_email, accion, entidad, entidad_id, detalle, ip)
                 VALUES (:uid, :email, :accion, :entidad, :eid, :detalle, :ip)',
                [
                    'uid'     => $usuario['id'] ?? null,
                    'email'   => $usuario['email'] ?? null,
                    'accion'  => $accion,
                    'entidad' => $entidad,
                    'eid'     => $entidadId,
                    'detalle' => $detalle,
                    'ip'      => $req->ip(),
                ]
            );
        } catch (Throwable) {
            // La bitácora nunca debe tumbar la operación que la origina: si no
            // se puede escribir, se pierde el registro pero la acción del
            // usuario sigue adelante.
        }
    }
}
