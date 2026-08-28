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

    /**
     * Todo lo que el censo sabe del hogar de esta cédula.
     *
     * ── Por qué esto no contradice el booleano de arriba ─────────────────────
     *
     * `estaInscrito()` responde una ruta PÚBLICA y por eso no puede decir más
     * que sí o no. Esto responde otra, a la que solo se llega **después de
     * subir la foto de la cédula**: quien quiera cosechar datos de familias
     * damnificadas tiene que subir una imagen por cada cédula que pruebe, y esa
     * imagen queda guardada en el servidor junto al intento.
     *
     * No es una barrera infranqueable —una imagen se falsifica— pero convierte
     * un ataque gratuito y silencioso en uno caro y que deja rastro. Ese es
     * todo el objetivo: que el coste de recorrer el censo sea mayor que el de
     * ir a preguntar a la Alcaldía.
     *
     * ── Y por qué vale la pena correr ese riesgo ─────────────────────────────
     *
     * Del otro lado hay una familia damnificada llenando un formulario desde un
     * celular, escribiendo a mano su dirección y los nombres de los suyos
     * —datos que un funcionario ya levantó con la casa delante— y
     * equivocándose. Enseñarle lo que ya se sabe y dejarle corregir es la
     * diferencia entre una solicitud que cuadra con su ficha y una que hay que
     * conciliar después a mano.
     *
     * @return array<string,mixed>|null null si esa cédula no está en el censo
     */
    public static function hogarDe(string $documento): ?array
    {
        if (! self::pareceCedula($documento)) {
            return null;
        }

        // La ficha se busca por CUALQUIER integrante, no solo por el jefe de
        // hogar: el hijo mayor de edad que hace el trámite está censado igual
        // que su madre, y es la misma regla que usa `estaInscrito()`.
        $ficha = Db::first(
            'SELECT r.id, r.radicado, r.zona, r.corregimiento, r.vereda_sector_barrio,
                    r.direccion, r.contacto_telefono, p.id AS persona_id
               FROM rufe_personas p
               JOIN rufe_reportes r ON r.id = p.reporte_id
              WHERE p.numero_documento = :d
                AND r.estado <> :rechazado
              ORDER BY r.creado_en DESC, r.id DESC
              LIMIT 1',
            ['d' => $documento, 'rechazado' => 'RECHAZADO']
        );

        if ($ficha === null) {
            return null;
        }

        $personas = Db::all(
            'SELECT id, orden, nombres, apellidos, tipo_documento, numero_documento,
                    parentesco, genero, fecha_nacimiento
               FROM rufe_personas
              WHERE reporte_id = :r
              ORDER BY orden ASC, id ASC',
            ['r' => (int) $ficha['id']]
        );

        return [
            'reporte_id' => (int) $ficha['id'],
            // El radicado NO viaja al navegador: no le sirve de nada a quien
            // llena el formulario y sí a quien quisiera coleccionar fichas.
            'zona' => $ficha['zona'] === 'RURAL' ? 'RURAL' : 'URBANA',
            'corregimiento' => (string) ($ficha['corregimiento'] ?? ''),
            'vereda' => (string) ($ficha['vereda_sector_barrio'] ?? ''),
            'direccion' => (string) ($ficha['direccion'] ?? ''),
            'telefono' => (string) ($ficha['contacto_telefono'] ?? ''),
            // Quién de la casa es el que está escribiendo: con eso el formulario
            // precarga su nombre y no el del jefe de hogar cuando no coinciden.
            'persona_id' => (int) $ficha['persona_id'],
            'personas' => array_map(
                static fn (array $p): array => [
                    'id' => (int) $p['id'],
                    'nombres' => (string) $p['nombres'],
                    'apellidos' => (string) $p['apellidos'],
                    'tipo_documento' => (int) $p['tipo_documento'],
                    'numero_documento' => (string) ($p['numero_documento'] ?? ''),
                    'parentesco' => (int) $p['parentesco'],
                    'genero' => (int) $p['genero'],
                    'fecha_nacimiento' => (string) ($p['fecha_nacimiento'] ?? ''),
                ],
                $personas
            ),
        ];
    }

    /**
     * En qué cambió una persona respecto de como la dejó el censo.
     *
     * Lo decide el SERVIDOR al recibir el envío, nunca el navegador. Si el
     * estado viniera del cliente, bastaría con mentir en una casilla para que
     * una corrección entrara marcada como «igual» y ningún funcionario la
     * mirara.
     *
     * Se comparan solo los campos que el ciudadano puede tocar. Y se comparan
     * normalizados —sin espacios de sobra, sin mayúsculas— porque «JUAN» y
     * «Juan» no son una corrección que nadie tenga que revisar.
     *
     * @param  array<string,mixed>  $enviada  la persona tal como la mandó el navegador
     * @param  array<string,mixed>|null  $censo  como la tenía el censo, si venía de él
     */
    public static function estadoDePersona(array $enviada, ?array $censo, bool $noViveAqui): string
    {
        if ($noViveAqui) {
            return 'NO_VIVE_AQUI';
        }

        if ($censo === null) {
            return 'NUEVA';
        }

        foreach (['nombres', 'apellidos', 'numero_documento', 'fecha_nacimiento'] as $campo) {
            if (self::comparable((string) ($enviada[$campo] ?? '')) !== self::comparable((string) ($censo[$campo] ?? ''))) {
                return 'CORREGIDA';
            }
        }

        foreach (['tipo_documento', 'parentesco', 'genero'] as $campo) {
            if ((int) ($enviada[$campo] ?? 0) !== (int) ($censo[$campo] ?? 0)) {
                return 'CORREGIDA';
            }
        }

        return 'IGUAL';
    }

    /** Texto reducido a lo que de verdad distingue un dato de otro. */
    private static function comparable(string $texto): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));
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
