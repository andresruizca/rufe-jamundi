# INSTRUCCIÓN — Botón «Enviar WhatsApp» por ciudadano en el módulo Call Center

## CONTEXTO

Las operadoras del Call Center llaman uno por uno a los hogares del censo RUFE
para pedirles que diligencien la pre-inscripción. Muchos no contestan el
teléfono. Se quiere un **botón por hogar** que le mande a esa persona, por
WhatsApp, el mensaje con el enlace al formulario — sin salir de la pantalla y
quedando registrado como una gestión más.

El envío sale por **Zavu** (`https://api.zavu.dev/v1`), la plataforma que ya
opera el número **+57 310 617 3887** y el chatbot de consulta del censo.

---

## LO PRIMERO, PORQUE CONDICIONA TODO

WhatsApp **no permite** escribirle libremente a quien no te ha escrito antes.
La regla es la ventana de 24 horas:

- Si el ciudadano escribió en las últimas 24 h → texto libre, lo que sea.
- Si no → **solo una plantilla aprobada por Meta**, y la personalización se
  limita a sus variables.

Casi ningún hogar del censo habrá escrito primero. Por tanto **este botón envía
la plantilla**, no texto libre. Ya existe creada y enviada a aprobación:

- Plantilla `registro_afectacion_vivienda`, idioma `es`, categoría `UTILITY`
- Id de Zavu: `ks7bwsg7hnfefcaeqsg227rvcs8dbv5g`
- Una sola variable, `{{1}}` = nombre del ciudadano

**No implementes envío de texto libre para este botón.** Fallaría en todos los
casos salvo los pocos con ventana abierta, y el error de Meta no dice
claramente por qué.

---

## ALCANCE

1. Una ruta nueva en el backend que envía la plantilla a UN hogar.
2. Un botón por fila en la pantalla del Call Center.
3. El envío queda registrado en el historial del hogar, como una gestión más.

**Fuera de alcance:** envíos masivos, programación, plantillas nuevas, y
cualquier cosa que mande el mensaje sin que una operadora pulse el botón.

---

## LO QUE NO SE DEBE HACER

- **El token de Zavu NUNCA llega al navegador.** El botón llama a *nuestra* API
  y es el backend quien habla con Zavu. Un token en el frontend es un token
  público: cualquiera con la consola abierta puede mandar WhatsApp desde el
  número oficial de la Alcaldía.
- **No enviar sin registrar.** Un mensaje que sale y no queda en el historial
  hace que la siguiente operadora vuelva a mandarlo.
- **No inventar un endpoint masivo.** Un botón, un hogar, una pulsación.
- **No cambiar el texto de la plantilla desde el código.** Está aprobada por
  Meta tal cual; cualquier cambio exige nueva aprobación.

---

## DISEÑO

### 1. Configuración

En `backend/config.example.php`, bloque nuevo:

```php
// Envío de WhatsApp por Zavu desde el Call Center. Con el token vacío el
// botón responde 503 y no se envía nada: la función no existe hasta que
// alguien la habilite. El valor real va SOLO en config.php.
'zavu' => [
    'api_token'   => '',                                   // zv_live_…
    'base_url'    => 'https://api.zavu.dev/v1',
    'sender_id'   => 'kd74xhwdx91gf1d2wfcfzydgmd8d843y',
    'plantilla_id'=> 'ks7bwsg7hnfefcaeqsg227rvcs8dbv5g',
    'timeout_ms'  => 15000,
],
```

### 2. Ruta

En `public/index.php`, junto a las demás del módulo y **con el mismo rol**:

```php
$router->post('/callcenter/hogares/{id}/whatsapp', [$callCenter, 'enviarWhatsapp'], Auth::CALL_CENTER);
```

### 3. `CallCenterController::enviarWhatsapp()`

En orden:

1. **Cargar el hogar** por `id`, con el jefe de hogar del mismo modo que
   `CRUCE` (subconsulta, no JOIN — ver el comentario que ya está escrito ahí
   sobre por qué un JOIN duplica filas).
2. **Elegir el teléfono**: `jefe.telefono` si existe, si no `r.contacto_telefono`.
   Si no hay ninguno, 422 con un mensaje claro; no es un fallo del sistema.
3. **Normalizar a E.164.** Solo dígitos; un móvil colombiano de 10 dígitos que
   empiece por 3 lleva `57` delante. Reutiliza el criterio que ya usa el
   proyecto para teléfonos si existe uno; si no, escríbelo en un método propio
   y pruébalo aparte.
4. **Comprobar el guardarraíl de repetición** (ver §4).
5. **Llamar a Zavu**:

```
POST {base_url}/messages
Authorization: Bearer {api_token}
Zavu-Sender: {sender_id}

{
  "to": "+57XXXXXXXXXX",
  "channel": "whatsapp",
  "messageType": "template",
  "idempotencyKey": "rufe-<reporte_id>-<AAAAMMDD>",
  "content": {
    "templateId": "{plantilla_id}",
    "templateVariables": { "1": "<nombres del jefe de hogar>" }
  }
}
```

**`channel` es obligatorio.** Si se omite, un mensaje de texto se va por SMS,
no por WhatsApp — y no hay ningún error que lo delate, solo un SMS cobrado que
nadie esperaba.

**`idempotencyKey` con la fecha** convierte el doble clic en un solo envío,
que es el fallo más probable de un botón que tarda dos segundos en responder.

6. **Registrar SIEMPRE la gestión**, salga bien o mal (§5).
7. **Responder** el resultado para que la pantalla lo muestre: enviado, o el
   motivo del fallo tal como lo devolvió Zavu.

### 4. Guardarraíl: no repetir el mensaje

Antes de enviar, comprobar si ya se le mandó a ese hogar en las **últimas 24
horas**. Si sí, responder 409 con la fecha del envío anterior y **no enviar**.

No es una preferencia de estilo. Son hogares que acaban de perder parte de su
casa; recibir tres veces el mismo mensaje automático de la Alcaldía es
maltrato, y además cada plantilla se cobra. Con varias operadoras trabajando la
misma lista, sin este guardarraíl pasa el primer día.

La pantalla debe pedir confirmación antes de enviar, y el botón deshabilitarse
mientras la petición está en curso.

### 5. Registro en el historial

Un envío es una gestión más: va a `rufe_gestiones`, para que el historial del
hogar sea una sola línea de tiempo y no dos.

**Pero no es una llamada, y no debe contarse como tal.** Hoy el módulo cuenta
`intentos` con `COUNT(*) FROM rufe_gestiones` y tiene `MAX_INTENTOS_UTILES`.
Si un WhatsApp suma ahí, un hogar al que nadie ha llamado aparecerá como si se
le hubiera intentado cinco veces, y **la cifra de avance que se le reporta a la
Alcaldía queda inflada** — el mismo problema que ya causó el JOIN duplicando
filas, y que está documentado en el propio controlador.

Por eso:

- **Migración aditiva**: añadir a `rufe_gestiones` una columna
  `canal VARCHAR(20) NOT NULL DEFAULT 'LLAMADA'`. El valor por omisión deja
  todas las filas históricas correctamente marcadas como llamadas.
- Los envíos se guardan con `canal = 'WHATSAPP'`.
- **Excluir `canal = 'WHATSAPP'` de los conteos de intentos** y de la lógica de
  `MAX_INTENTOS_UTILES`. Busca todos los sitios donde se cuentan gestiones; no
  basta con el de `listar()`.
- Dos resultados nuevos, en la constante `RESULTADOS`:
  `WHATSAPP_ENVIADO` («Se le envió el formulario por WhatsApp») y
  `WHATSAPP_FALLIDO` («No se pudo enviar el WhatsApp»). En el fallido, el
  motivo va en `nota`.

### 6. Frontend

Un botón por fila en la lista del Call Center, y otro en la pantalla de
atención. Debe mostrar:

- **Deshabilitado** si el hogar no tiene teléfono, con el motivo al pasar el ratón.
- **Deshabilitado** si ya se envió en las últimas 24 h, indicando cuándo.
- **En curso** mientras se envía (Zavu puede tardar un par de segundos).
- El resultado, y el historial actualizado sin recargar la página.

Sigue el estilo del módulo; no introduzcas componentes ni dependencias nuevas.

---

## PRUEBAS QUE DEBEN PASAR

En `backend/tests/run.php`, con el estilo de las que ya están:

1. Sin `zavu.api_token` configurado, la ruta responde 503 y **no** intenta salir a internet.
2. Un hogar sin ningún teléfono da 422 y no registra gestión de envío.
3. La normalización: `3001112233` → `+573001112233`; un número que ya trae
   indicativo no se toca; uno con puntos y espacios se limpia.
4. Enviar dos veces en menos de 24 h: el segundo da 409 y **no** llama a Zavu.
5. Un envío fallido registra igualmente la gestión, con `WHATSAPP_FALLIDO` y el
   motivo en `nota`.
6. **Las gestiones con `canal = 'WHATSAPP'` no cuentan como intentos de llamada**
   ni en la lista ni en el resumen.
7. El rol se respeta: sin `Auth::CALL_CENTER` la ruta no se sirve.
8. El cuerpo enviado a Zavu lleva `channel: "whatsapp"` y un `idempotencyKey`.

Aísla la llamada HTTP a Zavu detrás de un método propio para poder probar todo
lo anterior sin red, igual que `planDeLimites()` se separó para poder probarse
sin MySQL.

---

## CRITERIOS DE ACEPTACIÓN

- Con el token vacío, el sistema se comporta exactamente como antes.
- El token de Zavu no aparece en ninguna respuesta, log ni archivo del frontend.
- La migración es aditiva; ninguna fila existente cambia de significado.
- Las cifras de avance del Call Center son idénticas antes y después para los
  hogares a los que no se les ha mandado WhatsApp.
- Las pruebas nuevas pasan y las existentes siguen pasando.

## AL TERMINAR

Entrega: archivos tocados, la migración a aplicar, y qué falta para que el
botón funcione de verdad en producción.

**Advierte explícitamente** de que hoy el envío fallará aunque el código esté
bien, porque faltan dos cosas del lado de Meta que no se arreglan desde este
proyecto: la plantilla sigue en revisión (`pending`) y la cuenta de WhatsApp
Business no tiene medio de pago activo (`canSendTemplates: false`).
