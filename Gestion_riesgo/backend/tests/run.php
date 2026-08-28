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

use App\Core\Auth;
use App\Core\Migrador;
use App\Sistema\Actualizador;
use App\Rufe\Busqueda;
use App\Rufe\Catalogos;
use App\Rufe\Geocodificador;
use App\Rufe\Radicado;
use App\Rufe\Barrios;
use App\Rufe\Rud;
use App\Rufe\Tablero;
use App\Rufe\Validador;
use App\Inspeccion\BancoMateriales;
use App\Inspeccion\Catalogos as CatalogosInspeccion;
use App\Inspeccion\Validador as ValidadorInspeccion;
use App\Inspeccion\Numero;
use App\Inspeccion\NivelDano;

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

prueba('una etnia fuera del catálogo se rechaza', function (): void {
    // El límite se lee del catálogo y no se escribe a mano: al entrar «No
    // informa» con el censo en papel, un 7 pasó a ser válido y esta prueba
    // habría fallado por estar mirando un número, no una regla.
    afirmarError(
        base(['personas' => [persona(['pertenencia_etnica' => count(Catalogos::ETNIAS) + 1])]]),
        'personas.0.pertenencia_etnica'
    );
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

prueba('la dirección se pregunta con su barrio antes que sola', function (): void {
    // Es la mejora que de verdad ubica este censo: «Casa 9, Jamundí» no existe
    // para el servicio, «Casa 9, Colinas de Miravalle, Jamundí» sí. Y si ni con
    // eso, se pregunta por el barrio solo — un punto en el centro del barrio
    // correcto dice a qué sector mandar la brigada; el centroide del municipio
    // no dice nada.
    $intentos = Geocodificador::intentosPara('Casa 9', 'Colinas de Miravalle');

    afirmarIgual(3, count($intentos));
    afirmarIgual('Casa 9, Colinas de Miravalle', $intentos[0]['texto']);
    afirmarIgual('Casa 9', $intentos[1]['texto']);
    afirmarIgual('Colinas de Miravalle', $intentos[2]['texto']);
});

prueba('preguntar por el barrio no puede devolver precisión de casa', function (): void {
    // El servicio puede llamar «exacto» al resultado de buscar un barrio: exacto
    // DEL BARRIO, no de la casa. Sin ese tope el mapa dibujaría un predio con
    // una precisión que no tiene, y de ahí salen las decisiones de a dónde va
    // una brigada.
    $intentos = Geocodificador::intentosPara('Casa 9', 'Robles');

    afirmarIgual(null, $intentos[0]['techo']);
    afirmarIgual(null, $intentos[1]['techo']);
    afirmarIgual('BARRIO', $intentos[2]['techo']);
});

prueba('sin barrio se pregunta una sola vez', function (): void {
    $intentos = Geocodificador::intentosPara('Carrera 11 # 8-26');

    afirmarIgual(1, count($intentos));
    afirmarIgual('Carrera 11 # 8-26', $intentos[0]['texto']);
});

prueba('una dirección que ES su barrio no se pregunta dos veces igual', function (): void {
    // Pasa en la mitad del censo rural: la «dirección» que trae la ficha es el
    // nombre de la vereda. Sin esto se gastarían tres consultas —y tres
    // segundos— en preguntar tres veces lo mismo.
    $intentos = Geocodificador::intentosPara('Bocas del Palo', 'BOCAS DEL PALO');

    afirmarIgual(1, count($intentos));
});

grupo('Tablero sobre datos oficiales');

prueba('el mismo barrio escrito distinto se agrupa igual', function (): void {
    // El censo lo escribe a mano: 249 nombres para lo que la Alcaldía maneja
    // como 117 barrios. Sin agrupar, la tabla por barrio parte barrios reales
    // en varios — y esa tabla es la que decide a dónde sale una brigada.
    afirmar(Barrios::esMismo('Bocas Del Palo', 'Bocas del Palo'), 'solo cambia una mayúscula');
    afirmar(Barrios::esMismo('TERRANOVA', 'Terranova'), 'solo cambian las mayúsculas');
    afirmar(Barrios::esMismo('Barrio 12 De Octubre', '12 De Octubre'), '«barrio» no distingue nada');
    afirmar(Barrios::esMismo('Vereda La Estrella', 'La Estrella'), '«vereda» tampoco');
    afirmar(Barrios::esMismo('Quinamayó', 'Quinamayo'), 'la tilde no distingue');
    afirmar(Barrios::esMismo('Villa Paz', 'Villapaz'), 'el alias del corregimiento oficial');
});

prueba('dos barrios distintos NO se fusionan', function (): void {
    // El riesgo del otro lado: agrupar de más junta a familias de sitios
    // distintos bajo un total que no corresponde a ninguno.
    afirmar(! Barrios::esMismo('San Antonio', 'San Vicente'), 'son dos corregimientos');
    afirmar(! Barrios::esMismo('Terranova', 'Terranova Sector 1'), 'el sector es otro sitio');
    afirmar(! Barrios::esMismo('', 'Robles'), 'un nombre vacío no es ningún barrio');
});

prueba('el nombre del grupo es el que más gente escribió', function (): void {
    // Y con empate manda el alfabético, para que dos recargas del tablero no
    // cambien el nombre de un barrio: eso hace que parezca roto.
    $g = Barrios::agrupar(['bocas del palo' => 3, 'Bocas Del Palo' => 9]);

    afirmarIgual(1, count($g));
    afirmarIgual('Bocas Del Palo', reset($g)['nombre']);
});

prueba('la edad se calcula contra la fecha del evento, no contra hoy', function (): void {
    // Si se usara la fecha actual, un niño que cumple doce años cambiaría de
    // grupo él solo y la cifra que la Alcaldía reportó el mes pasado dejaría de
    // reproducirse.
    afirmarIgual('Ninos', Tablero::grupoDeEdad('2020-01-01'));
    afirmarIgual('Jovenes', Tablero::grupoDeEdad('2005-01-01'));
    afirmarIgual('Adultos', Tablero::grupoDeEdad('1980-01-01'));
    afirmarIgual('AdultosMayores', Tablero::grupoDeEdad('1950-01-01'));
});

prueba('sin fecha de nacimiento no se inventa un grupo de edad', function (): void {
    // Dos tercios del censo en papel no la traen. Repartirlos «a ojo» inflaría
    // justo los indicadores que se usan para priorizar ayuda a menores y
    // adultos mayores.
    afirmarIgual(null, Tablero::grupoDeEdad(null));
    afirmarIgual(null, Tablero::grupoDeEdad(''));
    afirmarIgual(null, Tablero::grupoDeEdad('2030-01-01'), 'una fecha futura no es una edad');
});

grupo('Censo RUD (carga desde Excel)');

prueba('el texto del inmueble se desarma en sus seis partes', function (): void {
    // El RUD no trae columnas: mete todo el inmueble en un solo texto corrido.
    // Si este patrón deja de casar, los hogares entran sin dirección y sin
    // estado, que es la clase de fallo que solo se ve cuando alguien busca una
    // casa y no la encuentra.
    $b = Rud::desarmarBien(
        'Bien: Vivienda.  Tenencia: Propietario. Estado: No Habitable. '
        .'Vereda/sector: Bellavista Finca La Piscina. Corregimiento: Ampudia Direccion: Casa 9'
    );

    afirmarIgual('Vivienda', $b['bien']);
    afirmarIgual('Propietario', $b['tenencia']);
    afirmarIgual('No Habitable', $b['estado']);
    afirmarIgual('Bellavista Finca La Piscina', $b['vereda']);
    afirmarIgual('Ampudia', $b['corregimiento']);
    afirmarIgual('Casa 9', $b['direccion']);
});

prueba('un texto de inmueble que no casa no se importa a medias', function (): void {
    $b = Rud::desarmarBien('cualquier cosa');

    afirmarIgual('', $b['bien']);
    afirmarIgual('', $b['direccion']);
});

prueba('las etiquetas del RUD encuentran su código', function (): void {
    // Se traduce por ETIQUETA y no por código: el RUD trae la numeración
    // oficial de la UNGRD y el sistema usa la suya. Copiar el número sería
    // silenciosamente incorrecto — un «41» del RUD es «Hijo(a)» y aquí no
    // existe.
    afirmarIgual(1, Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, 'Jefe(a) o cabeza del hogar'));
    afirmarIgual(3, Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, 'Hijo(a), hijastro(a)'));
    // Mayúsculas distintas: «Pareja, Esposo(a)» en el RUD.
    afirmarIgual(2, Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, 'Pareja, Esposo(a)'));
    // Espacio de más antes del paréntesis.
    afirmarIgual(5, Rud::codigoPorEtiqueta(Catalogos::PARENTESCOS, 'Sobrino (a)'));
    // Con sigla delante.
    afirmarIgual(3, Rud::codigoPorEtiqueta(Catalogos::TIPOS_DOCUMENTO, 'CC - Cédula de ciudadanía'));
    afirmarIgual(11, Rud::codigoPorEtiqueta(Catalogos::TIPOS_DOCUMENTO, 'PPT'));
    // El RUD escribe la etnia sin la «(a)» final de afrodescendiente.
    afirmarIgual(
        5,
        Rud::codigoPorEtiqueta(Catalogos::ETNIAS, 'Negro(a), Mulato(a), Afrodescendiente, Afrocolombiano(a)')
    );
    afirmarIgual('VIVIENDA', Rud::codigoPorEtiqueta(Catalogos::TIPOS_BIEN, 'Vivienda'));
    afirmarIgual('NO_HABITABLE', Rud::codigoPorEtiqueta(Catalogos::ESTADOS_BIEN, 'No Habitable'));
    afirmarIgual(null, Rud::codigoPorEtiqueta(Catalogos::GENEROS, ''));
});

prueba('la zona sale del corregimiento, que es la única marca de ruralidad', function (): void {
    // El RUD no trae la columna urbano/rural. Lo que trae es un campo que mezcla
    // los corregimientos oficiales con los barrios de la cabecera.
    afirmarIgual('RURAL', Rud::zonaPorCorregimiento('San Antonio'));
    afirmarIgual('RURAL', Rud::zonaPorCorregimiento('paso de la bolsa'));
    afirmarIgual('URBANO', Rud::zonaPorCorregimiento('Jamundí'));
    afirmarIgual('URBANO', Rud::zonaPorCorregimiento('Terranova'));
    afirmarIgual('URBANO', Rud::zonaPorCorregimiento(''));
});

prueba('un barrio no se guarda como corregimiento', function (): void {
    // La columna solo admite uno de los diecisiete; meter barrios ahí rompería
    // los filtros del tablero. El nombre no se pierde: va al campo de barrio.
    afirmarIgual('San Antonio', Rud::corregimientoOficial('SAN ANTONIO'));
    afirmarIgual(null, Rud::corregimientoOficial('Terranova'));
});

prueba('la ubicación baja por lo que el censo sí levantó', function (): void {
    // Un tercio de las fichas trae la dirección vacía y la vereda llena, que es
    // como se ubica una casa en zona rural. Exigir nomenclatura urbana dejaría
    // fuera del censo a cientos de familias bien ubicadas.
    $sinDireccion = ['direccion' => '', 'vereda' => 'Bellavista', 'corregimiento' => 'Ampudia'];
    afirmarIgual('Bellavista', Rud::direccionDe($sinDireccion));

    $soloCorregimiento = ['direccion' => '', 'vereda' => '', 'corregimiento' => 'Robles'];
    afirmarIgual('Robles', Rud::direccionDe($soloCorregimiento));
    afirmarIgual('Robles', Rud::veredaDe($soloCorregimiento));

    afirmarIgual('', Rud::direccionDe(['direccion' => '', 'vereda' => '', 'corregimiento' => '']));
});

prueba('nunca se inventa un teléfono', function (): void {
    afirmarIgual('3157576420', Rud::telefonoDe([['telefono' => ''], ['telefono' => '3157576420']]));
    afirmarIgual('', Rud::telefonoDe([['telefono' => ''], ['telefono' => '123']]));
    afirmarIgual('', Rud::telefonoDe([]));
});

prueba('el jefe solo se deduce cuando el hogar es de una persona', function (): void {
    // Con una sola persona, es cabeza de su hogar por aritmética. Con dos o
    // más habría que decidir quién encabeza una familia, y eso no se hace desde
    // un script.
    afirmar(Rud::jefeDeducible([['parentesco' => 'No informa']]), 'una persona sola es su propio jefe');
    afirmar(
        ! Rud::jefeDeducible([['parentesco' => 'No informa'], ['parentesco' => 'Hijo(a), hijastro(a)']]),
        'con dos personas no se asciende a nadie'
    );
    afirmar(
        ! Rud::jefeDeducible([['parentesco' => 'Jefe(a) o cabeza del hogar']]),
        'si ya hay jefe no hay nada que deducir'
    );
});

prueba('un documento sin número no se guarda como cédula', function (): void {
    // Guardar «CC» con la casilla vacía afirmaría que esa persona tiene cédula
    // y que el sistema perdió el número. Lo que pasó es que el papel se llenó
    // sin ese dato, y el formato tiene un código para eso.
    afirmarIgual(8, Rud::tipoDocumentoCoherente(3, ''));
    afirmarIgual(3, Rud::tipoDocumentoCoherente(3, '19124025'));
    // Los que por definición no llevan número se respetan tal cual.
    afirmarIgual(6, Rud::tipoDocumentoCoherente(6, ''));
    afirmarIgual(8, Rud::tipoDocumentoCoherente(null, '123'));
});

prueba('la fecha de nacimiento del RUD se recorta a la fecha', function (): void {
    afirmarIgual('1951-02-02', Rud::fechaNacimiento('1951-02-02 00:00:00'));
    afirmarIgual(null, Rud::fechaNacimiento(''));
    afirmarIgual(null, Rud::fechaNacimiento('sin dato'));
});

grupo('Catálogos');

prueba('los códigos del formato están completos', function (): void {
    afirmarIgual(11, count(Catalogos::TIPOS_DOCUMENTO));
    afirmarIgual(15, count(Catalogos::PARENTESCOS));
    afirmarIgual(4, count(Catalogos::GENEROS));
    afirmarIgual(7, count(Catalogos::ETNIAS));
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
    foreach ([1, 2, 3, 4, 5, 9, 10, 11] as $codigo) {
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
    afirmarIgual(
        ['DOCUMENTO', 'DANO', 'INSPECCION', 'PRE_CEDULA', 'PRE_CEDULA_REVERSO', 'PRE_DANO'],
        array_keys(Catalogos::TIPOS_EVIDENCIA)
    );

    // Cinco fotos de 900 KB caben de sobra en el cupo total de la carga.
    afirmar(
        Catalogos::MAX_EVIDENCIAS * Catalogos::OBJETIVO_BYTES_FOTO < Catalogos::MAX_BYTES_CARGA,
        'el cupo total de la carga no alcanza para el máximo de fotos'
    );
});

prueba('el registro fotográfico de la inspección tiene las diez casillas del formato', function (): void {
    // El numeral 11 imprime diez recuadros. Si el cupo del servidor fuera menor,
    // el profesional llenaría el papel y el sistema le rechazaría fotos sin que
    // nada en pantalla explicara por qué.
    afirmarIgual(
        CatalogosInspeccion::MAX_FOTOS,
        Catalogos::TIPOS_EVIDENCIA['INSPECCION']['maximo']
    );
    afirmarIgual(10, CatalogosInspeccion::MAX_FOTOS);
});

prueba('las diez fotos de una inspección caben en el cupo de la carga', function (): void {
    // Diez es el doble de lo que sube un RUFE. Si no cupieran, el fallo
    // aparecería en la última foto de una visita ya terminada.
    afirmar(
        CatalogosInspeccion::MAX_FOTOS * Catalogos::OBJETIVO_BYTES_FOTO < Catalogos::MAX_BYTES_CARGA,
        'el cupo de la carga no alcanza para las diez fotos del numeral 11'
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

prueba('todos los .sql del Migrador se trocean y son idempotentes', function () use ($raiz): void {
    // Se recorre la lista real del Migrador y no una escrita a mano: un archivo
    // que se añada allí y no aquí se aplicaría en producción sin que nada lo
    // hubiera mirado. El hosting no tiene consola — si una migración falla a
    // medias, se arregla por FTP.
    foreach (Migrador::ARCHIVOS as $archivo) {
        $ruta = $raiz.'/database/'.$archivo;
        afirmar(is_file($ruta), "falta database/{$archivo}");

        $sentencias = Migrador::sentencias((string) file_get_contents($ruta));
        afirmar($sentencias !== [], "{$archivo} no produjo ninguna sentencia");

        foreach ($sentencias as $s) {
            if (str_starts_with($s, 'CREATE TABLE')) {
                afirmar(str_contains($s, 'IF NOT EXISTS'), "{$archivo}: CREATE TABLE sin IF NOT EXISTS");
            }
            afirmar(! str_contains($s, '--'), "{$archivo}: quedó un comentario dentro de una sentencia");
        }
    }
});

/**
 * ¿Este ALTER solo ENSANCHA un ENUM?
 *
 * Cierto únicamente si es un `MODIFY COLUMN … ENUM(...)` sobre una de las
 * columnas declaradas abajo, no toca nada más, y la lista nueva contiene todos
 * los valores que esa columna ya admitía.
 *
 * Los valores previos se escriben AQUÍ y no se leen del archivo de migración:
 * sacarlos del mismo sitio que se quiere comprobar no comprobaría nada.
 */
function ensanchaUnEnum(string $sentencia): bool
{
    // La excepción está acotada a columnas concretas. Abrirla a cualquier
    // columna sería regalar el permiso de modificar lo que sea.
    foreach (ENUMS_QUE_PUEDEN_CRECER as $columna => $anteriores) {
        if (preg_match('/MODIFY\s+COLUMN\s+'.$columna.'\s+ENUM\s*\(([^)]*)\)/i', $sentencia, $m) !== 1) {
            continue;
        }

        // Nada más en el mismo ALTER: ni DROP, ni CHANGE, ni otro MODIFY.
        if (preg_match_all('/\b(DROP|CHANGE|MODIFY)\s+COLUMN\b/i', $sentencia) !== 1) {
            return false;
        }

        preg_match_all("/'{2}([A-Z_]+)'{2}/", $m[1], $valores);
        $nuevos = $valores[1];

        foreach ($anteriores as $previo) {
            if (! in_array($previo, $nuevos, true)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * Qué ENUM puede ensancharse, y qué valores admitía antes.
 *
 * @var array<string,list<string>>
 */
const ENUMS_QUE_PUEDEN_CRECER = [
    'rol'  => ['ADMINISTRADOR', 'GESTOR', 'VISUALIZACION'],
    'tipo' => ['DOCUMENTO', 'DANO'],
    // Cómo terminó una gestión del call center. Creció para dar cabida al
    // envío del enlace por WhatsApp, que no es una llamada pero sí una
    // gestión. Los seis de aquí son los que existían cuando solo se llamaba:
    // la comprobación de arriba impide que una migración futura pierda alguno.
    'resultado' => [
        'CONTACTADO', 'NO_CONTESTA', 'NUMERO_ERRADO',
        'VOLVER_A_LLAMAR', 'NO_INTERESA', 'YA_DILIGENCIO',
    ],
];

prueba('la excepción del ENUM no vale para recortarlo', function (): void {
    // Sin esto, «se permite un MODIFY de un ENUM» sería un agujero por el que
    // cabría cualquier cosa. Se comprueba invirtiendo el caso.
    $ensancha = "ALTER TABLE usuarios MODIFY COLUMN rol ENUM(''ADMINISTRADOR'',''GESTOR'',''VISUALIZACION'',''INSPECTOR'')";
    $recorta  = "ALTER TABLE usuarios MODIFY COLUMN rol ENUM(''ADMINISTRADOR'',''INSPECTOR'')";
    $otraCosa = "ALTER TABLE usuarios MODIFY COLUMN email VARCHAR(200) NOT NULL";
    $tipoOk   = "ALTER TABLE rufe_evidencias MODIFY COLUMN tipo ENUM(''DOCUMENTO'',''DANO'',''INSPECCION'')";
    $tipoMal  = "ALTER TABLE rufe_evidencias MODIFY COLUMN tipo ENUM(''DANO'',''INSPECCION'')";
    $conDrop  = "ALTER TABLE usuarios MODIFY COLUMN rol ENUM(''ADMINISTRADOR'',''GESTOR'',''VISUALIZACION'',''INSPECTOR''), DROP COLUMN activo";

    afirmar(ensanchaUnEnum($ensancha), 'añadir un rol debería permitirse');
    afirmar(! ensanchaUnEnum($recorta), 'quitar un rol NO puede permitirse');
    afirmar(! ensanchaUnEnum($otraCosa), 'la excepción es solo para el ENUM de rol');
    afirmar(! ensanchaUnEnum($conDrop), 'un DROP colado en el mismo ALTER debe bloquearlo');
    afirmar(ensanchaUnEnum($tipoOk), 'añadir un tipo de evidencia debería permitirse');
    afirmar(! ensanchaUnEnum($tipoMal), 'quitar DOCUMENTO NO puede permitirse');
});

prueba('ninguna migración puede borrar datos', function () use ($raiz): void {
    // Esto no es celo: estas migraciones se aplican sobre una base con fichas de
    // hogares damnificados que NO existen en ningún otro sitio. Una sentencia
    // destructiva que se colara aquí no se notaría hasta que fuera irreversible.
    //
    // Se comprueban los verbos, no el texto: «ON DELETE CASCADE» dentro de una
    // clave foránea define comportamiento referencial y no borra nada, mientras
    // que un DROP o un TRUNCATE sueltos sí.
    $permitidos = ['CREATE', 'SET', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'INSERT', 'DO'];

    foreach (Migrador::ARCHIVOS as $archivo) {
        foreach (Migrador::sentencias((string) file_get_contents($raiz.'/database/'.$archivo)) as $s) {
            $verbo = strtoupper(strtok(trim($s), " (\n"));

            afirmar(
                in_array($verbo, $permitidos, true),
                "{$archivo}: sentencia «{$verbo}» no permitida en una migración"
            );

            // Un ALTER escondido dentro de un SET @sql := IF(...) solo puede
            // AÑADIR: cambiar o quitar una columna con datos dentro los pierde.
            //
            // Con UNA excepción, la de ensanchar un ENUM. MySQL no sabe añadirle
            // un valor a un ENUM que no sea redefinirlo entero, así que sin esto
            // no se podría crear nunca un rol nuevo. La excepción es estrecha a
            // propósito: se comprueba que la lista nueva CONTENGA todos los
            // valores anteriores. Un MODIFY que quite un valor —o que toque
            // cualquier otra cosa— sigue prohibido.
            if (preg_match('/\bALTER\s+TABLE\b/i', $s) === 1) {
                $ensancha = ensanchaUnEnum($s);

                if (! $ensancha) {
                    afirmar(
                        preg_match('/\b(DROP|MODIFY|CHANGE)\s+COLUMN\b/i', $s) !== 1,
                        "{$archivo}: un ALTER TABLE quita o cambia una columna"
                    );
                    afirmar(
                        preg_match('/\bADD\s+(COLUMN|KEY|CONSTRAINT|UNIQUE)\b/i', $s) === 1,
                        "{$archivo}: un ALTER TABLE que no añade nada"
                    );
                }
            }
        }
    }
});

prueba('el archivo de reversión NUNCA está en la lista del Migrador', function (): void {
    // rufe_revertir.sql borra las siete tablas del censo. Existe para desarrollo
    // y para deshacer una instalación fallida; que se colara en la lista que se
    // ejecuta en cada despliegue vaciaría la base en producción.
    afirmar(
        ! in_array('rufe_revertir.sql', Migrador::ARCHIVOS, true),
        'rufe_revertir.sql no puede aplicarse automáticamente'
    );
});

prueba('ninguna migración se queda fuera de la lista', function () use ($raiz): void {
    // El 28 de agosto de 2026 el call center se cayó entero en producción con
    // «Unknown column 'g2.canal'»: se desplegó el código que consultaba esa
    // columna y la migración que la crea nunca se corrió contra la base. La
    // pantalla que usan las tres operadoras quedó inservible.
    //
    // Aquel archivo SÍ estaba en la lista —lo que falló fue correrla, y eso lo
    // vigila el despliegue, no una prueba—. Pero el hermano de ese fallo sí se
    // caza aquí: escribir una migración y olvidar registrarla produce
    // exactamente el mismo síntoma, y hasta hoy nada lo habría dicho.
    //
    // La única excepción es el archivo de reversión, y está declarada arriba
    // con su motivo: borra las siete tablas del censo.
    $enDisco = array_map('basename', glob($raiz.'/database/*.sql') ?: []);
    $registrados = Migrador::ARCHIVOS;
    $exentos = ['rufe_revertir.sql'];

    foreach ($enDisco as $archivo) {
        afirmar(
            in_array($archivo, $registrados, true) || in_array($archivo, $exentos, true),
            "database/{$archivo} existe pero no está en Migrador::ARCHIVOS: nunca se va a aplicar"
        );
    }
});

prueba('todo lo registrado existe de verdad', function () use ($raiz): void {
    // Al revés: un nombre mal escrito en la lista revienta el despliegue entero
    // —`aplicar()` lanza al no encontrar el archivo—, y se lleva por delante
    // las migraciones que iban después.
    foreach (Migrador::ARCHIVOS as $archivo) {
        afirmar(
            is_file($raiz.'/database/'.$archivo),
            "Migrador::ARCHIVOS nombra database/{$archivo}, que no existe"
        );
    }
});

prueba('la inspección se aplica después del RUFE, del que depende', function (): void {
    // Declara una foránea contra rufe_reportes y añade columnas a
    // rufe_evidencias: al revés, la migración reventaría en el primer despliegue.
    $orden = array_flip(Migrador::ARCHIVOS);

    afirmar(
        $orden['inspeccion_01_viviendas.sql'] > $orden['rufe.sql'],
        'inspeccion_01_viviendas.sql tiene que ir después de rufe.sql'
    );
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

prueba('la condición de persona no choca con la del hogar', function (): void {
    // Las dos viajan en la MISMA petición. Si compartieran un nombre de
    // marcador, MySQL respondería «Invalid parameter number» al preparar — que
    // es exactamente cómo estuvo roto este buscador antes.
    foreach (['Juan Pérez', '1113456789', 'calle 10 juan 123'] as $texto) {
        $delHogar = array_keys(Busqueda::condicion($texto)[1]);
        $deLaPersona = array_keys(Busqueda::condicionPersona($texto)[1]);

        afirmarIgual([], array_intersect($delHogar, $deLaPersona), "chocan con «{$texto}»");
    }
});

prueba('la condición de persona tiene un parámetro por marcador', function (): void {
    foreach (['Juan Pérez García Lopez Ruiz', '1.113.456.789', 'la playa'] as $texto) {
        [$sql, $params] = Busqueda::condicionPersona($texto);
        $enSql = array_keys(marcadores($sql));
        $enParams = array_keys($params);
        sort($enSql);
        sort($enParams);
        afirmarIgual($enSql, $enParams, "descuadre con «{$texto}»");
    }
});

prueba('la persona se busca por la misma cédula que el hogar', function (): void {
    // Si discreparan, la ficha aparecería en la lista y debajo no saldría nadie:
    // quien atiende en ventanilla no podría confirmar que encontró a su persona.
    afirmarIgual('1113456789', Busqueda::condicionPersona('1.113.456.789')[1]['pdoc']);
    afirmar(Busqueda::condicionPersona('')[0] === '', 'sin texto no hay condición de persona');
    afirmar(Busqueda::condicionPersona('123')[0] === '', 'un número corto no identifica a nadie');
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

prueba('un acierto en otro municipio se descarta aunque caiga en la caja', function (): void {
    // ESTE era el fallo que ponía predios donde no van. La caja de coordenadas es
    // un rectángulo y Jamundí no lo es: roza Cali por el norte y Villa Rica por
    // el sur, así que por caja sola se colaban aciertos de municipios vecinos y
    // se pintaban como propios.
    $enCali = [
        'lat' => '3.4200', 'lon' => '-76.5200',
        'address' => ['city' => 'Cali', 'state' => 'Valle del Cauca'],
    ];
    afirmar(Geocodificador::dentroDeJamundi(3.42, -76.52), 'la caja sí lo admite');
    afirmar(! Geocodificador::esDeJamundi($enCali), 'pero no es de Jamundí');
});

prueba('un acierto en Jamundí se acepta, con o sin tilde', function (): void {
    foreach (['Jamundí', 'Jamundi', 'JAMUNDÍ', 'Municipio de Jamundí'] as $nombre) {
        $r = ['lat' => '3.2700', 'lon' => '-76.5500', 'address' => ['county' => $nombre]];
        afirmar(Geocodificador::esDeJamundi($r), "«{$nombre}» debía aceptarse");
    }
});

prueba('el municipio se busca en la clave que traiga', function (): void {
    // Nominatim lo mete en una u otra según el tipo de lugar.
    foreach (['county', 'city', 'town', 'municipality', 'village'] as $clave) {
        $r = ['lat' => '3.2700', 'lon' => '-76.5500', 'address' => [$clave => 'Jamundí']];
        afirmar(Geocodificador::esDeJamundi($r), "no se miró la clave «{$clave}»");
    }
});

prueba('sin detalle de dirección se admite si cae en la caja', function (): void {
    // No se puede comprobar el nombre; la caja es lo único que queda.
    afirmar(
        Geocodificador::esDeJamundi(['lat' => '3.2700', 'lon' => '-76.5500']),
        'sin detalle, la caja debía bastar'
    );
});

prueba('fuera de la caja se descarta aunque diga Jamundí', function (): void {
    $r = ['lat' => '4.7110', 'lon' => '-74.0721', 'address' => ['county' => 'Jamundí']];
    afirmar(! Geocodificador::esDeJamundi($r), 'Bogotá no es Jamundí');
});

// ── Inspección de viviendas: Anexo 1 y niveles permitidos ────────────────────

grupo('Inspección › niveles de daño (Anexo 1)');

prueba('los niveles de cada elemento salen del anexo, no de una lista aparte', function (): void {
    // Si algún día se escribieran en dos sitios, un elemento acabaría
    // ofreciendo un nivel que el anexo no sabe describir.
    foreach (NivelDano::SISTEMAS as $sistema) {
        foreach (NivelDano::elementos($sistema) as $elemento) {
            foreach (NivelDano::nivelesDe($sistema, $elemento) as $nivel) {
                afirmar(
                    NivelDano::descriptores($sistema, $elemento, $nivel) !== [],
                    "{$sistema}/{$elemento}/{$nivel} se ofrece sin criterios que lo describan"
                );
            }
        }
    }
});

prueba('reproduce exactamente las casillas N/A del numeral 5.4', function (): void {
    // Las cuatro casillas marcadas N/A en el papel. Que coincidan no es
    // casualidad: son las que el Anexo 1 deja sin definir.
    afirmar(! NivelDano::permite('MAMPOSTERIA', 'PLACA_PISO', 'LEVE'), 'placa de piso no tiene leve');
    afirmar(! NivelDano::permite('MAMPOSTERIA', 'ELECTRICAS', 'LEVE'), 'eléctricas de mampostería no tienen leve');
    afirmar(! NivelDano::permite('MADERA', 'MUROS_MADERA', 'LEVE'), 'muros en madera no tienen leve');
    afirmar(! NivelDano::permite('MADERA', 'ELECTRICAS', 'LEVE'), 'eléctricas de madera no tienen leve');

    // Y que no se pase de listo quitando de más.
    afirmarIgual(4, count(NivelDano::nivelesDe('MAMPOSTERIA', 'VIGAS_COLUMNAS')));
    afirmarIgual(3, count(NivelDano::nivelesDe('MAMPOSTERIA', 'PLACA_PISO')));
});

prueba('los elementos son los del formato, en su orden', function (): void {
    afirmarIgual(
        ['VIGAS_COLUMNAS', 'MUROS_CARGA', 'MUROS_DIVISORIOS', 'PLACA_PISO', 'CUBIERTA', 'HIDROSANITARIAS', 'ELECTRICAS'],
        NivelDano::elementos('MAMPOSTERIA')
    );
    afirmarIgual(
        ['VIGAS_COLUMNAS', 'ENTREPISOS', 'MUROS_MADERA', 'CUBIERTA', 'HIDROSANITARIAS', 'ELECTRICAS'],
        NivelDano::elementos('MADERA')
    );
});

prueba('los niveles se ordenan de leve a colapso, venga como venga el anexo', function (): void {
    afirmarIgual(['MODERADO', 'SEVERO', 'COLAPSO_TOTAL'], NivelDano::nivelesDe('MADERA', 'ELECTRICAS'));
});

prueba('peor() ordena por gravedad y trata null como sin daño', function (): void {
    afirmarIgual('SEVERO', NivelDano::peor('LEVE', 'SEVERO'));
    afirmarIgual('SEVERO', NivelDano::peor('SEVERO', 'LEVE'));
    afirmarIgual('COLAPSO_TOTAL', NivelDano::peor('SEVERO', 'COLAPSO_TOTAL'));
    afirmarIgual('LEVE', NivelDano::peor(null, 'LEVE'));
    afirmarIgual(null, NivelDano::peor(null, null));
});

prueba('el texto duplicado del original quedó corregido', function (): void {
    $d = NivelDano::descriptores('MADERA', 'HIDROSANITARIAS', 'MODERADO');
    afirmarIgual(['Fisuras o roturas en la tubería', 'Desacople de los accesorios de la tubería'], $d);
});

// ── Inspección de viviendas: el combo del numeral 6 ──────────────────────────

grupo('Inspección › combo de materiales (numeral 6)');

prueba('el combo lo fija el sistema estructural, no el peor daño de la casa', function (): void {
    // El caso que la regla del formato existe para resolver: cubierta destruida
    // sobre estructura apenas fisurada. Entregar un combo severo aquí sería
    // entregar materiales que no se necesitan.
    $r = BancoMateriales::determinar('MAMPOSTERIA', [
        'VIGAS_COLUMNAS' => 'LEVE',
        'MUROS_CARGA' => 'LEVE',
        'CUBIERTA' => 'COLAPSO_TOTAL',
        'HIDROSANITARIAS' => 'SEVERO',
    ]);

    afirmarIgual('COMBO_1', $r['combo']);
    afirmarIgual('LEVE', $r['nivel']);
});

prueba('entre los estructurales manda el peor', function (): void {
    $r = BancoMateriales::determinar('MAMPOSTERIA', ['VIGAS_COLUMNAS' => 'LEVE', 'MUROS_CARGA' => 'SEVERO']);

    afirmarIgual('COMBO_3', $r['combo']);
    afirmar(str_contains($r['motivo'], 'muros de carga'), "el motivo debe decir quién decidió: {$r['motivo']}");
});

prueba('cada sistema tiene sus propios combos', function (): void {
    afirmarIgual('COMBO_2', BancoMateriales::determinar('MAMPOSTERIA', ['VIGAS_COLUMNAS' => 'MODERADO'])['combo']);
    afirmarIgual('COMBO_5', BancoMateriales::determinar('MADERA', ['VIGAS_COLUMNAS' => 'MODERADO'])['combo']);
});

prueba('el colapso total manda sobre la tabla por elementos', function (): void {
    // «Si la vivienda sufrió colapso estructural total, marque solo esta casilla».
    $r = BancoMateriales::determinar('MAMPOSTERIA', ['VIGAS_COLUMNAS' => 'LEVE'], true);

    afirmarIgual('COLAPSO_MAMPOSTERIA', $r['combo']);
    afirmarIgual('COLAPSO_TOTAL', $r['nivel']);
});

prueba('sin daño estructural no corresponde combo', function (): void {
    // Y se dice por qué, en vez de devolver un vacío que parezca un error.
    $r = BancoMateriales::determinar('MADERA', ['CUBIERTA' => 'SEVERO']);

    afirmarIgual(null, $r['combo']);
    afirmar(str_contains($r['motivo'], 'no resultó afectado'), $r['motivo']);
});

prueba('en madera no se busca un muro de carga que no existe', function (): void {
    $r = BancoMateriales::determinar('MADERA', ['MUROS_MADERA' => 'COLAPSO_TOTAL', 'VIGAS_COLUMNAS' => 'LEVE']);

    afirmarIgual('COMBO_4', $r['combo']);
});

grupo('Inspección › lista de materiales (Anexo 2)');

prueba('el nivel filtra los ítems que lleva cada kit', function (): void {
    // Cotejado contra el impreso: en mampostería leve, el kit de estructura
    // solo lleva cemento; las varillas aparecen desde moderado.
    $leve = BancoMateriales::materiales('MAMPOSTERIA', 'LEVE');
    $estructura = $leve['kits'][0];

    afirmarIgual('Kit Estructura tipo concreto (Vigas, columnas, placas de piso)', $estructura['kit']);
    afirmarIgual(1, count($estructura['items']));
    afirmarIgual('Cemento Bulto 50 Kg', $estructura['items'][0]['descripcion']);
    afirmarIgual('5', $estructura['items'][0]['cantidad']);
});

prueba('las cantidades del anexo se conservan al pie de la letra', function (): void {
    $severo = BancoMateriales::materiales('MAMPOSTERIA', 'SEVERO');

    // Se busca dentro de su kit, no en una lista aplanada: ver la prueba
    // siguiente, que explica por qué aplanar pierde información.
    $cantidad = static function (array $r, string $kit, string $item): ?string {
        foreach ($r['kits'] as $k) {
            if ($k['kit'] !== $kit) {
                continue;
            }
            foreach ($k['items'] as $i) {
                if ($i['descripcion'] === $item) {
                    return $i['cantidad'];
                }
            }
        }

        return null;
    };

    afirmarIgual('2050', $cantidad($severo, 'Kit Mampostería adobe macizo', 'Ladrillo tolete común'));
    afirmarIgual('67', $cantidad($severo, 'Kit Estructura tipo concreto (Vigas, columnas, placas de piso)', 'Varilla de 1/4" L=6M'));
    afirmarIgual('50', $cantidad($severo, 'Kit Eléctrico', 'Cable 10 AWG - THW'));
});

prueba('el mismo material puede ir en dos kits con cantidades distintas', function (): void {
    // El cemento aparece en el kit de estructura (25 bultos en severo) y otra
    // vez en el de mampostería (21). Son partidas distintas del mismo anexo.
    //
    // Esto fija que la lista NO se puede aplanar por descripción: hacerlo
    // borraría una de las dos y el almacén entregaría 21 bultos donde hacen
    // falta 46. Lo descubrió esta misma prueba al escribirse mal la primera vez.
    $severo = BancoMateriales::materiales('MAMPOSTERIA', 'SEVERO');
    $cementos = [];

    foreach ($severo['kits'] as $k) {
        foreach ($k['items'] as $i) {
            if ($i['descripcion'] === 'Cemento Bulto 50 Kg') {
                $cementos[$k['kit']] = $i['cantidad'];
            }
        }
    }

    afirmarIgual(2, count($cementos), 'el cemento va en dos kits');
    afirmarIgual('25', $cementos['Kit Estructura tipo concreto (Vigas, columnas, placas de piso)']);
    afirmarIgual('21', $cementos['Kit Mampostería adobe macizo']);
});

prueba('el kit de cubierta se suma solo si se eligió', function (): void {
    $sin = BancoMateriales::contarItems('MAMPOSTERIA', 'SEVERO');
    $con = BancoMateriales::contarItems('MAMPOSTERIA', 'SEVERO', 'ZINC');

    afirmarIgual(4, $con - $sin, 'el kit de zinc trae cuatro renglones');
});

prueba('un cero escrito en el original es «no lleva»', function (): void {
    // En madera, el tanque de agua está como 0 en leve y moderado, y como 1 en
    // severo. Un cero impreso en una orden de entrega se lee como error.
    $nombres = static function (string $nivel): array {
        $out = [];
        foreach (BancoMateriales::materiales('MADERA', $nivel)['kits'] as $k) {
            foreach ($k['items'] as $i) {
                $out[] = $i['descripcion'];
            }
        }

        return $out;
    };

    afirmar(! in_array('Tanque de agua 500 L', $nombres('LEVE'), true), 'no debe aparecer en leve');
    afirmar(in_array('Tanque de agua 500 L', $nombres('SEVERO'), true), 'sí en severo');
});

prueba('en madera no se ofrece fibrocemento', function (): void {
    // No es un olvido del anexo: el fibrocemento pesa más de lo que sostiene
    // una estructura de madera de este tipo.
    afirmarIgual(['ZINC'], array_keys(BancoMateriales::KITS_CUBIERTA['MADERA']));
    afirmarIgual(0, BancoMateriales::contarItems('MADERA', 'SEVERO', 'FIBROCEMENTO')
        - BancoMateriales::contarItems('MADERA', 'SEVERO'));
});

prueba('el colapso total se declara sin lista, no se rellena con la del severo', function (): void {
    // El Anexo 2 solo trae columnas leve, moderado y severo. Inventar
    // cantidades para el colapso pondría cifras falsas en una orden de entrega
    // de materiales públicos, indistinguibles de las buenas al imprimirlas.
    $r = BancoMateriales::materiales('MAMPOSTERIA', 'COLAPSO_TOTAL');

    afirmarIgual([], $r['kits']);
    afirmar($r['sin_lista'], 'debe declararse sin lista');
    afirmar(str_contains($r['nota'], 'Anexo 2 no define'), $r['nota']);
});

grupo('Inspección › tabla de casos compartida');

prueba('el servidor resuelve los 21 casos de combos.json', function (): void {
    // La MISMA tabla la ejecuta `frontend/src/lib/inspeccion-form/combo.spec.ts`.
    // Si alguien cambia una implementación y no la otra, falla una de las dos
    // suites. Sin esto divergirían en silencio, y de este cálculo depende una
    // entrega de materiales públicos.
    $ruta = __DIR__.'/fixtures/combos.json';
    afirmar(is_file($ruta), 'falta la tabla de casos compartida');

    $casos = json_decode((string) file_get_contents($ruta), true)['casos'];
    afirmar(count($casos) >= 20, 'la tabla no debería encogerse');

    foreach ($casos as $caso) {
        $r = BancoMateriales::determinar(
            $caso['sistema'],
            $caso['danos'],
            $caso['colapso_total'] ?? false
        );
        $e = $caso['espera'];

        afirmarIgual($e['combo'], $r['combo'], $caso['nombre']);
        afirmarIgual($e['nivel'], $r['nivel'], $caso['nombre'].' (nivel)');

        $elemento = BancoMateriales::nivelEstructural($caso['sistema'], $caso['danos'])['elemento'];
        if (! ($caso['colapso_total'] ?? false)) {
            afirmarIgual($e['elemento'], $elemento, $caso['nombre'].' (quién decidió)');
        }
    }
});

prueba('los fixtures del Anexo 2 siguen al día', function () use ($raiz): void {
    // Cierra el círculo con la prueba del navegador
    // (`frontend/src/lib/inspeccion-form/materiales.spec.ts`), que comprueba su
    // filtro contra estos mismos archivos:
    //
    //   • si se toca el anexo en PHP y no se regeneran, falla AQUÍ;
    //   • si se regeneran y el filtro del navegador no coincide, falla ALLÁ.
    //
    // Sin esto, el teléfono podría mostrar una lista de materiales y el
    // expediente guardar otra, y nadie se enteraría hasta el almacén.
    $anexo = (string) file_get_contents($raiz.'/tests/fixtures/anexo2.json');
    afirmarIgual(
        json_decode($anexo, true),
        BancoMateriales::anexo2ParaApi(),
        'regenere tests/fixtures/anexo2.json'
    );

    $esperado = json_decode((string) file_get_contents($raiz.'/tests/fixtures/materiales.json'), true);

    foreach ($esperado['casos'] as $caso) {
        $r = BancoMateriales::materiales($caso['sistema'], $caso['nivel'], $caso['kit']);
        $total = array_sum(array_map(static fn (array $k): int => count($k['items']), $r['kits']));

        afirmarIgual($caso['total'], $total, "{$caso['sistema']}/{$caso['nivel']}");
        afirmarIgual($caso['sin_lista'], $r['sin_lista'], "{$caso['sistema']}/{$caso['nivel']} sin_lista");
        afirmarIgual($caso['kits'], array_map(static fn (array $k): string => $k['kit'], $r['kits']));
    }
});

grupo('Inspección › catálogos');

prueba('el formulario se puede dibujar entero con una sola respuesta', function (): void {
    // Tiene que caber en la caché del teléfono: en la vereda no hay segunda
    // petición que valga.
    $c = CatalogosInspeccion::paraApi();

    foreach (['eventos', 'requisitos', 'convenciones', 'evaluacion', 'kits_cubierta', 'parentescos'] as $clave) {
        afirmar(($c[$clave] ?? []) !== [], "falta «{$clave}» en los catálogos");
    }

    afirmar(strlen(json_encode($c)) < 60000, 'los catálogos no deberían pasar de unas decenas de KB');
});

prueba('la evaluación viaja con los criterios de cada nivel', function (): void {
    $mamposteria = CatalogosInspeccion::paraApi()['evaluacion']['MAMPOSTERIA'];
    $placa = null;

    foreach ($mamposteria as $e) {
        if ($e['codigo'] === 'PLACA_PISO') {
            $placa = $e;
        }
    }

    afirmar($placa !== null, 'debe venir la placa de piso');
    afirmarIgual(3, count($placa['niveles']), 'la placa no tiene nivel leve');
    afirmarIgual('MODERADO', $placa['niveles'][0]['codigo']);
    afirmar($placa['niveles'][0]['criterios'] !== [], 'cada nivel viaja con sus criterios');
    afirmar(! $placa['estructural'], 'la placa de piso no decide el combo');
});

prueba('los estructurales vienen marcados, que son los que deciden el combo', function (): void {
    $marcados = [];
    foreach (CatalogosInspeccion::paraApi()['evaluacion']['MAMPOSTERIA'] as $e) {
        if ($e['estructural']) {
            $marcados[] = $e['codigo'];
        }
    }

    afirmarIgual(['VIGAS_COLUMNAS', 'MUROS_CARGA'], $marcados);
});

prueba('no se inventa un código de formato que la entidad no ha asignado', function (): void {
    afirmarIgual('', CatalogosInspeccion::FORMATO_CODIGO);
});

prueba('el municipio y los corregimientos son los mismos del RUFE', function (): void {
    // Dos listas de corregimientos acabarían teniendo una un sector que la otra
    // no, y el mismo predio saldría en dos sitios distintos.
    afirmarIgual(Catalogos::MUNICIPIO, CatalogosInspeccion::MUNICIPIO);
    afirmarIgual(Catalogos::CORREGIMIENTOS, CatalogosInspeccion::paraApi()['corregimientos']);
});

prueba('el material de la cubierta sugiere su kit', function (): void {
    afirmarIgual('ZINC', CatalogosInspeccion::KIT_SUGERIDO['Z']);
    afirmarIgual('FIBROCEMENTO', CatalogosInspeccion::KIT_SUGERIDO['Ac']);
    afirmar(! isset(CatalogosInspeccion::KIT_SUGERIDO['M']), 'una cubierta de madera no sugiere kit');
});

prueba('las convenciones distinguen madera de mampostería en estructura', function (): void {
    // «M» es madera en las cuatro categorías; la mampostería es «Ma». Meterlas
    // en una sola tabla de letras las confundiría.
    afirmar(CatalogosInspeccion::esMaterialValido('ESTRUCTURA', 'Ma'), 'Ma es mampostería');
    afirmar(CatalogosInspeccion::esMaterialValido('ESTRUCTURA', 'M'), 'M es madera');
    afirmar(! CatalogosInspeccion::esMaterialValido('PISOS', 'Ma'), 'Ma no es un piso');
    afirmar(! CatalogosInspeccion::esMaterialValido('MUROS_DIVISORIOS', 'Z'), 'Z no es un muro');
});

// ── Inspección de viviendas: el validador ────────────────────────────────────

grupo('Inspección › validación');

/** Una inspección mínima y válida, con los tres requisitos en sí. */
function inspeccionBase(array $cambios = []): array
{
    return array_replace([
        'fecha_evaluacion' => date('Y-m-d'),
        'profesional_nombre' => 'Ana Ruiz',
        'profesional_tarjeta' => 'CO-12345',
        'profesional_profesion' => 'INGENIERO_CIVIL',
        'profesional_documento' => '31234567',
        'profesional_telefono' => '3151234567',
        'propietario_nombres' => 'Pedro Pérez Gómez',
        'propietario_documento' => '16234567',
        'direccion_cabecera' => 'Carrera 11 # 8-26',
        'requisitos' => ['NO_BENEFICIARIO' => true, 'PROPIETARIO' => true, 'NO_ALTO_RIESGO' => true],
        'evento' => 'SISMO',
        'sistema_constructivo' => 'MAMPOSTERIA',
        'infraestructura' => ['MUROS_DIVISORIOS' => 'L', 'PISOS' => 'C', 'ESTRUCTURA' => 'Co', 'CUBIERTA' => 'Z'],
        'danos' => [
            'VIGAS_COLUMNAS' => ['afectado' => true, 'nivel' => 'MODERADO'],
            'MUROS_CARGA' => ['afectado' => false],
            'MUROS_DIVISORIOS' => ['afectado' => false],
            'PLACA_PISO' => ['afectado' => false],
            'CUBIERTA' => ['afectado' => true, 'nivel' => 'LEVE'],
            'HIDROSANITARIAS' => ['afectado' => false],
            'ELECTRICAS' => ['afectado' => false],
        ],
        'requiere_evacuacion' => false,
        'kit_cubierta' => 'ZINC',
        'informante_nombre' => 'María Pérez',
        'informante_documento' => '1144567890',
        'informante_parentesco' => 3,
        'aprobacion_profesional' => 'Ana Ruiz',
    ], $cambios);
}

function erroresInspeccion(array $entrada): array
{
    return ValidadorInspeccion::inspeccion($entrada)['errores'];
}

function datosInspeccion(array $entrada): array
{
    return ValidadorInspeccion::inspeccion($entrada)['datos'];
}

prueba('una inspección completa pasa sin errores', function (): void {
    afirmarIgual([], erroresInspeccion(inspeccionBase()));
});

prueba('el numeral 9 ya no se diligencia en campo', function (): void {
    // Quien levanta la ficha no puede aprobarla en el mismo acto: de ella
    // depende una entrega de materiales públicos. La decisión se toma después,
    // sobre la ficha guardada, con el mecanismo de estados.
    $base = inspeccionBase();
    unset($base['aprobacion_profesional']);

    afirmarIgual([], erroresInspeccion($base));
});

prueba('una ficha que sí trae el numeral 9 lo conserva', function (): void {
    // Las inspecciones ya levantadas lo llevan y el PDF lo imprime. Dejar de
    // exigirlo no es lo mismo que empezar a descartarlo.
    $d = datosInspeccion(inspeccionBase(['aprobacion_coordinador' => 'Carlos Alberto Gil']));

    afirmarIgual('Ana Ruiz', $d['aprobacion_profesional']);
    afirmarIgual('Carlos Alberto Gil', $d['aprobacion_coordinador']);
});

prueba('la inspección guarda el punto GPS cuando se toma', function (): void {
    // La misma ubicación que ya toma el censo. Sin ella, «finca La Esperanza,
    // vía a Potrerito» es imposible de encontrar dos semanas después con un
    // camión de materiales.
    $d = datosInspeccion(inspeccionBase([
        'latitud' => 3.2611234,
        'longitud' => -76.5412345,
        'precision_m' => 12,
    ]));

    afirmarIgual(3.2611234, $d['latitud']);
    afirmarIgual(-76.5412345, $d['longitud']);
    afirmarIgual(12, $d['precision_m']);
});

prueba('sin ubicación la inspección sigue siendo válida', function (): void {
    // Tomarla es opcional: bajo un techo de zinc entre montañas el GPS no
    // engancha, y la visita no se puede detener por eso.
    $r = ValidadorInspeccion::inspeccion(inspeccionBase());

    afirmarIgual([], $r['errores']);
    afirmarIgual(null, $r['datos']['latitud']);
    afirmarIgual(null, $r['datos']['precision_m']);
});

prueba('una ubicación fuera de Colombia se descarta, no tumba la ficha', function (): void {
    // Un GPS que devuelve Madrid es un GPS averiado. Lo que no puede pasar es
    // que por eso se pierda una inspección ya diligenciada entera.
    $e = erroresInspeccion(inspeccionBase(['latitud' => 40.4168, 'longitud' => -3.7038]));

    afirmar(isset($e['latitud']), 'debe avisar de la ubicación imposible');
    afirmar(! isset($e['direccion_cabecera']), 'no debe arrastrar el resto del formulario');
});

prueba('una precisión absurda se ignora, pero el punto se conserva', function (): void {
    $d = datosInspeccion(inspeccionBase([
        'latitud' => 3.2611,
        'longitud' => -76.5412,
        'precision_m' => 999999,
    ]));

    afirmarIgual(null, $d['precision_m']);
    afirmarIgual(3.2611, $d['latitud']);
});

prueba('la profesión se guarda resuelta, no como código', function (): void {
    // Lo que va al papel y al expediente es el nombre de la profesión: un
    // «INGENIERO_CIVIL» impreso en un formato oficial no lo lee nadie.
    $d = datosInspeccion(inspeccionBase());

    afirmarIgual('Ingeniero(a) civil', $d['profesional_profesion']);
});

prueba('una profesión fuera de la lista se rechaza', function (): void {
    $e = erroresInspeccion(inspeccionBase(['profesional_profesion' => 'ASTRONAUTA']));

    afirmar(isset($e['profesional_profesion']), 'debe exigir una de la lista');
});

prueba('«Otra» guarda lo que escribió el profesional', function (): void {
    $d = datosInspeccion(inspeccionBase([
        'profesional_profesion' => 'OTRA',
        'profesional_profesion_otra' => 'Ingeniera sanitaria',
    ]));

    afirmarIgual('Ingeniera sanitaria', $d['profesional_profesion']);
});

prueba('«Otra» sin decir cuál no pasa', function (): void {
    $e = erroresInspeccion(inspeccionBase(['profesional_profesion' => 'OTRA']));

    afirmar(isset($e['profesional_profesion_otra']), 'falta decir cuál');
});

prueba('un texto libre con una profesión de la lista se rechaza', function (): void {
    // Significaría que el formulario y el servidor no están de acuerdo; guardarlo
    // dejaría un dato que nadie puede volver a ver ni corregir.
    $e = erroresInspeccion(inspeccionBase([
        'profesional_profesion' => 'ARQUITECTO',
        'profesional_profesion_otra' => 'Ingeniera sanitaria',
    ]));

    afirmar(isset($e['profesional_profesion_otra']), 'solo aplica con "Otra"');
});

prueba('las profesiones son las que pueden firmar una inspección', function (): void {
    // El formato exige tarjeta profesional en el renglón de al lado: solo caben
    // profesiones con matrícula que habilite para evaluar daño estructural.
    $codigos = array_keys(CatalogosInspeccion::PROFESIONES);

    foreach (['ARQUITECTO', 'INGENIERO_CIVIL', 'INGENIERO_ESTRUCTURAL', 'OTRA'] as $esperado) {
        afirmar(in_array($esperado, $codigos, true), "falta {$esperado}");
    }

    afirmarIgual('OTRA', end($codigos), '«Otra» va al final de la lista');
});

prueba('el combo se calcula aquí y no se acepta del cliente', function (): void {
    // Aunque el navegador mande un combo distinto, manda el del servidor: de
    // este número depende cuántos materiales recibe una familia.
    $d = datosInspeccion(inspeccionBase(['combo' => 'COMBO_3', 'combo_nivel' => 'SEVERO']));

    afirmarIgual('COMBO_2', $d['combo']);
    afirmarIgual('MODERADO', $d['combo_nivel']);
    afirmar(str_contains($d['combo_motivo'], 'vigas y columnas'), $d['combo_motivo']);
});

prueba('la lista de materiales queda resuelta en el expediente', function (): void {
    $d = datosInspeccion(inspeccionBase());

    afirmar($d['materiales']['kits'] !== [], 'debe traer los materiales del combo 2');
    afirmar(! $d['materiales']['sin_lista'], 'el combo 2 sí tiene lista');
});

prueba('el numeral 4 se deriva, no se acepta', function (): void {
    $d = datosInspeccion(inspeccionBase([
        'requisitos' => ['NO_BENEFICIARIO' => true, 'PROPIETARIO' => false, 'NO_ALTO_RIESGO' => true],
        'cumple_requisitos' => true,
        'evento' => '', 'sistema_constructivo' => '', 'danos' => [], 'kit_cubierta' => '',
        'informante_nombre' => '',
        'acta_modalidad' => 'REHABILITACION',
        'acta_nombre' => 'Pedro Pérez Gómez',
        'acta_documento' => '16234567',
    ]));

    afirmarIgual(false, $d['cumple_requisitos']);
});

prueba('un requisito sin contestar no se toma por un no', function (): void {
    // «Sin contestar» y «no cumple» son cosas distintas: la segunda cierra la
    // puerta al banco de materiales y la primera solo significa que falta.
    $e = erroresInspeccion(inspeccionBase([
        'requisitos' => ['NO_BENEFICIARIO' => true, 'NO_ALTO_RIESGO' => true],
    ]));

    afirmar(isset($e['requisitos.PROPIETARIO']), 'debe pedir que se conteste');
});

prueba('sin cumplir requisitos no se admite evaluación técnica', function (): void {
    // «No se continúa con la inspección de la vivienda, pasar al numeral 8».
    $e = erroresInspeccion(inspeccionBase([
        'requisitos' => ['NO_BENEFICIARIO' => true, 'PROPIETARIO' => false, 'NO_ALTO_RIESGO' => true],
        'acta_modalidad' => 'REHABILITACION',
        'acta_nombre' => 'Pedro Pérez Gómez',
        'acta_documento' => '16234567',
    ]));

    afirmar(isset($e['sistema_constructivo']) || isset($e['evento']) || isset($e['danos']),
        'la rama de inspección no debe aceptarse');
});

prueba('quien cumple no puede mandar además un acta', function (): void {
    $e = erroresInspeccion(inspeccionBase(['acta_nombre' => 'Pedro Pérez Gómez']));

    afirmar(isset($e['acta_nombre']), 'el acta no aplica cuando sí cumple');
});

prueba('un nivel que el Anexo 1 no define se rechaza aunque llegue a mano', function (): void {
    // Cierra el círculo: la pantalla no lo ofrece, y si alguien se la salta el
    // servidor tampoco lo acepta.
    $base = inspeccionBase();
    $base['danos']['PLACA_PISO'] = ['afectado' => true, 'nivel' => 'LEVE'];

    afirmar(isset(erroresInspeccion($base)['danos.PLACA_PISO.nivel']), 'la placa de piso no tiene nivel leve');
});

prueba('decir que fue afectado sin decir cuánto no pasa', function (): void {
    $base = inspeccionBase();
    $base['danos']['MUROS_CARGA'] = ['afectado' => true];

    afirmar(isset(erroresInspeccion($base)['danos.MUROS_CARGA.nivel']), 'falta el nivel');
});

prueba('cada elemento del sistema tiene que contestarse', function (): void {
    $base = inspeccionBase();
    unset($base['danos']['CUBIERTA']);

    afirmar(isset(erroresInspeccion($base)['danos.CUBIERTA.afectado']), 'no se puede dejar sin contestar');
});

prueba('la tabla del otro sistema constructivo se rechaza', function (): void {
    $base = inspeccionBase(['sistema_constructivo' => 'MADERA']);

    afirmar(isset(erroresInspeccion($base)['danos']), 'trae elementos de mampostería');
});

prueba('con colapso total no se admite la tabla por elementos', function (): void {
    // «Marque solo esta casilla». Una tabla llena al lado significa que alguien
    // entendió mal el formato, y hay que decirlo antes de que se firme.
    $e = erroresInspeccion(inspeccionBase(['colapso_total' => true]));

    afirmar(isset($e['danos']), 'no se llena la tabla con colapso total');
});

prueba('el colapso total da su combo sin necesidad de la tabla', function (): void {
    $d = datosInspeccion(inspeccionBase(['colapso_total' => true, 'danos' => []]));

    afirmarIgual('COLAPSO_MAMPOSTERIA', $d['combo']);
    afirmar($d['materiales']['sin_lista'], 'el Anexo 2 no lista materiales para colapso');
});

prueba('un kit de cubierta imposible en ese sistema se rechaza', function (): void {
    $base = inspeccionBase([
        'sistema_constructivo' => 'MADERA',
        'kit_cubierta' => 'FIBROCEMENTO',
        'danos' => [
            'VIGAS_COLUMNAS' => ['afectado' => true, 'nivel' => 'LEVE'],
            'ENTREPISOS' => ['afectado' => false],
            'MUROS_MADERA' => ['afectado' => false],
            'CUBIERTA' => ['afectado' => false],
            'HIDROSANITARIAS' => ['afectado' => false],
            'ELECTRICAS' => ['afectado' => false],
        ],
    ]);

    afirmar(isset(erroresInspeccion($base)['kit_cubierta']), 'en madera no hay fibrocemento');
});

prueba('una convención que no es de esa categoría se rechaza', function (): void {
    $base = inspeccionBase();
    $base['infraestructura']['PISOS'] = 'Ma';

    afirmar(isset(erroresInspeccion($base)['infraestructura.PISOS']), 'Ma no es un piso');
});

prueba('sin ninguna forma de ubicar la vivienda no se acepta', function (): void {
    $e = erroresInspeccion(inspeccionBase(['direccion_cabecera' => '', 'corregimiento' => '', 'vereda' => '']));

    afirmar(isset($e['direccion_cabecera']), 'hay que poder llegar al predio');
});

prueba('una vivienda rural se ubica por corregimiento y vereda', function (): void {
    $e = erroresInspeccion(inspeccionBase([
        'direccion_cabecera' => '',
        'corregimiento' => Catalogos::CORREGIMIENTOS[0],
        'vereda' => 'La Ventura',
    ]));

    afirmarIgual([], $e);
});

prueba('la fecha de evaluación no puede ser de mañana', function (): void {
    $e = erroresInspeccion(inspeccionBase(['fecha_evaluacion' => date('Y-m-d', strtotime('+1 day'))]));

    afirmar(isset($e['fecha_evaluacion']), 'no se inspecciona en el futuro');
});

prueba('el departamento y el municipio los pone el servidor', function (): void {
    $d = datosInspeccion(inspeccionBase(['departamento' => 'Antioquia', 'municipio' => 'Medellín']));

    afirmarIgual('Valle del Cauca', $d['departamento']);
    afirmarIgual('Jamundí', $d['municipio']);
});

prueba('la aprobación del coordinador puede quedar para después', function (): void {
    // Suele firmarse en la oficina; exigirla en campo dejaría la ficha sin cerrar.
    afirmarIgual([], erroresInspeccion(inspeccionBase(['aprobacion_coordinador' => ''])));
});

grupo('Inspección › número de ficha');

prueba('el formato es INSP-AAAA-XXXXXX y cabe en la casilla del papel', function (): void {
    // Seis caracteres: la casilla «Ficha No.» del formato mide 26 puntos y con
    // ocho el número solo cabía en letra de 4,5 pt, ilegible impresa.
    $n = Numero::componer(2026);

    afirmar(Numero::esValido($n), $n);
    afirmarIgual(16, strlen($n));
    afirmar(str_starts_with($n, 'INSP-2026-'), $n);
    afirmar(! Radicado::esValido($n), 'no debe pasar por un radicado del censo');
});

prueba('no usa letras que se confunden al dictarlas', function (): void {
    // Crockford Base32: sin I, L, O ni U. Estos números se dictan por teléfono.
    for ($i = 0; $i < 60; $i++) {
        $sufijo = substr(Numero::componer(), 10);
        afirmar(preg_match('/[ILOU]/', $sufijo) === 0, "salió una letra confundible: {$sufijo}");
    }
});

prueba('no es correlativo: dos seguidos no se parecen', function (): void {
    // Un consecutivo diría cuántas inspecciones lleva el municipio y dejaría
    // adivinar el número de la vivienda de al lado.
    $vistos = [];
    for ($i = 0; $i < 50; $i++) {
        $vistos[] = Numero::componer();
    }

    afirmarIgual(50, count(array_unique($vistos)), 'salieron números repetidos');
});

prueba('la huella ignora mayúsculas y espacios de más en la dirección', function (): void {
    $a = Numero::huella('2026-08-20', 'Carrera 11 # 8-26', '16234567');
    $b = Numero::huella('2026-08-20', '  carrera   11 # 8-26 ', '16234567');

    afirmarIgual($a, $b, 'la misma vivienda debe dar la misma huella');
});

prueba('la huella distingue propietario y fecha', function (): void {
    $base = Numero::huella('2026-08-20', 'Carrera 11 # 8-26', '16234567');

    afirmar($base !== Numero::huella('2026-09-01', 'Carrera 11 # 8-26', '16234567'), 'otra fecha, otra huella');
    afirmar($base !== Numero::huella('2026-08-20', 'Carrera 11 # 8-26', '99999999'), 'otro propietario, otra huella');
});

grupo('Rutas › que ninguna apunte a un método inexistente');

prueba('todas las rutas resuelven a un método que existe', function () use ($raiz): void {
    // Esto no es celo de más: el 18 de agosto de 2026 una ruta quedó registrada
    // contra un método que no llegó a escribirse, y el TypeError al construir el
    // router tumbó TODAS las peticiones de la API, no solo la suya. El sitio
    // entero devolvió 500 hasta que se quitó la línea.
    $php = (string) file_get_contents($raiz.'/public/index.php');

    // Qué controlador hay detrás de cada variable: `$rufe = new RufeController;`
    preg_match_all('/\$(\w+)\s*=\s*new\s+(\w+);/', $php, $vars, PREG_SET_ORDER);
    $clase = [];
    foreach ($vars as $v) {
        $clase[$v[1]] = 'App\\Controllers\\'.$v[2];
    }

    // Y qué método pide cada ruta: `[$rufe, 'listar']`
    preg_match_all("/\[\\\$(\w+),\s*'(\w+)'\]/", $php, $rutas, PREG_SET_ORDER);
    afirmar(count($rutas) >= 30, 'se esperaban al menos 30 rutas, se leyeron '.count($rutas));

    foreach ($rutas as $r) {
        [$todo, $variable, $metodo] = $r;

        afirmar(isset($clase[$variable]), "la ruta usa \${$variable}, que no se instancia");
        afirmar(class_exists($clase[$variable]), "no existe la clase {$clase[$variable]}");
        afirmar(
            method_exists($clase[$variable], $metodo),
            "{$clase[$variable]}::{$metodo}() no existe — registrarla tumbaría TODA la API"
        );
    }
});

grupo('Rutas › hasta dónde llega el inspector de vivienda');

/**
 * Las rutas de `index.php` con la lista de roles que las protege, ya resuelta.
 *
 * Se lee el archivo en vez de consultar el router porque lo que hay que
 * comprobar es lo que está escrito ahí: una ruta con la constante equivocada no
 * da ningún error, simplemente abre datos a quien no debe verlos.
 *
 * @return array<string,string[]> «MÉTODO ruta» => roles
 */
function rutasConSusRoles(string $raiz): array
{
    $php = (string) file_get_contents($raiz.'/public/index.php');

    $listas = [
        'Auth::TODOS'         => Auth::TODOS,
        'Auth::ESCRITURA'     => Auth::ESCRITURA,
        'Auth::LECTURA_RUFE'  => Auth::LECTURA_RUFE,
        'Auth::INSPECCION'    => Auth::INSPECCION,
        'Auth::CALL_CENTER'   => Auth::CALL_CENTER,
        'Auth::LECTURA_INSPECCION' => Auth::LECTURA_INSPECCION,
        '$soloAdmin'          => [Auth::ADMINISTRADOR],
        '$capturaArchivos'    => array_values(array_unique(array_merge(Auth::ESCRITURA, Auth::INSPECCION))),
    ];

    preg_match_all(
        "/\\\$router->(get|post|put|delete)\\(\\s*'([^']+)'(.*?)\\);/s",
        $php,
        $encontradas,
        PREG_SET_ORDER
    );

    $salida = [];

    foreach ($encontradas as $r) {
        // El último argumento, cuando lo hay, es la lista de roles. Sin él la
        // ruta es pública —solo `/health` y `/auth/login`— y se omite.
        if (preg_match('/,\s*([A-Za-z_:$\\\\]+)\s*$/', trim($r[3]), $m) !== 1) {
            continue;
        }

        $salida[strtoupper($r[1]).' '.$r[2]] = $listas[trim($m[1])] ?? [];
    }

    return $salida;
}

prueba('borrar una solicitud ciudadana es solo del administrador', function () use ($raiz): void {
    // Es la única operación del sistema que destruye datos de un ciudadano y no
    // se deshace. El Gestor puede descartarla —lo que necesita para trabajar—
    // pero no hacerla desaparecer, y el Visualización ni siquiera eso.
    $roles = rutasConSusRoles($raiz)['DELETE /preinscripcion/fichas/{id}'] ?? null;

    afirmar($roles !== null, 'la ruta de borrado debe existir y declarar sus roles');
    afirmarIgual([App\Core\Auth::ADMINISTRADOR], $roles);
});

prueba('las rutas de archivos se leen ANTES de borrar la fila', function () use ($raiz): void {
    // Las claves foráneas se llevan las filas en cascada pero no tocan el disco.
    // Si se borrara primero la solicitud, ya no habría forma de saber qué
    // archivos borrar: la foto de la cédula de una persona se quedaría en el
    // servidor para siempre, sin ninguna fila que la nombrara y sin nadie que
    // supiera que está ahí.
    // Se mira `borrarFicha()`, que es donde vive el borrado desde que lo
    // comparten el de una y el de lote. Compartirlo es lo que impide que uno de
    // los dos se quede atrás y deje archivos en el disco.
    $fuente = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $metodo = substr($fuente, strpos($fuente, 'private function borrarFicha('));
    $metodo = substr($metodo, 0, strpos($metodo, 'public function cambiarEstado('));

    $lectura = strpos($metodo, 'ruta_relativa');
    $borrado = strpos($metodo, 'DELETE FROM preinscripciones');

    afirmar($lectura !== false, 'debe recoger las rutas de los archivos');
    afirmar($borrado !== false, 'debe borrar la solicitud');
    afirmar($lectura < $borrado, 'las rutas se leen antes del DELETE, no después');
});

prueba('una solicitud ya convertida en inspección no se puede borrar', function () use ($raiz): void {
    // Ninguna ficha de inspección guarda de qué solicitud nació. Borrarla
    // dejaría una inspección —de la que depende una entrega de materiales— sin
    // nada que explique por qué se hizo esa visita.
    $fuente = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');

    $unaSola = substr($fuente, strpos($fuente, 'public function eliminar(Request'));
    $unaSola = substr($unaSola, 0, strpos($unaSola, 'public function eliminarLote('));

    afirmar(
        str_contains($unaSola, "'CONVERTIDA'"),
        'eliminar() debe negarse con una solicitud ya convertida'
    );

    $lote = substr($fuente, strpos($fuente, 'public function eliminarLote('));
    $lote = substr($lote, 0, strpos($lote, 'private function borrarFicha('));

    afirmar(
        str_contains($lote, "'CONVERTIDA'"),
        'y el borrado en lote también: la regla no puede saltarse por la puerta de al lado'
    );
});

grupo('Borrar varias solicitudes de una vez');

prueba('el lote es del administrador, igual que el borrado de una', function () use ($raiz): void {
    // Sería absurdo blindar el borrado de una y dejar abierto el de treinta.
    $roles = rutasConSusRoles($raiz)['POST /preinscripcion/fichas/eliminar-lote'] ?? null;

    afirmar($roles !== null, 'la ruta del lote debe existir y declarar sus roles');
    afirmarIgual([App\Core\Auth::ADMINISTRADOR], $roles);
});

prueba('la ruta del lote se registra ANTES que la que lleva {id}', function () use ($raiz): void {
    // El router se queda con la primera que casa. Registrada después, `{id}` se
    // tragaría «eliminar-lote» como si fuera un número de solicitud.
    $php = (string) file_get_contents($raiz.'/public/index.php');

    $lote = strpos($php, "'/preinscripcion/fichas/eliminar-lote'");
    $una = strpos($php, "'/preinscripcion/fichas/{id}', [\$preinscripcion, 'eliminar']");

    afirmar($lote !== false && $una !== false, 'faltan las rutas de borrado');
    afirmar($lote < $una, 'la literal va antes que la del comodín');
});

prueba('el lote exige motivo, tiene tope y anota UNA constancia por solicitud', function () use ($raiz): void {
    $fuente = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $lote = substr($fuente, strpos($fuente, 'public function eliminarLote('));
    $lote = substr($lote, 0, strpos($lote, 'private function borrarFicha('));

    afirmar(str_contains($lote, "\$errores['motivo']"), 'el motivo es obligatorio también en lote');
    afirmar(str_contains($lote, 'MAX_BORRADO_LOTE'), 'debe haber un tope por petición');

    // La constancia va dentro de `borrarFicha()`, que se llama una vez por
    // solicitud. Una sola línea diciendo «se borraron 30» no dejaría constancia
    // de CUÁLES, y esa constancia es lo único que queda de esas personas.
    afirmar(
        substr_count($lote, 'Auditoria::registrar') === 0
            && str_contains($lote, '$this->borrarFicha('),
        'el lote no audita por su cuenta: delega en borrarFicha(), que anota una por una'
    );
});

prueba('los dos borrados comparten el mismo código, no una copia', function () use ($raiz): void {
    // Dos borrados con reglas parecidas acaban divergiendo, y el que se quede
    // atrás será el que deje la foto de una cédula en el disco.
    $fuente = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');

    afirmarIgual(
        1,
        substr_count($fuente, 'DELETE FROM preinscripciones WHERE id = :i'),
        'el DELETE debe estar escrito UNA sola vez'
    );

    afirmarIgual(
        2,
        substr_count($fuente, '$this->borrarFicha('),
        'y los dos caminos —una y lote— deben pasar por él'
    );
});

grupo('Pre-inscripción ciudadana');

prueba('el inspector llega EXACTAMENTE a estas rutas y a ninguna más', function () use ($raiz): void {
    // La lista va escrita a mano a propósito. Derivarla del código haría que la
    // prueba dijera «sí» a cualquier cosa que el código dijera; escrita así,
    // añadir una ruta sin decidir su acceso rompe aquí y obliga a pensarlo.
    //
    // Lo que está en juego: las fichas del censo llevan nombres, cédulas y
    // direcciones de hogares damnificados. El profesional que inspecciona
    // viviendas —a menudo un contratista externo— no las necesita.
    $esperadas = [
        // Su sesión.
        'GET /auth/me',
        'POST /auth/logout',
        'POST /auth/password',
        // Información del sistema.
        'GET /acerca/sistema',
        'GET /acerca/actualizaciones',
        // Su formato.
        'GET /inspeccion/catalogos',
        'GET /inspeccion/duplicados',
        // A nombre de quién firma. Son sus propios compañeros de rol, con los
        // datos que el numeral 1 pide de ellos; nada del censo.
        'GET /inspeccion/profesionales',
        'POST /inspeccion/fichas',
        'GET /inspeccion/fichas',
        'GET /inspeccion/fichas/{id}',
        'GET /inspeccion/fichas/{id}/fotos/{foto}',
        // Las fotos del numeral 11 suben por las cargas, que comparte con el censo.
        'POST /rufe/cargas',
        'GET /rufe/cargas/{carga}/archivos',
        'POST /rufe/cargas/{carga}/archivos',
        'PUT /rufe/cargas/{carga}/archivos/{id}',
        'DELETE /rufe/cargas/{carga}/archivos/{id}',
    ];

    $alcanza = [];

    foreach (rutasConSusRoles($raiz) as $ruta => $roles) {
        if (in_array(Auth::INSPECTOR, $roles, true)) {
            $alcanza[] = $ruta;
        }
    }

    sort($esperadas);
    sort($alcanza);

    afirmarIgual($esperadas, $alcanza);
});

prueba('una carga sin dueño dura lo suficiente para volver al día siguiente', function (): void {
    // No es un número cualquiera: es cuánto tiempo tiene una familia para
    // volver antes de que se le borren los videos.
    //
    // Las fotos del formulario ciudadano viven también en el teléfono y se
    // vuelven a subir solas, así que sobreviven a cualquier caducidad. Los
    // VIDEOS solo existen en el servidor: se suben por trozos y no se guardan
    // en el aparato. Con las dos horas que había, quien grababa de noche y
    // volvía por la mañana ya no los tenía.
    //
    // Bajarlo otra vez es volver a perder videos de gente damnificada. Si algún
    // día hace falta por disco, el arreglo es guardar también los videos en el
    // teléfono, no acortar esto.
    afirmar(
        App\Rufe\Archivos::HORAS_CARGA >= 12,
        'la carga caduca antes de que a alguien le dé tiempo de volver'
    );

    // Y un tope, porque esto también es disco de un hosting compartido que
    // nadie reclama: el peor caso por carga son ocho videos de 20 MiB más las
    // fotos.
    afirmar(
        App\Rufe\Archivos::HORAS_CARGA <= 24,
        'una carga sin dueño no puede vivir más de un día en el disco'
    );
});

grupo('El hogar precargado del censo');

prueba('el estado de una persona lo decide el servidor, no el navegador', function (): void {
    // Es la propiedad que sostiene toda la funcionalidad. Si el estado viniera
    // del cliente, bastaría con mandar «IGUAL» para que una corrección —o una
    // persona inventada— entrara sin que ningún funcionario la mirara.
    $censo = [
        'nombres' => 'Martha Cecilia', 'apellidos' => 'Londoño Zaen',
        'numero_documento' => '16844290', 'fecha_nacimiento' => '1970-05-02',
        'tipo_documento' => 1, 'parentesco' => 1, 'genero' => 2,
    ];

    $igual = $censo + ['estado' => 'CORREGIDA'];  // el navegador miente
    afirmarIgual('IGUAL', App\Preinscripcion\Censo::estadoDePersona($igual, $censo, false));

    $cambiada = array_merge($censo, ['apellidos' => 'Londoño Zaén', 'estado' => 'IGUAL']);
    afirmarIgual('CORREGIDA', App\Preinscripcion\Censo::estadoDePersona($cambiada, $censo, false));
});

prueba('quien no venía del censo es persona nueva', function (): void {
    afirmarIgual(
        'NUEVA',
        App\Preinscripcion\Censo::estadoDePersona(
            ['nombres' => 'Recién', 'apellidos' => 'Nacido'], null, false
        )
    );
});

prueba('el ciudadano no borra a nadie: lo marca como que ya no vive ahí', function (): void {
    // Quitar de un clic a una persona del censo de damnificados, y perder que
    // alguna vez estuvo, no puede hacerse sin que un funcionario lo mire.
    $censo = ['nombres' => 'Juan', 'apellidos' => 'Pérez', 'tipo_documento' => 1, 'parentesco' => 3, 'genero' => 1];

    afirmarIgual(
        'NO_VIVE_AQUI',
        App\Preinscripcion\Censo::estadoDePersona($censo, $censo, true),
        'la marca manda sobre todo lo demás'
    );
});

prueba('mayúsculas y espacios de sobra no son una corrección', function (): void {
    // Si lo fueran, la bandeja se llenaría de «correcciones» que no cambian
    // nada y el funcionario dejaría de mirarlas — incluidas las de verdad.
    $censo = ['nombres' => 'María José', 'apellidos' => 'Mina', 'tipo_documento' => 1, 'parentesco' => 1, 'genero' => 2];
    $enviada = ['nombres' => '  MARÍA  JOSÉ ', 'apellidos' => 'mina', 'tipo_documento' => 1, 'parentesco' => 1, 'genero' => 2];

    afirmarIgual('IGUAL', App\Preinscripcion\Censo::estadoDePersona($enviada, $censo, false));
});

prueba('cambiar la cédula de alguien SÍ es una corrección', function (): void {
    // Es el dato con el que se cruza todo el sistema: una cédula distinta no
    // puede pasar por un cambio menor.
    $censo = ['nombres' => 'Juan', 'apellidos' => 'Pérez', 'numero_documento' => '111', 'tipo_documento' => 1, 'parentesco' => 1, 'genero' => 1];
    $enviada = array_merge($censo, ['numero_documento' => '222']);

    afirmarIgual('CORREGIDA', App\Preinscripcion\Censo::estadoDePersona($enviada, $censo, false));
});

prueba('el listado del hogar acepta lo que una familia real puede dejar', function (): void {
    $r = App\Preinscripcion\Validador::revisar([
        'personas' => [
            ['nombres' => 'Martha', 'apellidos' => 'Londoño', 'numero_documento' => '16.844.290'],
            // Sin cédula ni fecha: un menor de edad, y no se le puede exigir.
            ['nombres' => 'Sara', 'apellidos' => 'Londoño'],
            // Vacía: quedó de pulsar «Agregar otra persona» y arrepentirse.
            ['nombres' => '', 'apellidos' => ''],
        ],
    ]);

    afirmar(! isset($r['errores']['personas']), 'no debería protestar por un listado corriente');
    afirmarIgual(2, count($r['datos']['personas']), 'la fila vacía se descarta sin ruido');
    afirmarIgual('16844290', $r['datos']['personas'][0]['numero_documento'], 'la cédula se guarda solo en dígitos');
});

prueba('una persona sin nombre no pasa, y una fecha imposible tampoco', function (): void {
    $sinNombre = App\Preinscripcion\Validador::revisar([
        'personas' => [['nombres' => '', 'apellidos' => 'Londoño']],
    ]);
    afirmar(isset($sinNombre['errores']['personas']), 'un apellido suelto no es una persona');

    $futuro = App\Preinscripcion\Validador::revisar([
        'personas' => [['nombres' => 'Ana', 'apellidos' => 'Mina', 'fecha_nacimiento' => '2099-01-01']],
    ]);
    afirmar(isset($futuro['errores']['personas']), 'nadie nace en 2099');
});

prueba('un listado desmesurado se rechaza', function (): void {
    // La ruta es pública: sin tope, un envío puede traer diez mil personas.
    $muchas = array_fill(0, 40, ['nombres' => 'A', 'apellidos' => 'B']);
    $r = App\Preinscripcion\Validador::revisar(['personas' => $muchas]);

    afirmar(isset($r['errores']['personas']), 'debe haber un tope');
});

prueba('sin listado, la solicitud sigue siendo válida', function (): void {
    // Quien llega por su cuenta y no tenía ficha no manda personas. Ese camino
    // no puede romperse por añadir esto.
    $r = App\Preinscripcion\Validador::revisar([]);

    afirmarIgual([], $r['datos']['personas'], 'sin personas es una lista vacía, no un error');
    afirmar(! isset($r['errores']['personas']), 'y no protesta');
});

grupo('El canal de WhatsApp consultando el censo');

/**
 * El código de `verificar()`, y solo el suyo.
 *
 * Se corta hasta el siguiente método y no hasta uno concreto por nombre: al
 * añadir `datosCenso()` justo después, el corte viejo se tragaba dos métodos y
 * la prueba de «una sola respuesta» empezó a contar la del vecino.
 */
function cuerpoDeVerificar(string $raiz): string
{
    $php = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $desde = (int) strpos($php, 'public function verificar(Request $req): void');
    $siguiente = strpos($php, '    public ', $desde + 20);

    return substr($php, $desde, $siguiente === false ? null : $siguiente - $desde);
}


/**
 * Atajo para leer el plan de límites con el estilo de las pruebas de al lado.
 *
 * @return list<array<string,mixed>>
 */
function planVerificar(
    string $secretoConfigurado = '',
    ?string $cabecera = null,
    ?string $origen = null,
    bool $https = true,
    string $ip = '190.0.0.7'
): array {
    return App\Controllers\PreinscripcionController::planDeLimites(
        $ip, $cabecera, $origen, $secretoConfigurado, $https
    );
}

/** Las acciones del plan, en orden. @return list<string> */
function accionesDe(array $plan): array
{
    return array_map(static fn (array $l): string => $l['accion'], $plan);
}

prueba('sin secreto configurado, la vía del servicio no existe', function (): void {
    // Es la propiedad que hace seguro tener este código escrito: mientras nadie
    // ponga un secreto en config.php, el sistema se comporta byte a byte como
    // antes. Ni siquiera mandando las cabeceras correctas cambia nada.
    $plan = planVerificar('', 'lo-que-sea', '573183335103');

    afirmarIgual(
        ['preinscripcion.verificar.hora', 'preinscripcion.verificar.dia'],
        accionesDe($plan),
        'con el secreto vacío se limita por IP, como siempre'
    );
});

prueba('dos ciudadanos del canal tienen cubetas independientes', function (): void {
    // El problema que resuelve todo esto: por IP, los mil trescientos hogares
    // comparten una cubeta porque el bot consulta desde un solo servidor. Si
    // las claves de dos números coincidieran, seguiríamos igual.
    $secreto = str_repeat('a', 64);

    $uno = planVerificar($secreto, $secreto, '573183335103');
    $otro = planVerificar($secreto, $secreto, '573125755695');

    afirmarIgual('573183335103', $uno[0]['identidad'], 'la cubeta es el número del ciudadano');
    afirmarIgual('573125755695', $otro[0]['identidad'], 'y la del otro es la suya');
    afirmar($uno[0]['identidad'] !== $otro[0]['identidad'], 'agotar uno no puede agotar el otro');
});

prueba('el número del ciudadano se normaliza como una cédula', function (): void {
    // «+57 318 333 3510» y «573183335103» son la misma persona. Sin
    // normalizar, cada forma de escribirlo sería una cubeta nueva y el límite
    // por ciudadano se sortearía poniendo un espacio.
    $secreto = str_repeat('b', 64);

    afirmarIgual(
        planVerificar($secreto, $secreto, '+57 318 333 3510')[0]['identidad'],
        planVerificar($secreto, $secreto, '573183333510')[0]['identidad'],
        'el mismo número escrito de dos maneras es una sola cubeta'
    );
});

prueba('un secreto equivocado cae al límite por IP y NO da 401', function (): void {
    // Un 401 le confirmaría a quien tantea que acertó el nombre de la cabecera.
    // Sin esa confirmación, probar cabeceras al azar es indistinguible de no
    // probar nada. Por eso el plan es el de siempre, no un error.
    $plan = planVerificar(str_repeat('c', 64), 'no-es-el-secreto', '573183335103');

    afirmarIgual(
        ['preinscripcion.verificar.hora', 'preinscripcion.verificar.dia'],
        accionesDe($plan),
        'con secreto incorrecto se limita por IP'
    );
});

prueba('con el secreto correcto pero sin origen, cae al límite por IP', function (): void {
    // Sin saber quién escribe no hay cubeta por ciudadano que valga: dejarlo
    // pasar sería levantar el límite, que es justo lo que no se puede hacer.
    $secreto = str_repeat('d', 64);

    afirmarIgual(
        ['preinscripcion.verificar.hora', 'preinscripcion.verificar.dia'],
        accionesDe(planVerificar($secreto, $secreto, null)),
        'sin X-RUFE-Origen se limita por IP'
    );

    afirmarIgual(
        ['preinscripcion.verificar.hora', 'preinscripcion.verificar.dia'],
        accionesDe(planVerificar($secreto, $secreto, 'no-tiene-digitos')),
        'un origen sin dígitos es como no traerlo'
    );
});

prueba('sin HTTPS no hay vía de servicio', function (): void {
    // Un secreto compartido que viaja en claro deja de ser un secreto en el
    // primer salto de red.
    $secreto = str_repeat('e', 64);

    afirmarIgual(
        ['preinscripcion.verificar.hora', 'preinscripcion.verificar.dia'],
        accionesDe(planVerificar($secreto, $secreto, '573183335103', false)),
        'en claro se limita por IP'
    );
});

prueba('el canal siempre tiene un techo global, cualquiera que sea el origen', function (): void {
    // Es la única defensa si el secreto se filtra: el origen lo dice el propio
    // bot, así que quien lo robe puede inventar uno distinto en cada petición.
    // Que este límite falte —o que se cuente por origen— deja el censo abierto
    // a enumeración.
    $secreto = str_repeat('f', 64);

    foreach (['573183335103', '573125755695', '573001112233'] as $numero) {
        $plan = planVerificar($secreto, $secreto, $numero);
        $global = array_values(array_filter($plan, static fn (array $l): bool => $l['global']));

        afirmarIgual(1, count($global), 'hay exactamente un límite global');
        afirmarIgual(
            'bot',
            $global[0]['identidad'],
            'el techo se cuenta con una identidad fija, no con la del ciudadano ni la IP'
        );
        afirmarIgual(3600, $global[0]['ventana'], 'el techo es por hora');
    }
});

prueba('el techo global se consume el último', function (): void {
    // Así solo lo gasta una consulta que ya pasó los límites de su ciudadano.
    // Al revés, alguien insistiendo desde su WhatsApp se llevaría por delante
    // la cubeta del canal entero y dejaría al bot mudo para los demás.
    $secreto = str_repeat('0', 64);
    $plan = planVerificar($secreto, $secreto, '573183335103');

    afirmarIgual(
        [
            'preinscripcion.verificar.origen.hora',
            'preinscripcion.verificar.origen.dia',
            'preinscripcion.verificar.servicio.hora',
        ],
        accionesDe($plan),
        'primero el ciudadano, después el canal'
    );
    afirmar($plan[2]['global'], 'el último es el global');
});

prueba('el ciudadano del canal nunca lee «desde esta conexión»', function (): void {
    // Quien escribe por WhatsApp no comparte conexión con nadie: decírselo es
    // mentirle, y en un canal de emergencias eso hace que deje de creerse lo
    // demás que se le diga.
    $secreto = str_repeat('9', 64);

    foreach (planVerificar($secreto, $secreto, '573183335103') as $l) {
        afirmar(
            ! str_contains($l['mensaje'], 'esta conexión'),
            "el mensaje de «{$l['accion']}» habla de una conexión que el ciudadano no comparte"
        );
    }
});

prueba('el tráfico web mantiene sus límites y sus mensajes', function (): void {
    // La instrucción era clara: para la web no puede cambiar nada.
    $plan = planVerificar();

    afirmarIgual(15, $plan[0]['maximo'], '15 por hora, como siempre');
    afirmarIgual(3600, $plan[0]['ventana'], 'ventana de una hora');
    afirmarIgual(40, $plan[1]['maximo'], '40 por día, como siempre');
    afirmarIgual(86400, $plan[1]['ventana'], 'ventana de un día');
    afirmarIgual('190.0.0.7', $plan[0]['identidad'], 'la cubeta sigue siendo la IP');
    afirmar(
        str_contains($plan[0]['mensaje'], 'desde esta conexión'),
        'el mensaje de la web no cambia'
    );
});

prueba('ni el secreto ni el número salen en ningún mensaje', function (): void {
    // Lo que se le devuelve al ciudadano acaba en el chat, y lo que va al log
    // acaba en un archivo que nadie limpia. Ninguno de los dos es sitio para un
    // secreto compartido ni para el teléfono de una familia damnificada.
    $secreto = 'secreto-de-prueba-que-no-debe-aparecer';
    $numero = '573183335103';

    foreach (planVerificar($secreto, $secreto, $numero) as $l) {
        afirmar(! str_contains($l['mensaje'], $secreto), "«{$l['accion']}» filtra el secreto");
        afirmar(! str_contains($l['mensaje'], $numero), "«{$l['accion']}» filtra el teléfono");
    }
});

prueba('la respuesta es la misma venga por donde venga', function () use ($raiz): void {
    // El endpoint responde `{habilitado, linea_atencion}` y nada más. Si algún
    // día alguien añadiera una respuesta distinta para el canal —«no estás en
    // el censo, pero sí en este otro listado»— este endpoint dejaría de ser un
    // booleano y pasaría a ser un buscador de damnificados.
    $cuerpo = cuerpoDeVerificar($raiz);

    afirmarIgual(
        1,
        substr_count($cuerpo, 'Response::ok'),
        'verificar() tiene más de una respuesta: el canal y la web deben recibir la misma'
    );
});

prueba('el formato de la cédula se comprueba antes de gastar cubeta', function () use ($raiz): void {
    // Un «12ab» no llega a preguntarle nada al censo. Cobrárselo solo servía
    // para que quien se equivoca tecleando se quedara sin intentos. Enumerar
    // sigue costando igual: para eso hay que mandar cédulas bien formadas.
    $cuerpo = cuerpoDeVerificar($raiz);

    afirmar(
        strpos($cuerpo, 'pareceCedula') < strpos($cuerpo, 'planDeLimites'),
        'la validación del documento debe ir antes de consumir ningún límite'
    );
});

/** Atajo para el plan de la vía firmada. @return list<array<string,mixed>> */
function planFirmado(?string $origen, string $documento = '1098765432'): array
{
    return App\Controllers\PreinscripcionController::planDeLimitesFirmado($origen, $documento);
}

prueba('la firma del bot se acepta en hexadecimal pelado', function (): void {
    $secreto = str_repeat('a', 64);
    $cuerpo = '{"documento":"1098765432"}';
    $firma = hash_hmac('sha256', $cuerpo, $secreto);

    afirmar(
        App\Controllers\PreinscripcionController::firmaValida($cuerpo, $firma, $secreto),
        'el formato documentado para herramientas debe valer'
    );
});

prueba('la firma del bot se acepta también con marca de tiempo', function (): void {
    // Los webhooks de la misma plataforma firman "{t}.{cuerpo}" y mandan
    // `t=<epoch>,v2=<hex>`. La documentación de las herramientas es escueta y
    // los dos formatos conviven, así que se aceptan ambos en vez de apostar.
    $secreto = str_repeat('b', 64);
    $cuerpo = '{"documento":"1098765432"}';
    $t = '1786113454';
    $cabecera = 't='.$t.',v2='.hash_hmac('sha256', $t.'.'.$cuerpo, $secreto);

    afirmar(
        App\Controllers\PreinscripcionController::firmaValida($cuerpo, $cabecera, $secreto),
        'el formato con marca de tiempo debe valer'
    );
});

prueba('una firma falsa no pasa', function (): void {
    $secreto = str_repeat('c', 64);
    $cuerpo = '{"documento":"1098765432"}';

    afirmar(
        ! App\Controllers\PreinscripcionController::firmaValida($cuerpo, str_repeat('0', 64), $secreto),
        'una firma inventada no puede valer'
    );
});

prueba('cambiar el cuerpo después de firmar invalida la firma', function (): void {
    // Es lo que aporta firmar el cuerpo y no solo mandar un secreto: una firma
    // capturada no sirve para consultar OTRA cédula.
    $secreto = str_repeat('d', 64);
    $firma = hash_hmac('sha256', '{"documento":"1098765432"}', $secreto);

    afirmar(
        ! App\Controllers\PreinscripcionController::firmaValida('{"documento":"9999999999"}', $firma, $secreto),
        'la firma de una cédula no puede servir para otra'
    );
});

prueba('sin secreto configurado ninguna firma vale', function (): void {
    // La misma propiedad que en la vía de las cabeceras: mientras config.php no
    // tenga un secreto, esta puerta no existe.
    afirmar(
        ! App\Controllers\PreinscripcionController::firmaValida('{}', hash_hmac('sha256', '{}', ''), ''),
        'con el secreto vacío no se puede autenticar nada'
    );
});

prueba('el bot con identificador de ciudadano usa las cubetas del ciudadano', function (): void {
    // Las MISMAS cubetas que la vía de X-RUFE-Origen, a propósito: es la misma
    // persona contada igual, venga por donde venga, y no dos presupuestos que
    // se suman.
    afirmarIgual(
        [
            'preinscripcion.verificar.origen.hora',
            'preinscripcion.verificar.origen.dia',
            'preinscripcion.verificar.servicio.hora',
        ],
        accionesDe(planFirmado('conv_abc123')),
        'con identificador se cuenta por ciudadano'
    );
});

prueba('el bot sin identificador cae a la cubeta por cédula', function (): void {
    // El motor de flujos no expone el teléfono de quien escribe. Cuando no
    // llega ningún identificador no hay forma de contar «por persona», y lo
    // único que queda por debajo del techo es la cédula consultada.
    afirmarIgual(
        [
            'preinscripcion.verificar.bot.cedula.dia',
            'preinscripcion.verificar.servicio.hora',
        ],
        accionesDe(planFirmado(null)),
        'sin identificador se cuenta por cédula'
    );
});

prueba('el techo global está en las DOS ramas del bot', function (): void {
    // Es la propiedad que de verdad importa. Sin identificador del ciudadano,
    // el techo es lo único que impide recorrer el censo con el secreto en la
    // mano: por cédula se frena a quien insiste sobre una, no a quien prueba
    // muchas. Si alguna rama se quedara sin techo, esa sería la puerta.
    foreach ([planFirmado('conv_abc123'), planFirmado(null)] as $plan) {
        $ultimo = $plan[count($plan) - 1];
        afirmar($ultimo['global'], 'el techo global debe cerrar todo plan del bot');
        afirmarIgual(
            'preinscripcion.verificar.servicio.hora',
            $ultimo['accion'],
            'y debe ser el mismo techo en las dos ramas'
        );
    }
});

prueba('dos conversaciones del bot no comparten cubeta', function (): void {
    $a = planFirmado('conv_aaa');
    $b = planFirmado('conv_bbb');

    afirmar(
        $a[0]['identidad'] !== $b[0]['identidad'],
        'agotar la cubeta de una conversación no puede dejar sin servicio a otra'
    );
});

prueba('el identificador del ciudadano se busca en el cuerpo del bot', function (): void {
    $c = 'App\Controllers\PreinscripcionController';

    afirmarIgual('conv_1', $c::origenDelBot(['conversationId' => 'conv_1']), 'conversationId');
    afirmarIgual('cont_2', $c::origenDelBot(['contactId' => 'cont_2']), 'contactId');
    afirmarIgual(null, $c::origenDelBot(['documento' => '1098765432']), 'sin contexto, null');
    // Se prefiere la conversación al teléfono: cuenta igual y no obliga a
    // manejar un número de móvil para algo que no lo necesita.
    afirmarIgual(
        'conv_3',
        $c::origenDelBot(['from' => '573001112233', 'conversationId' => 'conv_3']),
        'la conversación gana al teléfono'
    );
});

prueba('la cédula se encuentra aunque venga envuelta', function (): void {
    // La plataforma del bot no documenta cómo envuelve los parámetros de una
    // herramienta, y en la primera prueba real NO llegaron en la raíz. Buscar
    // solo ahí es lo que dejó el canal devolviendo 422 a todo.
    $c = 'App\\Controllers\\PreinscripcionController';

    afirmarIgual('1098765432', $c::buscarEnCuerpo(['documento' => '1098765432'], ['documento']), 'en la raíz');
    afirmarIgual('1098765432', $c::buscarEnCuerpo(['parameters' => ['documento' => '1098765432']], ['documento']), 'bajo parameters');
    afirmarIgual('1098765432', $c::buscarEnCuerpo(['tool' => ['input' => ['documento' => '1098765432']]], ['documento']), 'dos niveles abajo');
    afirmarIgual(null, $c::buscarEnCuerpo(['otra' => 'cosa'], ['documento']), 'si no está, null');
});

prueba('gana la clave más externa, no la más profunda', function (): void {
    // Si el mismo nombre aparece dentro y fuera del envoltorio, el de fuera es
    // el del llamador. Buscar en profundidad en vez de por niveles cogería el
    // de dentro, que puede ser un eco de otra cosa.
    $c = 'App\\Controllers\\PreinscripcionController';

    afirmarIgual(
        'fuera',
        $c::buscarEnCuerpo(['documento' => 'fuera', 'params' => ['documento' => 'dentro']], ['documento']),
        'el más externo gana'
    );
});

prueba('la forma del cuerpo no incluye ningún valor', function (): void {
    // Se registra para poder ajustar sin adivinar, y por eso mismo no puede
    // llevar valores: ahí viaja la cédula de alguien.
    $c = 'App\\Controllers\\PreinscripcionController';
    $forma = $c::formaDelCuerpo(['tool' => 'verificar_rufe', 'parameters' => ['documento' => '1098765432']]);

    afirmar(in_array('parameters.documento', $forma, true), 'debe describir la estructura');
    afirmar(! in_array('1098765432', $forma, true), 'NUNCA puede filtrar la cédula');
    afirmarIgual(
        0,
        count(array_filter($forma, static fn (string $x): bool => str_contains($x, '1098765432'))),
        'ningún valor puede aparecer en la forma'
    );
});

prueba('el orden de preferencia manda al buscar el origen', function (): void {
    $c = 'App\\Controllers\\PreinscripcionController';

    // conversationId antes que from, aunque from esté más arriba: cuenta igual
    // y no obliga a manejar un número de móvil.
    afirmarIgual(
        'conv_9',
        $c::buscarEnCuerpo(
            ['from' => '573001112233', 'ctx' => ['conversationId' => 'conv_9']],
            ['conversationId', 'contactId', 'sessionId', 'from', 'phone']
        ),
        'la preferencia de clave pesa más que la profundidad'
    );
});

prueba('el bot responde plano, sin la envoltura ok/data', function () use ($raiz): void {
    // El motor de flujos guarda la respuesta en una variable y la compara como
    // texto; no está documentado que sepa bajar por campos anidados.
    // `{{rufe.habilitado}}` funciona seguro, `{{rufe.data.habilitado}}` es una
    // apuesta. Y «si»/«no» en vez de true/false porque la comparación es
    // textual y cómo serializa un booleano cada versión es justo lo que rompe
    // un martes en producción.
    $php = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $desde = (int) strpos($php, 'public function verificarBot(Request $req): void');
    $hasta = (int) strpos($php, 'public static function firmaValida', $desde);
    $cuerpo = substr($php, $desde, $hasta - $desde);

    afirmarIgual(0, substr_count($cuerpo, 'Response::ok'), 'el bot no debe usar la envoltura ok/data');
    afirmar(str_contains($cuerpo, "'si' : 'no'"), 'debe responder si/no como texto');
});

prueba('el bot no revela más que la web', function () use ($raiz): void {
    // Cambia el envoltorio, nunca el contenido: si algún día alguien añadiera
    // aquí el nombre o el estado del caso, este endpoint dejaría de ser un
    // booleano y pasaría a ser un buscador de damnificados — y por WhatsApp,
    // donde cualquiera escribe desde cualquier número.
    $php = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $desde = (int) strpos($php, 'public function verificarBot(Request $req): void');
    $hasta = (int) strpos($php, 'public static function firmaValida', $desde);
    $cuerpo = substr($php, $desde, $hasta - $desde);

    afirmarIgual(1, substr_count($cuerpo, 'Response::'), 'una sola respuesta');
    afirmar(! str_contains($cuerpo, 'rufe_personas'), 'no debe consultar personas por su cuenta');
    afirmar(
        strpos($cuerpo, 'pareceCedula') < strpos($cuerpo, 'planDeLimitesFirmado'),
        'la validación del documento debe ir antes de consumir ningún límite'
    );
});

prueba('la firma se comprueba antes que nada', function () use ($raiz): void {
    // Antes de mirar el documento y antes de tocar la base: quien no trae firma
    // válida no debe poder ni provocar una consulta al censo.
    $php = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $desde = (int) strpos($php, 'public function verificarBot(Request $req): void');
    $hasta = (int) strpos($php, 'public static function firmaValida', $desde);
    $cuerpo = substr($php, $desde, $hasta - $desde);

    afirmar(
        strpos($cuerpo, 'firmaValida') < strpos($cuerpo, 'Censo::normalizar'),
        'la firma se comprueba antes de leer el documento'
    );
});

prueba('los límites del canal son más estrechos que los de la web', function (): void {
    // Una IP puede ser el celular compartido de una vereda; un número de
    // WhatsApp es una persona. Si el canal fuera más ancho, sería el camino
    // cómodo para enumerar.
    $secreto = str_repeat('1', 64);
    $plan = planVerificar($secreto, $secreto, '573183335103');

    afirmar($plan[0]['maximo'] < 15, 'por hora, más estrecho que la web');
    afirmar($plan[1]['maximo'] < 40, 'por día, más estrecho que la web');
});

grupo('Call center: qué hacer con una solicitud rechazada');

prueba('solo «no aplica» saca a una familia de la campaña', function (): void {
    // Es la decisión de fondo del módulo: qué familias vuelven al teléfono.
    // Si «faltaron datos» dejara de llamarse, una familia que ya hizo el
    // esfuerzo de llenar el formulario se quedaría fuera de la ayuda por una
    // foto — y nadie se enteraría, porque no volvería a aparecer en ninguna
    // lista.
    $motivos = App\Controllers\CallCenterController::MOTIVOS_DESCARTE;

    afirmar($motivos['DATOS_INCOMPLETOS']['llamar'], 'si le faltaron datos, hay que volver a llamarla');
    afirmar($motivos['FALTA_EVIDENCIA']['llamar'], 'si le faltó evidencia, hay que volver a llamarla');
    afirmar(! $motivos['NO_APLICA']['llamar'], '«no aplica» es el único que saca de la campaña');
});

prueba('cada motivo trae qué decirle a la persona', function (): void {
    // La operadora no puede improvisar el motivo de un rechazo por teléfono.
    foreach (App\Controllers\CallCenterController::MOTIVOS_DESCARTE as $codigo => $m) {
        afirmar(trim($m['etiqueta']) !== '', "{$codigo} sin etiqueta");
        afirmar(mb_strlen($m['decirle']) > 30, "{$codigo} no dice qué hacer con esa familia");
    }
});

grupo('El guión de la llamada');

prueba('el guión original nunca se puede perder', function (): void {
    // Vive en el código, no como una fila sembrada: la tabla puede quedar
    // vacía —alguien borra, una base nueva, una restauración a medias— y las
    // tres operadoras seguirían teniendo guión.
    afirmar(mb_strlen(App\CallCenter\Guion::PREDETERMINADO) > 1000, 'el guión original está vacío o truncado');
    afirmar(
        mb_strlen(App\CallCenter\Guion::PREDETERMINADO) <= App\CallCenter\Guion::MAX_LARGO,
        'el guión original no pasaría su propia validación de largo'
    );
});

prueba('el guión trae las salvaguardas que protegen a la ciudadanía', function (): void {
    // Tres personas hablan en nombre de la Alcaldía con familias damnificadas.
    // Estas tres frases no son adorno: son lo que separa una campaña de
    // información de una estafa telefónica indistinguible de ella.
    $g = App\CallCenter\Guion::PREDETERMINADO;

    afirmar(str_contains($g, '6025190969'), 'el guión no trae la línea de atención');
    afirmar(
        str_contains($g, 'Nunca prometa ayuda'),
        'el guión no prohíbe prometer ayudas que nadie aprobó'
    );
    afirmar(
        str_contains($g, 'Nunca pida claves'),
        'el guión no prohíbe pedir claves ni datos bancarios'
    );
    afirmar(
        str_contains($g, 'no está negada') || str_contains($g, 'no está negada: está esperando'),
        'el guión no explica que una solicitud incompleta no es una negada'
    );
});

grupo('Hasta dónde llega el operador de call center');

prueba('el operador llega EXACTAMENTE a estas rutas y a ninguna más', function () use ($raiz): void {
    // Escrita a mano, como la del inspector y por lo mismo: derivarla del
    // código haría que la prueba dijera «sí» a cualquier cosa que el código
    // dijera. Añadir una ruta sin decidir su acceso rompe aquí.
    //
    // Lo que está en juego: el operador suele ser personal contratado para la
    // campaña. Su trabajo es marcar un número y anotar qué pasó; el censo, con
    // las cédulas de todo el hogar y las fotos de las viviendas, no.
    $esperadas = [
        // Su sesión.
        'GET /auth/me',
        'POST /auth/logout',
        'POST /auth/password',
        // Información del sistema.
        'GET /acerca/sistema',
        'GET /acerca/actualizaciones',
        // Su lista de llamadas.
        'GET /callcenter/resumen',
        'GET /callcenter/hogares',
        'GET /callcenter/hogares/{id}/gestiones',
        'POST /callcenter/hogares/{id}/gestiones',
        // Le manda a un hogar el enlace del formulario por WhatsApp. Mismo rol
        // que llamar: es la misma gestión por otro canal, y quien puede hablar
        // con un ciudadano puede escribirle. No existe versión masiva.
        'POST /callcenter/hogares/{id}/whatsapp',
        // Que las tres operadoras no llamen a la misma familia. Solo mueve
        // nombres de operadora, nada del censo.
        'GET /callcenter/atenciones',
        'POST /callcenter/hogares/{id}/atencion',
        // Su guión. Lo LEE —lo tiene delante todo el turno—; reescribirlo es
        // `PUT /callcenter/guion`, que es del administrador y no está aquí.
        'GET /callcenter/guion',
    ];

    $alcanza = [];

    foreach (rutasConSusRoles($raiz) as $ruta => $roles) {
        if (in_array(Auth::OPERADOR, $roles, true)) {
            $alcanza[] = $ruta;
        }
    }

    sort($esperadas);
    sort($alcanza);

    afirmarIgual($esperadas, $alcanza);
});

prueba('el operador no lee el censo, ni el mapa, ni las inspecciones', function () use ($raiz): void {
    foreach (rutasConSusRoles($raiz) as $ruta => $roles) {
        $delCenso = str_contains($ruta, '/rufe/')
            || str_contains($ruta, '/mapa/')
            || str_contains($ruta, '/inspeccion/')
            || str_contains($ruta, '/preinscripcion/fichas')
            || str_contains($ruta, '/usuarios');

        if (! $delCenso) {
            continue;
        }

        afirmar(
            ! in_array(Auth::OPERADOR, $roles, true),
            "el operador alcanza «{$ruta}», que no es de su trabajo"
        );
    }
});

prueba('el operador no está en las listas que abren el censo', function (): void {
    // La ruta puede estar bien y aun así colarse el rol si se le añade a una
    // de estas listas «para que funcione algo».
    afirmar(! in_array(Auth::OPERADOR, Auth::LECTURA_RUFE, true), 'no puede leer el censo');
    afirmar(! in_array(Auth::OPERADOR, Auth::ESCRITURA, true), 'no puede escribir datos');
    afirmar(! in_array(Auth::OPERADOR, Auth::INSPECCION, true), 'no entra a las inspecciones');
    afirmar(in_array(Auth::OPERADOR, Auth::TODOS, true), 'sí es un usuario autenticado');
});

grupo('El buscador del call center');

prueba('una búsqueda sin resultados dice si los hay en otra lista', function () use ($raiz): void {
    // El 28 de agosto de 2026: se creó una ficha RUFE con la cédula 16844290 y
    // al buscarla en «Falta llamar» no salía nada. La ficha existía —el hogar
    // estaba en «Ya se preinscribieron», porque esa cédula ya había llenado el
    // formulario— pero la búsqueda estaba encerrada en la pestaña abierta.
    //
    // «No hay coincidencias» se lee como «esta familia no está en el censo». Es
    // la respuesta más cara que puede dar esta pantalla, y la que más se parece
    // a una respuesta buena: nadie la vuelve a comprobar.
    $php = (string) file_get_contents($raiz.'/src/Controllers/CallCenterController.php');

    afirmar(
        str_contains($php, "'en_otras_listas' => \$this->enOtrasListas("),
        'la lista ya no dice cuántos hogares hay fuera de la pestaña'
    );

    // Y que no cueste una consulta de más en cada tecleo cuando no hace falta.
    preg_match('/private function enOtrasListas\(.*?\n    \}/s', $php, $m);
    afirmar(isset($m[0]), 'no se encontró enOtrasListas()');

    afirmar(
        str_contains($m[0], "=== ''") && str_contains($m[0], "\$estado === 'todos'"),
        'enOtrasListas debe cortar sin búsqueda y en la pestaña «todos»'
    );
    afirmar(
        strpos($m[0], 'return 0;') < strpos($m[0], 'SELECT COUNT'),
        'el corte tiene que ir ANTES de la consulta, no después'
    );
});

prueba('un teléfono se encuentra escrito como sea', function (): void {
    // El fallo que esto cierra: se comparaba el texto tal cual contra la
    // columna. La ficha guarda el teléfono como lo escribió el funcionario que
    // visitó la casa —con espacios, con guiones, con +57—, y la operadora
    // escribe el que le acaban de dictar. No coincidían casi nunca.
    //
    // Peor: la lista MUESTRA el número agrupado en tres bloques, así que quien
    // copiaba lo que veía y lo pegaba tampoco encontraba nada.
    // El +57 se quita: la ficha guarda casi siempre el número corto, y quien
    // escribe el largo —como lo trae WhatsApp, o como se lo dictan— no
    // encontraba a nadie.
    foreach (['3136416997', '313 641 6997', '313-641-6997', '+57 313 641 6997', '(313) 6416997', '57 313 641 6997'] as $escrito) {
        [, $params] = App\Controllers\CallCenterController::condicionDeBusqueda($escrito);

        afirmarIgual('%3136416997%', $params['qtelefono'] ?? null, "no encontró el teléfono escrito «{$escrito}»");
    }
});

prueba('una cédula se encuentra con puntos y sin ellos', function (): void {
    // La gente dicta la cédula con puntos porque así la lee en su documento.
    foreach (['16234567', '16.234.567', '16 234 567'] as $escrito) {
        [, $params] = App\Controllers\CallCenterController::condicionDeBusqueda($escrito);

        afirmarIgual('%16234567%', $params['qdocumento'] ?? null, "no encontró la cédula escrita «{$escrito}»");
    }
});

prueba('quitar el indicativo no muerde el principio de una cédula', function (): void {
    // Se quita solo si quedan DOCE cifras empezando por 57, que es exactamente
    // un número colombiano con indicativo. Una cédula que empieza por 57 tiene
    // ocho, diez, nunca doce: tiene que llegar entera.
    [, $cedula] = App\Controllers\CallCenterController::condicionDeBusqueda('5712345');

    afirmarIgual('%5712345%', $cedula['qdocumento'], 'se comió el 57 de una cédula');

    [, $largo] = App\Controllers\CallCenterController::condicionDeBusqueda('573136416997');

    afirmarIgual('%3136416997%', $largo['qtelefono'], 'no quitó el indicativo de un teléfono');
});

prueba('se busca la cédula de cualquiera de la casa, no solo la del jefe', function (): void {
    // Quien contesta el teléfono es el hijo o la nuera. Si el buscador solo
    // mirara la cédula del jefe de hogar, la operadora concluiría que esa
    // familia no está en el censo — el error más caro de esta pantalla.
    [$sql] = App\Controllers\CallCenterController::condicionDeBusqueda('16234567');

    afirmar(str_contains($sql, 'FROM rufe_personas pb'), 'no mira a las personas del hogar');
    afirmar(str_contains($sql, 'pb.reporte_id = r.id'), 'no se limita a las personas de ESA casa');
    afirmar(str_contains($sql, 'pb.numero_documento'), 'no compara la cédula');
    afirmar(str_contains($sql, 'pb.telefono'), 'no compara el teléfono de la persona');
});

prueba('el nombre y el radicado se comparan tal cual', function (): void {
    // El radicado lleva guiones que SÍ son parte del dato: RUFE-2026-ZZ3C191Q.
    // Quitárselos rompería la única búsqueda que hoy no falla nunca.
    [, $params] = App\Controllers\CallCenterController::condicionDeBusqueda('RUFE-2026-ZZ3C191Q');

    afirmarIgual('%RUFE-2026-ZZ3C191Q%', $params['qradicado'], 'al radicado se le tocaron los guiones');

    [, $porNombre] = App\Controllers\CallCenterController::condicionDeBusqueda('Aleida Perez');

    afirmarIgual('%Aleida Perez%', $porNombre['qnombre'], 'al nombre se le tocó algo');
});

prueba('un texto sin cifras no busca por número', function (): void {
    // Buscar «Perez» dentro de los teléfonos no encuentra nada y hace trabajar
    // a la base de datos por gusto en cada tecla.
    [, $params] = App\Controllers\CallCenterController::condicionDeBusqueda('Perez');

    afirmar(! isset($params['qtelefono']), 'buscó un nombre dentro de los teléfonos');
    afirmar(! isset($params['qdocumento']), 'buscó un nombre dentro de las cédulas');
});

prueba('una o dos cifras todavía no buscan por número', function (): void {
    // Con «1», casi todos los teléfonos y todas las cédulas del censo casarían:
    // la primera tecla llenaría la pantalla de ruido.
    foreach (['1', '31'] as $corto) {
        [, $params] = App\Controllers\CallCenterController::condicionDeBusqueda($corto);

        afirmar(! isset($params['qtelefono']), "«{$corto}» no debería buscar por número todavía");
    }

    [, $tres] = App\Controllers\CallCenterController::condicionDeBusqueda('313');

    afirmarIgual('%313%', $tres['qtelefono'] ?? null, 'con tres cifras ya debería buscar por número');
});

prueba('el buscador vacío no filtra nada', function (): void {
    [$sql, $params] = App\Controllers\CallCenterController::condicionDeBusqueda('   ');

    afirmarIgual('', $sql, 'un buscador vacío estaba filtrando');
    afirmarIgual([], $params, 'un buscador vacío estaba mandando valores');
});

prueba('cada marcador del buscador se nombra una sola vez', function (): void {
    // PDO va con `ATTR_EMULATE_PREPARES => false`: las sentencias las prepara
    // MySQL, y un marcador repetido es un «Invalid parameter number» al
    // prepararla — la pantalla entera se cae, no la búsqueda sola.
    [$sql, $params] = App\Controllers\CallCenterController::condicionDeBusqueda('3136416997');

    preg_match_all('/:([a-z]+)/', $sql, $m);

    afirmarIgual(count($m[1]), count(array_unique($m[1])), 'hay un marcador repetido en la búsqueda');
    afirmarIgual(count($params), count(array_unique($m[1])), 'sobran o faltan valores para los marcadores');
});

prueba('el cruce del call center da UNA fila por hogar, pase lo que pase', function () use ($raiz): void {
    // El fallo que esto cierra: el cruce con `preinscripciones` era un JOIN
    // directo. Una persona puede pre-inscribirse más de una vez —el esquema lo
    // permite a propósito— y entonces su hogar salía DOS VECES en la lista y se
    // contaba DOS VECES en el resumen.
    //
    // Lo segundo es lo grave: la cifra de avance de la campaña se le reporta a
    // la Alcaldía y estaba inflada sin que nada lo delatara. Lo primero se notó
    // solo porque la pantalla se quedaba cargando.
    $php = (string) file_get_contents($raiz.'/src/Controllers/CallCenterController.php');

    preg_match('/private const CRUCE = \'(.*?)\';/s', $php, $m);
    afirmar(isset($m[1]), 'no se encontró la constante CRUCE');

    $cruce = $m[1];

    foreach (['preinscripciones pre', 'rufe_personas jefe', 'rufe_gestiones g'] as $tabla) {
        afirmar(str_contains($cruce, $tabla), "el cruce ya no une con {$tabla}");
    }

    // Cada tabla del cruce tiene que engancharse por `id = (SELECT … LIMIT 1)`.
    // Sin el LIMIT, una fila de más al otro lado multiplica el hogar.
    afirmarIgual(
        3,
        preg_match_all('/LIMIT 1\)/', $cruce),
        'cada tabla del cruce debe engancharse por una subconsulta con LIMIT 1'
    );

    afirmar(
        preg_match('/ON\s+pre\.documento\s*=/i', $cruce) !== 1,
        'la preinscripción vuelve a unirse por documento: eso multiplica filas'
    );
});

grupo('Rutas');

prueba('el inspector no puede aprobar una inspección', function () use ($raiz): void {
    // Sacamos la aprobación del formulario justo para que quien inspecciona no
    // se validara a sí mismo. Dejarle esta ruta lo desharía por otra puerta.
    $roles = rutasConSusRoles($raiz)['PUT /inspeccion/fichas/{id}/estado'] ?? null;

    afirmar($roles !== null, 'no se encontró la ruta de cambio de estado');
    afirmar(! in_array(Auth::INSPECTOR, $roles, true), 'el inspector NO puede decidir');
});

prueba('el inspector no ve ninguna ficha del censo ni el mapa', function () use ($raiz): void {
    foreach (rutasConSusRoles($raiz) as $ruta => $roles) {
        if (! str_contains($ruta, '/rufe/reportes') && ! str_contains($ruta, '/mapa/')) {
            continue;
        }

        afirmar(
            ! in_array(Auth::INSPECTOR, $roles, true),
            "el inspector alcanza «{$ruta}», que expone datos del censo"
        );
    }
});

prueba('todas las rutas se leyeron con una lista de roles conocida', function () use ($raiz): void {
    // Si aparece una constante nueva que `rutasConSusRoles` no sabe traducir,
    // esa ruta quedaría con la lista vacía y las pruebas de arriba dirían que
    // todo está bien sin haber mirado nada.
    $rutas = rutasConSusRoles($raiz);

    afirmar(count($rutas) >= 30, 'se leyeron solo '.count($rutas).' rutas');

    foreach ($rutas as $ruta => $roles) {
        afirmar($roles !== [], "«{$ruta}» se protege con una lista que la prueba no reconoce");
    }
});

prueba('los mismos roles en PHP, en la migración y en el navegador', function () use ($raiz): void {
    // Tres listas que tienen que decir lo mismo. Si se separan, aparece en el
    // menú un rol que la base rechaza al guardarlo, o al revés: un rol guardable
    // que el navegador no sabe dibujar y trata como si no tuviera permisos.
    // La migración se busca, no se nombra. Escrita a mano, esta prueba se
    // quedaba mirando `sistema_02_rol_inspector.sql` mientras el ENUM vigente
    // pasaba a la migración siguiente: seguía en verde comparando contra una
    // lista vieja. Se toma la ÚLTIMA de `Migrador::ARCHIVOS` que redefine `rol`,
    // que es la que manda en la base.
    $enEnum = [];
    foreach (Migrador::ARCHIVOS as $archivo) {
        $sql = (string) @file_get_contents($raiz.'/database/'.$archivo);
        if (preg_match("/MODIFY\s+COLUMN\s+rol\s+ENUM\s*\(([^)]*)\)/i", $sql, $m) === 1) {
            preg_match_all("/'{2}([A-Z_]+)'{2}/", $m[1], $enEnum);
        }
    }

    afirmar($enEnum !== [], 'ninguna migración redefine el ENUM de `rol`');

    $ts = (string) file_get_contents($raiz.'/../frontend/src/lib/navigation.ts');
    preg_match('/export const ROLES = \{(.*?)\} as const;/s', $ts, $m2);
    preg_match_all("/(\w+):\s*'([A-Z_]+)'/", $m2[1] ?? '', $enTs);

    $php = Auth::ROLES;
    sort($php);

    $enum = $enEnum[1];
    sort($enum);

    $navegador = $enTs[2];
    sort($navegador);

    afirmarIgual($php, $enum, 'el ENUM de la migración no coincide con Auth::ROLES');
    afirmarIgual($php, $navegador, 'navigation.ts no coincide con Auth::ROLES');
});

prueba('cada rol tiene etiqueta y capacidades declaradas', function (): void {
    // Un rol sin descripción se cuela en el selector de usuarios sin decir qué
    // hace, y sin capacidades el frontend le esconde todo sin explicar por qué.
    foreach (Auth::ROLES as $rol) {
        afirmar(isset(Auth::DESCRIPCION_ROLES[$rol]), "«{$rol}» no tiene etiqueta ni descripción");
        afirmar(Auth::capacidades($rol) !== [], "«{$rol}» no declara ninguna capacidad");
    }
});

prueba('una ruta literal se registra antes que la que lleva un comodín', function () use ($raiz): void {
    // El router recorre las rutas EN ORDEN y se queda con la primera que casa.
    // Si `/{id}` se registrara antes que `/orden`, reordenar el catálogo
    // acabaría intentando editar una categoría con id «orden» —y el fallo se
    // vería solo al arrastrar una fila, no al desplegar.
    $php = (string) file_get_contents($raiz.'/public/index.php');

    $posOrden = strpos($php, "'/admin/categorias-video/orden'");
    $posId = strpos($php, "'/admin/categorias-video/{id}'");

    afirmar($posOrden !== false, 'no se encontró la ruta de reordenar');
    afirmar($posId !== false, 'no se encontró la ruta de editar');
    afirmar($posOrden < $posId, 'la ruta literal debe registrarse antes que la del comodín');
});

grupo('Rutas › qué queda abierto a internet');

/**
 * Las rutas registradas SIN lista de roles, es decir, públicas.
 *
 * @return list<string>
 */
function rutasPublicas(string $raiz): array
{
    $php = (string) file_get_contents($raiz.'/public/index.php');

    preg_match_all(
        "/\\\$router->(get|post|put|delete)\\(\\s*'([^']+)'(.*?)\\);/s",
        $php,
        $encontradas,
        PREG_SET_ORDER
    );

    $salida = [];

    foreach ($encontradas as $r) {
        // Con lista de roles al final, no es pública.
        if (preg_match('/,\s*([A-Za-z_:$\\\\]+)\s*$/', trim($r[3])) === 1) {
            continue;
        }

        $salida[] = strtoupper($r[1]).' '.$r[2];
    }

    sort($salida);

    return $salida;
}

prueba('el buscador de la bandeja ciudadana no repite marcadores', function () use ($raiz): void {
    // Con preparadas nativas, un marcador repetido es «Invalid parameter
    // number» AL PREPARAR: el buscador respondería error 500 con cualquier
    // texto y no funcionaría nunca. Es exactamente como estuvo roto el buscador
    // del censo durante semanas, así que aquí se vigila desde el primer día.
    $metodo = new ReflectionMethod(App\Controllers\PreinscripcionController::class, 'busqueda');
    $metodo->setAccessible(true);

    foreach (['Juan Pérez', '16844290', 'Cra 78', 'juan 3126058353'] as $texto) {
        [$sql, $params] = $metodo->invoke(null, $texto);

        preg_match_all('/:([a-z][a-z0-9_]*)/i', $sql, $m);

        foreach (array_count_values($m[1]) as $nombre => $veces) {
            afirmarIgual(1, $veces, "el marcador «{$nombre}» aparece {$veces} veces con «{$texto}»");
        }

        $enSql = array_keys(array_count_values($m[1]));
        $enParams = array_keys($params);
        sort($enSql);
        sort($enParams);
        afirmarIgual($enSql, $enParams, "descuadre con «{$texto}»");
    }
});

prueba('la bandeja ciudadana busca la cédula exacta, no por trozos', function (): void {
    // Un documento parcial devolvería decenas de familias ajenas y convertiría
    // el buscador en una forma de pasear por el censo de damnificados.
    $metodo = new ReflectionMethod(App\Controllers\PreinscripcionController::class, 'busqueda');
    $metodo->setAccessible(true);

    [$sql, $params] = $metodo->invoke(null, '16844290');

    afirmar(str_contains($sql, 'documento = :doc'), 'la cédula debe compararse exacta');
    afirmarIgual('16844290', $params['doc']);

    // Sin texto no hay condición: la bandeja entera se lista igual que antes.
    afirmarIgual(['', []], $metodo->invoke(null, '   '));
});

prueba('solo estas rutas se sirven sin sesión', function () use ($raiz): void {
    // La lista va escrita a mano porque cada entrada amplía lo que un
    // desconocido puede tocar. Este sistema declaró desde el principio que todo
    // exige sesión; la pre-inscripción es la excepción deliberada, y tiene que
    // seguir siéndolo. Si aparece una ruta más, esto falla y obliga a pensarlo.
    afirmarIgual([
        'DELETE /preinscripcion/cargas/{carga}/archivos/{id}',
        'GET /health',
        'GET /preinscripcion/catalogos',
        'POST /auth/login',
        'POST /preinscripcion',
        'POST /preinscripcion/cargas',
        'POST /preinscripcion/cargas/{carga}/archivos',
        // El video se sube por trozos: el tope por archivo del hosting es 1 MiB
        // y uno de 30 segundos pesa unos 3 MB, así que no cabe de una vez.
        'POST /preinscripcion/cargas/{carga}/videos',
        'POST /preinscripcion/cargas/{carga}/videos/{id}/trozos',
        // Los datos que el censo ya tiene de ese hogar. Es la MÁS delicada de
        // todas: devuelve nombre, teléfono, dirección y quién vive en la casa.
        // Está aquí porque el ciudadano no tiene sesión, y lo que la sostiene no
        // es la sesión sino el coste: exige haber subido la foto de la cédula en
        // esa misma carga, y esa imagen queda guardada atada al intento. Ver
        // PreinscripcionController::datosCenso.
        'POST /preinscripcion/datos-censo',
        // La puerta del formulario: responde sí o no sobre una cédula, porque
        // la pre-inscripción continúa el censo y no es un formulario abierto.
        // Es la más delicada de la lista —mirada de cerca, dice si alguien está
        // en la lista de damnificados—, y por eso devuelve un booleano y nada
        // más, va por POST y lleva doble límite de tasa. Ver Preinscripcion\Censo.
        'POST /preinscripcion/verificacion',
        // La misma consulta para el bot de WhatsApp. Aparece en esta lista
        // porque el router la sirve sin sesión, pero abierta no está: sin
        // `rufe.bot_secreto` en config.php responde 404, y con él exige una
        // firma HMAC del cuerpo. Responde exactamente lo mismo que la de
        // arriba —si esa cédula está en el censo, y nada más— solo que plano,
        // porque el motor de flujos del bot compara variables como texto.
        'POST /preinscripcion/verificacion-bot',
        // La vía corta para quien la puerta de arriba rechazó. Mismas defensas
        // que /preinscripcion: límite de tasa, trampa antirrobot e idempotencia
        // por envio_id. Ver SinCensoController.
        'POST /sin-censo',
    ], rutasPublicas($raiz));
});

prueba('la puerta del censo acepta la cédula como la escribe la gente', function (): void {
    afirmarIgual('1144062345', App\Preinscripcion\Censo::normalizar('1.144.062.345'));
    afirmarIgual('16285943', App\Preinscripcion\Censo::normalizar(' 16 285 943 '));
});

prueba('la puerta del censo exige una cédula plausible', function (): void {
    // Los mismos límites que el validador de la pre-inscripción. Si discreparan,
    // la puerta dejaría pasar cédulas que el paso 1 rechaza un momento después.
    afirmar(App\Preinscripcion\Censo::pareceCedula('1144062345'), 'una cédula normal debería pasar');
    afirmar(! App\Preinscripcion\Censo::pareceCedula('1234'), 'cuatro dígitos no son una cédula');
    afirmar(! App\Preinscripcion\Censo::pareceCedula('1234567890123456'), 'dieciséis dígitos tampoco');
    afirmar(! App\Preinscripcion\Censo::pareceCedula(''), 'la cadena vacía tampoco');
});

prueba('el envío ciudadano vuelve a comprobar el censo, no se fía de la pantalla', function () use ($raiz): void {
    // La ruta es pública: saltarse el navegador y hacer el POST a mano es
    // trivial. Si esta comprobación desapareciera del controlador, la puerta se
    // quedaría en decoración y cualquiera podría pre-inscribirse.
    $php = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');

    afirmar(
        str_contains($php, 'Censo::estaInscrito($datos[\'documento\'])'),
        'crear() ya no comprueba la cédula contra el censo'
    );
});

prueba('cada señal de daño tiene su video que pedir', function () use ($raiz): void {
    // El formulario le pide a la persona un video POR CADA daño que marca. Una
    // señal sin categoría sembrada es un daño que se puede marcar y del que
    // nunca se pide evidencia — y nadie lo notaría hasta que quien revisa
    // echara de menos el video justo del daño que importaba.
    $sql = (string) file_get_contents($raiz.'/database/preinscripcion_04_video_por_dano.sql');

    foreach (App\Preinscripcion\Senales::codigos() as $codigo) {
        afirmar(
            str_contains($sql, "'{$codigo}'"),
            "la señal «{$codigo}» no tiene categoría de video sembrada"
        );
    }
});

prueba('ningún video sembrado pasa de dos minutos', function () use ($raiz): void {
    // Dos minutos a 480p son unos 12 MB: veinte trozos de subida. El triple
    // serían sesenta, y basta con que la señal se caiga en el cuarenta para que
    // la persona abandone. El tope del servidor por video está calculado sobre
    // esta duración.
    $sql = (string) file_get_contents($raiz.'/database/preinscripcion_04_video_por_dano.sql');

    preg_match_all('/,\s*(\d+),\s*(\d+),\s*1\)/', $sql, $m, PREG_SET_ORDER);
    afirmar($m !== [], 'no se encontró ninguna fila sembrada');

    foreach ($m as $fila) {
        afirmar((int) $fila[2] <= 120, "un video sembrado dura {$fila[2]} s");
        afirmar((int) $fila[1] <= (int) $fila[2], 'el mínimo supera al máximo');
    }
});

prueba('el cupo de fotos de una solicitud ciudadana es acotado', function (): void {
    // Es una ruta de subida SIN sesión: cada foto de más es almacenamiento que
    // cualquiera en internet puede consumir. Diez del daño desde el sismo —eran
    // cuatro—, y la cédula sigue siendo UNA.
    afirmarIgual(1, App\Rufe\Catalogos::TIPOS_EVIDENCIA['PRE_CEDULA']['maximo']);
    afirmarIgual(10, App\Rufe\Catalogos::TIPOS_EVIDENCIA['PRE_DANO']['maximo']);
});

prueba('las doce fotos de una pre-inscripción caben en el cupo de la carga', function (): void {
    // Doce archivos en el límite de 1 MiB cada uno: diez daños y las dos caras
    // de la cédula. Si no cupieran, la última se rechazaría con un mensaje que
    // habla de megabytes, después de que la persona tomara las diez fotos.
    $fotos = App\Rufe\Catalogos::TIPOS_EVIDENCIA['PRE_DANO']['maximo']
           + App\Rufe\Catalogos::TIPOS_EVIDENCIA['PRE_CEDULA']['maximo']
           + App\Rufe\Catalogos::TIPOS_EVIDENCIA['PRE_CEDULA_REVERSO']['maximo'];

    afirmar(
        $fotos * App\Rufe\Catalogos::MAX_BYTES_ARCHIVO <= App\Rufe\Catalogos::MAX_BYTES_CARGA,
        "{$fotos} fotos en el límite no caben en el cupo de la carga"
    );
});

prueba('sin sesión solo se pueden subir los tres tipos de la pre-inscripción', function (): void {
    // El tipo llega en la petición. Sin lista blanca, quien quisiera podría
    // pedir el cupo de diez fotos del registro fotográfico de una inspección.
    //
    // Las dos caras de la cédula son tipos DISTINTOS. Con dos fotos del mismo
    // tipo nadie puede saber si la persona subió las dos caras o dos veces la
    // misma, que es lo que pasa cuando se pide «suba 2 fotos» sin decir cuál va
    // en cada sitio.
    afirmarIgual(
        ['PRE_CEDULA', 'PRE_CEDULA_REVERSO', 'PRE_DANO'],
        App\Rufe\Catalogos::TIPOS_PREINSCRIPCION
    );

    foreach (App\Rufe\Catalogos::TIPOS_PREINSCRIPCION as $t) {
        afirmar(
            isset(App\Rufe\Catalogos::TIPOS_EVIDENCIA[$t]),
            "«{$t}» no existe como tipo de evidencia"
        );
    }

    foreach (['DOCUMENTO', 'DANO', 'INSPECCION'] as $t) {
        afirmar(
            ! in_array($t, App\Rufe\Catalogos::TIPOS_PREINSCRIPCION, true),
            "«{$t}» no puede subirse sin sesión"
        );
    }
});

prueba('ninguna ruta pública devuelve pre-inscripciones', function () use ($raiz): void {
    // Consultar por radicado sin sesión sería un buscador de damnificados para
    // cualquiera que probara combinaciones.
    foreach (rutasPublicas($raiz) as $ruta) {
        afirmar(
            ! str_starts_with($ruta, 'GET /preinscripcion/fichas'),
            "«{$ruta}» expondría solicitudes ciudadanas sin sesión"
        );
    }
});

prueba('los topes del video están acotados', function (): void {
    // Es una ruta pública que escribe archivos en disco. Sin topes es
    // alojamiento gratuito para cualquiera, y el disco lo comparten todos los
    // sitios de la Alcaldía.
    afirmarIgual(1048576, App\Preinscripcion\Videos::BYTES_TROZO, 'el trozo debe caber en una petición');

    // Veinte MiB por video: dos minutos a 480p y 800 kbps son unos 12 MB, y el
    // resto es margen para el teléfono que grabe más gordo. Antes eran 8 MiB
    // con un techo de 30 segundos de grabación.
    afirmar(App\Preinscripcion\Videos::MAX_BYTES_VIDEO <= 20 * 1048576, 'el tope por video se pasó');
    afirmar(App\Preinscripcion\Videos::MAX_VIDEOS_POR_CARGA <= 10, 'demasiados videos por solicitud');

    // El caso que importa: lo peor que puede subir una sola solicitud, que es
    // alguien que marca los ocho daños y graba los ocho videos en el límite.
    // El caso normal son dos o tres daños, unos 12 MB cada uno. El disco lo
    // comparten todos los sitios de la Alcaldía, así que este número no puede
    // crecer sin que alguien lo mire.
    $peor = App\Preinscripcion\Videos::MAX_BYTES_VIDEO * App\Preinscripcion\Videos::MAX_VIDEOS_POR_CARGA;
    afirmar($peor <= 160 * 1048576, 'una sola solicitud podría subir '.round($peor / 1048576).' MiB');
});

prueba('el trozo cabe en el tope por archivo del hosting', function (): void {
    // Si el trozo fuera mayor que lo que admite una petición, la subida
    // fallaría siempre y solo se vería con un video real.
    afirmar(
        App\Preinscripcion\Videos::BYTES_TROZO <= App\Rufe\Catalogos::MAX_BYTES_ARCHIVO,
        'el trozo no cabe en una petición'
    );
});

grupo('Pre-inscripción › validación');

function erroresPre(array $entrada): array
{
    return App\Preinscripcion\Validador::revisar($entrada)['errores'];
}

function datosPre(array $entrada): array
{
    return App\Preinscripcion\Validador::revisar($entrada)['datos'];
}

function preBase(array $cambios = []): array
{
    return array_replace([
        'nombre_completo' => 'Pedro Antonio Pérez Gómez',
        'documento' => '16.234.567',
        'telefono' => '315 123 4567',
        'direccion' => 'Carrera 11 # 8-26',
        'zona' => 'URBANA',
        'autoriza_datos' => true,
        'aviso_version' => App\Rufe\Catalogos::AVISO_VERSION,
    ], $cambios);
}

prueba('una solicitud mínima y completa pasa', function (): void {
    afirmarIgual([], erroresPre(preBase()));
});

prueba('la cédula y el teléfono se guardan sin puntos ni espacios', function (): void {
    // La gente los escribe como los lee en su documento. Normalizar aquí evita
    // que el mismo hogar quede con dos escrituras distintas.
    $d = datosPre(preBase());

    afirmarIgual('16234567', $d['documento']);
    afirmarIgual('3151234567', $d['telefono']);
});

prueba('sin autorización de datos NO se guarda, aunque el navegador insista', function (): void {
    // Es el ciudadano entregando sus propios datos sin nadie delante que se lo
    // explique. La prueba de que aceptó no puede depender del navegador.
    $e = erroresPre(preBase(['autoriza_datos' => false]));

    afirmar(isset($e['autoriza_datos']), 'debe exigir la autorización');
});

prueba('una versión de aviso desconocida se rechaza', function (): void {
    // Lo que prueba qué aceptó el ciudadano es la versión guardada. Si llega una
    // que no existe, no hay nada que probar.
    $e = erroresPre(preBase(['aviso_version' => 'inventada-v9']));

    afirmar(isset($e['aviso_version']), 'debe exigir una versión conocida');
});

prueba('la ubicación es opcional y una imposible se descarta sin tumbar la solicitud', function (): void {
    // Mucha gente rechaza el permiso de ubicación. Perder la solicitud por eso
    // sería absurdo.
    afirmarIgual([], erroresPre(preBase()));
    afirmarIgual(null, datosPre(preBase())['latitud']);

    $d = datosPre(preBase(['latitud' => 40.4168, 'longitud' => -3.7038]));
    afirmarIgual(null, $d['latitud'], 'Madrid no es Jamundí');

    $d = datosPre(preBase(['latitud' => 3.2611234, 'longitud' => -76.5412345, 'precision_m' => 12]));
    afirmarIgual(3.2611234, $d['latitud']);
    afirmarIgual(12, $d['precision_m']);
});

prueba('no se piden datos sensibles', function (): void {
    // Género y pertenencia étnica son datos sensibles del art. 5 de la Ley 1581
    // y los levanta el funcionario en la visita, con el aviso explicado de viva
    // voz. Si alguien los mandara igual, no deben acabar guardados.
    $d = datosPre(preBase(['genero' => 'M', 'pertenencia_etnica' => 'NINGUNA']));

    afirmar(! isset($d['genero']), 'el género no debe guardarse aquí');
    afirmar(! isset($d['pertenencia_etnica']), 'la pertenencia étnica no debe guardarse aquí');
});

prueba('la zona urbana o rural es obligatoria', function (): void {
    // Antes se DEDUCÍA de si venía corregimiento, y la deducción era falsa:
    // quien vive en el campo y no sabe a qué corregimiento pertenece su vereda
    // entraba al sistema como urbano, y la visita salía al pueblo.
    $e = erroresPre(preBase(['zona' => '']));
    afirmar(isset($e['zona']), 'debe exigir la zona');

    $e = erroresPre(preBase(['zona' => 'SEMIRURAL']));
    afirmar(isset($e['zona']), 'no debe aceptar una zona inventada');

    afirmarIgual('RURAL', datosPre(preBase(['zona' => 'rural']))['zona']);
});

prueba('en zona urbana el corregimiento se descarta en vez de rechazarse', function (): void {
    // Si alguien eligió corregimiento y después corrigió la zona, ese dato
    // sobrante no puede costarle el envío.
    $d = datosPre(preBase([
        'zona' => 'URBANA',
        'corregimiento' => App\Rufe\Catalogos::CORREGIMIENTOS[0],
    ]));

    afirmarIgual([], erroresPre(preBase([
        'zona' => 'URBANA',
        'corregimiento' => App\Rufe\Catalogos::CORREGIMIENTOS[0],
    ])));
    afirmarIgual(null, $d['corregimiento'], 'en la cabecera no hay corregimiento');
});

prueba('la dirección puede ser una referencia, no una nomenclatura', function (): void {
    // Media zona rural de Jamundí no tiene calle y número. «La casa azul
    // pasando el puente» es una dirección perfectamente válida para quien va a
    // ir a buscarla, y exigir formato dejaría fuera justo a quien más lo
    // necesita.
    afirmarIgual([], erroresPre(preBase([
        'zona' => 'RURAL',
        'direccion' => 'La casa azul pasando el puente de La Liberia, al lado de la tienda',
    ])));
});

prueba('ninguna señal de daño es obligatoria', function (): void {
    // Quien tiene la casa partida por la mitad puede no reconocerse en ninguno
    // de los ocho dibujos. Negarle el turno por eso sería el error que este
    // formulario existe para no cometer.
    afirmarIgual([], erroresPre(preBase()));
    afirmarIgual([], datosPre(preBase())['senales']);
});

prueba('una señal inventada se rechaza y no se guarda a medias', function (): void {
    // La ruta es pública: cualquiera puede mandar lo que quiera contra ella.
    $e = erroresPre(preBase(['senales' => ['PARED_AGRIETADA', 'CASA_EMBRUJADA']]));

    afirmar(isset($e['senales']), 'debe rechazar el código desconocido');
    afirmarIgual([], datosPre(preBase(['senales' => ['PARED_AGRIETADA', 'CASA_EMBRUJADA']]))['senales']);
});

prueba('la misma señal marcada dos veces se guarda una sola vez', function (): void {
    // La tabla tiene un único por (solicitud, código): sin limpiar aquí, el
    // INSERT reventaría con un error que el ciudadano no sabría interpretar.
    $d = datosPre(preBase(['senales' => ['PARED_AGRIETADA', 'PARED_AGRIETADA', 'TECHO_CAIDO']]));

    afirmarIgual(['PARED_AGRIETADA', 'TECHO_CAIDO'], $d['senales']);
});

prueba('cada señal apunta a un elemento que el formato de inspección conoce', function (): void {
    // Es lo que hace útil la conversión a inspección: lo que marcó el ciudadano
    // le dice al profesional qué filas del numeral 5.4 mirar primero. Si una
    // señal apuntara a un elemento inventado, ese puente se rompería en
    // silencio y nadie se enteraría.
    $delFormato = App\Preinscripcion\Senales::elementosDelFormato();

    foreach (App\Preinscripcion\Senales::CATALOGO as $senal) {
        afirmar(
            in_array($senal['elemento'], $delFormato, true),
            "la señal {$senal['codigo']} apunta a un elemento inexistente: {$senal['elemento']}"
        );
    }
});

prueba('las señales cubren todos los grupos de elementos evaluables', function (): void {
    // Si el formato evalúa un elemento y ninguna señal apunta a él, hay un daño
    // que el ciudadano no tiene cómo reportar. Muros y entrepisos de madera
    // quedan cubiertos por sus equivalentes de mampostería, que es lo que la
    // persona ve: una pared es una pared.
    $equivalentes = ['MUROS_MADERA' => 'MUROS_CARGA', 'ENTREPISOS' => 'PLACA_PISO'];
    $apuntados = App\Preinscripcion\Senales::elementosApuntados(
        App\Preinscripcion\Senales::codigos()
    );

    foreach (App\Preinscripcion\Senales::elementosDelFormato() as $elemento) {
        // Los muros divisorios no deciden nada estructural y pedirle al
        // ciudadano que los distinga de los de carga sería pedirle criterio
        // técnico.
        if ($elemento === 'MUROS_DIVISORIOS') {
            continue;
        }

        $buscado = $equivalentes[$elemento] ?? $elemento;
        afirmar(
            in_array($buscado, $apuntados, true),
            "ninguna señal permite reportar daño en {$elemento}"
        );
    }
});

prueba('cada señal resuelve su dibujo, y una desconocida no revienta', function (): void {
    // El dibujo NO se guarda con la solicitud: se resuelve contra el catálogo de
    // hoy, para que mejorar una figura la mejore también en los expedientes
    // viejos. La etiqueta sí queda congelada, que es la que prueba qué se le
    // mostró a la persona.
    foreach (App\Preinscripcion\Senales::CATALOGO as $senal) {
        afirmarIgual($senal['icono'], App\Preinscripcion\Senales::icono($senal['codigo']));
    }

    // Un código retirado del catálogo sigue existiendo en las solicitudes ya
    // enviadas. Devolver cadena vacía hace que se dibuje la marca de «señal sin
    // dibujo», que se ve y se corrige; reventar dejaría la bandeja en blanco.
    afirmarIgual('', App\Preinscripcion\Senales::icono('SENAL_RETIRADA'));
});

prueba('el catálogo público de señales no revela a qué elemento apunta cada una', function (): void {
    // Al ciudadano no le dice nada, y publicarlo solo invita a deducir desde
    // fuera cómo se clasificará técnicamente su caso.
    foreach (App\Preinscripcion\Senales::paraApi() as $senal) {
        afirmar(! isset($senal['elemento']), 'el elemento no debe salir al público');
        afirmar($senal['icono'] !== '', 'cada señal necesita su dibujo');
    }
});

prueba('ninguna función obsoleta escribe avisos dentro de las respuestas', function () use ($raiz): void {
    // Ya pasó dos veces en este módulo: PHP imprime el aviso DENTRO del cuerpo,
    // así que el JSON llega precedido de «<br /><b>Deprecated</b>» y el
    // navegador no puede leerlo. En producción los avisos están apagados y no
    // se ve; en el equipo de quien desarrolla, subir una foto simplemente
    // fallaba.
    //
    // La lista es corta a propósito: solo lo que este código usa de verdad y
    // que PHP ya marcó como obsoleto. No pretende ser un analizador estático.
    //
    // Se busca la LLAMADA —el paréntesis seguido de una variable— y no el
    // nombre suelto: la primera versión de esta prueba saltaba con el comentario
    // que explica por qué la función ya no se usa, que es exactamente el texto
    // que quiere uno conservar.
    $obsoletas = ['finfo_close($'];

    foreach (glob($raiz.'/src/*/*.php') as $archivo) {
        $fuente = (string) file_get_contents($archivo);

        foreach ($obsoletas as $llamada) {
            afirmar(
                ! str_contains($fuente, $llamada),
                basename($archivo).' llama a '.rtrim($llamada, '($').'(), obsoleta desde PHP 8.5'
            );
        }
    }
});

prueba('abrir una carga limpia también los videos huérfanos', function () use ($raiz): void {
    // Estaba solo en `iniciarVideo`, y eso los dejaba casi sin limpiar: una
    // carga se abre cada vez que alguien entra al formulario, pero un video se
    // empieza a subir en muy pocas de esas visitas. En producción se vio claro:
    // las fotos huérfanas desaparecían solas y los videos —que pesan mil veces
    // más— seguían en el disco un día después.
    $fuente = (string) file_get_contents($raiz.'/src/Controllers/PreinscripcionController.php');
    $metodo = substr($fuente, strpos($fuente, 'public function abrirCarga('));
    $metodo = substr($metodo, 0, strpos($metodo, 'public function subirArchivo('));

    afirmar(str_contains($metodo, 'Archivos::purgarCargasCaducadas('), 'debe purgar las fotos');
    afirmar(str_contains($metodo, 'Videos::purgarCaducados('), 'debe purgar también los videos');
});

prueba('un video sale con su tipo, no como un archivo cualquiera', function (): void {
    // Salía como `application/octet-stream`, y con `X-Content-Type-Options:
    // nosniff` puesto —que sí queremos— el navegador se niega a decodificarlo.
    // El reproductor mostraba un recuadro negro y nadie podía ver lo que el
    // ciudadano grabó.
    afirmarIgual('video/mp4', App\Rufe\Archivos::tipoDeSalida('mp4'));
    afirmarIgual('video/webm', App\Rufe\Archivos::tipoDeSalida('webm'));
    afirmarIgual('image/webp', App\Rufe\Archivos::tipoDeSalida('webp'));
});

prueba('todo formato que se puede subir se puede servir', function (): void {
    // Son dos listas: una decide qué entra y otra con qué tipo sale. Añadir un
    // formato a la primera y olvidar la segunda no rompe la subida —el video se
    // guarda perfectamente— y solo se nota cuando alguien intenta verlo.
    foreach (App\Preinscripcion\Videos::FORMATOS as $mime => $extension) {
        afirmarIgual(
            $mime,
            App\Rufe\Archivos::tipoDeSalida($extension),
            "el formato .{$extension} se puede subir pero no servir"
        );
    }
});

prueba('lo que no se reconoce sale como archivo opaco', function (): void {
    // El caso por defecto tiene que seguir siendo el inofensivo: nada de
    // devolver text/html para una extensión inesperada.
    afirmarIgual('application/octet-stream', App\Rufe\Archivos::tipoDeSalida('svg'));
    afirmarIgual('application/octet-stream', App\Rufe\Archivos::tipoDeSalida('html'));
});

prueba('fotos y videos de una solicitud caen en la misma carpeta', function (): void {
    // Un expediente repartido en dos sitios es un expediente que alguien archiva
    // a medias. Las dos rutas salen del mismo cálculo justamente para que no
    // puedan separarse.
    $carpeta = App\Rufe\Archivos::carpetaDe('preinscripcion', 7);

    afirmarIgual('preinscripcion/'.date('Y/m').'/7', $carpeta);
});

prueba('la carpeta definitiva nunca es la temporal', function (): void {
    // Los videos vivían en `temporal/` incluso después de aceptarse la
    // solicitud. No se perdía nada porque la purga solo borra lo que no tiene
    // dueño, pero bastaba con que alguien limpiara una carpeta llamada
    // «temporal» —cosa que el nombre invita a hacer— para perder los videos de
    // expedientes reales.
    foreach (['preinscripcion', 'rufe', 'inspeccion'] as $base) {
        afirmar(
            ! str_contains(App\Rufe\Archivos::carpetaDe($base, 1), 'temporal'),
            "la carpeta de {$base} no puede ser la temporal"
        );
    }
});

prueba('la bandeja recoloca los videos que quedaron en temporal', function (): void {
    // No hay consola ni tareas programadas en este hosting: el mantenimiento va
    // montado en peticiones que ya ocurren, igual que la purga de cargas
    // caducadas. Si alguien quita esta llamada, los videos antiguos se quedan
    // en `temporal/` para siempre y nada falla hasta que se limpie la carpeta.
    $fuente = file_get_contents(__DIR__.'/../src/Controllers/PreinscripcionController.php');
    $desde = strpos($fuente, 'public function listar(');
    afirmar($desde !== false, 'no se encontró listar()');

    // Hasta la siguiente declaración de método, sea pública o privada: buscar un
    // nombre concreto ataría la prueba al orden en que están escritos.
    $siguiente = preg_match(
        '/\n    (?:public|private|protected) function /',
        $fuente,
        $m,
        PREG_OFFSET_CAPTURE,
        $desde + 10
    ) === 1 ? $m[0][1] : strlen($fuente);

    afirmar(
        str_contains(substr($fuente, $desde, $siguiente - $desde), 'Videos::reubicarPendientes('),
        'listar() debe recolocar los videos pendientes'
    );
});

prueba('un reenvío no puede tirar los archivos que trae', function (): void {
    // Los dos atajos de `crear()` —el reintento sin señal y la solicitud
    // duplicada— devolvían el radicado y se marchaban sin tocar la carga: las
    // fotos y videos recién subidos se quedaban huérfanos y la purga se los
    // llevaba dos horas después.
    //
    // El caso que lo hace grave: una familia vuelve a inscribirse porque esta
    // vez SÍ consiguió grabar el video del daño. El servidor le contestaba «su
    // vivienda ya estaba registrada» —con razón— y le tiraba el video.
    $fuente = file_get_contents(__DIR__.'/../src/Controllers/PreinscripcionController.php');
    $crear = substr($fuente, strpos($fuente, 'public function crear('));
    $crear = substr($crear, 0, strpos($crear, 'private function adjuntarA('));

    afirmarIgual(
        2,
        substr_count($crear, '$this->adjuntarA('),
        'los dos atajos de crear() deben adoptar la carga antes de responder'
    );
});

prueba('un video que se cortó a mitad deja constancia, no desaparece', function (): void {
    // Un archivo al que le faltan trozos no lo abre ningún reproductor, así que
    // se borra. Pero antes se borraba EN SILENCIO: quien revisaba la solicitud
    // veía una ficha sin videos, exactamente igual que si la persona no hubiera
    // grabado ninguno, y nunca se le ocurriría llamar para pedirlo otra vez.
    $fuente = file_get_contents(__DIR__.'/../src/Preinscripcion/Videos.php');
    $adoptar = substr($fuente, strpos($fuente, 'public static function adoptar('));

    afirmar(
        str_contains($adoptar, 'preinscripcion_historial'),
        'descartar un video incompleto debe quedar escrito en el historial'
    );
});

prueba('el radicado ciudadano se distingue de los otros dos', function (): void {
    $r = App\Preinscripcion\Radicado::componer(2026);

    afirmar(str_starts_with($r, 'PRE-2026-'), "el radicado no lleva el prefijo esperado: {$r}");
    afirmar(App\Preinscripcion\Radicado::esValido($r), 'debería validarse a sí mismo');
    afirmar(! App\Preinscripcion\Radicado::esValido('RUFE-2026-ABCDEFGH'), 'no debe aceptar el del censo');
});

prueba('la huella junta la misma vivienda del mismo solicitante', function (): void {
    $a = App\Preinscripcion\Radicado::huella('Carrera 11 # 8-26', '16234567');
    $b = App\Preinscripcion\Radicado::huella('  carrera   11 # 8-26 ', '16234567');
    $c = App\Preinscripcion\Radicado::huella('Carrera 11 # 8-26', '99999999');

    afirmarIgual($a, $b, 'la misma casa y persona deben coincidir');
    afirmar($a !== $c, 'otro solicitante es otra solicitud');
});

grupo('Sin censo › validación');

function erroresSC(array $entrada): array
{
    return App\SinCenso\Validador::revisar($entrada)['errores'];
}

function datosSC(array $entrada): array
{
    return App\SinCenso\Validador::revisar($entrada)['datos'];
}

function scBase(array $cambios = []): array
{
    return array_replace([
        'nombres' => 'Ana Lucía',
        'apellidos' => 'Torres',
        'documento' => '16.234.567',
        'telefono' => '315 123 4567',
        'zona' => 'URBANO',
        'direccion' => 'Cerca al parque principal',
        'autoriza_datos' => true,
        'aviso_version' => App\Rufe\Catalogos::AVISO_VERSION,
    ], $cambios);
}

prueba('una solicitud mínima y completa pasa', function (): void {
    afirmarIgual([], erroresSC(scBase()));
});

prueba('nombres y apellidos van por separado, igual que en rufe_personas', function (): void {
    // Misma regla que Rufe\Validador::persona(): así, si la solicitud se
    // convierte, el jefe de hogar se precarga tal cual, sin adivinar dónde
    // termina el nombre y empieza el apellido.
    afirmar(isset(erroresSC(scBase(['nombres' => '']))['nombres']), 'debe exigir los nombres');
    afirmar(isset(erroresSC(scBase(['apellidos' => '']))['apellidos']), 'debe exigir los apellidos');
    afirmar(isset(erroresSC(scBase(['nombres' => 'A']))['nombres']), 'un solo caracter no basta');
    afirmar(
        isset(erroresSC(scBase(['nombres' => 'Ana123']))['nombres']),
        'no debe aceptar dígitos en el nombre'
    );

    $d = datosSC(scBase(['nombres' => 'María José', 'apellidos' => "O'Higgins"]));
    afirmarIgual('María José', $d['nombres']);
    afirmarIgual("O'Higgins", $d['apellidos']);
});

prueba('el teléfono se guarda sin puntos ni espacios', function (): void {
    afirmarIgual('3151234567', datosSC(scBase())['telefono']);
});

prueba('la cédula es de referencia: si no parece una, se guarda como null y no tumba el envío', function (): void {
    // Aquí no hay censo con qué compararla, y quien la escribe puede estar
    // recordándola mal. Exigirle una forma plausible no aporta nada.
    afirmarIgual([], erroresSC(scBase(['documento' => 'no recuerdo'])));
    afirmarIgual(null, datosSC(scBase(['documento' => 'no recuerdo']))['documento']);
    afirmarIgual('16234567', datosSC(scBase())['documento']);
});

prueba('sin autorización de datos NO se guarda', function (): void {
    afirmar(isset(erroresSC(scBase(['autoriza_datos' => false]))['autoriza_datos']), 'debe exigir la autorización');
});

prueba('una versión de aviso desconocida se rechaza', function (): void {
    afirmar(isset(erroresSC(scBase(['aviso_version' => 'inventada-v9']))['aviso_version']), 'debe exigir una versión conocida');
});

prueba('la zona urbana o rural es obligatoria', function (): void {
    afirmar(isset(erroresSC(scBase(['zona' => '']))['zona']), 'debe exigir la zona');
    afirmar(isset(erroresSC(scBase(['zona' => 'SEMIRURAL']))['zona']), 'no debe aceptar una zona inventada');
});

prueba('en zona urbana el corregimiento se descarta en vez de rechazarse', function (): void {
    $d = datosSC(scBase([
        'zona' => 'URBANO',
        'corregimiento' => App\Rufe\Catalogos::CORREGIMIENTOS[0],
    ]));

    afirmarIgual(null, $d['corregimiento'], 'en zona urbana no hay corregimiento');
});

prueba('hace falta AL MENOS una pista de dónde vive', function (): void {
    // No un campo concreto: una dirección con formato dejaría fuera a quien no
    // la tiene, y solo el corregimiento no basta en zona urbana. Lo que no
    // puede pasar es que no quede ninguna pista.
    $e = erroresSC(scBase(['direccion' => '']));
    afirmar(isset($e['direccion']), 'sin dirección, vereda ni corregimiento debe fallar');

    afirmarIgual([], erroresSC(scBase([
        'direccion' => '',
        'vereda_sector_barrio' => 'Vereda La Liberia',
    ])), 'la vereda sola ya alcanza');

    afirmarIgual([], erroresSC(scBase([
        'zona' => 'RURAL',
        'direccion' => '',
        'corregimiento' => App\Rufe\Catalogos::CORREGIMIENTOS[0],
    ])), 'el corregimiento solo ya alcanza en zona rural');
});

prueba('la descripción es opcional y tiene un tope de caracteres', function (): void {
    afirmarIgual([], erroresSC(scBase()), 'sin descripción no debe fallar');
    afirmarIgual(null, datosSC(scBase())['descripcion']);

    $e = erroresSC(scBase(['descripcion' => str_repeat('a', 501)]));
    afirmar(isset($e['descripcion']), 'debe rechazar una descripción demasiado larga');
});

prueba('no se piden datos del inmueble ni del hogar', function (): void {
    // Eso lo levanta el funcionario si el caso resulta real, no esta puerta.
    $d = datosSC(scBase());

    afirmar(! isset($d['tipo_bien']), 'el tipo de bien no debe pedirse aquí');
    afirmar(! isset($d['personas']), 'la composición del hogar no debe pedirse aquí');
});

grupo('Sin censo › radicado');

prueba('el radicado se distingue de los otros dos', function (): void {
    $r = App\SinCenso\Radicado::componer(2026);

    afirmar(str_starts_with($r, 'SC-2026-'), "el radicado no lleva el prefijo esperado: {$r}");
    afirmar(App\SinCenso\Radicado::esValido($r), 'debería validarse a sí mismo');
    afirmar(! App\SinCenso\Radicado::esValido('PRE-2026-ABCDEFGH'), 'no debe aceptar el de pre-inscripción');
    afirmar(! App\SinCenso\Radicado::esValido('RUFE-2026-ABCDEFGH'), 'no debe aceptar el del censo');
});

grupo('La profesión del inspector, de la ficha de usuario al numeral 1');

prueba('el puente entre las dos pantallas: etiqueta guardada, código esperado', function (): void {
    // El fallo que esto cierra. La ficha de usuario guardaba «Ingeniero(a)
    // civil» —la etiqueta— porque su desplegable solo recibía etiquetas; el
    // formato de inspección trabaja con «INGENIERO_CIVIL». Cada pantalla se
    // veía bien por su cuenta y la precarga del numeral 1 salía en blanco.
    afirmarIgual('INGENIERO_CIVIL', CatalogosInspeccion::codigoProfesion('Ingeniero(a) civil'));
    afirmarIgual('INGENIERO_CIVIL', CatalogosInspeccion::codigoProfesion('INGENIERO_CIVIL'));
});

prueba('los usuarios guardados antes del arreglo siguen funcionando', function (): void {
    // No hay consola en el servidor para migrar la tabla, así que se traduce al
    // leer. Sin esto habría que reeditar a mano cada inspector ya creado.
    foreach (CatalogosInspeccion::PROFESIONES as $codigo => $etiqueta) {
        afirmarIgual((string) $codigo, CatalogosInspeccion::codigoProfesion($etiqueta), "etiqueta {$etiqueta}");
    }
});

prueba('lo que no se reconoce queda sin definir, no a medias', function (): void {
    // Dejar un valor que no corresponde a ninguna opción pondría el desplegable
    // en un estado que no existe: parecería lleno y no lo estaría.
    afirmarIgual('', CatalogosInspeccion::codigoProfesion('Ingeniera sanitaria'));
    afirmarIgual('', CatalogosInspeccion::codigoProfesion(null));
    afirmarIgual('', CatalogosInspeccion::codigoProfesion('   '));
});

prueba('las opciones que viajan a la ficha de usuario traen código Y etiqueta', function (): void {
    // Es la causa raíz: mandando solo etiquetas, el desplegable no tenía con qué
    // guardar el código aunque quisiera.
    $opciones = CatalogosInspeccion::opcionesProfesiones();

    afirmar($opciones !== [], 'la lista no puede estar vacía');

    foreach ($opciones as $o) {
        afirmar(isset($o['codigo'], $o['etiqueta']), 'cada opción lleva código y etiqueta');
        afirmar(CatalogosInspeccion::esProfesionValida($o['codigo']), "código válido: {$o['codigo']}");
    }
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

// ── El botón de WhatsApp del Call Center ────────────────────────────────────

prueba('un móvil colombiano de diez dígitos recibe su indicativo', function (): void {
    $w = 'App\\CallCenter\\Whatsapp';

    afirmarIgual('+573001112233', $w::normalizarTelefono('3001112233'), 'diez dígitos');
    afirmarIgual('+573001112233', $w::normalizarTelefono('300 111 2233'), 'con espacios');
    afirmarIgual('+573001112233', $w::normalizarTelefono('300-111-2233'), 'con guiones');
});

prueba('un número que ya trae indicativo no se toca', function (): void {
    // Añadirle otro 57 lo convierte en un número inventado, y el mensaje se va
    // a un desconocido. En un censo de damnificados eso es entregarle a un
    // tercero el dato de que alguien reportó daños.
    $w = 'App\\CallCenter\\Whatsapp';

    afirmarIgual('+573001112233', $w::normalizarTelefono('573001112233'), 'ya venía con 57');
    afirmarIgual('+573001112233', $w::normalizarTelefono('+57 300 111 2233'), 'con + y espacios');
});

prueba('un fijo no recibe WhatsApp', function (): void {
    // WhatsApp es de móviles. Mandarle la plantilla a un fijo la cobra igual y
    // no llega a nadie.
    $w = 'App\\CallCenter\\Whatsapp';

    afirmarIgual(null, $w::normalizarTelefono('6025190969'), 'fijo de Cali/Jamundí');
    afirmarIgual(null, $w::normalizarTelefono(''), 'vacío');
    afirmarIgual(null, $w::normalizarTelefono(null), 'nulo');
    afirmarIgual(null, $w::normalizarTelefono('123'), 'demasiado corto');
});

prueba('el saludo usa un nombre y un apellido, capitalizados', function (): void {
    // «MARIA FERNANDA DE LOS SANTOS PEREZ GOMEZ» en un saludo suena a base de
    // datos, y en mayúsculas sostenidas suena a grito.
    $w = 'App\\CallCenter\\Whatsapp';

    afirmarIgual('Maria Perez', $w::nombreParaSaludo('MARIA FERNANDA', 'PEREZ GOMEZ'), 'primer nombre y primer apellido');
    afirmarIgual('Aleida Pérez', $w::nombreParaSaludo('Aleida', 'Pérez'), 'respeta los acentos');
    afirmarIgual('Juan', $w::nombreParaSaludo('juan', null), 'sin apellido');
});

prueba('sin nombre se saluda neutro, nunca vacío', function (): void {
    // Meta rechaza una variable vacía, y «Hola, .» es peor que no personalizar.
    $w = 'App\\CallCenter\\Whatsapp';

    afirmarIgual('ciudadano', $w::nombreParaSaludo(null, null), 'sin datos');
    afirmarIgual('ciudadano', $w::nombreParaSaludo('   ', '  '), 'solo espacios');
});

prueba('el mensaje va por WhatsApp y no por SMS', function (): void {
    // Si se omite `channel`, el proveedor manda un SMS y no hay ningún error
    // que lo delate: solo un SMS cobrado que nadie esperaba.
    $w = 'App\\CallCenter\\Whatsapp';
    $c = $w::cuerpoDelMensaje('+573001112233', 'Aleida Pérez', 42);

    afirmarIgual('whatsapp', $c['channel'], 'el canal debe ser explícito');
    afirmarIgual('template', $c['messageType'], 'se manda la plantilla, no texto libre');
    afirmarIgual('Aleida Pérez', $c['content']['templateVariables']['1'], 'el nombre va en la variable 1');
});

prueba('dos clics el mismo día son un solo mensaje', function (): void {
    // Es el fallo más probable de un botón que tarda dos segundos en responder.
    $w = 'App\\CallCenter\\Whatsapp';

    $a = $w::cuerpoDelMensaje('+573001112233', 'Aleida Pérez', 42);
    $b = $w::cuerpoDelMensaje('+573001112233', 'Aleida Pérez', 42);
    afirmarIgual($a['idempotencyKey'], $b['idempotencyKey'], 'misma clave para el mismo hogar y día');

    $otro = $w::cuerpoDelMensaje('+573009998877', 'Otro Hogar', 43);
    afirmar($a['idempotencyKey'] !== $otro['idempotencyKey'], 'hogares distintos, claves distintas');
});

prueba('sin token configurado el envío no existe', function (): void {
    // Mientras nadie ponga un token en config.php, el sistema se comporta como
    // si este código no estuviera.
    afirmar(! App\CallCenter\Whatsapp::configurado(), 'sin token, no configurado');
});

prueba('una operadora no puede marcar a mano un WhatsApp como enviado', function () use ($raiz): void {
    // Los dos resultados de WhatsApp los escribe enviarWhatsapp() cuando el
    // proveedor confirma. Aceptarlos en el formulario de la llamada dejaría
    // marcar como enviado un mensaje que nunca salió, y el hogar quedaría
    // esperando un enlace que nadie le mandó.
    $c = 'App\\Controllers\\CallCenterController';

    afirmar(! in_array('WHATSAPP_ENVIADO', $c::RESULTADOS_DE_LLAMADA, true), 'no es un resultado de llamada');
    afirmar(! in_array('WHATSAPP_FALLIDO', $c::RESULTADOS_DE_LLAMADA, true), 'tampoco el fallido');
    afirmar(isset($c::RESULTADOS['WHATSAPP_ENVIADO']), 'pero el historial sabe nombrarlo');

    $php = (string) file_get_contents($raiz.'/src/Controllers/CallCenterController.php');
    afirmar(
        str_contains($php, 'in_array($resultado, self::RESULTADOS_DE_LLAMADA, true)'),
        'registrar() debe validar contra los resultados de llamada, no contra todos'
    );
});

prueba('un WhatsApp no cuenta como intento de llamada', function () use ($raiz): void {
    // El módulo da el hogar por agotado a los cinco intentos. Si un envío
    // sumara ahí, un hogar al que nadie ha llamado saldría de la cola sin que
    // nadie hubiera hablado con él — y la cifra de avance que se le reporta a
    // la Alcaldía quedaría inflada, como ya pasó con el JOIN que duplicaba.
    $php = (string) file_get_contents($raiz.'/src/Controllers/CallCenterController.php');

    afirmar(
        str_contains($php, "gc.canal = \\'LLAMADA\\') AS intentos"),
        'el conteo de intentos debe excluir los WhatsApp'
    );
    afirmar(
        str_contains($php, "g2.canal = \\'LLAMADA\\'"),
        'el último resultado mostrado tampoco puede ser un WhatsApp'
    );
});

prueba('la migración del canal es aditiva e idempotente', function () use ($raiz): void {
    // El hosting no tiene consola: las migraciones se aplican por web y pueden
    // repetirse. Y DEFAULT LLAMADA es lo que deja bien marcadas las filas
    // anteriores, en las que toda gestión era una llamada.
    $sql = (string) file_get_contents($raiz.'/database/callcenter_03_whatsapp.sql');

    afirmar(str_contains($sql, 'information_schema.COLUMNS'), 'comprueba antes de añadir');
    afirmar(str_contains($sql, "DEFAULT ''LLAMADA''"), 'las filas anteriores quedan como llamada');
    afirmar(! str_contains($sql, 'DROP '), 'una migración de esta campaña no borra nada');
    afirmar(
        in_array('callcenter_03_whatsapp.sql', App\Core\Migrador::ARCHIVOS, true),
        'debe estar registrada en el Migrador, o no se aplica nunca'
    );
});

