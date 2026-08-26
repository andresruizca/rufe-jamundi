# Bot de Gestión del Riesgo — notas de arquitectura

Qué se tomó del proyecto de referencia `isp-manager`, qué se descartó y por qué.
No hay lógica de negocio de Gestión del Riesgo todavía: esto es el esqueleto.

---

## 1. La decisión de fondo: el bot vive FUERA de WaSphere

WaSphere no trae motor de chatbot. Trae la sesión de WhatsApp, la API REST de
envío, los webhooks firmados y el Team Inbox. `isp-manager` no modificó
WaSphere: montó un servicio propio que **recibe el webhook y responde por la
API REST**. Se conserva esa separación, y por la misma razón que allá:

- WaSphere se actualiza desde su repo upstream sin conflictos de merge.
- El bot puede reiniciarse, fallar o desplegarse solo, sin tumbar la sesión de
  WhatsApp — que es lo caro de recuperar (hay que reescanear el QR).
- Los 3 agentes siguen usando el Team Inbox nativo, no una herramienta paralela.

Aquí el bot es un **quinto servicio** en el mismo `docker-compose`, en la red
interna de WaSphere.

## 2. Handoff a humano: silencio, no enrutamiento

Es el patrón más valioso de la referencia y el que menos código cuesta.

En `isp-manager` el escalamiento **no mueve la conversación a ningún lado**:
marca `mutedUntil = ahora + 4h` en la sesión del bot y el bot deja de
responder a ese número. El mensaje del cliente sigue llegando al mismo número
de WhatsApp, así que **ya está en la bandeja**; el humano simplemente escribe.

Aquí funciona igual y mejor, porque el Team Inbox de WaSphere es justamente la
bandeja compartida donde los 3 agentes ven el hilo. No hay que construir
enrutamiento, colas ni asignación: el handoff es *dejar de hablar*.

Lo que sí se conserva: el aviso al operador (`avisarOperador`) para que alguien
sepa que hay alguien esperando, en vez de depender de que un agente mire la
bandeja por casualidad.

## 3. Verificación del webhook (utilidad genérica reutilizada)

`src/webhook/verify.js` es una adaptación directa de
`backend/src/services/bot/bot.webhook-auth.js`. Es de las pocas cosas que se
copian casi literal, porque es utilidad genérica y porque el formato real de
WaSphere se descubrió a fuerza de tráfico en producción:

- La cabecera es **`x-wasphere-signature`**, y el valor viene como
  **`v1,sha256=<hex>`**. Quitar solo `sha256=` deja el `v1,` delante y **nada
  coincide nunca** — ese fue el bug que rechazaba el 100% de los eventos allá.
  Aquí se limpian ambos prefijos desde el día uno.
- WaSphere manda además `x-wasphere-timestamp` y `x-wasphere-delivery-id`, y
  firma el timestamp junto al cuerpo. Se prueban las combinaciones.
- Comparación en **tiempo constante** (`timingSafeEqual`): una comparación
  normal filtra el secreto por el tiempo de respuesta.
- **Falla cerrado**: si hay secreto configurado y nada coincide, se rechaza.
- Ante rechazo se registran los **nombres** de las cabeceras, nunca sus
  valores, más la firma y el timestamp recibidos (que son derivados, no
  secretos). Es lo que permite diagnosticar sin filtrar el secreto al log.

Se responde **200 incluso a firma inválida**: un 401 le confirmaría a quien
sondea que la URL existe y espera un token.

## 4. Idempotencia por `externalId`

`bot_events.external_id` con índice **único**. WaSphere reintenta el webhook si
la respuesta tarda, y un reintento significa responderle dos veces al
ciudadano. La colisión `P2002` de Prisma se trata como "ya se atendió" y se
corta ahí — no es un error.

Complemento del mismo problema: el webhook **acusa recibo antes de trabajar**
(`res.sendStatus(200)` y luego procesar), para no provocar el reintento.

## 5. Persistencia del contexto

Dos tablas, calcadas en forma de `bot_sessions` / `bot_events`:

- **`bot_sessions`** — una fila por número. Guarda `state` (el nodo del
  autómata), `context` JSONB (lo que se lleva recogido en la conversación),
  `muted_until` (el handoff) y `last_seen_at`.
  - **Caducidad a los 30 min**: si vuelve a escribir al día siguiente, la
    conversación empieza de cero. Retomar un menú a medias confunde más de lo
    que ayuda. Excepción: una sesión ESCALADA no se reinicia sola.
- **`bot_events`** — bitácora de todo, entrante y saliente, con `raw` JSONB.
  Es lo que permite descubrir formatos no documentados del proveedor y auditar
  qué se le dijo a quién.

## 6. El autómata es una función PURA

`src/bot/engine.js` expone `transicionar({estado, texto, contexto}) →
{estado, contexto, respuestas}`. No toca base de datos, no envía nada, no mira
el reloj. Todo el mundo exterior vive en `src/bot/service.js`.

Es lo que hace la conversación **testeable y reproducible**: misma entrada y
mismo estado ⇒ misma respuesta, siempre. En un sistema de Gestión del Riesgo
eso no es elegancia, es requisito: hay que poder demostrar qué se le respondió
a un ciudadano y por qué.

### Dos estados que NO están en `flow.js`

El motor maneja dos estados propios que no son nodos de contenido:

- **`NUEVA`** — conversación que todavía no empezó. Existe porque aquí el nodo
  inicial es un nodo *real*, con texto y opciones, mientras que en la
  referencia `INICIO` saltaba de inmediato a pedir la cédula. Sin este
  centinela, quien está parado en el nodo inicial nunca podría avanzar: cada
  mensaje se leería como "conversación nueva" y volvería a saludar. Es el
  `DEFAULT` de la columna `state`.
- **`ESPERA_INACTIVIDAD`** — lo escribe el vigilante tras preguntar "¿sigues
  ahí?". Al responder se retoma **donde estaba** (`context.estadoAnterior`), no
  se reinicia: reiniciar castigaría a quien tardó en contestar. Si además
  escribió algo accionable, se atiende directo en vez de repetir el nodo.

Un estado huérfano —porque `flow.js` cambió y un nodo dejó de existir— no
rompe: cae al nodo inicial.

**Sin IA**, por la misma razón que en la referencia: un modelo puede inventar
un dato, y aquí los datos son de emergencia y riesgo. El reconocimiento de
intención es un diccionario de sinónimos comparado por raíz de palabra
(`normalizar` + `raiz`, tomados tal cual: el español conjuga mucho y "reporto"
/ "reportar" deben caer en el mismo sitio).

## 7. Lo que se DESCARTÓ del proyecto ISP

- **Identificación obligatoria por cédula antes de atender.** Allá es correcto:
  se van a mostrar saldos y facturas, y un celular se presta. Aquí el primer
  contacto puede ser una emergencia; exigir cédula antes de escuchar sería
  dañino. La identificación queda como paso *opcional y posterior*, dentro del
  flujo que definas.
- **Todo el dominio ISP**: facturación, estado de cuenta, enlaces de pago,
  diagnóstico de PPPoE contra MikroTik. Nada de eso aplica.
- **El menú de 5 opciones y sus textos.** El contenido se define contigo en la
  fase siguiente; `src/bot/flow.js` está deliberadamente vacío y es el único
  archivo que hay que llenar.

## 8. Detalles del proveedor que se conservan (y cuestan caro descubrir)

Vienen medidos en producción en la referencia. Se traen ya resueltos:

- **Responder al JID, no al teléfono.** Cuando el contacto tiene la privacidad
  de número activada, WhatsApp direcciona por LID (`…@lid`) y el teléfono real
  solo viaja como metadato en `senderPn`. Responder al número hace que Baileys
  trate cada dirección como identidad distinta y **el siguiente mensaje del
  cliente ya no descifra** (llega con `content: {}`). Medido allá: fallaba 7 de
  7. Ver `destinoDeRespuesta` en `src/bot/service.js`.
- **Un `@lid` no es un teléfono.** Usarlo como número no identifica a nadie.
- **Timeout de envío amplio (30 s).** WaSphere aplica un retardo aleatorio de
  4–12 s antes de despachar para no ganarse un baneo. Con 10 s, envíos exitosos
  daban timeout y el reintento **duplicaba el mensaje**.
- **Filtrar por tipo de evento.** Solo `message.received` es conversación.
  `message.sent`, `message.delivered`, `message.read`, `session.connected` no
  deben tratarse como formato roto ni provocar respuesta.
- **Descartar ecos propios** (`key.fromMe`) o el bot se responde a sí mismo.
- **Descartar grupos** (`isGroup`).
- **Mensaje entrante sin contenido descifrable**: se le pide al ciudadano que
  reescriba, como máximo una vez cada 2 minutos, y el marcador anti-ráfaga se
  reserva ANTES de enviar (por la ventana de 4–12 s del retardo).

## 9. Límites y cierre por inactividad

- **20 mensajes por número cada 10 minutos.** Tope antiabuso.
- **Inactividad en dos etapas**: a los 5 min "¿sigues ahí?", 2 min después
  despedida y vuelta a INICIO. No toca conversaciones escaladas — si un agente
  está atendiendo, el bot no interrumpe.

## 10. Dónde se conecta el contenido real

`src/bot/flow.js`. Ahí van los nodos, los textos y las intenciones de Gestión
del Riesgo. `src/bot/actions.js` es el punto de integración con el backend del
SGR si el bot debe consultar o registrar algo — hoy son stubs.

Regla que se hereda de la referencia y conviene mantener: **el bot no calcula
nada por su cuenta**, delega en el servicio que ya usa el resto del sistema. Si
el bot calculara, tarde o temprano diría una cifra distinta a la del panel.
