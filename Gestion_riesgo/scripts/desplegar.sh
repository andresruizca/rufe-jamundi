#!/usr/bin/env bash
#
# Despliegue del Sistema de Gestión del Riesgo a grj.oticjamundi.com
#
# ── Por qué existe este script ───────────────────────────────────────────────
#
# En producción el backend está APLANADO: el punto de entrada que Apache
# ejecuta es /api/index.php, no /api/public/index.php. La carpeta public/ existe
# en el servidor y no la sirve nadie.
#
# Consecuencia: subir el zip con src, public y database actualiza
# api/public/index.php y deja el api/index.php REAL sin tocar. Una ruta nueva
# responde 405 y parece un fallo del router. Ya pasó dos veces.
#
# El paso que se olvida —copiar public/index.php encima de api/index.php— está
# aquí dentro, después del zip, donde no se puede saltar.
#
# ── Lo que este script NO hace ───────────────────────────────────────────────
#
# No corre migraciones. No hay forma de hacerlo sin consola, y automatizar algo
# que reescribe el esquema de una base con datos de familias damnificadas, sin
# poder mirar el resultado, es peor que acordarse a mano.
#
# ── Uso ──────────────────────────────────────────────────────────────────────
#
#   CPANEL_TOKEN=xxxx ./scripts/desplegar.sh [backend|frontend|todo]
#
# El token NUNCA va escrito aquí: es de la cuenta entera de la Alcaldía.

set -euo pipefail

USUARIO="gilibert"
HOST="shared10.hostgator.co:2083"
RAIZ="/home1/${USUARIO}/grj.oticjamundi.com"
QUE="${1:-todo}"

if [[ -z "${CPANEL_TOKEN:-}" ]]; then
  echo "Falta CPANEL_TOKEN. Uso: CPANEL_TOKEN=xxxx $0 [backend|frontend|todo]" >&2
  exit 1
fi

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

AUTH="Authorization: cpanel ${USUARIO}:${CPANEL_TOKEN}"

api() { curl -sf --max-time 120 -H "$AUTH" "$@"; }

subir() {  # subir <archivo> <directorio-destino>
  api "https://${HOST}/execute/Fileman/upload_files" \
      -F "dir=$2" -F "overwrite=1" -F "file-1=@$1" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; sys.exit(0 if d["failed"]==0 else 1)'
}

extraer() {  # extraer <zip-remoto> <destino>
  api "https://${HOST}/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=fileop&op=extract&sourcefiles=$1&destfiles=$2" \
    | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin)["cpanelresult"]["data"][0]["result"]==1 else 1)'
}

borrar() {  # borrar <ruta-remota>
  api "https://${HOST}/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=fileop&op=unlink&sourcefiles=$1" >/dev/null
}

comprobar() {  # comprobar <url> <código-esperado>
  local codigo
  codigo="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1")"
  if [[ "$codigo" != "$2" ]]; then
    echo "  ✗ $1 respondió $codigo, se esperaba $2" >&2
    return 1
  fi
  echo "  ✓ $1 → $codigo"
}

# ── Las pruebas primero. Un despliegue con las pruebas en rojo no ocurre. ────
echo "── Pruebas ──"
( cd "$AQUI/backend" && php tests/run.php >/dev/null && echo "  ✓ backend" )
( cd "$AQUI/frontend" && npm test --silent >/dev/null 2>&1 && echo "  ✓ frontend" )

if [[ "$QUE" == "backend" || "$QUE" == "todo" ]]; then
  echo "── Backend ──"
  ( cd "$AQUI/backend" && zip -qr "$TMP/api.zip" src public database )
  subir "$TMP/api.zip" "${RAIZ}/api"
  extraer "${RAIZ}/api/api.zip" "${RAIZ}/api"
  borrar "${RAIZ}/api/api.zip"
  echo "  ✓ src, public y database"

  # EL PASO QUE SE OLVIDA. Va aquí y no en un README porque un README se salta.
  subir "$AQUI/backend/public/index.php" "${RAIZ}/api"
  echo "  ✓ index.php aplanado (el que Apache ejecuta de verdad)"
fi

if [[ "$QUE" == "frontend" || "$QUE" == "todo" ]]; then
  echo "── Frontend ──"
  ( cd "$AQUI/frontend" && npm run build >/dev/null 2>&1 )
  ( cd "$AQUI/frontend/build" && zip -qr "$TMP/front.zip" . )
  subir "$TMP/front.zip" "$RAIZ"
  extraer "${RAIZ}/front.zip" "$RAIZ"
  borrar "${RAIZ}/front.zip"
  echo "  ✓ compilado y subido"
fi

# ── Comprobar contra producción. Subir sin mirar no es desplegar. ───────────
echo "── Producción ──"
comprobar "https://grj.oticjamundi.com/api/preinscripcion/catalogos" 200
comprobar "https://grj.oticjamundi.com/api/callcenter/hogares" 401
comprobar "https://grj.oticjamundi.com/preinscripcion" 200

if [[ "$QUE" == "frontend" || "$QUE" == "todo" ]]; then
  local_app="$(grep -o '_app/immutable/entry/app\.[A-Za-z0-9_-]*\.js' "$AQUI/frontend/build/index.html" | head -1)"
  remoto_app="$(curl -s --max-time 30 https://grj.oticjamundi.com/preinscripcion | grep -o '_app/immutable/entry/app\.[A-Za-z0-9_-]*\.js' | head -1)"
  if [[ "$local_app" == "$remoto_app" ]]; then
    echo "  ✓ el navegador recibe lo que se acaba de compilar ($local_app)"
  else
    echo "  ✗ desajuste: local $local_app, remoto $remoto_app" >&2
    exit 1
  fi
fi

echo "Listo."
