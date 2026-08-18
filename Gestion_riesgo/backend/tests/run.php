<?php

declare(strict_types=1);

/**
 * Pruebas del backend, sin PHPUnit.
 *
 * No hay Composer en el hosting ni forma de instalarlo, así que las pruebas son
 * un archivo PHP que se ejecuta con `php backend/tests/run.php`. Solo cubren
 * código puro (validación, catálogos, radicado, troceo de SQL): nada aquí toca
 * la base de datos, para que se pueda ejecutar en cualquier máquina sin montar
 * nada.
 *
 * Lo que necesita una base viva —transacciones, tasa, subida de archivos— se
 * comprueba con tests/http.sh contra un servidor local.
 */

$raiz = dirname(__DIR__);

spl_autoload_register(static function (string $clase) use ($raiz): void {
    if (! str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = $raiz.'/src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});

use App\Core\Migrador;
use App\Sistema\Actualizador;
use App\Rufe\Busqueda;
use App\Rufe\Catalogos;
use App\Rufe\Geocodificador;
use App\Rufe\Radicado;
use App\Rufe\Validador;

date_default_timezone_set('America/Bogota');

// ── Mini arnés ───────────────────────────────────────────────────────────────

$pasadas = 0;
$fallos = [];
$grupo = '';

function grupo(string $nombre): void
{
    global $grupo;
    $grupo = $nombre;
    echo "\n\033[1m{$nombre}\033[0m\n";
}

function prueba(string $nombre, callable $fn): void
{
    global $pasadas, $fallos, $grupo;

    try {
        $fn();
        $pasadas++;
        echo "  \033[32m✓\033[0m {$nombre}\n";
    } catch (Throwable $e) {
        $fallos[] = "{$grupo} › {$nombre}: ".$e->getMessage();
        echo "  \033[31m✗\033[0m {$nombre}\n      \033[31m".$e->getMessage()."\033[0m\n";
    }
}

function afirmar(bool $condicion, string $mensaje): void
{
    if (! $condicion) {
        throw new RuntimeException($mensaje);
    }
}

function afirmarIgual(mixed $esperado, mixed $real, string $mensaje = ''): void
{
    if ($esperado !== $real) {
        throw new RuntimeException(
            ($mensaje !== '' ? $mensaje.' — ' : '')
            .'esperado '.var_export($esperado, true).', recibido '.var_export($real, true)
        );
    }
}

/** @param array<string,mixed> $entrada */
function errores(array $entrada): array
{
    return Validador::reporte($entrada)['errores'];
}

/** @param array<string,mixed> $entrada */
function datos(array $entrada): array
{
    return Validador::reporte($entrada)['datos'];
}

function afirmarError(array $entrada, string $campo): void
{
    $e = errores($entrada);
    afirmar(
        isset($e[$campo]),
        "se esperaba un error en «{$campo}», se obtuvieron: ".(
            $e === [] ? '(ninguno)' : implode(', ', array_keys($e))
        )
    );
}

function afirmarSinError(array $entrada, string $campo): void
{
    $e = errores($entrada);
    afirmar(! isset($e[$campo]), "no se esperaba error en «{$campo}»: ".($e[$campo] ?? ''));
}

// ── Datos base ───────────────────────────────────────────────────────────────

/** Un reporte mínimo y válido. Cada prueba lo modifica en lo que le interesa. */
function base(array $cambios = []): array
{
    return array_replace([
        'evento' => 'Terremoto',
        'fecha_evento' => date('Y-m-d', strtotime('-3 days')),
        'zona' => 'URBANO',
        'vereda_sector_barrio' => 'Barrio Belalcázar',
        'direccion' => 'Calle 10 # 5-32',
        'alojamiento' => 'LUGAR_HABITUAL',
        'forma_tenencia' => 'PROPIETARIO',
        'estado_bien' => 'AVERIADO',
        'tipo_bien' => 'VIVIENDA',
        'personas' => [persona()],
        'tiene_afectacion_agro' => false,
        'contacto_telefono' => '3105551234',
        'autoriza_tratamiento' => true,
    ], $cambios);
}

function persona(array $cambios = []): array
{
    return array_replace([
        'nombres' => 'María José',
        'apellidos' => 'Riascos Mina',
        'tipo_documento' => 3,
        'numero_documento' => '31234567',
        'parentesco' => 1,
        'genero' => 2,
        'fecha_nacimiento' => '1985-04-11',
        'pertenencia_etnica' => 5,
        'telefono' => '3105551234',
    ], $cambios);
}

// ── Pruebas ──────────────────────────────────────────────────────────────────

grupo('Reporte válido');

prueba('un reporte completo no produce errores', function (): void {
    afirmarIgual([], errores(base()));
});

prueba('los campos fijos los pone el servidor, no el cliente', function (): void {
    $d = datos(base(['departamento' => 'Antioquia', 'municipio' => 'Medellín']));
    afirmarIgual(Catalogos::DEPARTAMENTO, $d['departamento']);
    afirmarIgual(Catalogos::MUNICIPIO, $d['municipio']);
});

prueba('la fecha RUFE es la de hoy y no la que envíe el cliente', function (): void {
    afirmarIgual(date('Y-m-d'), datos(base(['fecha_rufe' => '2001-01-01']))['fecha_rufe']);
});

prueba('el orden de las personas se renumera desde 1', function (): void {
    $d = datos(base(['personas' => [
        persona(['orden' => 77]),
        persona(['parentesco' => 3, 'numero_documento' => '1088', 'tipo_documento' => 2, 'telefono' => null]),
    ]]));
    afirmarIgual([1, 2], array_column($d['personas'], 'orden'));
});

grupo('Evento y fecha');

prueba('el evento es obligatorio', function (): void {
    afirmarError(base(['evento' => '']), 'evento');
});

prueba('un evento fuera del catálogo se rechaza', function (): void {
    afirmarError(base(['evento' => 'Invasión alienígena']), 'evento');
});

prueba('C1: "Otro" exige el texto libre', function (): void {
    afirmarError(base(['evento' => 'OTRO']), 'evento_otro');
});

prueba('C1: "Otro" guarda el texto libre como evento', function (): void {
    $d = datos(base(['evento' => 'OTRO', 'evento_otro' => 'Socavación de la vía']));
    afirmarIgual('Socavación de la vía', $d['evento']);
});

prueba('C1: el texto libre sobra si el evento salió de la lista', function (): void {
    afirmarError(base(['evento' => 'Terremoto', 'evento_otro' => 'algo']), 'evento_otro');
});

prueba('la fecha del evento no puede ser futura', function (): void {
    afirmarError(base(['fecha_evento' => date('Y-m-d', strtotime('+1 day'))]), 'fecha_evento');
});

prueba('la fecha del evento no puede ser de hace más de dos años', function (): void {
    afirmarError(base(['fecha_evento' => date('Y-m-d', strtotime('-3 years'))]), 'fecha_evento');
});

prueba('una fecha que no existe se rechaza', function (): void {
    afirmarError(base(['fecha_evento' => '2026-02-31']), 'fecha_evento');
});

prueba('una fecha con formato inválido se rechaza', function (): void {
    afirmarError(base(['fecha_evento' => '11/04/2026']), 'fecha_evento');
});

grupo('Ubicación');

prueba('C2: en zona rural el corregimiento es obligatorio', function (): void {
    afirmarError(base(['zona' => 'RURAL']), 'corregimiento');
});

prueba('C2: en zona rural con corregimiento no hay error', function (): void {
    afirmarIgual([], errores(base(['zona' => 'RURAL', 'corregimiento' => 'Potrerito'])));
});

prueba('C2: el corregimiento se rechaza en zona urbana', function (): void {
    afirmarError(base(['zona' => 'URBANO', 'corregimiento' => 'Potrerito']), 'corregimiento');
});

prueba('C2: en zona urbana el corregimiento queda nulo', function (): void {
    afirmarIgual(null, datos(base())['corregimiento']);
});

prueba('una zona inventada se rechaza', function (): void {
    afirmarError(base(['zona' => 'SUBURBANO']), 'zona');
});

prueba('la dirección exige al menos 5 caracteres', function (): void {
    afirmarError(base(['direccion' => 'C 1']), 'direccion');
});

prueba('C12: las coordenadas son opcionales', function (): void {
    afirmarIgual(null, datos(base())['latitud']);
});

prueba('C12: coordenadas válidas se conservan', function (): void {
    $d = datos(base(['latitud' => 3.2611, 'longitud' => -76.5423, 'precision_m' => 18]));
    afirmarIgual(3.2611, $d['latitud']);
    afirmarIgual(-76.5423, $d['longitud']);
    afirmarIgual(18, $d['precision_m']);
});

prueba('C12: coordenadas fuera de Colombia se descartan', function (): void {
    afirmarError(base(['latitud' => 48.85, 'longitud' => 2.35]), 'latitud');
});

prueba('C12: media coordenada se descarta', function (): void {
    afirmarError(base(['latitud' => 3.26, 'longitud' => null]), 'latitud');
});

grupo('Alojamiento y bien');

prueba('C4: si evacuó, exige dónde se aloja', function (): void {
    afirmarError(base(['alojamiento' => 'EVACUADO']), 'alojamiento_direccion');
});

prueba('C4: la dirección de alojamiento sobra si no evacuó', function (): void {
    afirmarError(base(['alojamiento_direccion' => 'Casa de un familiar']), 'alojamiento_direccion');
});

prueba('un tipo de bien fuera del catálogo se rechaza', function (): void {
    afirmarError(base(['tipo_bien' => 'CASTILLO']), 'tipo_bien');
});

prueba('los catorce tipos de bien del formato se aceptan', function (): void {
    foreach (array_keys(Catalogos::TIPOS_BIEN) as $tipo) {
        afirmarSinError(base(['tipo_bien' => $tipo]), 'tipo_bien');
    }
});

grupo('Personas');

prueba('se exige al menos una persona', function (): void {
    afirmarError(base(['personas' => []]), 'personas');
});

prueba('no se admiten más de diez personas', function (): void {
    $once = array_map(
        static fn (int $i): array => persona([
            'parentesco' => $i === 0 ? 1 : 3,
            'numero_documento' => (string) (10000000 + $i),
        ]),
        range(0, 10)
    );
    afirmarError(base(['personas' => $once]), 'personas');
});

prueba('diez personas sí se admiten', function (): void {
    $diez = array_map(
        static fn (int $i): array => persona([
            'parentesco' => $i === 0 ? 1 : 3,
            'numero_documento' => (string) (10000000 + $i),
        ]),
        range(0, 9)
    );
    afirmarIgual([], errores(base(['personas' => $diez])));
});

prueba('debe haber un jefe de hogar', function (): void {
    afirmarError(base(['personas' => [persona(['parentesco' => 3])]]), 'personas');
});

prueba('no puede haber dos jefes de hogar', function (): void {
    afirmarError(base(['personas' => [
        persona(),
        persona(['numero_documento' => '99887766']),
    ]]), 'personas');
});

prueba('no se repite el mismo documento en el hogar', function (): void {
    afirmarError(base(['personas' => [
        persona(),
        persona(['parentesco' => 3]),
    ]]), 'personas.1.numero_documento');
});

prueba('C6: la cédula exige número', function (): void {
    afirmarError(base(['personas' => [persona(['numero_documento' => ''])]]), 'personas.0.numero_documento');
});

prueba('C6: la cédula no admite letras', function (): void {
    afirmarError(base(['personas' => [persona(['numero_documento' => 'AB123456'])]]), 'personas.0.numero_documento');
});

prueba('C6: el pasaporte sí admite letras', function (): void {
    afirmarSinError(
        base(['personas' => [persona(['tipo_documento' => 5, 'numero_documento' => 'AV123456'])]]),
        'personas.0.numero_documento'
    );
});

prueba('C5: "menor sin identificación" no lleva número', function (): void {
    afirmarError(
        base(['personas' => [persona(['tipo_documento' => 6, 'numero_documento' => '123456'])]]),
        'personas.0.numero_documento'
    );
});

prueba('C5: "menor sin identificación" sin número es válido', function (): void {
    afirmarIgual([], errores(base(['personas' => [
        persona(['tipo_documento' => 6, 'numero_documento' => '']),
    ]])));
});

prueba('C7: el documento "Otro" exige decir cuál', function (): void {
    afirmarError(
        base(['personas' => [persona(['tipo_documento' => 10, 'numero_documento' => 'X1234'])]]),
        'personas.0.documento_otro'
    );
});

prueba('C7: "cuál documento" sobra con cédula', function (): void {
    afirmarError(
        base(['personas' => [persona(['documento_otro' => 'Libreta militar'])]]),
        'personas.0.documento_otro'
    );
});

prueba('C8: el jefe de hogar necesita teléfono', function (): void {
    afirmarError(base(['personas' => [persona(['telefono' => ''])]]), 'personas.0.telefono');
});

prueba('C8: los demás integrantes no necesitan teléfono', function (): void {
    afirmarIgual([], errores(base(['personas' => [
        persona(),
        persona(['parentesco' => 3, 'tipo_documento' => 1, 'numero_documento' => '1088123', 'telefono' => '']),
    ]])));
});

prueba('un parentesco fuera de 1..15 se rechaza', function (): void {
    afirmarError(base(['personas' => [persona(['parentesco' => 99])]]), 'personas.0.parentesco');
});

prueba('un género fuera de 1..3 se rechaza', function (): void {
    afirmarError(base(['personas' => [persona(['genero' => 0])]]), 'personas.0.genero');
});

prueba('una etnia fuera de 1..6 se rechaza', function (): void {
    afirmarError(base(['personas' => [persona(['pertenencia_etnica' => 7])]]), 'personas.0.pertenencia_etnica');
});

prueba('la fecha de nacimiento es opcional', function (): void {
    afirmarIgual([], errores(base(['personas' => [persona(['fecha_nacimiento' => ''])]])));
});

prueba('la fecha de nacimiento no puede ser futura', function (): void {
    afirmarError(
        base(['personas' => [persona(['fecha_nacimiento' => date('Y-m-d', strtotime('+1 day'))])]]),
        'personas.0.fecha_nacimiento'
    );
});

prueba('el nombre no admite dígitos', function (): void {
    afirmarError(base(['personas' => [persona(['nombres' => 'Ana 3'])]]), 'personas.0.nombres');
});

prueba('el nombre admite tildes, ñ, apóstrofo y guion', function (): void {
    afirmarSinError(base(['personas' => [persona(['nombres' => "Ñandú D'Ángelo-Peña"])]]), 'personas.0.nombres');
});

grupo('Sector agropecuario');

prueba('C9: sin afectación no se admiten renglones', function (): void {
    afirmarError(base([
        'tiene_afectacion_agro' => false,
        'agropecuario' => [['tipo_cultivo' => 'Plátano']],
    ]), 'agropecuario');
});

prueba('C9: sin afectación el arreglo queda vacío', function (): void {
    afirmarIgual([], datos(base())['agropecuario']);
});

prueba('C9: con afectación se exige al menos un renglón', function (): void {
    afirmarError(base(['tiene_afectacion_agro' => true, 'agropecuario' => []]), 'agropecuario');
});

prueba('un renglón sin cultivo ni especie se rechaza', function (): void {
    afirmarError(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => [['tipo_cultivo' => '', 'especie_pecuaria' => '']],
    ]), 'agropecuario.0');
});

prueba('C10: el cultivo exige unidad y área', function (): void {
    $e = errores(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => [['tipo_cultivo' => 'Caña']],
    ]));
    afirmar(isset($e['agropecuario.0.unidad_medida']), 'falta el error de unidad');
    afirmar(isset($e['agropecuario.0.area_cantidad']), 'falta el error de área');
});

prueba('C10: un área de cero se rechaza', function (): void {
    afirmarError(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => [['tipo_cultivo' => 'Caña', 'unidad_medida' => 'HECTAREA', 'area_cantidad' => 0]],
    ]), 'agropecuario.0.area_cantidad');
});

prueba('C11: la especie exige cantidad', function (): void {
    afirmarError(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => [['especie_pecuaria' => 'Gallinas']],
    ]), 'agropecuario.0.cantidad_unidades');
});

prueba('un renglón solo pecuario es válido', function (): void {
    afirmarIgual([], errores(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => [['especie_pecuaria' => 'Gallinas', 'cantidad_unidades' => 40]],
    ])));
});

prueba('no se admiten más de cuatro renglones', function (): void {
    afirmarError(base([
        'tiene_afectacion_agro' => true,
        'agropecuario' => array_fill(0, 5, ['especie_pecuaria' => 'Cerdos', 'cantidad_unidades' => 2]),
    ]), 'agropecuario');
});

grupo('Contacto y autorizaciones');

prueba('el teléfono de contacto es obligatorio', function (): void {
    afirmarError(base(['contacto_telefono' => '']), 'contacto_telefono');
});

prueba('un teléfono de menos de siete dígitos se rechaza', function (): void {
    afirmarError(base(['contacto_telefono' => '31055']), 'contacto_telefono');
});

prueba('el teléfono se normaliza a solo dígitos', function (): void {
    afirmarIgual('573105551234', datos(base(['contacto_telefono' => '+57 (310) 555-1234']))['contacto_telefono']);
});

prueba('un correo inválido se rechaza', function (): void {
    afirmarError(base(['contacto_correo' => 'no-es-correo']), 'contacto_correo');
});

prueba('el correo es opcional', function (): void {
    afirmarIgual(null, datos(base())['contacto_correo']);
});

prueba('el correo se normaliza a minúsculas', function (): void {
    afirmarIgual('A@B.CO', 'A@B.CO');
    afirmarIgual('ana@jamundi.gov.co', datos(base(['contacto_correo' => 'Ana@Jamundi.Gov.CO']))['contacto_correo']);
});

prueba('la autorización es obligatoria', function (): void {
    afirmarError(base(['autoriza_tratamiento' => false]), 'autoriza_tratamiento');
});

prueba('una autorización que no sea exactamente true no vale', function (): void {
    // Un 1 o un "si" no son un consentimiento: se exige el booleano, para que un
    // cliente mal escrito no pueda dar por autorizado lo que nadie autorizó.
    afirmarError(base(['autoriza_tratamiento' => 'si']), 'autoriza_tratamiento');
    afirmarError(base(['autoriza_tratamiento' => 1]), 'autoriza_tratamiento');
});

prueba('se guarda el aviso que leyó el ciudadano, no el vigente hoy', function (): void {
    // Una ficha levantada sin señal puede llegar días después, con la aplicación
    // ya cambiada. Estampar la versión vigente afirmaría que esa persona aceptó
    // un texto que nunca vio, y ese registro es la prueba exigible ante la SIC.
    afirmarIgual('habeas-data-v1', datos(base(['aviso_version' => 'habeas-data-v1']))['autorizacion_texto']);
    afirmarIgual('habeas-data-v2', datos(base(['aviso_version' => 'habeas-data-v2']))['autorizacion_texto']);
});

prueba('un aviso inventado no se guarda: se usa el vigente', function (): void {
    // El cliente no puede escribir cualquier cosa en la prueba del consentimiento.
    afirmarIgual(Catalogos::AVISO_VERSION, datos(base(['aviso_version' => 'lo-que-sea']))['autorizacion_texto']);
    afirmarIgual(Catalogos::AVISO_VERSION, datos(base(['aviso_version' => 123]))['autorizacion_texto']);
});

prueba('sin aviso declarado se asume el vigente', function (): void {
    afirmarIgual(Catalogos::AVISO_VERSION, datos(base())['autorizacion_texto']);
});

prueba('una sola casilla sigue guardando las dos columnas de la ley', function (): void {
    // La ley distingue los datos sensibles del resto. Aunque el ciudadano marque
    // una casilla, la base debe poder responder qué autorizó exactamente.
    $d = datos(base());
    afirmarIgual(1, $d['autoriza_datos']);
    afirmarIgual(1, $d['autoriza_sensibles']);
});

prueba('el aviso aceptado sube de versión al cambiar su texto', function (): void {
    // Lo que prueba qué aceptó el ciudadano es este número, no lo que hoy diga
    // la pantalla. Las fichas anteriores conservan la versión que aceptaron.
    afirmarIgual('habeas-data-v2', Catalogos::AVISO_VERSION);
    afirmarIgual('habeas-data-v2', datos(base())['autorizacion_texto']);
});

prueba('se guarda la versión del aviso aceptado', function (): void {
    afirmarIgual(Catalogos::AVISO_VERSION, datos(base())['autorizacion_texto']);
});

prueba('las observaciones se limitan a 2000 caracteres', function (): void {
    afirmarError(base(['observaciones' => str_repeat('a', 2001)]), 'observaciones');
});

grupo('Saneamiento');

prueba('el texto se recorta', function (): void {
    afirmarIgual('Calle 10 # 5-32', datos(base(['direccion' => "   Calle 10 # 5-32\t "]))['direccion']);
});

prueba('los caracteres de control se eliminan', function (): void {
    afirmarIgual('Calle 10 # 5-32', datos(base(['direccion' => "Calle 10 \x00# 5-32"]))['direccion']);
});

prueba('el HTML se conserva literal: escaparlo es tarea de quien lo muestre', function (): void {
    $entrada = '<script>alert(1)</script> en el patio';
    afirmarIgual($entrada, datos(base(['observaciones' => $entrada]))['observaciones']);
});

prueba('una comilla de inyección SQL es solo texto', function (): void {
    $entrada = "Calle 5' OR 1=1 --";
    afirmarIgual($entrada, datos(base(['direccion' => $entrada]))['direccion']);
});

prueba('un valor no escalar no revienta el validador', function (): void {
    afirmarError(base(['direccion' => ['a' => 'b']]), 'direccion');
});

prueba('personas que no son un arreglo no revientan el validador', function (): void {
    afirmarError(base(['personas' => 'muchas']), 'personas');
});

grupo('Radicado');

prueba('el formato es RUFE-AAAA-XXXXXXXX', function (): void {
    afirmar(Radicado::esValido(Radicado::componer()), 'el radicado generado no pasa su propia validación');
});

prueba('lleva el año en curso', function (): void {
    afirmar(str_starts_with(Radicado::componer(), 'RUFE-'.date('Y').'-'), 'el año no coincide');
});

prueba('mide exactamente 18 caracteres, como la columna', function (): void {
    afirmarIgual(18, strlen(Radicado::componer()));
});

prueba('no usa I, L, O ni U, que se confunden al dictarlo', function (): void {
    for ($i = 0; $i < 200; $i++) {
        $sufijo = substr(Radicado::componer(), 10);
        afirmar(preg_match('/[ILOU]/', $sufijo) !== 1, "el sufijo {$sufijo} trae un carácter ambiguo");
    }
});

prueba('no es predecible: 500 radicados sin repetición', function (): void {
    $vistos = [];
    for ($i = 0; $i < 500; $i++) {
        $vistos[Radicado::componer()] = true;
    }
    afirmarIgual(500, count($vistos), 'hubo colisiones');
});

prueba('un radicado con formato ajeno no valida', function (): void {
    afirmar(! Radicado::esValido('RUFE-2026-0000000I'), 'aceptó una I');
    afirmar(! Radicado::esValido('RUFE-26-ABCDEFGH'), 'aceptó un año de dos dígitos');
    afirmar(! Radicado::esValido('rufe-2026-ABCDEFGH'), 'aceptó minúsculas');
});

grupo('Huella anti-duplicado');

prueba('la misma dirección con otro espaciado da la misma huella', function (): void {
    afirmarIgual(
        Radicado::huella('2026-08-01', 'Calle 10 # 5-32', '31234567'),
        Radicado::huella('2026-08-01', '  calle 10   #  5-32 ', '31234567')
    );
});

prueba('otra fecha da otra huella', function (): void {
    afirmar(
        Radicado::huella('2026-08-01', 'Calle 10', '312') !== Radicado::huella('2026-08-02', 'Calle 10', '312'),
        'la fecha no influye en la huella'
    );
});

prueba('otro jefe de hogar da otra huella', function (): void {
    afirmar(
        Radicado::huella('2026-08-01', 'Calle 10', '312') !== Radicado::huella('2026-08-01', 'Calle 10', '999'),
        'el documento no influye en la huella'
    );
});

grupo('Catálogos');

prueba('los códigos del formato están completos', function (): void {
    afirmarIgual(10, count(Catalogos::TIPOS_DOCUMENTO));
    afirmarIgual(15, count(Catalogos::PARENTESCOS));
    afirmarIgual(3, count(Catalogos::GENEROS));
    afirmarIgual(6, count(Catalogos::ETNIAS));
    afirmarIgual(14, count(Catalogos::TIPOS_BIEN));
    afirmarIgual(5, count(Catalogos::FORMAS_TENENCIA));
    afirmarIgual(5, count(Catalogos::ESTADOS_BIEN));
    afirmarIgual(5, count(Catalogos::UNIDADES_MEDIDA));
});

prueba('los códigos numéricos empiezan en 1 y son contiguos', function (): void {
    foreach ([Catalogos::TIPOS_DOCUMENTO, Catalogos::PARENTESCOS, Catalogos::GENEROS, Catalogos::ETNIAS] as $catalogo) {
        afirmarIgual(range(1, count($catalogo)), array_keys($catalogo));
    }
});

prueba('solo tres códigos describen ausencia de documento', function (): void {
    foreach ([6, 7, 8] as $codigo) {
        afirmar(! Catalogos::exigeNumeroDocumento($codigo), "el código {$codigo} no debería exigir número");
    }
    foreach ([1, 2, 3, 4, 5, 9, 10] as $codigo) {
        afirmar(Catalogos::exigeNumeroDocumento($codigo), "el código {$codigo} debería exigir número");
    }
});

prueba('el código 9 es el NIT y lleva número', function (): void {
    // Se leyó mal del PDF original, borroso, como "NA": clasificado así, el
    // formulario impedía escribir el NIT de un hospital o una escuela, que son
    // tipos de bien del propio formato.
    afirmarIgual('NIT', Catalogos::TIPOS_DOCUMENTO[9]);
    afirmar(Catalogos::exigeNumeroDocumento(9), 'el NIT debe exigir número');
    afirmar(
        in_array(9, Catalogos::DOCUMENTOS_ALFANUMERICOS, true),
        'el NIT debe admitir el guion del dígito de verificación'
    );
});

prueba('un NIT con dígito de verificación se acepta', function (): void {
    afirmarSinError(
        base(['personas' => [persona(['tipo_documento' => 9, 'numero_documento' => '900123456-1'])]]),
        'personas.0.numero_documento'
    );
});

prueba('los predeterminados apuntan a un evento que existe en el catálogo', function (): void {
    afirmar(
        in_array(Catalogos::EVENTO_PREDETERMINADO, Catalogos::EVENTOS_SUGERIDOS, true),
        'el evento precargado no está en la lista, el formulario abriría con un valor que él mismo rechaza'
    );
});

prueba('la fecha predeterminada es válida para el formulario', function (): void {
    $f = Catalogos::FECHA_EVENTO_PREDETERMINADA;
    afirmar(preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1, 'formato inesperado');
    afirmar($f <= date('Y-m-d'), 'la fecha precargada es futura');
    afirmar(
        $f >= date('Y-m-d', strtotime('-'.Catalogos::ANOS_ATRAS_EVENTO.' years')),
        'la fecha precargada quedó fuera de la ventana admitida'
    );
    afirmarIgual([], errores(base(['evento' => Catalogos::EVENTO_PREDETERMINADO, 'fecha_evento' => $f])));
});

prueba('el servidor solo acepta WebP y JPEG', function (): void {
    // El navegador convierte toda foto antes de subirla. Aceptar PNG, HEIC o PDF
    // sería dejar abierta una puerta que el formulario ya no usa.
    afirmarIgual(['webp', 'jpg', 'jpeg'], array_keys(Catalogos::EXTENSIONES));
    afirmar(! isset(Catalogos::EXTENSIONES['png']), 'PNG debería estar fuera');
    afirmar(! isset(Catalogos::EXTENSIONES['pdf']), 'PDF debería estar fuera');
    afirmar(! isset(Catalogos::EXTENSIONES['heic']), 'HEIC debería estar fuera');
});

prueba('el tope por foto deja margen sobre la meta del navegador', function (): void {
    afirmar(
        Catalogos::MAX_BYTES_ARCHIVO > Catalogos::OBJETIVO_BYTES_FOTO,
        'el tope del servidor debe ser mayor que la meta del navegador, o una foto en el límite se rechazaría'
    );
    afirmar(Catalogos::MAX_BYTES_ARCHIVO <= 1048576, 'el tope subió de 1 MiB');
    afirmarIgual(921600, Catalogos::OBJETIVO_BYTES_FOTO);
});

prueba('hay tope de resolución contra bombas de descompresión', function (): void {
    afirmar(Catalogos::MAX_LADO_PIXELES > 1920, 'debe caber lo que produce el navegador');
    afirmar(Catalogos::MAX_LADO_PIXELES <= 8000, 'un tope demasiado alto no protege de nada');
});

prueba('los cupos de evidencia son uno de documento y cuatro de daño', function (): void {
    afirmarIgual(1, Catalogos::MAX_EVIDENCIAS_DOCUMENTO);
    afirmarIgual(4, Catalogos::MAX_EVIDENCIAS_DANO);
    afirmarIgual(5, Catalogos::MAX_EVIDENCIAS);
    afirmarIgual(['DOCUMENTO', 'DANO'], array_keys(Catalogos::TIPOS_EVIDENCIA));

    // Cinco fotos de 900 KB caben de sobra en el cupo total de la carga.
    afirmar(
        Catalogos::MAX_EVIDENCIAS * Catalogos::OBJETIVO_BYTES_FOTO < Catalogos::MAX_BYTES_CARGA,
        'el cupo total de la carga no alcanza para el máximo de fotos'
    );
});

prueba('la respuesta de la API es serializable y trae lo esencial', function (): void {
    $json = json_encode(Catalogos::paraApi(), JSON_UNESCAPED_UNICODE);
    afirmar($json !== false, 'no se pudo serializar');

    $vuelta = json_decode((string) $json, true);
    foreach (['tipos_documento', 'parentescos', 'generos', 'etnias', 'tipos_bien', 'limites', 'fijos', 'predeterminados'] as $clave) {
        afirmar(isset($vuelta[$clave]), "falta la clave «{$clave}»");
    }
    afirmarIgual(15, count($vuelta['parentescos']));
    afirmarIgual(1, $vuelta['parentescos'][0]['codigo'], 'el primer parentesco debe ser el jefe de hogar');
});

prueba('los catálogos numerados viajan como lista y conservan el orden', function (): void {
    $json = (string) json_encode(Catalogos::paraApi());
    afirmar(str_contains($json, '"parentescos":[{'), 'los parentescos deberían ser un arreglo JSON, no un objeto');
});

grupo('Troceo del SQL');

prueba('los comentarios se quitan antes de partir', function (): void {
    $sentencias = Migrador::sentencias("-- comentario\nSELECT 1;\n-- otro\nSELECT 2;");
    afirmarIgual(['SELECT 1', 'SELECT 2'], $sentencias);
});

prueba('la migración posterior no rompe el troceo', function () use ($raiz): void {
    $sql = (string) file_get_contents($raiz.'/database/rufe_02_evidencias_y_envio.sql');
    $sentencias = Migrador::sentencias($sql);

    afirmar($sentencias !== [], 'el archivo quedó vacío tras quitar comentarios');
    foreach ($sentencias as $s) {
        afirmar(! str_contains($s, '--'), 'quedó un comentario dentro de una sentencia');
    }

    // Comprueba la pareja PREPARE/DEALLOCATE: si el troceo partiera una por la
    // mitad, la migración fallaría a mitad de camino en producción.
    afirmarIgual(2, count(array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'PREPARE'))));
    afirmarIgual(2, count(array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'DEALLOCATE'))));
});

prueba('la migración solo añade columnas: no borra ni renombra nada', function () use ($raiz): void {
    $sql = strtoupper((string) file_get_contents($raiz.'/database/rufe_02_evidencias_y_envio.sql'));
    foreach ([' DROP ', ' TRUNCATE ', 'DELETE FROM', 'CHANGE COLUMN'] as $peligrosa) {
        afirmar(! str_contains($sql, $peligrosa), "la migración contiene «{$peligrosa}»");
    }
});

prueba('rufe.sql se trocea en las siete tablas esperadas', function () use ($raiz): void {
    $sql = (string) file_get_contents($raiz.'/database/rufe.sql');
    $sentencias = Migrador::sentencias($sql);

    $creates = array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'CREATE TABLE'));
    afirmarIgual(7, count($creates), 'número de CREATE TABLE');

    foreach ($sentencias as $s) {
        afirmar(! str_contains($s, '--'), 'quedó un comentario dentro de una sentencia');
    }
});

prueba('rufe.sql es idempotente: todo CREATE lleva IF NOT EXISTS', function () use ($raiz): void {
    $sql = (string) file_get_contents($raiz.'/database/rufe.sql');
    foreach (Migrador::sentencias($sql) as $s) {
        if (str_starts_with($s, 'CREATE TABLE')) {
            afirmar(str_contains($s, 'IF NOT EXISTS'), 'un CREATE TABLE sin IF NOT EXISTS: '.substr($s, 0, 60));
        }
    }
});

prueba('rufe_revertir.sql borra exactamente lo que crea rufe.sql', function () use ($raiz): void {
    $crea = [];
    foreach (Migrador::sentencias((string) file_get_contents($raiz.'/database/rufe.sql')) as $s) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $s, $m) === 1) {
            $crea[] = $m[1];
        }
    }

    $borra = [];
    foreach (Migrador::sentencias((string) file_get_contents($raiz.'/database/rufe_revertir.sql')) as $s) {
        if (preg_match('/DROP TABLE IF EXISTS (\w+)/', $s, $m) === 1) {
            $borra[] = $m[1];
        }
    }

    // El orden importa: hay claves foráneas, así que hay que borrar de la hoja
    // hacia la raíz, es decir exactamente al revés de como se creó.
    afirmarIgual(array_reverse($crea), $borra, 'la reversión no va en orden inverso a la creación');
});

prueba('la reversión no toca ninguna tabla previa', function () use ($raiz): void {
    $sql = (string) file_get_contents($raiz.'/database/rufe_revertir.sql');
    foreach (['usuarios', 'sesiones', 'auditoria', 'ajustes'] as $tabla) {
        afirmar(
            ! str_contains($sql, 'DROP TABLE IF EXISTS '.$tabla.';')
            && ! preg_match('/DROP TABLE IF EXISTS '.$tabla.'\b/', $sql),
            "la reversión borraría la tabla previa «{$tabla}»"
        );
    }
});

grupo('Actualizador del sistema');

/** Acceso a los métodos privados: son las reglas que deciden qué se sobrescribe. */
function actualizador(string $metodo, mixed ...$args): mixed
{
    // setAccessible() no se llama: desde PHP 8.1 no hace nada y en 8.5 avisa
    // como obsoleta. La reflexión ya alcanza los métodos privados.
    return (new ReflectionMethod(Actualizador::class, $metodo))->invoke(new Actualizador, ...$args);
}

function constanteActualizador(string $nombre): mixed
{
    return (new ReflectionClass(Actualizador::class))->getConstant($nombre);
}

prueba('config.php nunca se sobrescribe', function (): void {
    afirmar(
        in_array('config.php', constanteActualizador('PROTEGIDAS'), true),
        'config.php debe estar protegido: un despliegue que lo pise borra las credenciales'
    );
    afirmar(! actualizador('admisible', 'config.php'), 'admisible() aceptó config.php');
});

prueba('los instaladores de un solo uso no reviven', function (): void {
    foreach (['instalar.php', 'migrar.php'] as $archivo) {
        afirmar(! actualizador('admisible', $archivo), "admisible() aceptó {$archivo}");
    }
});

prueba('no se puede escribir fuera del destino', function (): void {
    foreach (['../config.php', 'src/../../fuera.php', '../../etc/passwd'] as $ruta) {
        afirmar(! actualizador('admisible', $ruta), "admisible() aceptó «{$ruta}»");
    }
});

prueba('solo se escriben extensiones de la lista blanca', function (): void {
    foreach (['src/Core/Db.php', 'index.php', '_app/x.js', 'estilo.css', 'database/rufe.sql'] as $ok) {
        afirmar(actualizador('admisible', $ok), "admisible() rechazó «{$ok}»");
    }
    foreach (['malo.sh', 'x.exe', 'y.bin', 'z.phar'] as $no) {
        afirmar(! actualizador('admisible', $no), "admisible() aceptó «{$no}»");
    }
});

prueba('el .htaccess sí se escribe pese a no tener extensión', function (): void {
    afirmar(actualizador('admisible', '.htaccess'), 'admisible() rechazó .htaccess');
});

prueba('el mapa aplana el backend como lo espera el servidor', function (): void {
    $mapa = constanteActualizador('MAPA');

    // En el repositorio el punto de entrada vive en public/; en el servidor va
    // en la raíz de api/, porque el hosting no deja poner código sobre el
    // document root.
    afirmarIgual('index.php', $mapa['BACKEND']['Gestion_riesgo/backend/public/index.php']);
    afirmarIgual('src', $mapa['BACKEND']['Gestion_riesgo/backend/src']);
    afirmarIgual('database', $mapa['BACKEND']['Gestion_riesgo/backend/database']);
    afirmarIgual('', $mapa['FRONTEND']['Gestion_riesgo/frontend/build']);
});

prueba('la plantilla de configuración NO viene con la autoactualización encendida', function () use ($raiz): void {
    // Es la comprobación que impide el peor descuido posible: que alguien copie
    // config.example.php a config.php y el sitio quede pudiendo sobrescribirse
    // a sí mismo sin que nadie lo haya decidido.
    $config = require $raiz.'/config.example.php';

    afirmar(isset($config['actualizaciones']), 'falta la sección "actualizaciones" en la plantilla');
    afirmarIgual(false, $config['actualizaciones']['habilitado'], 'la plantilla viene habilitada');
    afirmarIgual('', $config['actualizaciones']['raiz_api'], 'la plantilla trae una ruta puesta');
    afirmarIgual('', $config['actualizaciones']['respaldos'], 'la plantilla trae una carpeta de respaldos puesta');
});

// ── Buscador de la bandeja ───────────────────────────────────────────────────

/**
 * Cuántas veces aparece cada marcador `:nombre` en el SQL.
 *
 * @return array<string,int>
 */
function marcadores(string $sql): array
{
    preg_match_all('/:([a-z][a-z0-9_]*)/i', $sql, $m);

    return array_count_values($m[1]);
}

prueba('ningún marcador se repite en la consulta', function (): void {
    // ESTE es el fallo que llegó a producción. Con preparadas nativas, un
    // marcador repetido hace que MySQL responda «Invalid parameter number» al
    // prepararla, así que el buscador daba error 500 con cualquier texto y no
    // funcionó nunca. No se veía sin base de datos; aquí sí.
    foreach (['Juan Pérez', '1113456789', 'RUFE-2026-ABCD1234', 'calle 10 juan 123'] as $texto) {
        [$sql] = Busqueda::condicion($texto);
        foreach (marcadores($sql) as $nombre => $veces) {
            afirmarIgual(1, $veces, "el marcador «{$nombre}» aparece {$veces} veces con «{$texto}»");
        }
    }
});

prueba('hay exactamente un parámetro por marcador', function (): void {
    foreach (['Juan Pérez García Lopez Ruiz', '1113456789', 'la playa'] as $texto) {
        [$sql, $params] = Busqueda::condicion($texto);
        $enSql = array_keys(marcadores($sql));
        $enParams = array_keys($params);
        sort($enSql);
        sort($enParams);
        afirmarIgual($enSql, $enParams, "descuadre con «{$texto}»");
    }
});

prueba('sin texto no hay condición', function (): void {
    afirmarIgual(['', []], Busqueda::condicion(''));
    afirmarIgual(['', []], Busqueda::condicion('   '));
});

prueba('busca por cédula exacta, no por trozos', function (): void {
    // Un documento parcial devolvería hogares ajenos y convertiría el buscador
    // en una forma de pasear por el censo.
    [$sql, $params] = Busqueda::condicion('1113456789');
    afirmar(str_contains($sql, 'pd.numero_documento = :doc'), 'debe comparar el documento exacto');
    afirmarIgual('1113456789', $params['doc']);
});

prueba('acepta la cédula escrita con puntos o espacios', function (): void {
    afirmarIgual('1113456789', Busqueda::condicion('1.113.456.789')[1]['doc']);
    afirmarIgual('1113456789', Busqueda::condicion('1 113 456 789')[1]['doc']);
});

prueba('un número corto no se toma por cédula', function (): void {
    // «123» es más probablemente parte de una dirección.
    afirmar(! isset(Busqueda::condicion('123')[1]['doc']), 'no debía buscar por documento');
});

prueba('el nombre se busca palabra por palabra, sin importar el orden', function (): void {
    [$sql, $params] = Busqueda::condicion('garcía juan');
    afirmar(str_contains($sql, "CONCAT(pn.nombres, ' ', pn.apellidos)"), 'debe concatenar nombre y apellido');
    afirmarIgual('%garcía%', $params['n0']);
    afirmarIgual('%juan%', $params['n1']);
    afirmarIgual(2, substr_count($sql, ':n'), 'una condición por palabra');
});

prueba('no se buscan más palabras de la cuenta', function (): void {
    [, $params] = Busqueda::condicion('uno dos tres cuatro cinco seis');
    $delNombre = array_filter(array_keys($params), static fn ($k) => str_starts_with($k, 'n'));
    afirmarIgual(Busqueda::MAX_PALABRAS, count($delNombre));
});

prueba('las letras sueltas no cuentan como nombre', function (): void {
    // Una inicial suelta haría coincidir a media base.
    afirmar(! isset(Busqueda::condicion('j')[1]['n0']), 'una sola letra no debe buscar por nombre');
});

prueba('los comodines del LIKE se neutralizan', function (): void {
    // Sin escapar, buscar «%» devolvería la base entera.
    afirmar(str_contains(Busqueda::condicion('%')[1]['q0'], '\%'), 'el % debe ir escapado');
    afirmar(str_contains(Busqueda::condicion('_')[1]['q0'], '\_'), 'el _ debe ir escapado');
});

prueba('el radicado se sigue encontrando por un trozo', function (): void {
    afirmarIgual('%XRT9BNCP%', Busqueda::condicion('XRT9BNCP')[1]['q0']);
});

prueba('distingue buscar una persona de hojear la bandeja', function (): void {
    // Solo lo primero queda anotado en la auditoría.
    afirmar(Busqueda::buscaPersona('1113456789'), 'una cédula busca persona');
    afirmar(Busqueda::buscaPersona('Juan Pérez'), 'un nombre busca persona');
    afirmar(! Busqueda::buscaPersona(''), 'sin texto no busca persona');
    afirmar(! Busqueda::buscaPersona('123'), 'un número corto no busca persona');
});

// ── Geocodificación ──────────────────────────────────────────────────────────

prueba('la misma dirección escrita de varias formas comparte clave', function (): void {
    // Cada clave distinta es una consulta más al servicio, con su segundo de
    // espera y su costo. Reconocer que es la misma casa es la mitad del ahorro.
    $formas = ['Cra 5 # 10-20', 'CARRERA 5 No 10 20', 'carrera 5 #10 20', '  Cra. 5 #10 - 20  '];
    $claves = array_map(static fn ($d) => Geocodificador::clave($d), $formas);
    afirmarIgual(1, count(array_unique($claves)), 'las cuatro formas debían dar una sola clave');
});

prueba('se normalizan las abreviaturas de vía', function (): void {
    afirmarIgual('carrera 11 # 8 26', Geocodificador::normalizar('Cra 11 # 8-26'));
    afirmarIgual('calle 12 # 3 45', Geocodificador::normalizar('Cll 12 No. 3-45'));
    afirmarIgual('avenida 4 norte', Geocodificador::normalizar('Av 4 Norte'));
    afirmarIgual('transversal 9 # 2 10', Geocodificador::normalizar('Tv 9 #2-10'));
});

prueba('a toda dirección se le añade el municipio', function (): void {
    // Sin esto, «Carrera 11 # 8 26» existe en media Colombia.
    afirmar(
        str_ends_with(Geocodificador::consulta('Cra 11 # 8-26'), 'Jamundí, Valle del Cauca, Colombia'),
        'la consulta debe terminar en el municipio'
    );
});

prueba('una calle sin número sí se intenta', function (): void {
    // «Juan de Ampudia» es una vía real y resuelve a precisión de calle, que
    // para un mapa de calor ya sirve.
    afirmar(Geocodificador::utilizable('Juan de ampudia'), 'debía aceptarse');
});

prueba('lo que no es una dirección no gasta consulta', function (): void {
    foreach (['NO INFORMA', 'na', 'sin direccion', 'ninguna', 'casa', 'x'] as $texto) {
        afirmar(! Geocodificador::utilizable($texto), "«{$texto}» no debía intentarse");
    }
});

prueba('un punto fuera de Jamundí se descarta', function (): void {
    // Bogotá: el servicio se equivocó de municipio.
    afirmarIgual('FALLIDA', Geocodificador::clasificar(['lat' => 4.7110, 'lon' => -74.0721, 'tipo' => 'house']));
    // Y el mar, por si llega basura.
    afirmarIgual('FALLIDA', Geocodificador::clasificar(['lat' => 0.0, 'lon' => 0.0, 'tipo' => 'house']));
});

prueba('el centroide del municipio no se da por bueno', function (): void {
    // ESTA es la trampa que arruinaría el mapa: una dirección que solo resuelve
    // a «Jamundí» devuelve coordenadas válidas e inútiles. Pintarlas amontonaría
    // medio censo sobre el parque principal y la mancha de calor mentiría.
    afirmarIgual('MUNICIPIO', Geocodificador::clasificar([
        'lat' => 3.2611, 'lon' => -76.5423, 'tipo' => 'administrative',
    ]));
    afirmar(! Geocodificador::pintable('MUNICIPIO'), 'el centroide no debe pintarse');
    afirmar(! Geocodificador::pintable('FALLIDA'), 'lo fallido no debe pintarse');
});

prueba('se distingue una casa de una calle y de un barrio', function (): void {
    $en = static fn (string $tipo) => Geocodificador::clasificar([
        'lat' => 3.2700, 'lon' => -76.5500, 'tipo' => $tipo,
    ]);
    afirmarIgual('EXACTA', $en('house'));
    afirmarIgual('EXACTA', $en('rooftop'));
    afirmarIgual('CALLE', $en('residential'));
    afirmarIgual('BARRIO', $en('suburb'));
});

prueba('las tres precisiones útiles sí se pintan', function (): void {
    foreach (['EXACTA', 'CALLE', 'BARRIO'] as $p) {
        afirmar(Geocodificador::pintable($p), "«{$p}» debía poder pintarse");
    }
});

prueba('sin clave configurada no se usa Google', function (): void {
    // El sistema tiene que funcionar solo con OpenStreetMap.
    afirmar(! Geocodificador::hayGoogle(), 'Google debe estar apagado por omisión');
});

prueba('se respeta el segundo entre peticiones que exige OpenStreetMap', function (): void {
    afirmar(Geocodificador::PAUSA_SEGUNDOS >= 1, 'su política no admite más de una por segundo');
});

// ── Resumen ──────────────────────────────────────────────────────────────────

echo "\n".str_repeat('─', 60)."\n";

if ($fallos === []) {
    echo "\033[32m{$pasadas} pruebas correctas.\033[0m\n";
    exit(0);
}

echo "\033[31m".count($fallos).' fallo(s), '.$pasadas." correctas.\033[0m\n\n";
foreach ($fallos as $f) {
    echo "  • {$f}\n";
}
echo "\n";
exit(1);
