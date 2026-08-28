<?php

declare(strict_types=1);

namespace App\CallCenter;

use App\Core\Db;

/**
 * El guión de la llamada: lo que la operadora tiene delante todo el turno.
 *
 * Tres personas llaman a mil trescientas familias damnificadas en nombre de la
 * Alcaldía. Sin un guión común, cada una explica el formulario a su manera y el
 * municipio termina diciendo tres cosas distintas sobre el mismo trámite —y
 * alguna de las tres, tarde o temprano, promete una ayuda que nadie aprobó.
 *
 * ── Por qué el original vive en el código y las ediciones en la base ─────────
 *
 * El texto de abajo es el que se sirve mientras nadie lo haya cambiado. El
 * administrador puede reescribirlo desde el sistema, y cada versión queda como
 * una fila nueva en `callcenter_guion`: nunca se actualiza en sitio, porque
 * «¿desde cuándo se les está diciendo esto a las familias?» es una pregunta que
 * alguien va a hacer.
 *
 * Guardar el original aquí y no como una fila sembrada tiene dos motivos:
 *
 * 1. **No se puede perder.** Un guión borrado a mitad de campaña dejaría a las
 *    tres operadoras improvisando. Con el original en el código, «restaurar»
 *    siempre es posible aunque la tabla quede vacía.
 * 2. **El troceador de migraciones parte por `;`**, y este texto está lleno de
 *    puntos y coma, comillas y tildes. Sembrarlo en SQL era pedir que se
 *    partiera por la mitad en el primer despliegue.
 *
 * ── El formato ───────────────────────────────────────────────────────────────
 *
 * Texto plano con cuatro marcas al principio de la línea, para que se pueda
 * editar desde un cuadro de texto corriente sin aprender nada:
 *
 *   `## `  una sección del guión
 *   `» `   lo que se LEE en voz alta al ciudadano
 *   `- `   una indicación para la operadora, que no se dice
 *   `! `   lo que NO se debe decir ni prometer
 *   `? `   una pregunta frecuente y su respuesta
 *
 * Quien lo interpreta es la pantalla (`$lib/callcenter/guion.ts`). Aquí no se
 * valida la forma: un guión con una marca mal puesta se sigue leyendo, y
 * rechazar el texto por eso dejaría a la campaña sin guión por una tontería.
 */
final class Guion
{
    /** Un guión más largo que esto no se lee en pantalla: se pierde. */
    public const MAX_LARGO = 20000;

    /**
     * A donde se manda al ciudadano.
     *
     * Vive aqui y no solo dentro del texto porque la pantalla lo pinta aparte,
     * grande y con boton de copiar: es el mismo numero en las mil trescientas
     * llamadas, y leerlo de un parrafo trescientas veces al dia es justo la
     * friccion que hay que quitar.
     */
    public const WHATSAPP_OFICIAL = '3106173887';

    public const PREDETERMINADO = <<<'TXT'
## Antes de marcar

- Mire la ficha: nombre, barrio y si ya se preinscribió. Si le faltaron datos o evidencia, empiece por ahí.
- Marque en el teléfono IP. El sistema no marca por usted.

## La llamada

» Buenos días. Le habla [su nombre], de la Alcaldía de Jamundí, oficina de Gestión del Riesgo. ¿Hablo con [nombre del ciudadano]?

- Si contesta otra persona, pregunte a qué hora conviene volver a llamar. No explique el trámite a un tercero.

» Le llamo porque su hogar quedó registrado en el censo del sismo y falta un paso para continuar con la inspección de su vivienda.

» Va a guardar un número de WhatsApp: 310 617 3887. Es el número oficial de Gestión del Riesgo de la Alcaldía.

- Dígalo despacio y en dos partes. Espere a que lo anote y pídale que se lo repita.

» Escríbale un mensaje a ese número, el que sea, un «hola» basta. Le va a contestar un asistente automático.

» Cuando le conteste, responda con el número 1. Ahí mismo le llega el enlace del formulario.

» Ese formulario le va a pedir la foto de su cédula por las dos caras y al menos cinco fotos de los daños de la casa. Téngalas listas antes de empezar.

## Si le faltó algo

- Solo para quien aparece en «Les faltó algo». Es la cola más urgente: ya llenaron el formulario entero y se quedaron a un paso.

» Su solicitud no está negada: está esperando que nos complete [lo que diga la ficha].

» Escriba otra vez al 310 617 3887, responda 1, y el formulario le abre donde lo dejó.

## Antes de colgar

- Confirme que anotó el número. Si no lo tiene, repítalo.
- Si no tiene WhatsApp, anote VOLVER A LLAMAR y dígalo en la nota: hay que buscarle otra vía.
- Anote el resultado antes de pasar a la siguiente llamada.

## Si preguntan

? ¿Cuánto cuesta? »» Nada. Ni el formulario ni la inspección tienen costo, y nadie de la Alcaldía le va a pedir dinero.
? ¿Cuándo me visitan? »» Todavía no hay fecha. Primero se revisa su solicitud y después se programa la visita.
? ¿Me van a dar ayuda? »» Eso no lo decide esta llamada. El formulario es para que un profesional revise su vivienda.
? No tengo datos en el celular. »» Escribir por WhatsApp gasta muy poco. Si no puede, dele el número a alguien de confianza que sí tenga.
? Ya llené un formulario antes. »» Que escriba igual al número: si ya está registrado, el asistente se lo confirma.
? ¿Puedo llamar en vez de escribir? »» Sí, a la línea de atención 6025190969, pero por WhatsApp es más rápido y le queda el enlace guardado.

## Nunca

! Nunca prometa ayuda, mercados, materiales, subsidios ni plazos. Eso no lo decide esta llamada.
! Nunca pida claves, datos bancarios ni códigos que le lleguen por mensaje. La Alcaldía no los necesita.
! Nunca diga que la inspección está aprobada. Eso lo decide un profesional después de visitar la casa.
TXT;

    /**
     * El guión que se está usando hoy.
     *
     * @return array{cuerpo: string, es_predeterminado: bool, actualizado_en: ?string, por: ?string}
     */
    public static function vigente(): array
    {
        $fila = Db::first(
            'SELECT cuerpo, usuario_email, creado_en
               FROM callcenter_guion
              ORDER BY creado_en DESC, id DESC
              LIMIT 1'
        );

        if ($fila === null) {
            return [
                'cuerpo' => self::PREDETERMINADO,
                'es_predeterminado' => true,
                'actualizado_en' => null,
                'por' => null,
            ];
        }

        $cuerpo = (string) $fila['cuerpo'];

        return [
            'cuerpo' => $cuerpo,
            // Se compara el texto y no un indicador guardado: si alguien edita
            // el guión y termina escribiendo el original, es el original.
            'es_predeterminado' => trim($cuerpo) === trim(self::PREDETERMINADO),
            'actualizado_en' => (string) $fila['creado_en'],
            'por' => $fila['usuario_email'] === null ? null : (string) $fila['usuario_email'],
        ];
    }

    /**
     * Guarda una versión nueva. Nunca sobrescribe la anterior.
     *
     * @param  array<string,mixed>  $actor
     */
    public static function guardar(string $cuerpo, array $actor): void
    {
        Db::exec(
            'INSERT INTO callcenter_guion (cuerpo, usuario_id, usuario_email)
             VALUES (:c, :u, :e)',
            [
                'c' => mb_substr(trim($cuerpo), 0, self::MAX_LARGO),
                'u' => $actor['id'] ?? null,
                'e' => $actor['email'] ?? null,
            ]
        );
    }

    /**
     * Las versiones anteriores, para saber qué se le dijo a la gente y cuándo.
     *
     * @return list<array<string,mixed>>
     */
    public static function historial(int $limite = 20): array
    {
        return Db::all(
            'SELECT id, usuario_email, creado_en, CHAR_LENGTH(cuerpo) AS largo
               FROM callcenter_guion
              ORDER BY creado_en DESC, id DESC
              LIMIT :l',
            ['l' => max(1, min(100, $limite))]
        );
    }
}
