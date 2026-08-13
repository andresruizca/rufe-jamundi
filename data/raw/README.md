# `data/raw/`

Esta carpeta es donde se coloca localmente el CSV exportado del formulario
RUFE para regenerar `src/lib/data/*.json` con `scripts/build_data.py`.

**Los archivos CSV de esta carpeta nunca se suben al repositorio** (están en
`.gitignore`): contienen nombre completo, número de documento y teléfono de
cada persona registrada. Solo el resultado agregado por barrio/vereda
(`src/lib/data/*.json`, sin datos personales) se versiona en Git.

Para regenerar los datos, pide el CSV más reciente al equipo que administra
el RUFE y colócalo aquí antes de correr `scripts/build_data.py` (ver
instrucciones en el README principal del repo).
