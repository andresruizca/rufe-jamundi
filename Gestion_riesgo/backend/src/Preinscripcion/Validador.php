<?php

declare(strict_types=1);

namespace App\Preinscripcion;

use App\Rufe\CatalogoBarrios;
use App\Rufe\Catalogos as Rufe;

/**
 * Validación de la pre-inscripción ciudadana.
 *
 * Se pide MENOS que en el censo, a propósito. Quien llena esto es la persona
 * afectada, sola, en su celular y probablemente alterada: cada campo de más es
 * un motivo para abandonar el formulario a la mitad. Lo imprescindible es poder
 * llegar a la casa y poder llamar a alguien.
 *
 * No se PIDE nada sensible: ni género, ni pertenencia étnica, ni composición
 * del hogar. Eso lo levanta el funcionario en la visita, con el aviso de la Ley
 * 1581 explicado de viva voz.
 *
 * Con una excepción que no rompe la regla: cuando la cédula está en el censo,
 * el formulario le ENSEÑA a la persona el hogar que un funcionario ya levantó y
 * le deja decir qué cambió. Ahí no se está recogiendo un dato nuevo —ya estaba
 * en el sistema— sino dándole a la familia la oportunidad de corregirlo. Y lo
 * que deje es una propuesta: `rufe_personas` no cambia por esto.
 *
 * Este validador MANDA. El navegador valida lo mismo para dar respuesta
 * inmediata, pero lo que decide es esto: la ruta es pública y cualquiera puede
 * mandar lo que quiera contra ella.
 */
final class Validador
{
    /** @var list<string> */
    /**
     * Las zonas que se aceptan al recibir.
     *
     * `URBANO` es como lo dice el censo y es lo que se guarda desde la
     * conciliación; `URBANA` es lo que decía este formulario antes y lo que
     * todavía mandan el APK sin conexión y cualquier pestaña que lleve horas
     * abierta. Se admiten las dos al entrar y se normaliza a una sola al
     * guardar: rechazar el envío de una familia por una letra sería el peor
     * canje posible.
     */
    public const ZONAS = ['URBANA', 'URBANO', 'RURAL'];

    /** La que se guarda. Una sola, la del censo. */
    public const ZONA_URBANA = 'URBANO';

    /**
     * Tope de personas en el listado del hogar.
     *
     * Generoso: hay hogares extensos de verdad, y quedarse corto le impide a una
     * familia real decir quiénes son. Pero un tope tiene que haber: sin él, una
     * ruta pública acepta un envío con diez mil personas.
     */
    private const MAX_PERSONAS = 30;

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
        $v->hogar($e);
        $v->estadoVivienda($e);
        $v->relato($e);
        $v->autorizacion($e);

        return ['errores' => $v->errores, 'datos' => $v->datos];
    }

    /** @param array<string,mixed> $e */
    private function identificacion(array $e): void
    {
        $this->nombre($e);

        // Solo dígitos y una longitud plausible. No se valida contra la
        // Registraduría: aquí no hay forma de hacerlo, y rechazar una cédula
        // legítima por una regla inventada dejaría a alguien fuera de la ayuda.
        $documento = preg_replace('/\D+/', '', $this->texto($e, 'documento')) ?? '';
        if (strlen($documento) < 5 || strlen($documento) > 15) {
            $this->errores['documento'] = 'Escriba su número de cédula, sin puntos ni espacios.';
        } else {
            $this->datos['documento'] = $documento;
        }

        // El tipo de documento, del mismo catálogo que el censo. Antes solo se
        // guardaba el número, y cédula, tarjeta de identidad y pasaporte
        // quedaban indistinguibles: al volver al censo había que adivinarlo.
        //
        // Opcional: quien llene esto desde una pestaña vieja o desde el APK no
        // lo manda, y eso no puede costarle la solicitud. Sin él se queda nulo,
        // que es honesto — «no se preguntó» y no «es una cédula».
        $tipo = trim($this->texto($e, 'tipo_documento'));
        $this->datos['tipo_documento'] = $tipo !== '' && isset(Rufe::TIPOS_DOCUMENTO[(int) $tipo])
            ? (int) $tipo
            : null;

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

    /**
     * Las personas del hogar, cuando el formulario las precargó del censo.
     *
     * Todo es opcional salvo el nombre: quien llegó por su cuenta y no tenía
     * ficha no manda nada, y quien la tenía puede haber dejado el listado tal
     * como venía. Un formulario ciudadano no puede exigir la fecha de
     * nacimiento del cuñado para dejar pedir una inspección.
     *
     * Lo que NO se acepta aquí es el `estado` de cada persona —igual,
     * corregida, nueva—: eso lo calcula el servidor comparando contra el censo.
     * Si lo mandara el navegador, bastaría con mentir en una casilla para que
     * una corrección entrara como «igual» y ningún funcionario la mirara.
     *
     * @param  array<string,mixed>  $e
     */
    private function hogar(array $e): void
    {
        $this->datos['personas'] = [];

        $crudas = $e['personas'] ?? [];

        if (! is_array($crudas) || $crudas === []) {
            return;
        }

        if (count($crudas) > self::MAX_PERSONAS) {
            $this->errores['personas'] = 'Son demasiadas personas para este formulario. Llame a la línea de atención.';

            return;
        }

        $limpias = [];

        foreach (array_values($crudas) as $i => $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $nombres = trim($this->texto($cruda, 'nombres'));
            $apellidos = trim($this->texto($cruda, 'apellidos'));

            // Una fila del todo vacía se descarta sin protestar: es la que queda
            // cuando alguien pulsa «Agregar otra persona» y se arrepiente.
            if ($nombres === '' && $apellidos === '') {
                continue;
            }

            if ($nombres === '' || $apellidos === '') {
                $this->errores['personas'] = 'A alguien del listado le falta el nombre o los apellidos.';

                return;
            }

            $documento = preg_replace('/\D+/', '', $this->texto($cruda, 'numero_documento')) ?? '';
            $nacimiento = trim($this->texto($cruda, 'fecha_nacimiento'));

            if ($nacimiento !== '' && ! $this->esFechaValida($nacimiento)) {
                $this->errores['personas'] = 'Revise una fecha de nacimiento del listado.';

                return;
            }

            $limpias[] = [
                'orden' => $i + 1,
                'nombres' => mb_substr($nombres, 0, 120),
                'apellidos' => mb_substr($apellidos, 0, 120),
                'tipo_documento' => $this->codigo($cruda, 'tipo_documento', Rufe::TIPOS_DOCUMENTO),
                'numero_documento' => $documento === '' ? null : mb_substr($documento, 0, 30),
                'parentesco' => $this->codigo($cruda, 'parentesco', Rufe::PARENTESCOS),
                'genero' => $this->codigo($cruda, 'genero', Rufe::GENEROS),
                'fecha_nacimiento' => $nacimiento === '' ? null : $nacimiento,
                // De qué persona del censo salió. Se acepta como pista y el
                // controlador la comprueba contra la ficha de verdad: sin esa
                // comprobación, alguien podría atribuirse una persona de otro
                // hogar.
                'rufe_persona_id' => (int) ($cruda['rufe_persona_id'] ?? 0) ?: null,
                'no_vive_aqui' => ! empty($cruda['no_vive_aqui']),
            ];
        }

        $this->datos['personas'] = $limpias;
    }

    /** Un código de catálogo, o null si no viene o no se reconoce. */
    private function codigo(array $cruda, string $clave, array $catalogo): ?int
    {
        $valor = (int) ($cruda[$clave] ?? 0);

        return isset($catalogo[$valor]) ? $valor : null;
    }

    private function esFechaValida(string $valor): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $d !== false && $d->format('Y-m-d') === $valor && $d <= new \DateTimeImmutable('today');
    }

    /**
     * El nombre, en dos campos como en el censo.
     *
     * ── Qué se arregla ───────────────────────────────────────────────────────
     *
     * `rufe_personas` guarda `nombres` y `apellidos` por separado, y también lo
     * hacen el listado del hogar de esta misma preinscripción y el formulario
     * de quien no aparece en el censo. Esta tabla era la única que guardaba una
     * sola cadena, y al precargar desde el censo se unían las dos partes: esa
     * frontera se perdía y no se puede reconstruir por regla, porque en
     * Colombia hay uno o dos nombres y dos apellidos sin forma de saber dónde
     * corta cada caso.
     *
     * ── Por qué se sigue aceptando el campo viejo ────────────────────────────
     *
     * El APK guarda solicitudes sin conexión y las manda días después, con el
     * formato que tenía cuando se llenaron. Una pestaña abierta desde ayer,
     * igual. Rechazarlas sería tirar el trabajo de una familia por un cambio
     * nuestro. Si llegan las dos partes, mandan ellas; si llega solo la cadena
     * entera, se guarda tal cual y las dos partes quedan nulas — nulo significa
     * «no se preguntó», y ninguna división inventada es mejor que eso.
     */
    private function nombre(array $e): void
    {
        $nombres = trim($this->texto($e, 'nombres'));
        $apellidos = trim($this->texto($e, 'apellidos'));

        if ($nombres !== '' || $apellidos !== '') {
            if (mb_strlen($nombres) < 2 || mb_strlen($nombres) > 120) {
                $this->errores['nombres'] = 'Escriba su nombre.';
            } else {
                $this->datos['nombres'] = $nombres;
            }

            if (mb_strlen($apellidos) < 2 || mb_strlen($apellidos) > 120) {
                $this->errores['apellidos'] = 'Escriba sus apellidos.';
            } else {
                $this->datos['apellidos'] = $apellidos;
            }

            // `nombre_completo` sigue existiendo y sigue siendo obligatorio en
            // la tabla. Lo compone el servidor: así una sola fuente manda, y
            // las pantallas que todavía lo leen siguen funcionando.
            $this->datos['nombre_completo'] = trim($nombres.' '.$apellidos);

            return;
        }

        $nombre = $this->texto($e, 'nombre_completo');

        if (mb_strlen($nombre) < 5 || mb_strlen($nombre) > 200) {
            $this->errores['nombres'] = 'Escriba su nombre y sus apellidos.';

            return;
        }

        $this->datos['nombre_completo'] = $nombre;
        $this->datos['nombres'] = null;
        $this->datos['apellidos'] = null;
    }

    /** @param array<string,mixed> $e */
    private function ubicacion(array $e): void
    {
        // Texto libre a propósito, y sin exigir formato de nomenclatura: media
        // zona rural de Jamundí no tiene dirección con calle y número. Lo que
        // sirve es «la casa azul pasando el puente de La Liberia», y eso es una
        // dirección perfectamente válida para quien va a ir a buscarla.
        $direccion = $this->texto($e, 'direccion');
        if (mb_strlen($direccion) < 5 || mb_strlen($direccion) > 200) {
            $this->errores['direccion'] = 'Escriba dónde queda la vivienda, como se lo explicaría a alguien que va a buscarla.';
        } else {
            $this->datos['direccion'] = $direccion;
        }

        // Antes la zona se DEDUCÍA de si venía corregimiento, y esa deducción
        // era falsa: quien vive en el campo y no sabe a qué corregimiento
        // pertenece su vereda entraba al sistema como urbano.
        $zona = strtoupper($this->texto($e, 'zona'));
        if (! in_array($zona, self::ZONAS, true)) {
            $this->errores['zona'] = 'Indique si la vivienda está en zona urbana o rural.';
            $this->datos['zona'] = null;
            $zona = '';
        } else {
            // Se guarda con el vocabulario del censo, venga como venga.
            $zona = $zona === 'RURAL' ? 'RURAL' : self::ZONA_URBANA;
            $this->datos['zona'] = $zona;
        }

        $corregimiento = $this->texto($e, 'corregimiento');
        if ($corregimiento !== '' && ! in_array($corregimiento, Rufe::CORREGIMIENTOS, true)) {
            $this->errores['corregimiento'] = 'Seleccione un corregimiento de la lista.';
        } elseif ($zona === self::ZONA_URBANA) {
            // En zona urbana no hay corregimiento. Se descarta en vez de
            // rechazar: si alguien marcó uno y después corrigió la zona, el dato
            // sobrante no puede costarle el envío.
            $this->datos['corregimiento'] = null;
        } else {
            $this->datos['corregimiento'] = $corregimiento === '' ? null : $corregimiento;
        }

        $vereda = $this->texto($e, 'vereda');
        $this->datos['vereda'] = $vereda === '' ? null : mb_substr($vereda, 0, 120);

        // ¿Salió de la lista del POT o lo escribió a mano? Lo escrito a mano es
        // lo que Planeación tiene que revisar: o falta un barrio en la lista de
        // 2021, o es una grafía nueva de uno que ya está. Sin esta marca habría
        // que volver a adivinarlo comparando cadenas, que es de donde venimos.
        $this->datos['barrio_del_catalogo'] = $zona === self::ZONA_URBANA
            && $vereda !== ''
            && CatalogoBarrios::reconocido($vereda) ? 1 : 0;

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

    /**
     * Las señales de daño que marcó el ciudadano.
     *
     * NINGUNA es obligatoria. Quien tiene la casa partida por la mitad puede no
     * reconocerse en ninguno de los ocho dibujos, y negarle el turno por eso
     * sería exactamente el error que este formulario existe para no cometer.
     * Lo que sí se exige es que los códigos sean del catálogo: la ruta es
     * pública y cualquiera puede mandar lo que quiera contra ella.
     *
     * @param array<string,mixed> $e
     */
    private function estadoVivienda(array $e): void
    {
        $this->datos['senales'] = [];

        $marcadas = $e['senales'] ?? [];
        if (! is_array($marcadas)) {
            return;
        }

        $limpias = [];

        foreach ($marcadas as $codigo) {
            if (! is_string($codigo)) {
                continue;
            }

            $codigo = strtoupper(trim($codigo));

            if (! Senales::existe($codigo)) {
                $this->errores['senales'] = 'Alguna de las opciones marcadas no se reconoce. Recargue la página e intente de nuevo.';

                return;
            }

            // Marcar dos veces lo mismo no significa nada y la tabla lo
            // rechazaría con un error que el ciudadano no sabría interpretar.
            $limpias[$codigo] = true;
        }

        $this->datos['senales'] = array_keys($limpias);
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
