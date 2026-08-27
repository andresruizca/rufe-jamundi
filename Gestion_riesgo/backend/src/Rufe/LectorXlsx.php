<?php

declare(strict_types=1);

namespace App\Rufe;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lector mínimo de archivos .xlsx, sin Composer.
 *
 * Un .xlsx es un ZIP con XML adentro, y PHP trae `ZipArchive` y
 * `SimpleXMLElement` de fábrica. Eso basta para leer una exportación tabular
 * —que es lo único que hace falta aquí— y evita la única alternativa real, que
 * sería bajar PhpSpreadsheet y sus dependencias a un hosting sin consola.
 *
 * Lo que NO hace, a propósito: fórmulas, formatos, fechas seriales de Excel,
 * hojas con celdas combinadas. Si algún día hiciera falta, es señal de que el
 * archivo dejó de ser una exportación plana y hay que revisar el importador
 * entero, no parchear esto.
 */
final class LectorXlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Las filas de una hoja, como mapas «encabezado => valor».
     *
     * La primera fila es el encabezado. Las columnas vacías de una fila
     * sencillamente no aparecen en su mapa: es más fiel que rellenarlas con
     * cadena vacía, porque distingue «no vino» de «vino en blanco» — aunque
     * aquí se traten igual, quien lea el CSV de problemas lo agradece.
     *
     * @return list<array<string,string>>
     */
    public static function filas(string $ruta, string $hoja): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir «{$ruta}» como .xlsx.");
        }

        try {
            $compartidas = self::cadenasCompartidas($zip);
            $indice = self::indiceDeHoja($zip, $hoja);

            $xml = $zip->getFromName("xl/worksheets/sheet{$indice}.xml");
            if ($xml === false) {
                throw new RuntimeException("El archivo no tiene la hoja «{$hoja}».");
            }

            $hojaXml = new SimpleXMLElement($xml);

            $encabezado = [];
            $filas = [];

            // Se recorre con `children()` y no con xpath: SimpleXML NO hereda los
            // prefijos registrados en los nodos hijos, así que un `xpath('s:c')`
            // sobre una fila falla con «Undefined namespace prefix» y devuelve
            // la hoja entera vacía sin lanzar ningún error.
            foreach ($hojaXml->children(self::NS)->sheetData->children(self::NS)->row as $fila) {
                $celdas = [];

                // Y los atributos se leen con `attributes()`, no con `$celda['r']`:
                // tras un `children($ns)`, SimpleXML busca los atributos EN ESE
                // espacio de nombres, y los de un .xlsx no llevan prefijo. La
                // celda devolvía una referencia vacía y la hoja salía en blanco.
                foreach ($fila->children(self::NS)->c as $celda) {
                    $ref = (string) ($celda->attributes()['r'] ?? '');
                    $columna = preg_replace('/\d+/', '', $ref) ?? '';
                    $celdas[$columna] = self::valor($celda, $compartidas);
                }

                if ($encabezado === []) {
                    $encabezado = array_filter($celdas, static fn (string $v): bool => $v !== '');

                    continue;
                }

                $registro = [];

                foreach ($celdas as $columna => $valor) {
                    if (isset($encabezado[$columna])) {
                        $registro[$encabezado[$columna]] = $valor;
                    }
                }

                $filas[] = $registro;
            }

            return $filas;
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> los nombres de las hojas, en su orden */
    public static function hojas(string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException("No se pudo abrir «{$ruta}» como .xlsx.");
        }

        try {
            $libro = new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml'));

            $nombres = [];

            foreach ($libro->children(self::NS)->sheets->children(self::NS)->sheet as $hoja) {
                $nombres[] = (string) ($hoja->attributes()['name'] ?? '');
            }

            return $nombres;
        } finally {
            $zip->close();
        }
    }

    /** @param list<string> $compartidas */
    private static function valor(SimpleXMLElement $celda, array $compartidas): string
    {
        $tipo = (string) ($celda->attributes()['t'] ?? '');

        // Cadena en línea: el texto vive dentro de la propia celda.
        $hijos = $celda->children(self::NS);

        if ($tipo === 'inlineStr') {
            return trim((string) ($hijos->is->t ?? ''));
        }

        $crudo = (string) ($hijos->v ?? '');

        // Cadena compartida: la celda guarda un índice a la tabla común, que es
        // como Excel evita repetir el mismo texto miles de veces.
        if ($tipo === 's') {
            return trim($compartidas[(int) $crudo] ?? '');
        }

        return trim($crudo);
    }

    /** @return list<string> */
    private static function cadenasCompartidas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $tabla = new SimpleXMLElement($xml);

        $cadenas = [];

        foreach ($tabla->children(self::NS)->si as $si) {
            // Un texto con formato viene partido en varios <t>: se concatenan,
            // que es lo que se ve en la celda.
            $texto = '';

            foreach ($si->children(self::NS)->t as $t) {
                $texto .= (string) $t;
            }

            foreach ($si->children(self::NS)->r as $tramo) {
                foreach ($tramo->children(self::NS)->t as $t) {
                    $texto .= (string) $t;
                }
            }

            $cadenas[] = $texto;
        }

        return $cadenas;
    }

    private static function indiceDeHoja(ZipArchive $zip, string $hoja): int
    {
        $libro = new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml'));

        $i = 0;

        foreach ($libro->children(self::NS)->sheets->children(self::NS)->sheet as $s) {
            $i++;

            if ((string) ($s->attributes()['name'] ?? '') === $hoja) {
                return $i;
            }
        }

        throw new RuntimeException("El archivo no tiene la hoja «{$hoja}».");
    }
}
