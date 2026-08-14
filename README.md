# Tablero RUFE — Sismo Jamundí

Tablero interactivo de personas registradas en el RUFE (Registro consolidado
de familias/personas afectadas) por el sismo del 10 de agosto de 2026 en
Jamundí: mujeres, hombres, niños, jóvenes, adultos y adultos mayores, por
zona rural/urbana y por barrio/vereda.

**Datos en vivo**: el tablero se conecta directo, desde el navegador de
quien lo visita, a la hoja de Google del consolidado RUFE (compartida como
"Cualquiera con el enlace puede ver") y se refresca solo cada 3 minutos. No
hay backend propio ni credenciales que gestionar — ver
[`src/lib/rufe/live.ts`](src/lib/rufe/live.ts).

Construido con [SvelteKit](https://svelte.dev/docs/kit) + `adapter-static`,
publicado en GitHub Pages. Identidad visual (colores, tipografía) tomada de
la hoja de estilos en producción de [INNOLAB](https://oticjamundi.com/innolab)
(Oficina TIC de la Alcaldía de Jamundí); escudo oficial tomado de
[jamundi.gov.co](https://jamundi.gov.co).

## Desarrollo local

```sh
npm install
npm run dev -- --open
```

## Verificación

```sh
npm run check     # svelte-check (tipos)
npm run test      # Vitest — parser del RUFE, filtros, orden, agregación
npm run lint      # prettier + eslint
npm run build     # export estático a build/
npm run preview   # sirve build/ localmente
```

## Cómo funcionan los datos

```text
Hoja de Google (RUFE) ──fetch──▶ src/lib/rufe/parse.ts ──▶ Dataset (agregado por barrio)
                                        ▲
                                        │ misma función
                                        │
                          scripts/refresh-snapshot.ts ──▶ src/lib/data/rufe-fallback.json
```

- **`src/lib/rufe/parse.ts`** es la única implementación del parseo/agregación
  (mapea las columnas del CSV, rellena corregimiento/barrio hacia adelante
  dentro de un mismo hogar, clasifica zona rural/urbana, agrupa por
  barrio/vereda). La usan tanto el fetch en vivo del navegador como el
  script de refresco — a propósito, para que nunca haya dos implementaciones
  que puedan desacordarse (eso ya pasó una vez, ver el comentario sobre los
  hogares 91/117 en ese archivo).
- **`src/lib/data/rufe-fallback.json`** es solo un snapshot de respaldo: lo
  que se muestra en el primer render (antes de que termine el fetch en
  vivo) y si la hoja deja de estar accesible. Se refresca con:

  ```sh
  npm run data:refresh
  ```

- Si el script imprime advertencias de "zona inconsistente", es que algún
  hogar de la hoja tiene un corregimiento/barrio contradictorio entre sus
  integrantes — no rompe el tablero (se queda con la primera zona vista para
  ese barrio), pero conviene revisarlo en la hoja fuente cuando haya tiempo.
- Si la hoja deja de estar en "Cualquiera con el enlace puede ver", el
  tablero lo detecta (aviso "Sin conexión con la hoja") y sigue mostrando el
  último snapshot en vez de romperse.

## Despliegue

Cada push a `main` dispara `.github/workflows/deploy.yml`, que compila el
sitio con `BASE_PATH=/<nombre-del-repo>` y lo publica en GitHub Pages
mediante GitHub Actions (ver Settings → Pages → Source: GitHub Actions en el
repositorio).

Si en cambio vas a subir el sitio a otro hosting (no GitHub Pages): corre
`npm run build` **sin** la variable `BASE_PATH` — genera un `build/` con
rutas relativas a la raíz, listo para subir tal cual a la raíz de cualquier
hosting estático (cPanel, un dominio propio, etc.). Solo hace falta fijar
`BASE_PATH=/subcarpeta` si el sitio va a vivir bajo una subcarpeta en vez de
la raíz del dominio.

## Notas sobre la calidad de los datos

- El **género** se lee directo de la columna "Identidad de género" del
  formulario (M/F; otros valores como "T" cuentan en el total pero no en
  mujeres/hombres); puede haber registros sin diligenciar.
- La **zona** rural/urbana se infiere del corregimiento reportado; un
  corregimiento vacío con un barrio que coincide con un corregimiento rural
  conocido se reclasifica como rural (ver `RURAL` en
  `src/lib/rufe/parse.ts`).
- Cifras de apoyo operativo para la respuesta a la emergencia, no un censo
  certificado.

## Privacidad

El CSV crudo de la hoja trae nombre completo, número de documento y
teléfono de cada persona. Esos campos **nunca se leen** por el parser (solo
se usan corregimiento/barrio/género/edad, y solo para sumar conteos) y nunca
se guardan en ningún archivo del repositorio — únicamente el agregado por
barrio/vereda (sin datos personales) queda en `src/lib/data/rufe-fallback.json`.
