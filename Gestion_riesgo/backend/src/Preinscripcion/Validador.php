<?php

declare(strict_types=1);

namespace App\Preinscripcion;

use App\Rufe\Catalogos as Rufe;

/**
 * Validación de la pre-inscripción ciudadana.
 *
 * Se pide MENOS que en el censo, a propósito. Quien llena esto es la persona
 * afectada, sola, en su celular y probablemente alterada: cada campo de más es
 * un motivo para abandonar el formulario a la mitad. Lo imprescindible es poder
 * llegar a la casa y poder llamar a alguien.
 *
 * Nada de datos sensibles —ni género, ni pertenencia étnica, ni composición del
 * hogar—: eso lo levanta el funcionario en la visita, con el aviso de la Ley
 * 1581 explicado de viva voz. Aquí solo se recoge lo mínimo para asignar turno.
 *
 * Este validador MANDA. El navegador valida lo mismo para dar respuesta
 * inmediata, pero lo que decide es esto: la ruta es pública y cualquiera puede
 * mandar lo que quiera contra ella.
 */
final class Validador
{
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

    /** @param array<string,mixed> $e */
    private function identificacion(array $e): void
    {
        $nombre = $this->texto($e, 'nombre_completo');
        if (mb_strlen($nombre) < 5 || mb_strlen($nombre) > 200) {
            $this->errores['nombre_completo'] = 'Escriba su nombre y sus apellidos.';
        } else {
            $this->datos['nombre_completo'] = $nombre;
        }

        // Solo dígitos y una longitud plausible. No se valida contra la
        // Registraduría: aquí no hay forma de hacerlo, y rechazar una cédula
        // legítima por una regla inventada dejaría a alguien fuera de la ayuda.
        $documento = preg_replace('/\D+/', '', $this->texto($e, 'documento')) ?? '';
        if (strlen($documento) < 5 || strlen($documento) > 15) {
            $this->errores['documento'] = 'Escriba su número de cédula, sin puntos ni espacios.';
        } else {
            $this->datos['documento'] = $documento;
        }

        $telefono = preg_replace('/\D+/', '', $this->texto($e, 'telefono')) ?? '';
        if (strlen($telefono) < 7 || strlen($telefono) > 15) {
            $this->errores['telefono'] = 'Escriba un teléfono donde podamos llamarle.';
        } else {
            $this->datos['telefono'] = $telefono;
        }

        $correo = $this->texto($e, 'correo');
        if ($correo === '') {
            $this->datos['correo'] = null;
        } elseif (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 150) {
            $this->errores['correo'] = 'Ese correo no parece válido. Puede dejarlo en blanco.';
        } else {
            $this->datos['correo'] = mb_strtolower($correo);
        }
    }

    /** @param array<string,mixed> $e */
    private function ubicacion(array $e): void
    {
        $direccion = $this->texto($e, 'direccion');
        if (mb_strlen($direccion) < 5 || mb_strlen($direccion) > 200) {
            $this->errores['direccion'] = 'Escriba la dirección como se la daría a alguien que va a buscarla.';
        } else {
            $this->datos['direccion'] = $direccion;
        }

        $corregimiento = $this->texto($e, 'corregimiento');
        if ($corregimiento !== '' && ! in_array($corregimiento, Rufe::CORREGIMIENTOS, true)) {
            $this->errores['corregimiento'] = 'Seleccione un corregimiento de la lista.';
        } else {
            $this->datos['corregimiento'] = $corregimiento === '' ? null : $corregimiento;
        }

        $vereda = $this->texto($e, 'vereda');
        $this->datos['vereda'] = $vereda === '' ? null : mb_substr($vereda, 0, 120);

        $this->coordenadas($e);
    }

    /**
     * El punto GPS, opcional.
     *
     * Es lo que más ayuda a encontrar la casa después, pero no se exige: mucha
     * gente rechaza el permiso de ubicación, y perder la solicitud por eso sería
     * absurdo. Un punto ilegible o de otro país se descarta sin tumbar el envío.
     *
     * @param array<string,mixed> $e
     */
    private function coordenadas(array $e): void
    {
        $this->datos['latitud'] = null;
        $this->datos['longitud'] = null;
        $this->datos['precision_m'] = null;

        $lat = $e['latitud'] ?? null;
        $lon = $e['longitud'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        // La misma caja que el censo: territorio continental e insular colombiano.
        if ($lat < -4.5 || $lat > 13.5 || $lon < -82.0 || $lon > -66.0) {
            return;
        }

        $this->datos['latitud'] = round($lat, 7);
        $this->datos['longitud'] = round($lon, 7);

        $precision = $e['precision_m'] ?? null;
        if (is_numeric($precision) && $precision >= 0 && $precision <= 10000) {
            $this->datos['precision_m'] = (int) $precision;
        }
    }

    /** @param array<string,mixed> $e */
    private function relato(array $e): void
    {
        $texto = $this->texto($e, 'descripcion_dano');

        if (mb_strlen($texto) > 1000) {
            $this->errores['descripcion_dano'] = 'Resuma en menos de 1000 caracteres.';

            return;
        }

        $this->datos['descripcion_dano'] = $texto === '' ? null : $texto;
    }

    /** @param array<string,mixed> $e */
    private function autorizacion(array $e): void
    {
        $acepta = (bool) ($e['autoriza_datos'] ?? false);

        // Sin autorización no hay envío, y esto se comprueba en el servidor: es
        // el ciudadano entregando sus propios datos sin nadie delante que se lo
        // explique, así que la prueba de que aceptó no puede depender de que el
        // navegador se haya portado bien.
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
