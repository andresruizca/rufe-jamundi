# Plan: Reemplazar Google Sheets por cargas de Excel (formato UNGRD-RUD)

## Contexto

Hasta ahora todo el proyecto (tablero en `main`/GitHub Pages y el Sistema de
Gestión del Riesgo en `sistema-gestion-riesgo`) lee los datos en vivo desde dos
hojas de Google Sheets (RUFE original + BASE-DATOS RUFE). El usuario ya no
quiere depender de Google Sheets: en su lugar va a proveer los datos como un
archivo Excel (formato oficial **UNGRD — Registro Único de Damnificados,
v1.5**), con varias pestañas relacionadas entre sí.

Este turno fue solo de **análisis** — el usuario pidió explícitamente no cargar
datos todavía, solo entender cómo se relacionan las pestañas usando un archivo
de ejemplo real: `~/Downloads/Formato-Registro-RUD-v1.5 - CARGA MASIVA-CONSOLIDADO-COMPLETO.xlsx`.

## Estructura del archivo de ejemplo (verificada, no supuesta)

Leí el `.xlsx` directo (es un ZIP de XML; lo parseé con la librería estándar de
Python, sin instalar nada) porque no había `openpyxl`/`pandas` disponibles.
Cada pestaña tiene 2 filas de encabezado decorativo (título UNGRD + fila en
blanco) antes del encabezado real en la fila 3.

**4 pestañas, llave relacional `numero_familia` en las 4:**

1. **Personas-Hogar** — 1 fila por PERSONA. 1.342 personas → 661
   `numero_familia` (hogares) únicos. Campos: numero_familia, nombres/
   apellidos, Parentesco (código UNGRD), tipo_documento (código UNGRD),
   no_documento, fecha_nacimiento, genero (código), etnia (código), telefono,
   usuario_creacion, fecha_creacion, Actualmente_se_encuentra (en residencia /
   evacuado), Ubicación_de_su_residencia (URBANO/RURAL), Lugar de evacuación.

2. **Bienes-Afectados** — 1 fila por HOGAR. Relación **1:1 exacta** con
   `numero_familia` (verificado: 661 filas, 661 `numero_familia` únicos, cero
   huérfanos en cualquier dirección). Campos: numero_familia, tipo_bien
   (código), forma_tenencia (código), estado_bien (código), corregimiento,
   vereda, direccion, ubicacion_bien (URBANO/RURAL).

3. **Cultivos-Perdidos** — detalle opcional por hogar (tipo_cultivo, area,
   unidad_medida). Vacía en este archivo de ejemplo.

4. **Ganado-Aves-Peces** — detalle opcional por hogar (pecuario, especie,
   cantidad). Vacía en este archivo de ejemplo.

**Hallazgo clave de compatibilidad**: los códigos usados aquí ("33 - CC -
Cédula de ciudadanía", "37 - Masculino", "6 - Arrendatario", "126 -Habitable")
coinciden con el catálogo oficial UNGRD que **ya está implementado** en
`Gestion_riesgo/backend/src/Rufe/Catalogos.php` (confirmado contra la
respuesta real de `GET /rufe/catalogos` probada en local: mismos tipos de
documento, parentescos, géneros, etnias, formas de tenencia, estados y tipos
de bien). Esto es una señal fuerte de que este Excel es exactamente el mismo
formato que ya sabe validar/guardar el backend nuevo (tablas `rufe_personas`,
`rufe_reportes`, `rufe_agropecuario`), no algo que haya que traducir desde
cero.

**Hallazgo de calidad de datos** (mismo patrón que ya vimos con BASE-DATOS
RUFE): 65 números de documento aparecen en DOS `numero_familia` distintos —
ej. documento 6334807 es "RAFAEL ARMERIO JARAMILLO" (Jefe de hogar) en la
familia 2, y "RAFAEL JARAMILLO" (también Jefe de hogar) en la familia 274:
muy probablemente la misma persona censada dos veces en hogares distintos.
Habrá que decidir una regla de fusión, igual que se hizo para RUFE original vs
BASE-DATOS RUFE.

## Decisiones ya confirmadas por el usuario

1. **Destino**: el backend MySQL nuevo (`rufe_reportes`/`rufe_personas`/
   `rufe_agropecuario`), no el tablero de Google Sheets.
2. **Alcance**: se deja de leer BASE-DATOS RUFE (Google Sheets). Este Excel
   se carga **una sola vez** como migración inicial; de ahí en adelante la
   base sigue creciendo solo por el formulario "Nueva ficha" ya existente.
   Pidió explícitamente **armonizar** contra lo que el formulario ya guarda,
   no solo insertar a ciegas.

## Armonización: Excel vs. lo que el backend ya sabe validar (`Catalogos.php`)

Comparé cada catálogo del Excel, código por código, contra
`Gestion_riesgo/backend/src/Rufe/Catalogos.php` y el esquema de
`database/rufe.sql`. Resultado central: **las etiquetas coinciden siempre,
pero los códigos numéricos NO** — el Excel trae los códigos oficiales reales
de la UNGRD (números grandes y no correlativos), mientras que `Catalogos.php`
usa una numeración interna propia, secuencial desde 1, que Andrés inventó al
programar el formulario. Ejemplo con `Parentesco`:

| Código en el Excel | Etiqueta | Código en Catalogos.php |
|---|---|---|
| 39 | Jefe(a) o cabeza del hogar | 1 |
| 41 | Hijo(a), hijastro(a) | 3 |
| 40 | Pareja, esposo(a) | 2 |
| 89 | Otro pariente | 8 |
| 86 | Nieto(a) | 6 |

Mismo patrón exacto en `tipo_documento`, `genero`, `etnia`, `tipo_bien`,
`forma_tenencia` y `estado_bien` — coincidencia total de etiquetas, códigos
distintos. **Conclusión: la migración necesita una tabla de traducción
código→código (por etiqueta), no puede copiar el número tal cual.** Esa tabla
sale directo de este análisis, es pequeña y fija (7 catálogos, entre 3 y 15
valores cada uno).

**Un valor real no tiene equivalente**: `tipo_documento` código 106 = "PPT"
(Permiso por Protección Temporal, documento migratorio colombiano real para
población venezolana) aparece 2 veces en el Excel y **no existe en absoluto**
en `Catalogos.php` (que solo tiene 10 tipos, ninguno es PPT).

## Otros hallazgos de calidad de datos (sobre 1.342 personas / 661 hogares)

| Hallazgo | Cantidad |
|---|---|
| Mismo número de documento en 2 `numero_familia` distintos | 65 personas |
| `no_documento` vacío | 46 |
| `no_documento` con texto no numérico ("SIN DATOS", "SIN IDENTIFICACIÓN", un NIT con guion) | 7 |
| Sin `primer_nombre` | 4 |
| Sin `primer_apellido` | 6 |
| Hogares (Bienes-Afectados) sin vereda NI dirección | 111 / 661 |
| Hogares sin ninguna persona con Parentesco "Jefe de hogar" | 34 / 661 |
| Jefes de hogar sin teléfono registrado | 39 / 627 |

Esto importa porque el esquema de `rufe_reportes` exige **NOT NULL** en
`vereda_sector_barrio`, `direccion`, `contacto_telefono`, `evento` y
`fecha_evento` — ninguno de estos dos últimos existe en el Excel en absoluto
(todo el archivo es un solo evento, el sismo del 10 de agosto, así que se
puede completar con `Catalogos::EVENTO_PREDETERMINADO`/
`FECHA_EVENTO_PREDETERMINADA` para todos los registros — no hace falta
preguntar esto).

## Aclaración del usuario sobre este archivo de ejemplo

Este `.xlsx` es solo una muestra para sacar las variables y su relación — el
archivo real que se va a importar **no tiene los 65 duplicados** (ya viene
depurado) y todavía no se ha entregado. Por eso el script de importación no
se diseña alrededor de "qué hacer con estos 65 duplicados puntuales", sino
con una regla general de **no adivinar**: todo registro que no pase las
mismas validaciones que ya usa el formulario "Nueva ficha" (nombre/apellido
vacíos, duplicado contra la base ya existente, etc.) se aparta en un reporte
para revisión humana — nunca se inventa un valor ni se descarta en silencio.
Esto aplica igual a los 10 registros sin nombre/apellido (decisión ya
tomada: van a ese reporte) y a cualquier duplicado que aparezca en el
archivo real pese a lo anterior (red de seguridad, no el caso esperado).

El tipo de documento "PPT" queda como **una decisión a tomar al momento de
correr la importación** (ver más abajo), no resuelta de antemano.

## Diseño del script de importación (una sola corrida, sobre el archivo real)

**Por qué PHP y no Node/Python**: así reutiliza tal cual la lógica que el
formulario "Nueva ficha" ya usa y ya tiene pruebas — `Rufe\Validador` (mismas
reglas de qué es un registro válido), `Rufe\Catalogos` (mismos catálogos),
`Rufe\Radicado` (mismo generador de radicado). Reimplementar esas reglas en
otro lenguaje sería la primera fuente de divergencia entre "lo que entra por
el Excel" y "lo que entra por el formulario" — justo lo que se pidió evitar.

Nuevo archivo: `Gestion_riesgo/backend/scripts/importar-rud.php`, ejecutado a
mano una sola vez: `php scripts/importar-rud.php ruta/archivo.xlsx [--dry-run] [--incluir-ppt]`.

1. **Lector de `.xlsx` sin Composer**: un `.xlsx` es un zip con XML adentro;
   PHP trae `ZipArchive` y `SimpleXMLElement`/`DOMDocument` de fábrica, así
   que se escribe un lector mínimo (leer `sharedStrings.xml` + cada
   `sheetN.xml`), igual al que usé para este análisis pero en PHP. Mismo
   principio de "sin dependencias" que ya sigue el resto del backend.

2. **Tabla de traducción de catálogos** (`Gestion_riesgo/backend/src/Rufe/CatalogosRud.php`,
   o una constante dentro del script — a decidir en la implementación): un
   mapa código-Excel → código-Catalogos.php por cada uno de los 7 catálogos
   (Parentesco, tipo_documento, género, etnia, tipo_bien, forma_tenencia,
   estado_bien), construido por coincidencia de etiqueta a partir de este
   mismo análisis. Es una tabla de datos, no lógica — fácil de ajustar si el
   archivo real trae alguna etiqueta con redacción distinta.

3. **Agrupar por `numero_familia`** → un `rufe_reportes` candidato por hogar:
   - `zona`/`corregimiento`/`vereda_sector_barrio`/`direccion`/
     `forma_tenencia`/`estado_bien`/`tipo_bien` ← de Bienes-Afectados.
   - `evento`/`fecha_evento` ← `Catalogos::EVENTO_PREDETERMINADO`/
     `FECHA_EVENTO_PREDETERMINADA` (todo el archivo es el mismo sismo; no
     hace falta preguntarlo).
   - `contacto_telefono` ← teléfono del Jefe de hogar; si no tiene, el de
     cualquier integrante; si nadie tiene, el hogar entra al reporte de
     revisión (no se inventa un número).
   - `vereda_sector_barrio`/`direccion` vacíos en ambos → mismo criterio:
     reporte de revisión, no un "Sin especificar" silencioso, porque acá sí
     es un campo NOT NULL que un funcionario tiene que completar.
   - `origen = 'INTERNO'` (carga masiva de un funcionario, no autorreporte
     ciudadano), `estado = 'RECIBIDO'` (que seguidamente pase por el mismo
     flujo de Vo.Bo. que cualquier ficha).
   - `radicado` y `huella` generados con las mismas funciones que usa
     `RufeCapturaController` hoy — no se inventa un formato nuevo.
   - `autoriza_datos`/`autoriza_sensibles`: el papel del RUD ya implica
     consentimiento informado del censo oficial; se guardan en `true` con
     `autorizacion_texto = 'RUD físico (carga masiva)'` en vez del texto de
     habeas data del formulario digital, para dejar trazable que el
     consentimiento vino del papel y no de un clic. **Este punto es una
     interpretación mía, no una instrucción explícita — se marca para que
     Andrés (o quien maneje la parte legal) lo confirme antes de correr la
     importación de verdad.**

4. **Por cada persona del hogar** → un `rufe_personas` candidato: traducir
   `Parentesco`/`tipo_documento`/`genero`/`etnia` por la tabla del punto 2;
   `orden` = posición dentro del hogar (Jefe primero si existe); validar
   `numero_documento` con la misma regla que ya usa `Validador`.

5. **Validar cada hogar candidato con `Rufe\Validador`, sin reescribir
   reglas**: se arma el payload tal como lo mandaría el formulario y se le
   pasa al validador existente. Si pasa, se inserta (transacción por hogar).
   Si no, el hogar completo (todas sus personas) va al reporte de problemas
   con el motivo exacto que devolvió el validador — mismo criterio para
   nombre/apellido vacíos, teléfono faltante, dirección faltante, etc.

6. **Cruce contra lo que YA existe en la base** (fichas ya capturadas por el
   formulario antes de esta migración, si las hay): mismo chequeo de
   documento repetido, ahora contra `rufe_personas` existente — si aparece,
   el hogar entero va al reporte en vez de duplicarse en silencio. Esto es
   la "armonización entre el Excel y la base ya creada" que se pidió.

7. **`--incluir-ppt`**: si se pasa, el script agrega "PPT" a la tabla de
   traducción como documento válido (y de paso deja pendiente sumarlo a
   `Catalogos::TIPOS_DOCUMENTO` para que el formulario también lo acepte a
   futuro); si no se pasa, esos registros van al reporte de problemas en vez
   de importarse como "Otro" a ciegas. Sin decidir código real de la UNGRD
   para PPT, no hay opción segura por defecto — se decide al correr.

8. **Salida de la corrida**: un resumen (hogares importados, personas
   importadas, cuántos fueron a revisión y por qué, agrupado por motivo) más
   un `problemas-importacion-rud.csv` con radicado propuesto, numero_familia
   original y el motivo exacto, para que el equipo lo revise sin tener que
   releer el Excel entero.

9. **`--dry-run` por defecto en la primera corrida**: hace todo el proceso
   (leer, traducir, agrupar, validar, cruzar contra la base) e imprime el
   mismo resumen, pero sin insertar nada. Solo se corre sin esa bandera
   cuando el resumen ya se revisó y se aprueba.

## Archivos a crear/tocar (cuando llegue el archivo real y se apruebe correr)

- **Nuevo**: `Gestion_riesgo/backend/scripts/importar-rud.php` (el importador).
- **Nuevo**: `Gestion_riesgo/backend/scripts/importar-rud-catalogos.php` (o
  clase equivalente) con la tabla de traducción código-Excel → código-app.
- **Posible cambio**: `Gestion_riesgo/backend/src/Rufe/Catalogos.php`, solo
  si se decide `--incluir-ppt` (agregar el tipo de documento 11 = PPT).
- **Nuevos tests** en `Gestion_riesgo/backend/tests/` para la tabla de
  traducción (que cada código del Excel resuelva al código correcto) y para
  el agrupamiento por `numero_familia` — mismo arnés sin Composer que ya usa
  `tests/run.php`.

## Verificación

- `php backend/tests/run.php` — nuevas pruebas de traducción de catálogos y
  agrupamiento en verde, junto con las 207 ya existentes.
- Correr `importar-rud.php --dry-run` contra el archivo real y revisar el
  resumen (cuántos hogares, cuántos a revisión, por qué) antes de aprobar la
  corrida real.
- Tras la corrida real: `GET /rufe/reportes` debe mostrar los hogares
  importados, y `/riesgo/reportes` en el frontend (ya probado y funcionando
  en local) debe listarlos igual que cualquier ficha capturada a mano.
- Comparar el total importado contra el total de `numero_familia` del Excel
  para que la diferencia sea exactamente la cantidad que quedó en el reporte
  de problemas — ningún hogar debe desaparecer sin explicación.

## Pendiente para poder ejecutar (no solo planear)

- Que el usuario entregue el archivo real (dijo que este era solo de
  ejemplo).
- Confirmar el punto de `autoriza_datos`/`autorizacion_texto` con quien
  maneje la parte legal/habeas data del sistema.
- Decidir `--incluir-ppt` sí/no (y, si es sí, el código real UNGRD para PPT).



---

# Cómo quedó de verdad (26 de agosto de 2026)

Este plan se escribió sobre un archivo de EJEMPLO. El archivo real
—`RUD JAMUNDÍ FILTROS.xlsx`, 486 KB— resultó ser otra cosa, y conviene dejarlo
escrito porque casi todo el diseño de arriba dependía de la estructura supuesta.

## Lo que cambió respecto de lo planeado

| El plan suponía | El archivo real |
|---|---|
| 4 pestañas relacionales (Personas-Hogar, Bienes-Afectados, Cultivos, Ganado) | 5 hojas: `Sheet1` con TODO, y cuatro copias filtradas por estado del bien |
| Llave `numero_familia` entre pestañas | Una fila por persona, con `numero_formulario` como hogar |
| Bien del hogar en su propia pestaña | El bien va como TEXTO CORRIDO dentro de una columna: `Bien: Vivienda. Tenencia: Propietario. Estado: Averiado. Vereda/sector: … Corregimiento: … Direccion: …` |
| Códigos numéricos de la UNGRD → tabla de traducción código→código | Vienen las ETIQUETAS, no los códigos. La traducción es etiqueta→código, más robusta |
| 661 hogares / 1.342 personas | **1.539 hogares / 3.184 personas** |
| 65 documentos duplicados | **Cero.** El archivo real viene depurado, como se anunció |
| Columna urbano/rural | **No existe.** Hay que deducirla |

`Sheet1` es el conjunto completo: las otras cuatro hojas son subconjuntos suyos
—verificado fila por fila— y solo suman 3.179 de las 3.184, porque los cinco
registros con estado «No Informa» o vacío no caen en ningún filtro.

## Decisiones tomadas (confirmadas con Andrés antes de correr nada)

1. **Zona urbano/rural**: se deduce del corregimiento. Si el nombre está en los
   diecisiete corregimientos oficiales → RURAL (585 hogares). Si no —«Jamundí»
   en 819 fichas, «Terranova», «El Rodeo», «Pangola»— → URBANO (954). El campo
   del RUD mezcla corregimientos con barrios de la cabecera, y esa es la única
   marca de ruralidad que trae el archivo.
2. **Ubicación**: se usa el dato más preciso que exista, bajando de dirección a
   vereda/sector y de ahí a corregimiento. No se rellena con «Sin especificar».
3. **Catálogos ampliados**: `GENEROS` recibe «No informa» (48 personas sin el
   dato), `ETNIAS` recibe «No informa» (553 personas), y `TIPOS_DOCUMENTO`
   recibe «PPT — Permiso por Protección Temporal» (4 personas). Vacío no es lo
   mismo que «No aplica», y en un municipio con la población afro de Jamundí
   dar por «no aplica» lo que nadie preguntó deforma una cifra que se usa para
   priorizar ayuda.
4. **Consentimiento**: las fichas del RUD llevan `aviso_version = 'rud-fisico-v1'`,
   un código propio. Estampar «habeas-data-v2» afirmaría que esas familias
   leyeron un texto de pantalla que nunca vieron.

Y tres deducciones acotadas, cada una con su prueba:

- **Hogar de UNA persona sin jefe marcado** → esa persona es la cabeza de su
  hogar. Son 90 de los 136 sin jefe. Con dos o más NO se asciende a nadie.
- **Tipo de documento sin número** («CC» con la casilla vacía, 64 personas) →
  se guarda como «No informa». Guardar «CC» afirmaría que tiene cédula y que el
  sistema perdió el número.
- **Jefe sin teléfono propio, pero el hogar sí tiene uno** → se le asigna ese,
  que es el número al que la Alcaldía va a llamar de todos modos.

## Resultado del ensayo (base local)

```
Personas en el archivo: 3.184
Hogares en el archivo:  1.539

Hogares que entran:  1.372  (2.830 personas)   89 %
Hogares a revisión:    167                     11 %

  93  Ningún integrante dejó teléfono
  46  Ningún integrante marcado como jefe (hogares de 2 o más)
  10  Documento que no lleva número, pero trae uno
   9  Dirección de menos de 5 caracteres
   6  Nombre o apellido vacío o con caracteres no válidos
   2  Dos personas marcadas como jefe
   1  Jefe sin teléfono válido

Cuadre: 1.372 + 167 = 1.539 ✓
```

El cuadre se imprime siempre: ningún hogar puede desaparecer sin explicación.

## Archivos

- `backend/src/Rufe/LectorXlsx.php` — lector de .xlsx sin Composer (ZipArchive +
  SimpleXML, los dos de fábrica en PHP).
- `backend/src/Rufe/Rud.php` — traducción del RUD, todo funciones puras.
- `backend/scripts/importar-rud.php` — la conciliación. Sin `--aplicar` no
  escribe nada.
- `backend/scripts/revision-rud.csv` — lo que quedó fuera, con el motivo exacto.

## Lo que falta para aplicarlo en producción

**No hay forma de correr un script PHP en el servidor**: el hosting no tiene
SSH, y abrir MySQL remoto para correrlo desde fuera quedó descartado. Las dos
vías posibles son un endpoint de una sola vez protegido con clave —como el
`migrar.php` que ya se usa y se borra— o generar el SQL y aplicarlo por
phpMyAdmin. Está pendiente de decidir.

El importador es **reanudable**: cruza por huella contra lo que ya existe, así
que si se corta a la mitad, volver a correrlo continúa donde iba sin duplicar
nada.
