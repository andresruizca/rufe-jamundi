# Lo que el servidor necesita antes de que el APK sirva

Este documento vive aquí porque describe cosas del APK, **pero lo que describe no
puede aplicarse desde aquí**: son cambios en `backend/`, que es el código que la
API ejecuta. Un archivo PHP dentro de `apk/` no lo corre nadie.

Están escritos, medidos y probados —el parche adjunto es el trabajo real, no un
boceto— y quedan a la espera de que Andrés decida cuándo aplicarlos al servidor.

El parche: [`servidor-limites-y-senales.patch`](servidor-limites-y-senales.patch)

```bash
# Desde la raíz del repositorio, cuando se autorice:
git apply apk/docs/servidor-limites-y-senales.patch
php backend/tests/run.php
```

---

## 1. El límite por IP bloquea al APK — el más grave

**Sin esto el APK falla en campo aunque esté perfectamente construido.**

`backend/src/Core/Limite.php` cuenta **solo por IP**, y
`PreinscripcionController` fija `MAX_ENVIOS_HORA = 5`.

Los operadores móviles colombianos usan CGNAT: **una vereda entera sale por la
misma IP pública**. Una brigada que ayuda a veinte familias a registrarse y baja
al pueblo a sincronizar recibiría cinco radicados y quince rechazos, por hacer
exactamente lo que se le pidió.

### Cómo se resuelve

El APK genera un `dispositivo_id` aleatorio al instalarse y lo manda en cada
petición. El límite pasa a contar dos presupuestos con contadores separados:

| Sujeto | Tope | Para qué |
|---|---|---|
| Dispositivo | el de siempre (5 envíos/hora) | Es el tope de una persona |
| Conexión (IP) | ×8 el anterior | Sigue frenando a un robot |

Sin `dispositivo_id` —el formulario web— **nada cambia**: se cuenta por IP con el
tope original.

Es un ensanche deliberado y acotado: quien falsifique identificadores consigue el
techo por conexión, no la barra libre.

### Medido contra MySQL, no supuesto

| Escenario | Resultado |
|---|---|
| 12 cargas del formulario web (sin dispositivo) | 10 aceptadas, luego 429 — **el web no cambia** |
| 20 teléfonos distintos, misma IP — *la vereda* | **20 aceptadas** (antes habrían pasado 10) |
| 1 teléfono insistiendo 14 veces | Se corta en el 11 — un aparato no se salta su cuota |
| Robot rotando 90 identificadores desde una IP | **Se corta exactamente en 80** |

Archivos: `backend/src/Core/Limite.php` (renombra `$ip` a `$sujeto`),
`backend/src/Controllers/PreinscripcionController.php` (añade `dispositivo()` y
`limitar()`).

---

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
