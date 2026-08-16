# Tablero RUFE — Sismo Jamundí

Tablero interactivo de personas y hogares registrados en el RUFE (Registro
Único de Familias/personas afectadas, formulario **FR-1703-SMD-69**) por el
sismo del 10 de agosto de 2026 en Jamundí (Valle del Cauca): mujeres,
hombres, niños, jóvenes, adultos y adultos mayores; hogares agrupados;
estado y tipo del bien; forma de tenencia; visitas técnicas; personal
evacuado; y observaciones críticas — todo por zona rural/urbana y por
barrio/vereda.

**Datos en vivo, sin backend propio**: el tablero se conecta directo, desde
el navegador de quien lo visita, a la hoja de Google del consolidado RUFE
(compartida como "Cualquiera con el enlace puede ver") y se refresca solo
cada 3 minutos mientras la pestaña está visible. No hay servidor, API ni
credenciales que gestionar — ver [`src/lib/rufe/live.ts`](src/lib/rufe/live.ts).

Sitio publicado: **<https://miltonf10.github.io/rufe-jamundi/>**

---

## Stack tecnológico

| Capa                                  | Tecnología                                                                                          | Versión | Por qué                                                                                                                             |
| ------------------------------------- | --------------------------------------------------------------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Framework                             | [SvelteKit](https://svelte.dev/docs/kit)                                                            | 2.63    | SPA/SSG estándar de Svelte; permite crecer a más rutas sin reestructurar                                                            |
| UI runtime                            | [Svelte 5](https://svelte.dev/docs/svelte) (**runes**: `$state`, `$derived`, `$props`, `$effect`)   | 5.56    | Reactividad explícita, sin virtual DOM                                                                                              |
| Lenguaje                              | TypeScript                                                                                          | 6.0     | Tipado en todo `src/lib` (parser, agregaciones, tipos de dominio)                                                                   |
| Build tool                            | Vite                                                                                                | 8.0     | Empaquetado, dev server, `vitest` comparte la misma config                                                                          |
| Adapter                               | [`@sveltejs/adapter-static`](https://svelte.dev/docs/kit/adapter-static)                            | 3.0     | Exporta un sitio 100% estático (HTML/JS/CSS), sin Node en producción                                                                |
| Prerender                             | `export const prerender = true` (`src/routes/+layout.ts`)                                           | —       | Una sola ruta, todo se genera en build                                                                                              |
| Parseo de CSV                         | [PapaParse](https://www.papaparse.com/)                                                             | 5.6     | Maneja comillas, comas y saltos de línea embebidos dentro de celdas — un `split(',')` manual se rompe con los datos reales del RUFE |
| Iconos                                | [`@lucide/svelte`](https://lucide.dev) (**no** el paquete `lucide-svelte`, deprecado)               | 1.31    | Mismo set de iconos que usa INNOLAB (la referencia de marca)                                                                        |
| Tipografía                            | [Inter](https://fonts.google.com/specimen/Inter) vía Google Fonts, `font-display: swap`             | —       | La misma que usa `oticjamundi.com/innolab`                                                                                          |
| Estilos                               | CSS plano con custom properties (`src/lib/theme.css`), sin framework de CSS                         | —       | Paleta institucional tokenizada, tema claro/oscuro, sin dependencia extra                                                           |
| Testing                               | [Vitest](https://vitest.dev)                                                                        | 4.1     | 46 pruebas — parser del CSV, agregación por hogar, filtros, orden, criticidad de observaciones                                      |
| Chequeo de tipos                      | `svelte-check` (`svelte-kit sync` + tsc)                                                            | 4.6     | Valida `.svelte` + `.ts` a la vez                                                                                                   |
| Lint / formato                        | ESLint 10 (`typescript-eslint`, `eslint-plugin-svelte`) + Prettier 3 (`prettier-plugin-svelte`)     | —       | Config plana (`eslint.config.js`), tabs + comillas simples (`prettier.config.js`)                                                   |
| Scripting de datos                    | [`tsx`](https://github.com/privatenumber/tsx) (ejecuta TypeScript directo, sin paso de compilación) | 4.23    | `scripts/refresh-snapshot.ts` reusa el parser de `src/lib` tal cual                                                                 |
| Runtime de Node exigido en CI/scripts | Node 22 (`engine-strict=true` en `.npmrc`)                                                          | —       | Fijado en `.github/workflows/deploy.yml` (`actions/setup-node@v4`)                                                                  |
| CI/CD                                 | GitHub Actions                                                                                      | —       | Test + type-check + build + deploy en cada push a `main`                                                                            |
| Hosting                               | GitHub Pages (`actions/upload-pages-artifact` + `actions/deploy-pages`)                             | —       | Gratis, estático, HTTPS automático; el mismo `build/` también sirve para hosting propio (ver más abajo)                             |
| Fuente de datos                       | Google Sheets, export CSV público (`docs.google.com/spreadsheets/d/…/export?format=csv`)            | —       | CORS permisivo confirmado (`access-control-allow-origin: *`), permite `fetch()` directo desde el navegador sin proxy                |

No hay backend, base de datos, autenticación ni variables de entorno secretas
en este proyecto — todo el "servidor" es el build estático más la hoja de
Google como fuente de datos pública de solo lectura.

## Estructura del proyecto

```text
rufe-jamundi/
├── .github/workflows/deploy.yml     # CI: test + check + build + deploy a GitHub Pages
├── .npmrc                           # engine-strict=true (fuerza Node 22 en CI/local)
├── .prettierignore
├── eslint.config.js                 # ESLint flat config (js + ts + svelte + prettier)
├── prettier.config.js               # tabs, comillas simples, sin coma final
├── tsconfig.json                    # extiende .svelte-kit/tsconfig.json (generado)
├── vite.config.ts                   # plugin sveltekit(), adapter-static, base path, config de vitest
├── data/                            # notas locales (sin datos crudos versionados, ver Privacidad)
├── scripts/
│   └── refresh-snapshot.ts          # regenera src/lib/data/rufe-fallback.json desde la hoja en vivo
├── static/
│   └── robots.txt
└── src/
    ├── app.html                     # shell HTML, preconnect + carga de Inter, theme-color
    ├── app.d.ts                     # ambiente de tipos global de SvelteKit
    ├── routes/
    │   ├── +layout.ts               # prerender = true
    │   ├── +layout.svelte           # importa theme.css, monta la app
    │   └── +page.svelte             # el tablero completo: estado, filtros, fetch en vivo, 12 tarjetas
    └── lib/
        ├── theme.css                # tokens de color/tipografía/espaciado (claro + oscuro)
        ├── data.ts                  # re-exporta tipos + FALLBACK_DATA (snapshot de respaldo)
        ├── aggregate.ts             # agregación a nivel PERSONA (totales, % , orden de tabla)
        ├── hogaresAggregate.ts      # agregación a nivel HOGAR (bien, tenencia, visitas, evacuados, observaciones)
        ├── data/
        │   └── rufe-fallback.json   # snapshot agregado (sin datos personales), generado por el script
        ├── assets/
        │   ├── logo-jamundi.svg     # escudo oficial (jamundi.gov.co)
        │   └── favicon.svg
        ├── rufe/
        │   ├── source.ts            # SHEET_ID + SHEET_CSV_URL
        │   ├── types.ts             # Zona, Barrio, Hogar, Dataset
        │   ├── parse.ts             # ÚNICA fuente de verdad del parseo/agregación del CSV
        │   ├── parse.spec.ts        # pruebas del parser (16 casos)
        │   └── live.ts              # fetchLiveDataset(): fetch + parseRufeCsv desde el navegador
        └── components/
            ├── Header.svelte        # escudo + título + contador de personas/hogares
            ├── KpiTile.svelte       # tarjeta numérica (mujeres, hombres, niños, …)
            ├── BarRow.svelte        # fila de barra horizontal (valor/máximo, color, variante "dim")
            ├── ZonaFilter.svelte    # selector Todas/Urbana/Rural
            ├── SearchBox.svelte     # buscador por barrio/vereda
            ├── BarrioTable.svelte   # tabla ordenable, con scroll horizontal en móvil
            ├── LiveStatus.svelte    # punto pulsante + estado del fetch en vivo + botón "Actualizar"
            └── ObservacionesList.svelte  # lista de observaciones, toggle "solo críticas", código de hogar
```

## Cómo funcionan los datos

```text
Hoja de Google (RUFE) ──fetch()──▶ src/lib/rufe/parse.ts ──▶ Dataset { barrios[], hogares[], warnings[] }
        ▲                                  ▲
        │ cada 3 min, desde el             │ misma función, sin duplicar lógica
        │ navegador de cada visitante      │
        │                                  │
        └── src/lib/rufe/live.ts ──────────┤
                                            │
                          scripts/refresh-snapshot.ts ──▶ src/lib/data/rufe-fallback.json (respaldo versionado)
```

- **`src/lib/rufe/parse.ts`** es la única implementación del parseo/agregación
  del CSV crudo (mapeo de columnas del formulario FR-1703-SMD-69, relleno
  hacia adelante de corregimiento/barrio dentro de un mismo hogar,
  canonicalización de textos libres como estado del bien/tenencia,
  clasificación de zona rural/urbana, bucketing de edad, agregación por
  barrio **y** por hogar). La usan tanto el fetch en vivo del navegador
  (`live.ts`) como el script de refresco (`scripts/refresh-snapshot.ts`) —
  a propósito, para que nunca haya dos implementaciones que puedan
  desacordarse (ya pasó una vez con un pipeline en Python separado, ver el
  comentario sobre los hogares 91/117 en ese archivo).
- **Agregación a nivel hogar vs. a nivel persona**: campos como estado del
  bien, tipo de bien, forma de tenencia, visita técnica, evacuación y
  observación son propiedades de la **vivienda**, no de cada integrante —
  contarlos por persona los infla según el tamaño del hogar. `parse.ts`
  construye un `Hogar` por número de hogar (tomando el primer valor no vacío
  visto entre sus integrantes) y `hogaresAggregate.ts` agrega sobre esa
  lista, no sobre las personas. Para "cuánto personal ha sido evacuado" sí
  se necesita el conteo por persona (un hogar evacuado de 6 no cuenta igual
  que uno de 1), por eso `Hogar.personas` guarda cuántas personas
  pertenecen a ese hogar.
- **Filas de relleno**: filas sin nombre, apellido ni documento (comunes al
  final de un hogar o como separadores en la hoja) se excluyen de
  `records`, pero antes de excluirlas ya aportaron al relleno hacia
  adelante de corregimiento/barrio si tenían esos datos.
- **`src/lib/data/rufe-fallback.json`** es solo un snapshot de respaldo: lo
  que se muestra en el primer render (antes de que termine el fetch en
  vivo) y si la hoja deja de estar accesible. Se refresca con:

  ```sh
  npm run data:refresh
  ```

- Si el script imprime advertencias de "zona inconsistente", es que algún
  hogar de la hoja tiene un corregimiento/barrio contradictorio entre sus
  integrantes — no rompe el tablero (se queda con la primera zona vista
  para ese barrio, y lo reporta en `warnings`), pero conviene revisarlo en
  la hoja fuente cuando haya tiempo. Este comportamiento es deliberado: la
  hoja la sigue editando personal de campo en tiempo real, así que el
  parser está diseñado para **nunca lanzar una excepción** por datos
  inconsistentes, solo para advertir.
- Si la hoja deja de estar en "Cualquiera con el enlace puede ver", el
  tablero lo detecta (`LiveStatus` muestra "Sin conexión con la hoja") y
  sigue mostrando el último snapshot en vez de romperse.

## Tarjetas del tablero

| Tarjeta                          | Icono               | Contenido                                                                                                                                                                 |
| -------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hogares registrados              | `House`             | Total de hogares (agrupados por número de hogar/código de familia), desglose urbana/rural, promedio de personas por hogar                                                 |
| Zona rural / urbana              | `MapPin`            | Personas por zona, según el corregimiento reportado                                                                                                                       |
| Grupo de edad                    | `CalendarDays`      | Niños (0–11) · Jóvenes (12–28) · Adultos (29–59) · Adultos mayores (60+) · sin dato                                                                                       |
| Género por zona                  | `VenusAndMars`      | Mujeres/hombres, separado por zona                                                                                                                                        |
| Barrios/veredas con más personas | `BarChart3`         | Ranking de los 12 barrios/veredas con más personas, dentro del filtro activo                                                                                              |
| Estado del bien                  | `ShieldAlert`       | Habitable / Averiado / Destruido / Sin dato, con colores de severidad fijos (`--status-good/warning/serious/critical`)                                                    |
| Tipo de bien                     | `Building2`         | Vivienda, local comercial, etc.                                                                                                                                           |
| Forma de tenencia                | `KeyRound`          | Propietario / Arrendatario / Poseedor / Ocupante / Sin dato                                                                                                               |
| Visitas técnicas                 | `ClipboardCheck`    | Si ya se hizo la visita de verificación al predio                                                                                                                         |
| Personal evacuado                | `Siren`             | Hogares y **personas** evacuadas (no son lo mismo si los hogares tienen tamaños distintos)                                                                                |
| Observaciones                    | `MessageSquareText` | Etiquetas por palabra clave (grietas, colapso, riesgo, evacuación urgente, fuga…), críticas marcadas en rojo; lista completa con código de hogar y toggle "solo críticas" |
| Detalle por barrio/vereda        | `Table2`            | Tabla completa ordenable por cualquier columna, con scroll horizontal en móvil                                                                                            |

Filtros globales (zona + búsqueda por barrio) afectan a todas las tarjetas
que dependen de personas/barrios a la vez, vía estado reactivo (`$derived`)
en `+page.svelte`.

## Identidad visual

Colores y tipografía tomados **verbatim** de la hoja de estilos en
producción de [INNOLAB](https://oticjamundi.com/innolab) (Oficina TIC de la
Alcaldía de Jamundí, `assets/css/variables.css`); escudo oficial tomado de
[jamundi.gov.co](https://jamundi.gov.co). Tokens completos en
[`src/lib/theme.css`](src/lib/theme.css):

| Token                                                | Valor                                           | Uso                                                                                                     |
| ---------------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `--color-primary`                                    | `#1577D6`                                       | Azul Jamundí (marca)                                                                                    |
| `--color-primary-deep`                               | `#0A3D7A`                                       | Degradado de header                                                                                     |
| `--color-secondary`                                  | `#22B6C6`                                       | Teal/cian                                                                                               |
| `--color-accent`                                     | `#F07A3F`                                       | Coral                                                                                                   |
| `--color-highlight`                                  | `#FFC42E`                                       | Amarillo dorado Jamundí                                                                                 |
| `--status-good` / `warning` / `serious` / `critical` | `#0CA30C` / `#FAB219` / `#EC835A` / `#D03B3B`   | Severidad del estado del bien y observaciones críticas — paleta fija, no se tematiza entre claro/oscuro |
| Tipografía                                           | **Inter** (400–800) + `system-ui` como fallback |                                                                                                         |

El tema soporta claro/oscuro automático (`prefers-color-scheme`) y un
`data-theme` explícito para forzarlo, siguiendo el mismo patrón de tokens en
los tres bloques del CSS (base, `@media dark`, `[data-theme='dark']).

## Desarrollo local

```sh
npm install
npm run dev -- --open
```

## Scripts disponibles

```sh
npm run dev            # servidor de desarrollo (Vite)
npm run check           # svelte-kit sync + svelte-check (tipos, .svelte incluido)
npm run check:watch     # igual, en modo watch
npm run lint            # prettier --check . && eslint .
npm run format           # prettier --write .
npm run test             # vitest --run (46 pruebas: parser, agregación por hogar, filtros, orden, criticidad)
npm run test:unit       # vitest en modo watch
npm run build            # export estático a build/
npm run preview          # sirve build/ localmente, igual que producción
npm run data:refresh     # regenera src/lib/data/rufe-fallback.json desde la hoja en vivo
```

## Despliegue

Cada push a `main` dispara [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml):

1. `actions/checkout` + `actions/setup-node@v4` (Node 22, cache de npm)
2. `npm ci`
3. `npm run test` — la suite de Vitest debe pasar
4. `npm run check` — `svelte-check` debe pasar sin errores de tipos
5. `npm run build` con `BASE_PATH=/rufe-jamundi` (el nombre del repo, para que las rutas resuelvan bajo `miltonf10.github.io/rufe-jamundi/`)
6. `actions/upload-pages-artifact@v3` + `actions/deploy-pages@v4`

Configurado en el repo bajo **Settings → Pages → Source: GitHub Actions**.

### Hosting propio (fuera de GitHub Pages)

Si en cambio vas a subir el sitio a otro hosting (cPanel, un dominio propio
de la Alcaldía, etc.): corre `npm run build` **sin** la variable
`BASE_PATH` — genera un `build/` con rutas relativas a la raíz, listo para
subir tal cual a la raíz de cualquier hosting estático. Solo hace falta
fijar `BASE_PATH=/subcarpeta` si el sitio va a vivir bajo una subcarpeta en
vez de la raíz del dominio.

⚠️ El error más común al mover el sitio a otro hosting es subir el `build/`
que se generó **con** `BASE_PATH=/rufe-jamundi` (el de GitHub Pages) a la
raíz de un dominio distinto: los archivos `_app/…` quedan referenciados como
`/rufe-jamundi/_app/…` y no cargan. Hay que generar un build nuevo sin esa
variable antes de subirlo a otro lado.

## Notas sobre la calidad de los datos

- El **género** se lee directo de la columna "Identidad de género" del
  formulario (M/F; otros valores como "T" cuentan en el total pero no en
  mujeres/hombres); puede haber registros sin diligenciar.
- La **zona** rural/urbana se infiere del corregimiento reportado; un
  corregimiento vacío con un barrio que coincide con un corregimiento rural
  conocido se reclasifica como rural (ver el set `RURAL` en
  `src/lib/rufe/parse.ts`).
- El **código de hogar** (columna HOGAR) puede tener números "reservados"
  sin ningún dato de persona diligenciado — el tablero cuenta como
  "hogares registrados" solo los que tienen al menos una persona con
  nombre, apellido y documento.
- Cifras de apoyo operativo para la respuesta a la emergencia, **no** un
  censo certificado.

## Pruebas

46 pruebas con Vitest, en tres archivos:

- **`src/lib/rufe/parse.spec.ts`** (16): mapeo de columnas, relleno hacia
  adelante de corregimiento/barrio, clasificación de zona (incluida la
  regresión de los hogares 91/117), bucketing de edad en los límites exactos
  (11/12, 28/29, 59/60), advertencia (no excepción) ante zona mixta,
  extracción de estado/tipo de bien/tenencia/visita/evacuación/observación
  por hogar, "primer valor no vacío gana" cuando un integrante posterior
  repite el campo en blanco.
- **`src/lib/hogaresAggregate.spec.ts`** (16): conteo y tallado por
  estado/tipo de bien y tenencia, evacuación por hogar **y** por persona,
  filtro por zona/barrio, etiquetado de observaciones por palabra clave y
  criticidad, orden alfabético (no "críticas primero") de la lista para que
  el toggle "solo críticas" tenga un efecto visible.
- **`src/lib/aggregate.spec.ts`**: agregación a nivel persona, filtros y
  orden de la tabla de barrios.

## Privacidad

El CSV crudo de la hoja trae nombre completo, número de documento y
teléfono de cada persona. Esos campos **nunca se leen** por el parser (solo
se usan corregimiento/barrio/género/edad/hogar, y solo para sumar conteos y
agrupar) y nunca se guardan en ningún archivo del repositorio — únicamente
el agregado por barrio/vereda y por hogar (sin datos personales) queda en
`src/lib/data/rufe-fallback.json`, que sí está versionado.
