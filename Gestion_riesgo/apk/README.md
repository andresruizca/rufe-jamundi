# APK Android — Pre-inscripción ciudadana

Aplicación Android para que un ciudadano registre su vivienda afectada **sin
conexión**, y que sincronice sola con `https://grj.oticjamundi.com/api` cuando el
teléfono tenga internet, sin que nadie tenga que hacer nada.

**Todo lo del APK vive en esta carpeta.** No se escribe nada fuera de `apk/`.

---

## Por qué un APK y no la aplicación web

La aplicación web ya es instalable y funciona sin conexión para los formatos del
funcionario. Pero:

1. **El formulario ciudadano exige conexión para enviarse.** `/preinscripcion` no
   está en `RUTAS_SIN_CONEXION` y publica directamente contra la API. Quien más
   lo necesita es el único que no puede registrarse sin señal.

2. **Instalar una aplicación web exige una visita en línea.** En una vereda sin
   cobertura eso no ocurre nunca. Un APK se pasa de teléfono a teléfono por
   Bluetooth o WhatsApp local, sin que exista internet en ningún momento. Esto es
   lo que ninguna PWA resuelve, y es la razón de fondo.

3. **WorkManager sincroniza con la aplicación cerrada.** El Background Sync de
   Chrome lo matan los fabricantes de Android con criterios impredecibles.

---

## Estado

| Fase | Estado |
|---|---|
| 0 · Cambios necesarios en el servidor | **Escritos y medidos**, sin aplicar — ver abajo |
| 1 · Andamiaje del proyecto | Hecho |
| 2 · Copia del formulario + detector de deriva | Hecho |
| 3 · Captura de foto y video | Pendiente |
| 4 · Guardado local y «Mis registros» | Esquema hecho; falta el acceso |
| 5 · `ApiCliente.kt` | Pendiente |
| 6 · `SyncWorker.kt` | Pendiente |
| 7-9 · Pruebas, campo, firma | Pendiente |

### El servidor tiene que cambiar antes

Está en [`docs/servidor-requerido.md`](docs/servidor-requerido.md), con el parche
listo. Resumido:

- **El límite por IP bloqueará al APK.** Los operadores usan CGNAT: una vereda
  entera sale por una IP, y hay cinco envíos por hora. Una brigada de veinte
  familias recibiría cinco radicados y quince rechazos. **Sin este arreglo el APK
  no sirve**, por bien construido que esté.
- **Una señal de daño no puede desaparecer del catálogo**, o un APK de hace seis
  meses perdería solicitudes enteras.

Esos cambios son de `backend/` y por eso **no están aplicados**: desde esta
carpeta no se toca el servidor. Quedan a la espera de que se autoricen.

---

## Cómo se trabaja aquí

```bash
cd apk
npm install

npm run dev          # el formulario en el navegador, sin Android de por medio
npm run check        # tipos
npm test             # deriva + esquema + unitarias
```

Para el APK hace falta Android Studio y un JDK 17:

```bash
npx cap add android  # una sola vez: genera android/
npm run apk:debug    # android/app/build/outputs/apk/debug/
```

---

## Las dos comprobaciones propias

### `npm run test:deriva`

El formulario está **copiado** de `frontend/src/lib/preinscripcion/`, no
compartido, para que esta carpeta sea autónoma. El precio es la deriva: la web
cambia, nadie se acuerda del APK, y meses después un teléfono manda algo que el
servidor ya no entiende.

El guion no impide la deriva —a veces el APK debe apartarse— la hace **ruidosa**.
Falla si un archivo cambió sin motivo declarado, y el motivo se escribe en el
propio guion.

### `npm run test:esquema`

Aplica `src/local/esquema.sql` en un SQLite real y comprueba lo que la
sincronización necesita poder leer.

Vigila sobre todo **la trampa del PRAGMA**: al escribir el esquema puse
`PRAGMA foreign_keys = ON` arriba y di por hecho que las cascadas funcionaban. No
funcionaban — en SQLite ese pragma es **por conexión**. Borrar un registro desde
otra conexión dejó dos señales y un adjunto huérfanos.

Importa porque WorkManager sincroniza desde Kotlin **con su propia conexión**: al
terminar borra el registro enviado y, sin el pragma, dejaría filas apuntando a
archivos que cree eliminados.

**Regla:** todo código que abra esta base —TypeScript o Kotlin— emite
`PRAGMA foreign_keys = ON` justo después de abrir.

---

## Datos personales en el teléfono

Es lo más delicado de un APK: guarda **fotos de cédula y datos de terceros** en
un aparato que se presta, se pierde y se vende.

- **Los archivos locales se borran al sincronizar.** No se conserva copia.
- **Nada de datos personales en el log**, ni en depuración.
  `webContentsDebuggingEnabled: false` en el APK que se publica.
- **El aviso de la Ley 1581 viaja embebido** con su versión (`habeas-data-v2`):
  sin conexión no se puede pedir al servidor. Un APK viejo mandará una versión
  anterior y el servidor la aceptará mientras siga en `AVISOS_CONOCIDOS`. **No
  vaciar esa lista.**
- **Android no avisa al desinstalar**, así que la aplicación avisa al abrirse
  cuando hay registros sin enviar.
- **HTTPS siempre**, sin excepciones de red.

---

## Distribución

Descarga directa desde el sitio, y de teléfono a teléfono. Exige que la persona
habilite «Fuentes desconocidas», que Android presenta con una advertencia; a
cambio se puede instalar donde no hay señal, que es justamente donde hace falta.

La firma (`*.keystore`, `firma.properties`) **nunca se versiona** — está en el
`.gitignore` de esta carpeta.
