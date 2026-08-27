<?php

declare(strict_types=1);

namespace App\Rufe;

/**
 * Traducción del RUD de la UNGRD a lo que el sistema ya sabe guardar.
 *
 * El archivo que entrega la dependencia es una exportación PLANA: una fila por
 * persona, con los datos del inmueble metidos como texto corrido dentro de una
 * sola columna —«Bien: Vivienda. Tenencia: Propietario. Estado: Averiado.
 * Vereda/sector: … Corregimiento: … Direccion: …»—. No es el formato relacional
 * de cuatro pestañas del RUD oficial; es lo que produce el filtro que usan en
 * la Alcaldía, y es con lo que hay que trabajar.
 *
 * Todo lo de aquí es FUNCIÓN PURA: se puede probar sin base de datos y sin
 * archivo. Es a propósito — las decisiones que toma esta clase deciden qué
 * familias entran al censo y cuáles quedan fuera, y eso no puede depender de
 * que alguien se acuerde de mirar el resultado.
 *
 * ── Lo que esta clase NO hace ────────────────────────────────────────────────
 *
 * No valida. Arma el mismo payload que manda el formulario «Nueva ficha» y se
 * lo entrega a `Rufe\Validador`, que es quien decide si un hogar es válido.
 * Reimplementar esas reglas aquí sería la primera fuente de divergencia entre
 * lo que entra por el Excel y lo que entra por el formulario.
 */
final class Rud
{
    /** La hoja completa. Las otras cuatro del archivo son filtros por estado. */
    public const HOJA = 'Sheet1';

    /**
     * Etiquetas que el RUD escribe distinto y no se resuelven normalizando.
     *
     * Solo entran aquí las que de verdad difieren en las letras, no en mayúsculas
     * ni en tildes: para esas basta `normalizar()`. Cada una está verificada
     * contra el archivo real, no supuesta.
     *
     * @var array<string,string>
     */
    private const ALIAS = [
        // El RUD omite la «(a)» de afrodescendiente que sí trae el catálogo.
        'negroamulatoaafrodescendienteafrocolombianoa'
            => 'Negro(a), mulato(a), afrodescendiente(a), afrocolombiano(a)',
        // Permiso por Protección Temporal: el RUD lo escribe solo con la sigla.
        'ppt' => 'PPT — Permiso por Protección Temporal',
    ];

    /**
     * Los diecisiete corregimientos son la ÚNICA marca de ruralidad del archivo.
     *
     * El RUD no trae la columna urbano/rural que el sistema exige. Lo que trae
     * es un campo «Corregimiento» que en realidad mezcla dos cosas: los
     * corregimientos oficiales del municipio y los barrios de la cabecera
     * —«Jamundí» en más de la mitad de las fichas, «Terranova», «El Rodeo»—.
     *
     * Así que la regla es: si el nombre está en el catálogo oficial de
     * corregimientos, el hogar es rural; si no, es urbano. Es lo que dicen los
     * datos, y se deja escrito para que quien encuentre una ficha mal
     * clasificada sepa POR QUÉ quedó así y no crea que alguien lo tecleó.
     */
    public static function zonaPorCorregimiento(string $corregimiento): string
    {
        $buscado = self::normalizar($corregimiento);

        foreach (Catalogos::CORREGIMIENTOS as $oficial) {
            if (self::normalizar($oficial) === $buscado) {
                return 'RURAL';
            }
        }

        return 'URBANO';
    }

    /**
     * El corregimiento oficial, o null si lo que venía era un barrio.
     *
     * `rufe_reportes.corregimiento` solo admite uno de los diecisiete; guardar
     * «Terranova» ahí ensuciaría el catálogo con barrios y rompería los filtros
     * del tablero. El nombre no se pierde: viaja a `vereda_sector_barrio`.
     */
    public static function corregimientoOficial(string $corregimiento): ?string
    {
        $buscado = self::normalizar($corregimiento);

        foreach (Catalogos::CORREGIMIENTOS as $oficial) {
            if (self::normalizar($oficial) === $buscado) {
                return $oficial;
            }
        }

        return null;
    }

    /**
     * Desarma el texto corrido del inmueble.
     *
     * Devuelve siempre las seis claves, vacías si no venían. Que el texto no
     * case con el patrón es un caso real que hay que poder contar, no una
     * excepción: se devuelve todo vacío y el hogar acaba en el informe de
     * revisión, nunca importado a medias.
     *
     * @return array{bien: string, tenencia: string, estado: string, vereda: string, corregimiento: string, direccion: string}
     */
    public static function desarmarBien(string $texto): array
    {
        $vacio = [
            'bien' => '', 'tenencia' => '', 'estado' => '',
            'vereda' => '', 'corregimiento' => '', 'direccion' => '',
        ];

        $patron = '/Bien:\s*(?P<bien>[^.]*)\.\s*'
                 .'Tenencia:\s*(?P<tenencia>[^.]*)\.\s*'
                 .'Estado:\s*(?P<estado>[^.]*)\.\s*'
                 .'Vereda\/sector:\s*(?P<vereda>.*?)\.\s*'
                 .'Corregimiento:\s*(?P<corregimiento>.*?)\s*'
                 .'Direccion:\s*(?P<direccion>.*)$/su';

        if (preg_match($patron, $texto, $m) !== 1) {
            return $vacio;
        }

        foreach (array_keys($vacio) as $clave) {
            $vacio[$clave] = trim($m[$clave] ?? '');
        }

        return $vacio;
    }

    /**
     * El código de un catálogo a partir de la etiqueta que escribió el RUD.
     *
     * Compara por etiqueta y no por código a propósito: el RUD trae los códigos
     * oficiales de la UNGRD y el sistema usa una numeración interna propia, así
     * que copiar el número sería silenciosamente incorrecto —un «41» del RUD es
     * «Hijo(a)» y en el sistema es un código que no existe—. Las etiquetas, en
     * cambio, coinciden palabra por palabra.
     *
     * @param  array<int|string,string|array<string,mixed>>  $catalogo
     * @return int|string|null el código del catálogo, o null si no hay equivalente
     */
    public static function codigoPorEtiqueta(array $catalogo, string $etiqueta): int|string|null
    {
        $buscado = self::normalizar(self::sinSigla($etiqueta));

        // La clave del alias es la etiqueta del RUD ya normalizada; el valor es
        // la del catálogo tal cual se escribe, para que se lea al mantenerlo.
        if (isset(self::ALIAS[$buscado])) {
            $buscado = self::normalizar(self::ALIAS[$buscado]);
        }

        foreach ($catalogo as $codigo => $suya) {
            // `TIPOS_BIEN` guarda un mapa con etiqueta y datos de apoyo; los
            // demás catálogos, la etiqueta suelta. Se admiten los dos para no
            // tener una función por forma de catálogo.
            $etiquetaCatalogo = is_array($suya) ? (string) ($suya['etiqueta'] ?? '') : (string) $suya;

            if (self::normalizar($etiquetaCatalogo) === $buscado) {
                return $codigo;
            }
        }

        return null;
    }

    /**
     * La dirección del inmueble, bajando por lo que el censo sí levantó.
     *
     * Un tercio de las fichas trae la dirección vacía pero la vereda llena
     * —«Bellavista Finca La Piscina»—, que es exactamente como se ubica una
     * casa en zona rural: no hay nomenclatura. Exigir una «dirección» con
     * formato urbano dejaría fuera del censo a cientos de familias que sí
     * están perfectamente ubicadas.
     *
     * No se inventa nada: se usa el dato de ubicación más preciso que existe.
     */
    public static function direccionDe(array $bien): string
    {
        foreach ([$bien['direccion'], $bien['vereda'], $bien['corregimiento']] as $candidato) {
            $valor = trim($candidato);

            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    /** Lo mismo para el barrio o vereda: si no vino, sirve el corregimiento. */
    public static function veredaDe(array $bien): string
    {
        foreach ([$bien['vereda'], $bien['corregimiento'], $bien['direccion']] as $candidato) {
            $valor = trim($candidato);

            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    /**
     * El teléfono del hogar: primero el del jefe, si no el de quien lo tenga.
     *
     * Nunca se inventa uno. Un hogar donde nadie dejó teléfono va al informe de
     * revisión: sin número no hay forma de citar a esa familia a la visita, y
     * un teléfono falso es peor que una casilla vacía porque nadie sabría que
     * está mal hasta llamar.
     *
     * @param  list<array<string,string>>  $personas filas del RUD, jefe primero
     */
    public static function telefonoDe(array $personas): string
    {
        foreach ($personas as $p) {
            $tel = preg_replace('/\D+/', '', $p['telefono'] ?? '') ?? '';

            if (strlen($tel) >= 7 && strlen($tel) <= 15) {
                return $tel;
            }
        }

        return '';
    }

    /**
     * Ordena el hogar dejando al jefe primero.
     *
     * `rufe_personas.orden` 1 es el jefe en todo el sistema: el call center, el
     * cruce con las preinscripciones y la ficha en PDF lo dan por hecho. En el
     * RUD el jefe puede venir en cualquier fila, y 136 hogares no traen ninguno
     * marcado; en ese caso NO se asciende a nadie —eso sería inventar quién
     * encabeza una familia— y el hogar va al informe.
     *
     * @param  list<array<string,string>>  $personas
     * @return list<array<string,string>>
     */
    public static function conJefePrimero(array $personas): array
    {
        $jefes = [];
        $resto = [];

        foreach ($personas as $p) {
            $codigo = self::codigoPorEtiqueta(Catalogos::PARENTESCOS, $p['parentesco'] ?? '');

            if ($codigo === Catalogos::PARENTESCO_JEFE) {
                $jefes[] = $p;
            } else {
                $resto[] = $p;
            }
        }

        return array_merge($jefes, $resto);
    }

    /**
     * ¿Se puede dar por jefe a la única persona del hogar?
     *
     * Un hogar de UNA persona sin jefe marcado no es un dato que falte: esa
     * persona es la cabeza de su hogar por aritmética, no por suposición. Son
     * 90 de los 136 hogares sin jefe del censo, y dejarlos fuera sería perder
     * casi cien familias —muchas de ellas adultos mayores solos— por una
     * casilla que el formato en papel no obligaba a marcar.
     *
     * Con dos personas o más NO se asciende a nadie: ahí sí habría que decidir
     * quién encabeza una familia, y eso no se hace desde un script.
     *
     * @param  list<array<string,string>>  $personas
     */
    public static function jefeDeducible(array $personas): bool
    {
        return count($personas) === 1 && ! self::tieneJefe($personas);
    }

    /**
     * El tipo de documento, corregido cuando no hay número que lo sustente.
     *
     * El censo trae 64 personas con «CC» y la casilla del número vacía. Guardar
     * eso sería afirmar que tienen cédula y que el sistema perdió el número;
     * lo que de verdad pasó es que el formato en papel se llenó sin ese dato.
     * «No informa» es el código que el propio formato UNGRD tiene para eso.
     *
     * No se hace al revés —nunca se inventa un número—, y el tipo declarado
     * queda igual en cuanto hay un número que lo respalde.
     */
    public static function tipoDocumentoCoherente(?int $tipo, string $numero): int
    {
        if ($tipo === null) {
            return 8;
        }

        if (trim($numero) === '' && Catalogos::exigeNumeroDocumento($tipo)) {
            return 8;
        }

        return $tipo;
    }

    /** ¿Alguien en este hogar está marcado como jefe? */
    public static function tieneJefe(array $personas): bool
    {
        foreach ($personas as $p) {
            if (self::codigoPorEtiqueta(Catalogos::PARENTESCOS, $p['parentesco'] ?? '') === Catalogos::PARENTESCO_JEFE) {
                return true;
            }
        }

        return false;
    }

    /**
     * La fecha de nacimiento, o null.
     *
     * El RUD la escribe «1951-02-02 00:00:00». Dos tercios de las filas la
     * traen vacía y la columna admite null, así que no es motivo de rechazo.
     */
    public static function fechaNacimiento(string $crudo): ?string
    {
        $crudo = trim($crudo);

        if ($crudo === '') {
            return null;
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $crudo, $m) === 1 ? $m[1] : null;
    }

    /**
     * Minúsculas, sin tildes, sin nada que no sea letra o número.
     *
     * Con esto «Sobrino (a)», «Sobrino(a)» y «SOBRINO(A)» son la misma etiqueta,
     * que es justo la clase de diferencia que trae una exportación hecha a mano.
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $texto) ?? '';
    }

    /** Quita el prefijo de sigla del RUD: «CC - Cédula de ciudadanía». */
    private static function sinSigla(string $etiqueta): string
    {
        return preg_replace('/^[A-Z]{1,3}\s*-\s*/u', '', trim($etiqueta)) ?? $etiqueta;
    }
}
