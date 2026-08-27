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

    public const PREDETERMINADO = <<<'TXT'
## Antes de marcar

- Mire la ficha en pantalla: el nombre de quien contesta, el barrio y si ya se preinscribió.
- Si la ficha dice que le faltaron datos o evidencia, empiece por ahí: es una llamada distinta.
- Tenga el número a la vista y márquelo en el teléfono IP. El sistema no marca por usted.

## Saludo

» Buenos días. Le habla [su nombre], de la Alcaldía de Jamundí, oficina de Gestión del Riesgo. ¿Hablo con [nombre del ciudadano]?

- Si contesta otra persona, pregunte si puede pasarle o a qué hora conviene volver a llamar. No explique el trámite a un tercero.

» Le llamo porque su hogar quedó registrado en el censo del sismo y falta un paso para continuar el proceso.

! Nunca prometa ayuda, subsidio, mercado, arriendo ni una cantidad de dinero. Este formulario registra la solicitud; no la aprueba.

## De qué se trata

» Es un formulario en internet donde usted nos cuenta cómo quedó su vivienda y sube fotos y videos. Con eso un ingeniero revisa su caso y programa la visita.

» Se llena desde el celular, con la cámara del mismo celular. Se demora unos quince minutos.

» No tiene ningún costo y no necesita ir a ninguna oficina.

## Mandarle el enlace

- Use el botón «Mandarle el enlace ahora» mientras la persona sigue al teléfono. No cuelgue antes de confirmar que le llegó.

» Le acabo de mandar un mensaje con el enlace. ¿Le llegó?

- Si no tiene WhatsApp, dícteselo despacio: ge, ere, jota, punto, oticjamundi, punto com, barra, preinscripción.
- Si no tiene celular inteligente o no tiene datos, anote «No tiene cómo llenarlo» en la nota de la llamada y dígale que puede acercarse a la oficina de Gestión del Riesgo. No lo deje sin salida.

## Acompañarlo pantalla por pantalla

» Lo primero que le pide es el número de cédula, sin puntos ni espacios.

- Si dice que le aparece que no está registrado: verifique que sea la cédula del titular del censo. Si aun así no aparece, dígale que llame al 6025190969, extensión 2070.

» Después le pide sus datos: nombre completo, cédula, teléfono y la dirección de la vivienda afectada. El correo es opcional.

» La dirección escríbala como se la explicaría a alguien que va a buscar la casa. Si es vereda, ponga el nombre de la vereda y un punto de referencia.

» Luego le muestra unos dibujos con los daños: paredes agrietadas, paredes caídas, columnas partidas, tejas, techo, piso, tubería y luz. Marque todos los que reconozca en su casa. No hace falta saber de construcción.

» Después le pide las fotos. Pida por lo menos diez: la cédula, la fachada de la casa y cada daño que marcó, de lejos y de cerca.

» Y por último un video corto de cada daño que marcó. Máximo dos minutos cada uno. No hace falta uno solo largo: es mejor uno por cada cosa.

» Al final tiene que aceptar la autorización de datos y darle a enviar.

» Cuando termine le aparece un número de radicado. Anótelo o tómele una foto a la pantalla: con ese número le damos razón de su solicitud.

## Si se le va la señal

» Si se le va la señal a mitad del formulario, no se preocupe ni lo llene otra vez. Queda guardado en el celular y se manda solo cuando le vuelva la conexión.

## Cerrar la llamada

» ¿Le quedó alguna duda de algún paso?

» Si algo no le funciona, llame al 6025190969, extensión 2070, y con mucho gusto le ayudamos.

- Anote la llamada ANTES de marcar el siguiente número. Lo que no queda anotado, el turno de la tarde lo vuelve a llamar.

## Cuando hay que volver a llamar porque le faltó algo

- La ficha le dice qué faltó. Empiece por ahí, no por el saludo del principio.

» Le llamo de nuevo de la Alcaldía. Ya revisamos su solicitud y para poder continuar nos falta [lo que diga la ficha]. ¿Puede volver a entrar al mismo enlace y completarlo?

» Su solicitud no está negada: está esperando ese dato para seguir.

! No diga «se la rechazaron» ni «se la negaron». Está incompleta, que es otra cosa.

## Preguntas que hacen siempre

? ¿Esto tiene algún costo? » Ninguno. Ni el formulario, ni la visita, ni el trámite.

? ¿Me van a dar casa o plata? » Eso yo no se lo puedo prometer. Lo que hace este formulario es registrar su caso para que un ingeniero lo revise y vaya a su vivienda.

? ¿Cuándo me visitan? » No le puedo dar una fecha exacta. Primero se revisa la solicitud y después se programa la visita.

? Ya lo llené. » Permítame y lo confirmo en el sistema.

- Si en la ficha no aparece, casi siempre es que se cerró antes de darle a enviar.

» No me aparece registrado. Es probable que se haya cerrado antes de enviarlo. ¿Puede intentarlo otra vez con el mismo enlace? Yo lo acompaño.

? ¿Cómo sé que ustedes son de la Alcaldía? » Puede colgar y llamar usted al 6025190969, extensión 2070, y preguntar por Gestión del Riesgo. Con gusto lo esperamos.

? No quiero dar mis datos. » Es voluntario y está en su derecho. Solo tenga en cuenta que sin esa autorización no podemos registrar su solicitud.

! Nunca pida claves, códigos que le lleguen por mensaje, número de cuenta ni datos de tarjetas. La Alcaldía no pide eso por teléfono. Si alguien lo ofrece, dígale que no es necesario.
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
