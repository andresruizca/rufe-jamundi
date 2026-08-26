<?php

declare(strict_types=1);

namespace App\Preinscripcion;

use App\Core\Db;

/**
 * La puerta de la pre-inscripción: quién está ya en el censo (RUFE).
 *
 * La pre-inscripción dejó de ser abierta. Es la CONTINUACIÓN del proceso de
 * quien ya fue censado en campo, y por eso lo primero que se pregunta es la
 * cédula: si no aparece en el RUFE, no se sigue — se le da la línea de atención
 * para que una persona decida qué hacer con su caso.
 *
 * ── Lo que esto es, y hay que decirlo sin adornos ────────────────────────────
 *
 * Una ruta pública que responde «sí» o «no» sobre una cédula es, mirada de
 * cerca, una forma de averiguar si alguien está en la lista de damnificados.
 * Este controlador se escribió justamente evitando eso: «NO hay ninguna ruta
 * pública que devuelva pre-inscripciones».
 *
 * Como el flujo pedido lo exige, se acota todo lo que se puede sin romperlo:
 *
 *  • Se responde un BOOLEANO y nada más. Ni nombre, ni teléfono, ni radicado,
 *    ni cuántos hogares hay. Quien pruebe una cédula ajena no obtiene un dato
 *    nuevo sobre esa persona más allá del sí/no.
 *  • Va por POST, no por GET: una cédula en la barra de direcciones acaba en el
 *    registro de accesos de Apache, en el historial del navegador y en las
 *    estadísticas del cPanel.
 *  • Doble límite de tasa por hora y por día. Recorrer cédulas a mano exige
 *    millones de intentos; el límite diario los vuelve inviables.
 *
 * ── Qué cuenta como estar en el censo ────────────────────────────────────────
 *
 * CUALQUIER persona de la ficha, no solo el jefe de hogar: el hijo mayor de
 * edad que va a hacer el trámite está censado igual que su madre.
 *
 * Las fichas RECHAZADAS no cuentan. Una ficha rechazada es un caso que alguien
 * revisó y descartó, y dejarla pasar aquí sería colar por la puerta de atrás lo
 * que se cerró por la de adelante. Esa persona cae en la línea de atención, que
 * es donde tiene que caer: la decide un funcionario, no un formulario.
 */
final class Censo
{
    /**
     * La cédula como la escribió la persona, reducida a dígitos.
     *
     * La gente la escribe como la lee en el documento, con puntos. Y las dos
     * puntas del cruce guardan solo dígitos: `Preinscripcion\Validador` aplica
     * el mismo `preg_replace` y `Rufe\Validador` exige `^\d{4,30}$`.
     */
    public static function normalizar(string $crudo): string
    {
        return preg_replace('/\D+/', '', $crudo) ?? '';
    }

    /** Longitud plausible de una cédula colombiana, la misma que el validador. */
    public static function pareceCedula(string $documento): bool
    {
        $n = strlen($documento);

        return $n >= 5 && $n <= 15;
    }

    /** ¿Esta cédula aparece en alguna ficha del censo que siga en pie? */
    public static function estaInscrito(string $documento): bool
    {
        if (! self::pareceCedula($documento)) {
            return false;
        }

        $fila = Db::first(
            'SELECT 1 AS hay
               FROM rufe_personas p
               JOIN rufe_reportes r ON r.id = p.reporte_id
              WHERE p.numero_documento = :d
                AND r.estado <> :rechazado
              LIMIT 1',
            ['d' => $documento, 'rechazado' => 'RECHAZADO']
        );

        return $fila !== null;
    }
}
