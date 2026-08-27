# Trabajar sin señal

Qué funciona sin internet en el Sistema de Gestión del Riesgo, qué se guarda en
el aparato, **qué no**, y por qué. Escrito para poder responder a una auditoría
sin leer el código.

## Lo que es

Una **aplicación web instalable (PWA)** con Service Worker propio. No es una capa
comprada ni un complemento: está en `frontend/src/service-worker.ts` y hace tres
cosas.

1. **Guarda la aplicación al instalarse.** Así se abre en una vereda sin
   cobertura, aunque se haya cerrado el navegador. Sin esto solo funcionaba si la
   pestaña ya estaba abierta cuando se cayó la señal.
2. **Guarda los datos que se consultan**, con las salvaguardas de más abajo.
3. **Envía lo levantado cuando vuelve la conexión**, con Background Sync y una
   cola en IndexedDB, aunque quien lo levantó ya haya cerrado la aplicación.

El Service Worker **solo funciona en contexto seguro**. Por eso el `.htaccess`
manda la cabecera HSTS: sin ella, quien entre por `http://` se queda sin envío en
segundo plano y sin enterarse.

## Qué funciona sin señal

| | |
|---|---|
| Abrir la aplicación con el navegador cerrado | Sí |
| Levantar una ficha RUFE | Sí |
| Llenar el formato de inspección | Sí |
| Tomar fotos y grabar video | Sí |
| Que salga solo al volver la señal | Sí |
| Consultar tablero, bandejas, mapa, reportes | **Solo lo ya consultado** |
| Ver una foto o un video de una ficha | No |
| Administración: usuarios, catálogos | No |

**«Solo lo ya consultado»** es literal: no se descarga nada por adelantado. Una
pantalla se dibuja sin señal si sus datos quedaron guardados de una visita
anterior con cobertura. Es la diferencia entre que en el aparato acabe lo que esa
persona miró y que acabe la base entera de la Alcaldía.

## Lo que hay que saber, y decirlo claro

**Desde agosto de 2026 el censo que alguien consulta vive en su aparato.** Fue una
decisión de la Alcaldía, pedida a sabiendas: el sistema entero debía funcionar sin
señal. Antes solo se guardaban los catálogos de los formularios, que no contienen
dato personal alguno.

Lo que sostiene esa decisión son cuatro salvaguardas. **Ninguna es opcional**, y
quitar cualquiera deja el censo desprotegido en un teléfono que se presta, se
pierde o cambia de manos.

1. **Se guarda lo que se abre.** Nunca una descarga masiva.
2. **Se borra al cerrar sesión** — incluida la sesión que caduca sola, porque esa
   es la que nadie decide. Vive en `borrarToken()`
   (`frontend/src/lib/api/client.ts`), el único punto por donde se pierde la
   sesión.
3. **Caduca a las 24 horas.** Pasado ese plazo se tira en vez de servirse. Un dato
   de hace una semana sobre un hogar damnificado —su estado, si ya se inspeccionó,
   si recibió materiales— es peor que no tener dato.
4. **Se dice en pantalla.** Cuando lo que se ve viene de la copia, el aviso lo
   indica con su fecha: «Lo que ve está guardado hoy a las 9:14 a. m.».

## Android y iPhone NO se comportan igual

Es la diferencia que más consecuencias tiene, y el sistema la distingue.

| | Chrome (Android, escritorio) | Safari (iPhone, iPad) |
|---|---|---|
| Envío con la aplicación cerrada | **Sí**, Background Sync | **No existe** |
| Lo guardado se conserva | Mientras haya espacio | **Se borra a los pocos días si no está instalada** |
| Instalar | Botón propio del navegador | Compartir → Añadir a inicio |
| Grabar video | WebM (VP9/VP8) | MP4/H.264, y exige iOS 14.3+ |

**En un iPhone todos los navegadores son Safari por dentro.** Apple obliga a
Chrome, Firefox y Edge de iOS a usar WebKit, así que «Chrome en iPhone» tampoco
tiene envío en segundo plano. El sistema lo detecta por la capacidad
(`'sync' in registro`), no por el nombre del navegador: el día que Safari lo
implemente, lo dará por bueno solo.

Consecuencias en pantalla, y por qué:

- Donde Chrome dice «se enviará **aunque cierre la aplicación**», Safari dice
  «**deje esta aplicación abierta**». Prometer lo primero en un iPhone es que
  alguien la cierre tranquilo y su ficha no llegue nunca.
- Con fichas en cola **sin la aplicación instalada**, se avisa — y en iPhone se
  explica que Safari las borra tras unos días sin abrirla, que es un riesgo que
  en Android no existe igual.
- El texto nombra a Safari cuando toca. Sin nombrarlo, suena a que la aplicación
  está a medio hacer y alguien pierde el día buscando un fallo que no existe.

Nada de esto se decide mirando el nombre del navegador salvo para **explicar**;
lo que se puede o no se puede hacer se le pregunta siempre a la API.

## Lo que NUNCA se guarda

- **Las evidencias**: la foto de una cédula, el video de una vivienda. Es el dato
  más sensible del sistema y pesa megabytes cada uno.
- **El login y el cambio de contraseña.**
- **La administración de usuarios.**

La lista está en `frontend/src/lib/offline/cacheables.ts`, se compara **entera**
—nunca por prefijo— y `cacheables.spec.ts` la fija. Añadir una ruta obliga a tocar
la prueba: es a propósito.

## Cómo comprobarlo

En Chrome, con las herramientas de desarrollo:

```
1. Con señal: abrir tablero, bandeja de solicitudes, mapa y reportes.
2. Application → Service Workers → marcar «Offline».
3. Recargar cada pantalla: deben dibujarse, con el aviso y su fecha.
4. Application → Cache Storage → `sgr-datos-*`: SOLO lo que se abrió.
5. Volver a poner señal y cerrar sesión → esa caché queda VACÍA.
6. Abrir una foto de una ficha sin señal → debe fallar. No se guardan.
```

Y las pruebas automáticas, desde `frontend/`:

```bash
npm test    # cacheables.spec.ts · guardado.spec.ts · navigation.spec.ts
```

## Si hubiera que dar marcha atrás

Volver a la política anterior —solo catálogos— es dejar en `API_CACHEABLE`
únicamente `/api/rufe/catalogos` y `/api/inspeccion/catalogos`. Nada más cambia:
las salvaguardas siguen valiendo y las pantallas de consulta vuelven a pedir
conexión, que es lo que el armazón ya sabe explicar.

---

## Revisión del 27 de agosto de 2026

Auditoría de lo que había, tras una semana de cambios. Tres cosas estaban rotas
y ninguna avisaba.

### El tablero y los mapas habían dejado de funcionar sin señal

Al pasar el tablero de leer una hoja de Google a leer la base, apareció
`GET /rufe/tablero` — y la lista de lo que se guarda no se entera de una ruta
nueva. Desde ese día las dos pantallas abrían en blanco sin conexión, y nada lo
delataba: el fallo solo se ve poniendo el navegador en modo sin red.

Ahora hay una prueba que cubre por nombre las rutas que el sistema usa de
verdad, no solo el comportamiento del comodín.

### El formulario ciudadano nunca funcionó sin señal

`/api/preinscripcion/catalogos` no estaba en la lista. Sin ese catálogo no hay
formulario que dibujar: la aplicación instalada abría en blanco.

Es el más importante de los tres catálogos —es el único formulario que abre un
ciudadano desde su casa, con la señal que le quede— y era el que faltaba.

### La puerta de la cédula era un muro sin señal

La primera pantalla del formulario consulta si la cédula está en el censo. Sin
conexión eso no se puede responder, y la persona se quedaba fuera.

Ahora se entra igual, con un aviso claro dentro del formulario. **No es un
agujero**: quien decide es el servidor al recibir el envío, y esa comprobación
no se puede saltar desde el navegador. Lo que se pierde es avisar antes; lo que
se gana es que una familia sin señal pueda dejar su solicitud lista.

### Y el botón de instalar no se veía donde hacía falta

Vivía solo dentro del menú lateral del sistema. El formulario ciudadano no
tiene menú, así que el ciudadano —el único que lo usa— nunca vio la opción de
instalarlo. Ahora está al final del formulario.

Importa más de lo que parece: instalada, el navegador deja de tratarla como una
pestaña más que puede desalojar cuando le falte espacio, y en iPhone Safari
desaloja la caché de los sitios NO instalados tras unos días sin usarlos.
