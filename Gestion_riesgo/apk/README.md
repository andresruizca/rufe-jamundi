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
| 0 · Cambios en el servidor | Estudiados — §1 descartado, §2 pendiente |
| 1 · Andamiaje del proyecto | Hecho |
| 2 · Copia del formulario + detector de deriva | Hecho |
| 3 · Captura de foto y video | Hecho |
| 4 · Guardado local y «Mis registros» | Hecho |
| 5 · `ApiCliente.kt` | Hecho — **compila**, en el APK |
| 6 · `SyncWorker.kt` | Hecho — **compila**, en el APK |
| — · Formulario de 4 pasos | Hecho — en el APK |
| — · Catálogo embebido | Hecho |
| 7-9 · Pruebas, campo, firma | Pendiente |

### Sobre el servidor

Ver [`docs/servidor-requerido.md`](docs/servidor-requerido.md).

- **El límite por IP: descartado.** Andrés decidió no tocarlo, y resultó que no
  hacía falta: `Limite.php` ya manda `Retry-After` y el APK lo estaba ignorando.
  Honrándolo, una brigada de veinte familias tras una misma IP pasa de 15 envíos
  solos a **20 de 20**, sin tocar el servidor.
- **Una señal de daño no debería desaparecer del catálogo**, o un APK de hace
  seis meses perdería solicitudes enteras. Pendiente de autorizar.

Los cambios de `backend/` **no están aplicados**: desde esta carpeta no se toca
el servidor.

---

## Cómo se trabaja aquí

```bash
cd apk
npm install

npm run dev          # el formulario en el navegador, sin Android de por medio
npm run check        # tipos
npm test             # deriva + esquema + unitarias
```

Para el APK **no hace falta Android Studio**: basta el SDK por línea de órdenes
y el envoltorio de Gradle que ya trae `android/`.

```bash
brew install --cask android-commandlinetools   # si no está
brew install openjdk@21

export ANDROID_HOME=/opt/homebrew/share/android-commandlinetools
npm run apk:debug        # android/app/build/outputs/apk/debug/
```

**El JDK tiene que ser el 21.** Ni más ni menos, y costó dos intentos
averiguarlo:

- Con el **Java 25** que trae este equipo por omisión, Gradle 8.11 falla con
  «Unsupported class file major version 69» — un mensaje que no menciona por
  ningún lado que el problema sea la versión de Java.
- Con el **17**, falla distinto: `@capacitor/filesystem` declara
  `jvmToolchain(21)`, así que Capacitor 7 no compila por debajo de 21.

Queda fijado en `android/gradle.properties` para que no dependa de la variable
de entorno de cada quien.

---

## El Kotlin compila, pero nadie lo ha ejecutado

`./gradlew assembleDebug` produce un APK de 28 MB con las seis clases dentro
—comprobado buscándolas en los `.dex`— y **sin un solo aviso sobre este código**;
los cuatro que salen son de `@capacitor/filesystem`.

Que compile no es que funcione. **Nadie ha ejecutado nunca este APK en un
teléfono.** Sin eso no se sabe si WorkManager despierta ni si las consultas de
`SyncWorker` devuelven lo que se espera.

Lo que sí dejó de ser una duda son los dos emparejamientos entre el WebView y
Kotlin, comprobados leyendo el código de los complementos:

- **La base**: el plugin compone `dbName + "SQLite.db"` y la guarda en
  `getDatabasePath()`. Kotlin ya no construye esa ruta a mano — se la pide a
  Android, igual que el plugin — y el nombre lo empareja `test:kotlin`.
- **Los archivos**: `Directory.Data` es `context.filesDir` en Android, que es de
  donde los lee `SyncWorker`.

Lo que sí está comprobado es que **los dos lados dicen lo mismo**:
`npm run test:kotlin` compara las escaleras de reintento, el tamaño del trozo,
el tope de `Retry-After`, las cuatro reglas que deciden si una solicitud se
pierde, el orden de los cinco pasos y el `PRAGMA`. Si alguien cambia la
especificación en TypeScript y se olvida del APK, falla.

Lo que **no** comprueba: que compile, que las consultas de `SyncWorker` sean
correctas o que WorkManager despierte. Eso solo lo dice un teléfono.

---

## Las tres comprobaciones propias

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
