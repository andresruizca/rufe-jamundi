<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dos envíos del mismo formulario que se cruzan en el aire.
 *
 * ── El fallo ─────────────────────────────────────────────────────────────────
 *
 * Los cuatro formularios que se pueden mandar sin sesión —el censo RUFE, la
 * inspección, la pre-inscripción ciudadana y la solicitud sin censo— llevan un
 * `envio_id` que el aparato genera una vez y repite en cada reintento. Cada uno
 * lo comprueba antes de insertar y, si ya está, devuelve el radicado que se
 * guardó la primera vez en vez de registrar dos veces al mismo hogar.
 *
 * Pero es un mirar-y-después-insertar. Cuando las dos peticiones llegan a la
 * vez —un doble toque, o el reintento que sale justo cuando la primera petición
 * por fin llega— las dos miran, las dos no encuentran nada, y las dos insertan.
 * La clave única de `envio_id` impide la ficha repetida, que es su trabajo, pero
 * la petición que pierde la carrera se llevaba un 500.
 *
 * Y ese 500 mentía. La ficha SÍ había quedado guardada: al funcionario en campo
 * le decía «Ocurrió un error en el servidor» sobre un formulario que acababa de
 * entrar bien, y lo natural entonces es volver a llenarlo entero. En el registro
 * de producción aparece cuatro veces en ocho días con un puñado de usuarios; en
 * campo, con mala cobertura y reintentos constantes, no es un caso raro.
 *
 * ── Por qué no se mira el código del error ───────────────────────────────────
 *
 * Se podría comprobar que el SQLSTATE sea 23000, pero eso cubre cualquier
 * violación de integridad, no solo esta clave, y el mensaje exacto cambia entre
 * versiones de MySQL. La pregunta que de verdad importa es más simple y no
 * depende de nadie: ¿está la fila ahí AHORA? Si está, esta petición perdió la
 * carrera y no hay nada que lamentar. Si no está, el fallo era otro y tiene que
 * subir tal cual.
 */
final class Reintento
{
    /**
     * Lo que ya quedó guardado con este `envio_id`, si de eso se trataba.
     *
     * @param  string  $sql  la consulta que devuelve la fila previa, con un solo
     *                       marcador `:e` para el `envio_id`
     * @return array<string,mixed>|null  la fila previa, o `null` si este fallo no
     *                                   era una carrera y hay que dejarlo subir
     */
    public static function filaPrevia(\Throwable $e, ?string $envioId, string $sql): ?array
    {
        // Sin `envio_id` no hay reintento posible: quien no lo manda no puede
        // haber chocado consigo mismo.
        if ($envioId === null || ! $e instanceof \PDOException) {
            return null;
        }

        return Db::first($sql, ['e' => $envioId]);
    }
}
