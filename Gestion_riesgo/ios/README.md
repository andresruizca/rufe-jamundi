# iOS — Pre-inscripción ciudadana

La misma aplicación del APK, para iPhone. **Todo lo del proyecto iOS vive en
esta carpeta.**

---

## ⚠ Falta Xcode en este equipo

El proyecto está generado y configurado, pero **no se ha compilado nunca**: en
la máquina donde se preparó solo hay las herramientas de línea de órdenes, no
Xcode. Sin él no existe `xcodebuild`, ni SDK de iOS, ni forma de firmar.

Lo que quedó hecho y lo que no:

| | Estado |
|---|---|
| Proyecto Xcode generado | Hecho |
| Configuración de Capacitor | Hecho |
| Textos de permiso en `Info.plist` | Hecho — ver `docs/permisos.md` |
| Mínimo de iOS subido a 14.3 | Hecho — ver abajo |
| `pod install` | **Falta.** Necesita Xcode |
| Compilar y firmar | **Falta.** Necesita Xcode y cuenta de Apple Developer |

### Para terminarlo en un Mac con Xcode

```bash
cd ios
npm install
npm run preparar        # compila ../apk y copia el web
cd ios/App && pod install
npm run abrir           # abre App.xcworkspace en Xcode
```

En Xcode: elegir el equipo de firma en **Signing & Capabilities** y ejecutar en
un iPhone conectado.

---

## De dónde sale el formulario

`capacitor.config.ts` apunta a `webDir: '../apk/build'`. Es la **única**
referencia que sale de esta carpeta, y solo lee.

El formulario ya está copiado una vez en `apk/src/formulario/` desde la web.
Copiarlo aquí otra vez serían **tres copias** de las mismas reglas de
validación, los mismos ocho dibujos y el mismo texto de la Ley 1581,
separándose cada una por su lado. Lo que se comparte es el resultado de
compilar, que es un artefacto, no código fuente.

Ningún archivo del proyecto iOS vive fuera de `ios/`.

---

## Las dos diferencias de fondo con Android

Ninguna es un detalle pendiente. Están documentadas aparte porque cambian lo
que la aplicación puede prometer:

### [`docs/sincronizacion.md`](docs/sincronizacion.md) — el envío no es automático

Android tiene WorkManager: la solicitud sale sola con la aplicación cerrada.
**iOS no tiene equivalente.** `BGTaskScheduler` no garantiza nada, y la propia
documentación de Apple dice que para una aplicación que la gente no abre a
menudo —exactamente este caso— es poco probable que llegue a ejecutarse.

En iPhone la persona **tiene que abrir la aplicación una vez cuando tenga
señal**. El texto de la pantalla final tiene que decirlo; prometer lo contrario
sería una mentira cara.

### [`docs/permisos.md`](docs/permisos.md) — los textos del `Info.plist`

En Android, usar una API sin declarar su permiso falla. En iOS, usarla sin su
texto de justificación **cierra la aplicación en el acto**, sin ningún mensaje.
Están puestos los cuatro.

---

## Por qué el mínimo es iOS 14.3 y no 14.0

Capacitor pone 14.0. `getUserMedia` dentro de un WKWebView **no existe antes de
14.3** — Apple lo habilitó en esa versión y no hay forma de sortearlo.

Con el mínimo en 14.0, un iPhone en 14.0, 14.1 o 14.2 instalaría la aplicación
y el grabador de video fallaría sin ninguna explicación. Es preferible que no se
instale a que se instale a medias.

Subido en el `Podfile` y en los cuatro destinos del proyecto Xcode.

---

## El video en iPhone graba MP4, y eso ya está resuelto

Safari no sabe grabar WebM. `video.ts` del formulario prueba VP9, VP8, WebM y
cae a `video/mp4;codecs=avc1`; el servidor acepta `video/mp4`. Hay pruebas que
lo fijan, incluida la variante de Safari que trae `MediaRecorder` pero no
`isTypeSupported`.

No hay nada que cambiar. Queda escrito para que nadie «arregle» esa lista
pensando que MP4 sobra.

---

## Distribución

A diferencia de Android, en iOS **no se puede pasar el archivo de teléfono a
teléfono**. Las opciones son:

| Vía | Qué exige |
|---|---|
| **TestFlight** | Cuenta de Apple Developer (99 USD/año) y revisión |
| **App Store** | Lo mismo, más revisión completa |
| **Ad Hoc** | Registrar el UDID de cada iPhone, máximo 100 |

Esto elimina la ventaja que justificaba el APK —instalar sin conexión, pasándolo
por Bluetooth—. En iPhone hace falta internet para instalar de todas formas.
