<?php

declare(strict_types=1);

namespace App\SinCenso;

use App\Rufe\Catalogos as Rufe;

/**
 * Validación de la solicitud de quien no aparece en el censo.
 *
 * Se pide MENOS que en la pre-inscripción, que a su vez pide menos que el
 * censo: quien llega aquí acaba de recibir un mensaje que dice «usted no
 * está en nuestros registros», y todavía no se sabe si de verdad necesita
 * una ficha RUFE. Lo imprescindible es poder llamarlo y saber más o menos
 * dónde queda; todo lo demás —tipo de bien, composición del hogar, daños—
 * lo levanta el funcionario si el caso resulta real.
 *
 * Este validador MANDA, igual que el de la pre-inscripción: la ruta es
 * pública y cualquiera puede mandar lo que quiera contra ella.
 */
final class Validador
{
    /** @var list<string> */
    public const ZONAS = ['URBANO', 'RURAL'];

    private const MAX_DESCRIPCION = 500;

    /** @var array<string,string> */
    private array $errores = [];

    /** @var array<string,mixed> */
    private array $datos = [];

    /**
     * @param  array<string,mixed>  $e
     * @return array{errores: array<string,string>, datos: array<string,mixed>}
     */
    public static function revisar(array $e): array
    {
        $v = new self;

        $v->identificacion($e);
        $v->ubicacion($e);
        $v->relato($e);
        $v->autorizacion($e);

        return ['errores' => $v->errores, 'datos' => $v->datos];
    }

    /**
     * Nombres y apellidos por separado, no un solo «nombre completo».
     *
     * Son los mismos dos campos de `rufe_personas` y con la misma regla —ver
     * `Rufe\Validador::persona()`—: si la solicitud se convierte en una ficha,
     * el jefe de hogar se precarga tal cual, sin adivinar dónde termina el
     * nombre y empieza el apellido.
     *
     * @param array<string,mixed> $e
     */
    private function identificacion(array $e): void
    {
        foreach (['nombres' => 'Escriba el nombre.', 'apellidos' => 'Escriba los apellidos.'] as $campo => $mensaje) {
            $valor = $this->texto($e, $campo);

            if (mb_strlen($valor) < 2 || mb_strlen($valor) > 100) {
                $this->errores[$campo] = $mensaje;
            } elseif (preg_match("/^[\p{L}\p{M}\s'.\-]+$/u", $valor) !== 1) {
                $this->errores[$campo] = 'Use solo letras, espacios, apóstrofos, puntos o guiones.';
            } else {
                $this->datos[$campo] = $valor;
            }
        }

        $telefono = preg_replace('/\D+/', '', $this->texto($e, 'telefono')) ?? '';
        if (strlen($telefono) < 7 || strlen($telefono) > 15) {
            $this->errores['telefono'] = 'Escriba un teléfono donde podamos llamar.';
        } else {
            $this->datos['telefono'] = $telefono;
        }

        // La cédula que la puerta rechazó, solo de referencia: aquí no hay
        // censo con el que compararla, y exigirle una forma plausible no
        // aporta nada — a lo sumo, una que no cumpla se guarda como si no
        // hubiera venido.
        $documento = preg_replace('/\D+/', '', $this->texto($e, 'documento')) ?? '';
        $this->datos['documento'] = (strlen($documento) >= 5 && strlen($documento) <= 15)
            ? $documento
            : null;
    }

    /** @param array<string,mixed> $e */
    private function ubicacion(array $e): void
    {
        $zona = strtoupper($this->texto($e, 'zona'));
        if (! in_array($zona, self::ZONAS, true)) {
            $this->errores['zona'] = 'Indique si vive en zona urbana o rural.';
            $this->datos['zona'] = null;
        } else {
            $this->datos['zona'] = $zona;
        }

        $corregimiento = $this->texto($e, 'corregimiento');
        if ($corregimiento !== '' && ! in_array($corregimiento, Rufe::CORREGIMIENTOS, true)) {
            $this->errores['corregimiento'] = 'Seleccione un corregimiento de la lista.';
        } elseif ($zona === 'URBANO') {
            $this->datos['corregimiento'] = null;
        } else {
            $this->datos['corregimiento'] = $corregimiento === '' ? null : $corregimiento;
        }

        $vereda = $this->texto($e, 'vereda_sector_barrio');
        $this->datos['vereda_sector_barrio'] = $vereda === '' ? null : mb_substr($vereda, 0, 120);

        $direccion = $this->texto($e, 'direccion');
        $this->datos['direccion'] = $direccion === '' ? null : mb_substr($direccion, 0, 200);

        // No se exige un campo concreto, sino que quede AL MENOS uno con qué
        // ubicar a la persona. Pedir una dirección con formato dejaría fuera
        // a quien no la tiene; pedir solo el corregimiento no basta en zona
        // urbana. Lo que no puede pasar es que no quede ninguna pista.
        if (
            $this->datos['direccion'] === null
            && $this->datos['vereda_sector_barrio'] === null
            && $this->datos['corregimiento'] === null
        ) {
            $this->errores['direccion'] = 'Dígannos aunque sea de forma aproximada dónde vive.';
        }
    }

    /** @param array<string,mixed> $e */
    private function relato(array $e): void
    {
        $texto = $this->texto($e, 'descripcion');

        if (mb_strlen($texto) > self::MAX_DESCRIPCION) {
            $this->errores['descripcion'] = 'Resuma en menos de '.self::MAX_DESCRIPCION.' caracteres.';

            return;
        }

        $this->datos['descripcion'] = $texto === '' ? null : $texto;
    }

    /** @param array<string,mixed> $e */
    private function autorizacion(array $e): void
    {
        $acepta = (bool) ($e['autoriza_datos'] ?? false);

        if (! $acepta) {
            $this->errores['autoriza_datos'] = 'Debe autorizar el tratamiento de sus datos para continuar.';
        }

        $this->datos['autoriza_datos'] = $acepta ? 1 : 0;

        $version = $this->texto($e, 'aviso_version');
        if (! in_array($version, Rufe::AVISOS_CONOCIDOS, true)) {
            $this->errores['aviso_version'] = 'No se pudo registrar la versión del aviso de privacidad.';
        } else {
            $this->datos['aviso_version'] = $version;
        }
    }

    /** @param array<string,mixed> $origen */
    private function texto(array $origen, string $clave): string
    {
        $valor = $origen[$clave] ?? '';

        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
