<?php

declare(strict_types=1);

namespace App\Rufe;

/**
 * Agrupar barrios que son el mismo barrio escrito de otra manera.
 *
 * El censo escribe el barrio a mano, y el resultado son 249 nombres distintos
 * para lo que la Alcaldía maneja como 117 barrios: «Bocas Del Palo» y «Bocas
 * del Palo», «TERRANOVA» y «Terranova», «Colinas De Miravalle» con y sin
 * número. Sin agrupar, la tabla por barrio del tablero parte barrios reales en
 * varios y ninguno de sus totales sirve.
 *
 * No es cosmético: esa tabla es la que decide a dónde sale una brigada.
 *
 * ── Lo que esta clase NO hace ────────────────────────────────────────────────
 *
 * No corrige la base. El nombre que escribió el funcionario se queda tal cual
 * en su ficha: la normalización ocurre AL SUMAR. Reescribir el dato original
 * para que cuadre un total es perder la única prueba de lo que se levantó en
 * campo, y además impide corregir la agrupación después.
 *
 * El nombre que se muestra es el más frecuente entre los que se agruparon —el
 * que la mayoría de funcionarios escribió—, no el primero que aparezca ni una
 * versión inventada.
 */
final class Barrios
{
    /**
     * Palabras que no distinguen un barrio de otro.
     *
     * «Barrio Terranova» y «Terranova» son el mismo sitio; «vereda La Meseta» y
     * «La Meseta» también. Se quitan solo al principio del nombre: «Villa Paz»
     * no puede perder su «Villa».
     *
     * @var list<string>
     */
    private const PREFIJOS = ['barrio', 'vereda', 'sector', 'corregimiento', 'urbanizacion', 'conjunto'];

    /**
     * Artículos y preposiciones que sobran para comparar.
     *
     * @var list<string>
     */
    private const VACIAS = ['de', 'del', 'la', 'las', 'el', 'los', 'y'];

    /**
     * Nombres que la normalización no puede unir sola.
     *
     * Las dos puntas van YA normalizadas —minúsculas, sin tildes, sin
     * artículos—, y se aplican una sola vez al final. Al principio esto era un
     * mapa de «como se escribe» a «como se dice» resuelto recursivamente, y
     * bastó una entrada cuyas dos puntas normalizaban igual («quinamayo» →
     * «Quinamayó») para que la función se llamara a sí misma hasta agotar la
     * memoria de PHP. Con las claves ya normalizadas eso no puede pasar.
     *
     * Cada entrada es una decisión sobre datos de gente real: va escrita, no
     * adivinada.
     *
     * @var array<string,string>
     */
    private const ALIAS = [
        // El corregimiento oficial se escribe junto; el censo lo parte en dos.
        'villa paz' => 'villapaz',
    ];

    /**
     * La clave con la que dos nombres se reconocen como el mismo barrio.
     *
     * Minúsculas, sin tildes, sin signos, sin prefijos genéricos y sin
     * artículos. Lo que queda es el nombre propio.
     */
    public static function clave(string $nombre): string
    {
        $texto = mb_strtolower(trim($nombre));
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        // Los signos pasan a espacio y no se borran: «villa-paz» tiene que
        // quedar «villa paz», no «villapaz», o dejaría de casar con el alias.
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '';
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? '');

        if ($texto === '') {
            return '';
        }

        $palabras = explode(' ', $texto);

        while ($palabras !== [] && in_array($palabras[0], self::PREFIJOS, true)) {
            array_shift($palabras);
        }

        $palabras = array_values(array_filter(
            $palabras,
            static fn (string $p): bool => ! in_array($p, self::VACIAS, true)
        ));

        $clave = implode(' ', $palabras);

        // Los alias se resuelven al final y una sola vez: nunca se vuelve a
        // entrar en esta función.
        return self::ALIAS[$clave] ?? $clave;
    }

    /**
     * ¿Son el mismo barrio escrito distinto?
     */
    public static function esMismo(string $uno, string $otro): bool
    {
        $a = self::clave($uno);

        return $a !== '' && $a === self::clave($otro);
    }

    /**
     * Agrupa una lista de nombres y devuelve, por cada grupo, cómo se llama.
     *
     * El nombre elegido es el más repetido del grupo. Con empate gana el que
     * viene antes por orden alfabético, para que dos corridas den lo mismo —un
     * tablero que cambia el nombre de un barrio entre recargas parece roto.
     *
     * @param  array<string,int>  $conteos  nombre tal como está escrito => cuántas veces
     * @return array<string, array{nombre: string, variantes: array<string,int>}> por clave
     */
    public static function agrupar(array $conteos): array
    {
        $grupos = [];

        foreach ($conteos as $nombre => $veces) {
            $clave = self::clave((string) $nombre);

            if ($clave === '') {
                continue;
            }

            $grupos[$clave]['variantes'][(string) $nombre] = $veces;
        }

        foreach ($grupos as $clave => $grupo) {
            $variantes = $grupo['variantes'];
            uksort($variantes, static function (string $a, string $b) use ($variantes): int {
                return $variantes[$b] <=> $variantes[$a] ?: strcmp($a, $b);
            });

            $grupos[$clave] = [
                'nombre' => (string) array_key_first($variantes),
                'variantes' => $variantes,
            ];
        }

        ksort($grupos);

        return $grupos;
    }
}
