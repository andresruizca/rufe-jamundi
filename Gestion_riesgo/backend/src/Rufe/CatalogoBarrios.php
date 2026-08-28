<?php

declare(strict_types=1);

namespace App\Rufe;

/**
 * Los barrios de Jamundí, como los reconoce la Alcaldía.
 *
 * Son los 165 del archivo `barrios.xls` que entregó la oficina de Planeación:
 * la capa del POT, revisada y ajustada para la etapa de diagnóstico en 2021.
 * Aquí van solo los nombres; el área y si estaban reconocidos en el PBOT de
 * 2002 no le sirven a nadie que esté llenando un formulario.
 *
 * ── Por qué una lista y no un campo de texto ─────────────────────────────────
 *
 * Porque ya sabemos qué pasa sin ella. El censo se levantó escribiendo el
 * barrio a mano y salieron 249 grafías distintas para 117 barrios reales:
 * «Bocas Del Palo» y «Bocas del Palo», «TERRANOVA» y «Terranova». La clase
 * `Barrios` existe precisamente para volver a juntarlos al sumar, y esa tabla
 * es la que decide a dónde sale una brigada.
 *
 * Elegir de una lista corta ese problema en el origen en vez de remendarlo
 * después.
 *
 * ── Y por qué la lista NO es obligatoria ─────────────────────────────────────
 *
 * Un barrio puede no estar. La lista es de 2021, y en un municipio que crece
 * por invasión y por urbanizaciones nuevas eso pasa. Cerrar el campo dejaría
 * fuera a una familia damnificada por un problema de catálogo, que es
 * exactamente el tipo de error que este sistema no puede permitirse.
 *
 * Así que se ofrece la lista, se busca dentro de ella, y quien no se encuentre
 * escribe lo suyo. Lo escrito a mano se marca aparte para que Planeación pueda
 * revisarlo y, si procede, ampliar la lista.
 *
 * ── Y por qué no están las veredas ───────────────────────────────────────────
 *
 * El archivo trae la zona urbana. En zona rural el campo sigue siendo libre y
 * se apoya en `CORREGIMIENTOS`, que sí está.
 */
final class CatalogoBarrios
{
    /**
     * Los 165 barrios, en orden alfabético.
     *
     * @var list<string>
     */
    public const BARRIOS = [
        'Alborada I y II',
        'Alegra',
        'Alferez Real I',
        'Alferez Real II',
        'Almendros de Belicia (Sector por desarrollar)',
        'Almendros de Rioclaro',
        'Amigos 2000 - Paraiso de Sardí',
        'Angel Maria Camacho',
        'Arbore Country Club',
        'Arizona',
        'Astromelias',
        'Belalcazar',
        'Belalcazar II',
        'Bello Horizonte',
        'Bonanza - La Primavera',
        'Bonanza - Las Margaritas',
        'Bonanza - Los Girasoles',
        'Bonanza - Los Tulipanes',
        'Bosque Encantado Sur (Sector por desarrollar)',
        'Bosques de Alejandría',
        'Brisas de Buenavista (Sector en desarrollo)',
        'Brisas y Mirador de Farallones',
        'Cantabria',
        'Centenario',
        'Centro Comercial Alfaguara',
        'Cinco Soles',
        'Ciro Velasco',
        'Ciudad Alfaguara (Sector en desarrollo)',
        'Ciudad Campestre El Castillo',
        'Ciudad Country',
        'Ciudad de Dios I',
        'Ciudad Farallones (Sector en desarrollo)',
        'Ciudad Sur',
        'Ciudadela de las Flores',
        'Ciudadela del Viento (Sector en desarrollo)',
        'Ciudadela Oporto',
        'Ciudadela Terranova',
        'Club Banco de Occidente (Sector por desarrollar)',
        'Club de Campo La Morada',
        'Condado del Sur',
        'Condominio Privilegio',
        'Conjunto Madeira Club House',
        'Conjunto Portal de Jamundí',
        'Country Plaza',
        'Covicedros',
        'Edén del Parque (Sector en desarrollo)',
        'El Dorado',
        'El Edém (Sector por desarrollar)',
        'El Jardín',
        'El Jardín II',
        'El Piloto',
        'El Porvenir',
        'El Rodeo',
        'El Rosario',
        'El Socorro',
        'Forestal Garden (Sector en desarrollo)',
        'Forestar Aqua (Sector en desarrollo)',
        'Guaduales de Las Mercedes',
        'Hacienda El Pino',
        'Jorge Eliecer Gaitan',
        'Juan de Ampudia',
        'Juan Pablo II',
        'Koa',
        'La Adrianita',
        'La Arboleda',
        'La Aurora',
        'La Ceibita',
        'La Esmeralda',
        'La Esperanza',
        'La Estación',
        'La Hacienda (Sector por desarrollar)',
        'La Hojarasca',
        'La Lucha',
        'La Mezquita (Sector en desarrollo)',
        'La Morada Condominio Club',
        'La Playita',
        'La Pradera',
        'La Reserva Ciudadela (Sector en desarrollo)',
        'La Unión',
        'Lago del Country (Sector por desarrollar)',
        'Las Acacias',
        'Las Margaritas (Sector en desarrollo)',
        'Las Palmas (Sector en desarrollo)',
        'Libertadores',
        'Llano Grande (Sector en desarrollo)',
        'Llanos de La Morada (Sector por desarrollar)',
        'Los Anturios',
        'Los Mandarinos',
        'Los Naranjos (Sector en desarrollo)',
        'Makunaima',
        'Marbella',
        'NN',
        'Oasis de Terrananova 1',
        'Palo Santo',
        'Panamericano',
        'Paraiso de La Morada',
        'Parcelación Campestre Las Mercedes',
        'Parcelación Valle Verde',
        'Parque Natura (Sector en desarrollo)',
        'Parques de Castilla',
        'Popular',
        'Portal de Jamundí',
        'Portal del Saman I',
        'Portal del Saman II',
        'PP Arizona (Sector por desarrollar)',
        'PP El Rodeo (Sector por desarrollar)',
        'PP Sachamate (Sector por desarrollar)',
        'Prados de Alfaguara',
        'Primero de Mayo',
        'PTAR',
        'Quintas de Bolivar',
        'Remanso de La Morada (Sector en desarrollo)',
        'Remansos del Jordán',
        'Riberas del Rosario',
        'Rincón de las Garzas',
        'Rincón de Las Mercedes',
        'Rincón de Santa Ana (Codinter)',
        'Rincón de Zaragoza',
        'Rivera de Las Mercedes',
        'Rustico Sol Naciente (Sector por desarrollar)',
        'Sachamate',
        'Samanes de La Morada',
        'Samanes del Country (Sector en desarrollo)',
        'Samán del Lago (Sector por desarrollar)',
        'San Benito I',
        'San Benito II',
        'San Cayetano',
        'San Diego',
        'San Marino',
        'Santa Ana',
        'Sector de Pangola (Sector en desarrollo)',
        'Sector Los Estribos',
        'Senderos de la Morada',
        'Siglo XXI',
        'Simón Bolivar',
        'Simón Bolivar II',
        'Solares de La Morada 1 y 2',
        'Solares de La Morada 10, 11 y 12 (Sector en desarrollo)',
        'Solares de La Morada 3 y 4',
        'Solares de La Morada 5 y 6',
        'Solares de La Morada 7 y 8',
        'Solares de La Morada 9B (Sector por desarrollar)',
        'Tecnoquimicas',
        'Torres de Alamadina (Sector en desarrollo)',
        'Torres de Jamundí',
        'Urbanizacion Maná',
        'Urbanización Pontevedra',
        'Urbanización Portal del Jordán',
        'Valle del Río',
        'Verde Alfaguara (Sector en desarrollo)',
        'Verdi (Sector en desarrollo)',
        'Villa del Sol',
        'Villa Delfa',
        'Villa Elvira',
        'Villa Ema',
        'Villa Estela',
        'Villa Maite',
        'Villa Monica',
        'Villa Olimpica',
        'Villa Paulina',
        'Villa Pyme (Sector en desarrollo)',
        'Villa Tatiana',
        'Villas de Altagracia',
        'Villas del Parque',
        'Zona Industrial',
    ];

    /**
     * ¿Este nombre está en la lista?
     *
     * Compara sin acentos, sin mayúsculas y sin espacios de más, apoyándose en
     * la misma normalización que usa `Barrios` para agrupar: si dos grafías
     * cuentan como el mismo barrio al sumar, tienen que contar como el mismo
     * barrio al validar.
     */
    public static function reconocido(string $nombre): bool
    {
        $buscado = Barrios::clave($nombre);

        if ($buscado === '') {
            return false;
        }

        foreach (self::BARRIOS as $barrio) {
            if (Barrios::clave($barrio) === $buscado) {
                return true;
            }
        }

        return false;
    }
}
