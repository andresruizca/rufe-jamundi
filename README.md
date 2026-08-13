# Tablero RUFE — Sismo Jamundí

Tablero interactivo de personas registradas en el RUFE (Registro consolidado
de familias/personas afectadas) por el sismo del 10 de agosto de 2026 en
Jamundí: mujeres, hombres, niños, jóvenes, adultos y adultos mayores, por
zona rural/urbana y por barrio/vereda.

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
npm run test      # Vitest — filtros, orden, agregación, integridad de datos
npm run lint       # prettier + eslint
npm run build      # export estático a build/
npm run preview    # sirve build/ localmente
```

## Actualizar los datos

Los datos viven como un snapshot estático versionado en
`src/lib/data/rufe-sismo-2026-08-10.json`, generado por
`scripts/build_data.py` a partir del CSV crudo en `data/raw/`. **No hay
conexión en vivo a Google Sheets todavía** — mientras no haya acceso de
lectura a la hoja, actualizar los datos es:

1. Reemplazar (o agregar) el CSV exportado del formulario RUFE en `data/raw/`.
2. Correr:

   ```sh
   python3 scripts/build_data.py --input data/raw/<archivo>.csv \
     --output src/lib/data/<archivo>.json --as-of "AAAA-MM-DD HH:MM"
   ```

3. Si el script imprime corregimientos que no reconoce (mensaje "Corregimientos
   vistos en el CSV"), revisar el diccionario `RURAL` en
   `scripts/build_data.py` — cualquier corregimiento fuera de esa lista se
   clasifica como zona Urbana.
4. Actualizar el `import` en `src/lib/data.ts` si el nombre de archivo cambió.
5. `npm run test` para confirmar que las sumas siguen cuadrando (mujeres +
   hombres + sin dato = total, y lo mismo para zona y grupos de edad).

Cuando haya acceso de lectura a la hoja de Google en línea, el único cambio
necesario es reemplazar el `import` estático en `src/lib/data.ts` por un
`fetch` al endpoint de la hoja — el resto de los componentes consumen `DATA`
sin saber de dónde vino.

## Despliegue

Cada push a `main` dispara `.github/workflows/deploy.yml`, que compila el
sitio con `BASE_PATH=/<nombre-del-repo>` y lo publica en GitHub Pages
mediante GitHub Actions (ver Settings → Pages → Source: GitHub Actions en el
repositorio).

## Notas sobre la calidad de los datos

- El **género** se infiere de cuál columna (M/F) tiene una "X" en el
  formulario físico digitalizado; puede haber inconsistencias de
  diligenciamiento (ver `scripts/build_data.py`, función que arma cada
  registro).
- La **zona** rural/urbana se infiere del corregimiento reportado; un
  corregimiento vacío con un barrio que coincide con un corregimiento rural
  conocido (p. ej. alguien cuyo campo "barrio" dice "San Isidro" y
  "corregimiento" quedó en blanco) se reclasifica como rural — ver el
  comentario sobre los hogares 91 y 117 en `scripts/build_data.py`.
- Cifras de apoyo operativo para la respuesta a la emergencia, no un censo
  certificado.
