# INSTRUCCIÓN — Permitir que el bot de WhatsApp consulte el censo sin agotar el límite por IP

## CONTEXTO

El canal de WhatsApp de Gestión del Riesgo (proyecto aparte, sobre Zavu) va a
consultar `POST /preinscripcion/verificacion` para decirle al ciudadano si su
cédula está en el censo RUFE y, si lo está, enviarle el enlace de
pre-inscripción.

El problema es de arquitectura, no de configuración:

`backend/src/Controllers/PreinscripcionController.php` limita a **15 consultas
por hora y 40 por día, por IP** (`MAX_VERIFICACIONES_HORA` /
`MAX_VERIFICACIONES_DIA`). Para la web es correcto: cada ciudadano llega con su
propia IP y su propia cubeta.

El bot no. Consulta desde **un solo servidor**, así que todos los ciudadanos
comparten una única cubeta. **En la consulta 16 de la hora el bot deja de
funcionar para todo el mundo** y responde «Ha consultado demasiadas veces desde
esta conexión». En un canal de emergencias eso se agota en minutos.

---

## LO QUE NO SE DEBE HACER

**No quites ni debilites la protección contra fuerza bruta.** Este endpoint es
un oráculo: dice si una cédula está o no en el censo de damnificados. Sin límite
efectivo, cualquiera puede enumerar cédulas y reconstruir quién es damnificado
en Jamundí. La decisión de que devuelva **solo un booleano** ya está tomada y
documentada en `backend/public/index.php` — respétala.

El objetivo **no** es levantar el límite. Es **cambiar la clave con la que se
cuenta**: del servidor del bot al ciudadano que está escribiendo.

Tampoco se debe:

- Añadir una ruta pública que devuelva datos personales.
- Permitir el bypass sin secreto configurado.
- Cambiar en nada el comportamiento para el tráfico web normal.

---

## PUNTO DE INSERCIÓN

`backend/src/Core/Limite.php` ya está bien diseñado para esto y **no hay que
tocarlo**. Su clave es `sha256(accion|$ip|ventana|sal)`, así que el parámetro
`$ip` es en realidad **una identidad opaca cualquiera**: se le puede pasar el
número de WhatsApp en vez de la IP sin cambiar la tabla `rufe_limite` ni el
esquema.

Todo el cambio vive en `PreinscripcionController::verificar()` más una entrada
de configuración.

---

## DISEÑO

### 1. Configuración nueva

En `backend/config.example.php`, dentro del bloque `'rufe'`:

```php
// Secreto compartido con el bot de WhatsApp. VACÍO por omisión: mientras lo
// esté, la consulta de cédula se limita por IP como siempre y no existe
// ninguna vía alterna. Generar con:
//   php -r "echo bin2hex(random_bytes(32));"
'servicio_secreto' => '',
```

Documenta en `config.example.php` que el valor real va solo en `config.php`,
que no se versiona.

### 2. Cabeceras que envía el bot

```
X-RUFE-Servicio: <el secreto>
X-RUFE-Origen:   <número de WhatsApp del ciudadano, solo dígitos>
```

### 3. Decidir si la petición es del servicio

En `verificar()`, antes de consumir ningún límite:

```php
$secreto = (string) Config::get('rufe.servicio_secreto', '');
$enviado = (string) ($req->cabecera('X-RUFE-Servicio') ?? '');
$origen  = Censo::normalizar((string) ($req->cabecera('X-RUFE-Origen') ?? ''));

$esServicio = $secreto !== ''
    && $enviado !== ''
    && hash_equals($secreto, $enviado)
    && $origen !== '';
```

Tres reglas que no son negociables:

- **`hash_equals`**, nunca `===`: una comparación normal filtra el secreto por
  el tiempo de respuesta.
- **Si el secreto no está configurado, `$esServicio` es siempre falso.** La vía
  alterna no existe hasta que alguien la habilite a conciencia.
- **Falla hacia lo estricto.** Si la cabecera viene y el secreto no coincide, o
  si falta `X-RUFE-Origen`, **no devuelvas 401**: cae al camino de siempre,
  el de IP. Un 401 le confirmaría a quien tantea que acertó el nombre de la
  cabecera, y además evita un camino de error nuevo que mantener.

### 4. Los límites cuando sí es el servicio

Sustituyen a los de IP, no se suman:

| Clave | Límite | Para qué |
|---|---|---|
| `preinscripcion.verificar.origen.hora` con `$origen` | 10 / hora | Que un ciudadano no enumere desde su WhatsApp |
| `preinscripcion.verificar.origen.dia` con `$origen` | 30 / día | Lo mismo, en ventana larga |
| `preinscripcion.verificar.servicio.hora` con la constante `'bot'` | 300 / hora | **Techo global del bot** |

Los dos primeros son el equivalente del límite por IP, con la clave correcta:
el ciudadano. El tercero es lo importante y no se puede omitir.

**Por qué el techo global es imprescindible:** el `X-RUFE-Origen` lo dice el
propio bot. Si alguien roba el secreto, puede inventar un origen distinto en
cada petición y saltarse los dos primeros límites sin esfuerzo. El techo global
es lo único que acota el daño: con 300/hora, enumerar un censo de decenas de
miles de cédulas tomaría meses y quedaría a la vista en la tabla `rufe_limite`.
No lo subas «por si acaso» — 300/hora son 5 consultas por segundo sostenidas,
de sobra para tres agentes.

Define los tres como constantes junto a las que ya existen, con el mismo estilo.

### 5. Mensajes de error

El mensaje actual («Ha consultado demasiadas veces **desde esta conexión**») es
falso para el bot: el ciudadano no comparte conexión con nadie. Usa textos
propios en el camino de servicio:

- Por origen: «Ha consultado demasiadas veces. Espere unos minutos e intente de
  nuevo.» + la línea de atención en el de día.
- Por techo global: el 429 con `Retry-After` que ya emite `Limite`. Ese caso es
  una anomalía operativa, no algo que el ciudadano pueda resolver: **regístralo
  en el log de la aplicación** para que alguien se entere.

### 6. Lo que no debe pasar nunca

- Ni el secreto ni el número de WhatsApp pueden aparecer en logs, mensajes de
  error ni respuestas. `Limite` ya hashea la identidad con sal; no la escribas
  en ningún otro sitio.
- La respuesta del endpoint **no cambia**: sigue siendo
  `{habilitado, linea_atencion}` y nada más, venga de donde venga.
- Rechaza la vía de servicio si la petición no llegó por HTTPS.

---

## PRUEBAS QUE DEBEN PASAR

Añádelas a `backend/tests/run.php`, con el estilo de las que ya están:

1. Sin `rufe.servicio_secreto` configurado, una petición con las cabeceras del
   servicio se limita **por IP** exactamente como hoy.
2. Con el secreto configurado y correcto, dos orígenes distintos tienen cubetas
   **independientes**: agotar el de uno no afecta al otro.
3. Con secreto **incorrecto**, se cae al límite por IP y **no** se devuelve 401.
4. Con secreto correcto y **sin** `X-RUFE-Origen`, se cae al límite por IP.
5. El techo global corta al superar su máximo aunque cada origen sea distinto.
6. La respuesta sigue siendo solo `{habilitado, linea_atencion}` en ambos
   caminos.
7. Una cédula con formato inválido sigue devolviendo 422 antes de consumir
   cualquier límite.
8. El comportamiento del tráfico web **no cambia en nada**: mismos límites,
   mismos mensajes.

---

## CRITERIOS DE ACEPTACIÓN

- `backend/src/Core/Limite.php` queda **sin modificar**.
- La tabla `rufe_limite` queda **sin migración**.
- Con el secreto vacío, el sistema se comporta byte a byte como antes.
- Las pruebas nuevas pasan y las existentes siguen pasando.
- `config.example.php` documenta la variable; `config.php` real no se versiona.

---

## AL TERMINAR

Entrega: qué archivos tocaste, el comando para generar el secreto, y las
cabeceras exactas que debe enviar el bot. **No generes ni escribas el secreto
real en ningún archivo versionado.**
