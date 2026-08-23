# La sincronización en iOS no es la de Android

Esto no es un detalle de implementación pendiente. Es una diferencia de fondo
entre las dos plataformas, y conviene decidirla con los ojos abiertos antes de
prometerle nada a nadie.

## Lo que hace el APK de Android

`SyncWorker.kt` corre con **WorkManager**: el ciudadano llena el formulario en
una vereda, guarda, cierra la aplicación y se olvida. Cuando el teléfono ve
señal —al día siguiente, camino al pueblo— Android despierta la tarea y la
solicitud sale sola. La aplicación puede llevar semanas sin abrirse.

## Lo que iOS NO permite

iOS no tiene equivalente. Lo más parecido es `BGTaskScheduler`, y la propia
documentación de Apple es explícita: la ejecución en segundo plano **no está
garantizada**, es oportunista y la decide el sistema.

Hay una frase de los foros de Apple que resume el problema para este caso
concreto:

> *Unless you have an app that the user is checking regularly, an app refresh
> task won't help you. It's unlikely that the system will ever decide to run your
> task and, even if it does, that commitment will fade away over time.*

Y eso describe exactamente a un ciudadano que instala la aplicación una vez,
registra su vivienda y no vuelve a abrirla. **Es el peor caso posible para
`BGTaskScheduler` y es el caso normal de esta aplicación.**

## Qué se hace entonces

Tres disparadores, y hay que ser honestos sobre cuánto vale cada uno:

| Cuándo | Qué tan fiable |
|---|---|
| Al abrir la aplicación | **Total.** Es el que de verdad sostiene iOS |
| Al volver al frente | **Total.** Cubre a quien la deja abierta y sale a buscar señal |
| `BGTaskScheduler` | **Ninguna garantía.** Regalo, no plan |

La consecuencia práctica: en iPhone, **la persona tiene que abrir la aplicación
una vez cuando tenga señal**. En Android no hace falta.

## Y por eso la pantalla dice otra cosa

En Android la aplicación promete «se enviará solo, no hace falta que abra la
aplicación». En iPhone esa frase sería mentira, y una mentira cara: alguien
esperaría semanas a que saliera una solicitud que nunca va a salir.

El texto de iOS tiene que pedir lo que de verdad hace falta:

> **Su registro quedó guardado.** Cuando tenga internet, abra esta aplicación
> una vez y se enviará. Le avisaremos con su número de radicado.

Es una línea de diferencia y es la más importante de las dos aplicaciones.

## Lo que NO se debe hacer

- **Prometer envío automático.** Ver arriba.
- **Pedir `Background Modes` que no correspondan.** Declarar `location` o
  `audio` para colar ejecución en segundo plano es un rechazo seguro en revisión
  de App Store, y además gastaría la batería de alguien que está en una
  emergencia.
- **Una notificación local que diga «abra la aplicación».** Se puede, pero exige
  permiso de notificaciones y es ruido para quien ya tiene un problema. Antes de
  añadirla, medir cuántas solicitudes se quedan sin salir.

## Fuentes

- [BGTaskScheduler — Apple Developer](https://developer.apple.com/documentation/backgroundtasks/bgtaskscheduler)
- [Background Tasks — foros de Apple Developer](https://developer.apple.com/forums/thread/131205)
