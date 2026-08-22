# El servidor y el APK

Qué se estudió del lado del servidor, qué se decidió y qué queda pendiente.

Nada de esto se aplica desde aquí: son cambios en `backend/`, y un archivo PHP
dentro de `apk/` no lo ejecuta nadie.

| | Estado |
|---|---|
| §1 · Límite por IP | **Descartado.** Resuelto desde el APK |
| §2 · Señales que no se retiran | Pendiente de autorizar |
| §3 · Contrato de respuesta | Nada que hacer — queda escrito para que no cambie |

El parche adjunto ([`servidor-limites-y-senales.patch`](servidor-limites-y-senales.patch))
trae los dos cambios juntos. Si algún día se aplica solo el de §2, hay que
separarlo primero.

---

## 1. El límite por IP — DESCARTADO por decisión de Andrés

**Andrés decidió el 22 de agosto de 2026 no aplicar este cambio.** Queda escrito
lo que se midió, para que la decisión se pueda revisar con datos si algún día
hace falta, y **no vuelve a proponerse.**

El servidor sigue contando cinco envíos por hora y por IP. Los operadores
móviles colombianos usan CGNAT, así que una vereda entera sale por la misma IP.

### Qué pasa de verdad — y aquí me había pasado de dramático

Antes dije que «sin este arreglo el APK no sirve». **No es cierto**, y conviene
dejarlo corregido. Simulando una brigada de veinte familias contra el límite
real, con la escalera de reintentos que trae el APK:

| | Enviadas solas | Piden un toque | La última tarda |
|---|---|---|---|
| Sin honrar `Retry-After` | 15 de 20 | 5 | 320 min |

Nada se pierde: las cinco restantes siguen en el teléfono y salen con el botón
«Reintentar ahora». Es lento, no catastrófico.

### Y se arregló entero desde el APK

Buscando cómo mitigarlo apareció que **`Limite.php` ya manda la cabecera
`Retry-After`** con los segundos que quedan de su ventana, y el APK la estaba
ignorando. Honrarla —`esperaSegunServidor()` en `src/local/sincronizacion.ts`—
cambia el resultado por completo:

| | Enviadas solas | Piden un toque | La última tarda |
|---|---|---|---|
| Honrando `Retry-After` | **20 de 20** | **0** | 180 min |

Sin tocar una línea del servidor. Probé también repartir los intentos al azar
para que veinte teléfonos no coincidan: no aporta nada (20 de 20 igual), así que
**no se añadió** — complejidad sin beneficio medido.

El `dispositivo_id` que el APK genera se conserva: no cuesta nada, no identifica
a nadie y sirve para diagnosticar. Simplemente el servidor no lo usa.

## 2. Una señal de daño no puede desaparecer del catálogo

`Validador::estadoVivienda` **rechaza el envío entero** si llega un código de
señal desconocido. Es lo correcto para la web, donde el formulario siempre está
al día.

Pero el APK lleva el formulario embebido y funciona sin conexión: un teléfono
puede estar mandando el catálogo de hace seis meses. Retirar una señal no
dejaría un campo vacío — **perdería la solicitud completa de una familia**.

### Cómo se resuelve

Regla escrita en `Senales::codigos()` y con una prueba que la hace cumplir: de
`Senales::CATALOGO` **solo se añade, nunca se quita**. La lista de la prueba está
escrita a mano; derivarla del catálogo la haría decir «sí» a cualquier cosa.

Añadir una señal nueva no rompe la prueba. Quitar una, sí. Comprobado
retirando `LUZ_DANADA`: falla.

Si algún día hay que dejar de *ofrecer* una señal, se saca de `paraApi()`
marcándola inactiva y se sigue aceptando en la validación. Es la misma disciplina
que ya protege a `categorias_video`.

---

## 3. El contrato de respuesta NO cambia

El plan original proponía una respuesta nueva (`exito`, `error.codigo`,
`archivos{}`). **Eso rompería la web, que está en producción.**

La API responde hoy, y el APK se adapta a esto:

```
201 { "ok": true,  "data": { "radicado": "PRE-2026-…", "recibido_en": "…",
                             "duplicada": true?, "reintento": true?,
                             "archivos_agregados": 3? } }
422 { "ok": false, "message": "Revisa los datos enviados.",
                   "errors": { "zona": "…" } }
```

Le viene bien: `reintento` y `duplicada` son exactamente las señales que el APK
necesita para marcar un registro como sincronizado sin duplicarlo.

**No hay nada que cambiar aquí.** Queda escrito para que nadie lo cambie después.
