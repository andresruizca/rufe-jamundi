# Los permisos de iOS

En Android, pedir un permiso que no está declarado **falla**. En iOS, usar una
API sin su texto de justificación en `Info.plist` **mata la aplicación en el
acto** — sin diálogo, sin mensaje, sin nada que el ciudadano pueda entender.

Es el error más caro de este proyecto en iOS y por eso va en su propio
documento.

## Las cuatro claves, y por qué cada una

Todas van en `ios/App/App/Info.plist`. `cap sync` **no las escribe**: hay que
ponerlas a mano una vez.

| Clave | La dispara | Si falta |
|---|---|---|
| `NSCameraUsageDescription` | Tomar foto y grabar video | Cierre inmediato |
| `NSMicrophoneUsageDescription` | El video, que graba con audio | Cierre inmediato |
| `NSPhotoLibraryUsageDescription` | Elegir una foto ya tomada | Cierre inmediato |
| `NSLocationWhenInUseUsageDescription` | «Tomar la ubicación aquí» | Cierre inmediato |

### El texto importa, y no es un trámite

Lo lee la persona en el diálogo del sistema, y Apple rechaza en revisión los que
son genéricos. Pero la razón de fondo no es la revisión: quien abre esto acaba
de perder parte de su casa y le está entregando la foto de su cédula a una
aplicación que le pasaron por Bluetooth. Que el teléfono le explique para qué
es, con palabras suyas, es lo mínimo.

```xml
<key>NSCameraUsageDescription</key>
<string>Para tomar la foto de su cédula y las fotos del daño de su vivienda, que la Alcaldía necesita para programar la visita técnica.</string>

<key>NSMicrophoneUsageDescription</key>
<string>Los videos de la vivienda se graban con sonido, para que quien revise pueda oír lo que usted explica mientras muestra el daño.</string>

<key>NSPhotoLibraryUsageDescription</key>
<string>Para adjuntar fotos que ya tomó antes, cuando el daño acababa de ocurrir y se veía mejor.</string>

<key>NSLocationWhenInUseUsageDescription</key>
<string>Para guardar el punto donde queda su vivienda y que la Alcaldía pueda encontrarla. Es opcional: con la dirección escrita es suficiente.</string>
```

**`WhenInUse` y no `Always`.** La aplicación solo necesita la ubicación mientras
la persona pulsa el botón, con la pantalla delante. Pedir la de siempre sería
pedir seguir a alguien por la ciudad para atender una emergencia en su casa.

## La cámara dentro del WebView

El formulario **no usa el complemento de cámara**: usa
`<input type="file" capture>` y `getUserMedia`, igual que la web. En iOS eso
tiene dos condiciones que se cumplen y conviene no romper:

1. **iOS 14.3 o superior.** Antes de esa versión `getUserMedia` sencillamente no
   existía dentro de un WKWebView, y no hay forma de sortearlo. Marca el mínimo
   de la aplicación.
2. **Contexto seguro.** WKWebView considera seguro `capacitor://localhost`.
   Cambiar `server.hostname` en `capacitor.config.ts` por cualquier otra cosa
   deja de serlo y la cámara desaparece para la aplicación, sin ningún error que
   lo explique.

## El video en iPhone graba MP4, nunca WebM

Safari no sabe grabar WebM. `video.ts` ya lo contempla —prueba VP9, VP8, WebM y
cae a `video/mp4;codecs=avc1`— y el servidor acepta `video/mp4` en
`Videos::FORMATOS`. Está probado en `video.spec.ts`, incluida la variante de
Safari que trae `MediaRecorder` pero no `isTypeSupported`.

No hay nada que cambiar. Queda escrito para que nadie «arregle» esa lista de
formatos pensando que MP4 sobra.
