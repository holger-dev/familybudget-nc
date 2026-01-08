#!/usr/bin/env bash
# nc_release_app_script.sh — Build Nextcloud App tar.gz + Upload-Signatur
# Signatur via occ IM DOCKER-CONTAINER (Keys/Certs bleiben auf dem Host; werden kurzzeitig in den Container kopiert)
# Nutzung:
#   ./nc_release_app_script.sh --project /path/to/app --app-id APP_ID --container CONTAINER [--certdir DIR] [--out DIR]
#   [--version X.Y.Z] [--no-build] [--verbose]
set -euo pipefail

# ── Args / Defaults ──────────────────────────────────────────────────────────
PROJECT_PATH=""; APP_ID=""; CONTAINER=""
CERT_DIR="${HOME}/.nextcloud/certificates"
OUT_DIR="."
VERSION_OVERRIDE=""
NO_BUILD=0
VERBOSE=0

usage() {
  cat <<'EOF'
Usage:
  nc_release_app_script.sh --project /path/to/app --app-id APP_ID --container CONTAINER_NAME [options]

Options:
  --certdir DIR     Path to APP_ID.crt/.key (default: ~/.nextcloud/certificates)
  --out DIR         Output directory for tar.gz (default: .)
  --version X.Y.Z   Override version used in filename (statt info.xml)
  --no-build        Skip npm/composer build steps
  --verbose         Verbose logging
  -h, --help        Show this help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT_PATH="$2"; shift 2;;
    --app-id) APP_ID="$2"; shift 2;;
    --container) CONTAINER="$2"; shift 2;;
    --certdir) CERT_DIR="$2"; shift 2;;
    --out) OUT_DIR="$2"; shift 2;;
    --version) VERSION_OVERRIDE="$2"; shift 2;;
    --no-build) NO_BUILD=1; shift;;
    --verbose) VERBOSE=1; shift;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown option: $1"; usage; exit 1;;
  esac
done

log(){ echo "[$(date +%H:%M:%S)] $*"; }
ve(){ if [[ "$VERBOSE" -eq 1 ]]; then echo "+ $*" >&2; fi; "$@"; }
die(){ echo "Fehler: $*" >&2; exit 1; }

# --- Mac-/Finder-Metadaten sauber entfernen, bevor signiert/gepackt wird ---
clean_macos_junk() {
  local path="$1"
  # Finder/AppleDouble-Dateien löschen
  find "$path" -name '.DS_Store' -delete \
               -o -name '._*' -delete \
               -o -name '__MACOSX' -prune -exec rm -rf {} + 2>/dev/null || true
}

# ── PRE-FLIGHT: Host-Checks ──────────────────────────────────────────────────
[[ -n "$PROJECT_PATH" ]] || die "--project fehlt"
[[ -n "$APP_ID" ]] || die "--app-id fehlt"
[[ -n "$CONTAINER" ]] || die "--container fehlt"
[[ -d "$PROJECT_PATH" ]] || die "Projektpfad nicht gefunden: $PROJECT_PATH"

PROJECT_PATH="$(cd "$PROJECT_PATH" && pwd)"
OUT_DIR="$(mkdir -p "$OUT_DIR" && cd "$OUT_DIR" && pwd)"

for bin in php openssl tar docker; do
  command -v "$bin" >/dev/null || die "Programm nicht gefunden: $bin"
done
command -v rsync >/dev/null || true
[[ $NO_BUILD -eq 1 || ! -f "$PROJECT_PATH/package.json" || $(command -v npm >/dev/null; echo $?) -eq 0 ]] || die "npm benötigt, aber nicht gefunden"
[[ $NO_BUILD -eq 1 || ! -f "$PROJECT_PATH/composer.json" || $(command -v composer >/dev/null; echo $?) -eq 0 ]] || die "composer benötigt, aber nicht gefunden"

INFO_XML="$PROJECT_PATH/appinfo/info.xml"
[[ -f "$INFO_XML" ]] || die "appinfo/info.xml nicht gefunden im Projektpfad"
extract_tag(){ sed -n "s:.*<$1>\\(.*\\)</$1>.*:\\1:p" "$INFO_XML" | head -n1; }
XML_ID="$(extract_tag id || true)"
[[ -n "$XML_ID" ]] || die "<id> in appinfo/info.xml nicht gefunden"
[[ "$XML_ID" == "$APP_ID" ]] || die "APP_ID ($APP_ID) passt nicht zu <id> in info.xml ($XML_ID). Bitte angleichen."
APP_VERSION="$(extract_tag version || true)"
[[ -n "$APP_VERSION" ]] || die "<version> in appinfo/info.xml nicht gefunden"
[[ -n "$VERSION_OVERRIDE" ]] && APP_VERSION="$VERSION_OVERRIDE"

CRT="$CERT_DIR/${APP_ID}.crt"
KEY="$CERT_DIR/${APP_ID}.key"
[[ -f "$CRT" ]] || die "Zertifikat fehlt: $CRT"
[[ -f "$KEY" ]] || die "Privater Schlüssel fehlt: $KEY"

log "Preflight OK (Host)."
log "  Projekt:    $PROJECT_PATH"
log "  APP_ID:     $APP_ID"
log "  Version:    $APP_VERSION"
log "  Container:  $CONTAINER"
log "  Cert Dir:   $CERT_DIR"
log "  Output:     $OUT_DIR"

# ── PRE-FLIGHT: Container-Checks ─────────────────────────────────────────────
docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER" || die "Container '$CONTAINER' läuft nicht (docker ps)."

HAS_WWWDATA=1
if ! docker exec "$CONTAINER" id -u www-data >/dev/null 2>&1; then
  HAS_WWWDATA=0
  log "Warnung: User 'www-data' nicht gefunden – fallback ohne -u."
fi

# occ im Container finden → PFAD|WORKDIR
find_occ(){
  local c="$1"
  local pairs=(
    "/var/www/html/occ|/var/www/html"
    "/var/www/nextcloud/occ|/var/www/nextcloud"
    "/var/www/html/nextcloud/occ|/var/www/html/nextcloud"
    "/var/www/occ|/var/www"
  )
  for p in "${pairs[@]}"; do
    local occ_path="${p%%|*}"
    local occ_dir="${p##*|}"
    if docker exec "$c" test -f "$occ_path"; then
      echo "$occ_path|$occ_dir"; return 0
    fi
  done
  return 1
}
OCC_INFO="$(find_occ "$CONTAINER" || true)"
[[ -n "$OCC_INFO" ]] || die "Konnte 'occ' im Container nicht finden."
OCC_PATH="${OCC_INFO%%|*}"
OCC_DIR="${OCC_INFO##*|}"

docker exec "$CONTAINER" php -v >/dev/null 2>&1 || die "php fehlt IM Container"

# identisch zur bewährten manuellen Zeile
if [[ $HAS_WWWDATA -eq 1 ]]; then
  docker exec -u www-data -w "$OCC_DIR" "$CONTAINER" php "$OCC_PATH" --version >/dev/null 2>&1 \
    || die "occ lässt sich im Container nicht ausführen (php $OCC_PATH, workdir $OCC_DIR, user www-data)."
else
  docker exec -w "$OCC_DIR" "$CONTAINER" php "$OCC_PATH" --version >/dev/null 2>&1 \
    || die "occ lässt sich im Container nicht ausführen (php $OCC_PATH, workdir $OCC_DIR)."
fi

log "Preflight OK (Container)."
log "  occ:        $OCC_PATH"
log "  workdir:    $OCC_DIR"
[[ $HAS_WWWDATA -eq 1 ]] && log "  user:       www-data" || log "  user:       <container default>"

# ── BUILD (Host) ─────────────────────────────────────────────────────────────
if [[ $NO_BUILD -eq 0 ]]; then
  if [[ -f "$PROJECT_PATH/package.json" ]]; then
    log "NPM: ci + build"
    ( cd "$PROJECT_PATH" && { [[ "$VERBOSE" -eq 1 ]] && set -x || true; } ; npm ci ; npm run build )
  else
    log "NPM: übersprungen (keine package.json)"
  fi
  if [[ -f "$PROJECT_PATH/composer.json" ]]; then
    log "Composer: install --no-dev --prefer-dist --optimize-autoloader"
    ( cd "$PROJECT_PATH" && { [[ "$VERBOSE" -eq 1 ]] && set -x || true; } ; composer install --no-dev --prefer-dist --optimize-autoloader )
  else
    log "Composer: übersprungen (keine composer.json)"
  fi
else
  log "Build-Schritte übersprungen (--no-build)"
fi

# ── Release-Struktur (Host) ──────────────────────────────────────────────────
BUILD_ROOT="$(mktemp -d -t ncappbuild.XXXXXXXX)"
APPDIR="$BUILD_ROOT/$APP_ID"
mkdir -p "$APPDIR"

copy_runtime(){
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude ".git" --exclude "node_modules" --exclude "tests" \
      --exclude ".github" --exclude ".gitlab" --exclude ".vscode" --exclude ".idea" \
      --exclude "docker*" --exclude "Dockerfile*" --exclude "Makefile" \
      --exclude "*.map" \
      "$1" "$2"
  else
    cp -a "$1" "$2"
  fi
}

for d in appinfo lib templates js css l10n img vendor; do
  [[ -e "$PROJECT_PATH/$d" ]] && copy_runtime "$PROJECT_PATH/$d" "$APPDIR/"
done
for f in CHANGELOG.md LICENSE LICENSE.txt README.md; do
  [[ -f "$PROJECT_PATH/$f" ]] && cp -a "$PROJECT_PATH/$f" "$APPDIR/"
done
[[ -f "$APPDIR/appinfo/info.xml" ]] || die "info.xml fehlt im Release-Ordner"

# NEU: Mac-/Finder-Metadaten vor dem Signieren entfernen
clean_macos_junk "$APPDIR"

# ── SIGNIEREN im Container ───────────────────────────────────────────────────
log "Kopiere App & Certs in den Container …"
WORK_BASE="/tmp/nc_sign_work"
docker exec "$CONTAINER" mkdir -p "$WORK_BASE" /tmp/certs
docker cp "$APPDIR" "$CONTAINER":"$WORK_BASE/"
docker cp "$CRT" "$CONTAINER":/tmp/certs/
docker cp "$KEY" "$CONTAINER":/tmp/certs/

# Rechte für www-data (lesen/schreiben)
docker exec "$CONTAINER" sh -lc "chown -R www-data:www-data '$WORK_BASE/$APP_ID' || true"
docker exec "$CONTAINER" sh -lc "chmod -R u+rwX '$WORK_BASE/$APP_ID' || true"
docker exec "$CONTAINER" sh -lc "chgrp -f www-data /tmp/certs/${APP_ID}.crt /tmp/certs/${APP_ID}.key || true"
docker exec "$CONTAINER" sh -lc "chmod 640 /tmp/certs/${APP_ID}.crt /tmp/certs/${APP_ID}.key || chmod 644 /tmp/certs/${APP_ID}.crt /tmp/certs/${APP_ID}.key"

# Sanity
docker exec "$CONTAINER" test -f "/tmp/certs/${APP_ID}.crt" || die "Im Container fehlt: /tmp/certs/${APP_ID}.crt"
docker exec "$CONTAINER" test -f "/tmp/certs/${APP_ID}.key" || die "Im Container fehlt: /tmp/certs/${APP_ID}.key"
docker exec "$CONTAINER" test -d "$WORK_BASE/${APP_ID}" || die "Im Container fehlt App-Ordner: $WORK_BASE/${APP_ID}"

# Signieren via occ (als www-data, im richtigen Workdir)
if [[ $HAS_WWWDATA -eq 1 ]]; then
  docker exec -u www-data -w "$OCC_DIR" "$CONTAINER" php "$OCC_PATH" integrity:sign-app \
    --privateKey="/tmp/certs/${APP_ID}.key" \
    --certificate="/tmp/certs/${APP_ID}.crt" \
    --path="$WORK_BASE/${APP_ID}"
else
  docker exec -w "$OCC_DIR" "$CONTAINER" php "$OCC_PATH" integrity:sign-app \
    --privateKey="/tmp/certs/${APP_ID}.key" \
    --certificate="/tmp/certs/${APP_ID}.crt" \
    --path="$WORK_BASE/${APP_ID}"
fi

# signature.json zurückholen & aufräumen
docker cp "$CONTAINER":"$WORK_BASE/${APP_ID}/appinfo/signature.json" "$APPDIR/appinfo/"
docker exec "$CONTAINER" rm -rf "$WORK_BASE" /tmp/certs

# ── Tarball & Upload-Signatur (Host) ─────────────────────────────────────────
# NEU: Vor dem Packen nochmal macOS-Junk entfernen (defensiv)
clean_macos_junk "$BUILD_ROOT/$APP_ID"

TARBALL="${OUT_DIR}/${APP_ID}-${APP_VERSION}.tar.gz"
log "Erzeuge Tarball: $TARBALL"
(
  cd "$BUILD_ROOT" && \
  COPYFILE_DISABLE=1 tar \
    --exclude='.DS_Store' \
    --exclude='._*' \
    --exclude='__MACOSX' \
    -czf "$TARBALL" "$APP_ID"
)

# NEU: Sicherstellen, dass genau ein Top-Level-Eintrag im Tarball enthalten ist
TOPS=$(tar tzf "$TARBALL" | sed -E 's#/.*##' | sort -u | grep -v '^$' | wc -l | tr -d ' ')
if [ "$TOPS" != "1" ]; then
  echo "Fehler: Das Archiv enthält mehr als einen Top-Level-Eintrag:"
  tar tzf "$TARBALL" | sed -E 's#/.*##' | sort -u | grep -v '^$'
  exit 1
fi

SHA512_SUM="$(openssl dgst -sha512 "$TARBALL" | awk '{print $2}')"
SIZE_BYTES="$(stat -c%s "$TARBALL" 2>/dev/null || stat -f%z "$TARBALL")"

log "Berechne Upload-Signatur (Base64) über das tar.gz …"
UPLOAD_SIG="$(openssl dgst -sha512 -sign "$KEY" "$TARBALL" | openssl base64)"

echo
echo "==============================================="
echo "FERTIG"
echo "Tarball:        $TARBALL"
echo "Größe:          ${SIZE_BYTES} Bytes"
echo "SHA512:         $SHA512_SUM"
echo "-----------------------------------------------"
echo "UPLOAD-SIGNATUR (Base64) — ins App-Store-Formular kopieren:"
echo "$UPLOAD_SIG"
echo "==============================================="
echo

rm -rf "$BUILD_ROOT"
