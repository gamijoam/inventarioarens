#!/usr/bin/env bash
set -Eeuo pipefail

REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${APP_VERSION:-1.0.0}"
WINDOWS_ARCH="x64"

if [[ ! "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$ ]]; then
  echo "APP_VERSION contiene caracteres no válidos." >&2
  exit 2
fi
for tool in git go pnpm rsync makensis curl python3 sha256sum file; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Falta la herramienta requerida: $tool" >&2; exit 2; }
done

UTC_BUILD="$(date -u +%Y%m%dT%H%M%SZ)"
BUILD_NUMERIC="1.0.$((10#$(date -u +%m) * 100 + 10#$(date -u +%d))).$((10#$(date -u +%H) * 60 + 10#$(date -u +%M)))"
REVISION="$(git -C "$REPO_DIR" rev-parse HEAD)"
CANDIDATE_ROOT="$REPO_DIR/build/installer-candidates"
mkdir -p -- "$CANDIDATE_ROOT"
CANDIDATE_DIR="$(mktemp -d "$CANDIDATE_ROOT/${VERSION//[^A-Za-z0-9._-]/_}-$UTC_BUILD.XXXXXX")"
WORK_DIR="$(mktemp -d "/tmp/balanzapro-installer-build.XXXXXX")"
PACKAGE_DIR="$CANDIDATE_DIR/payload"
mkdir -p -- "$PACKAGE_DIR"

cleanup() {
  rm -rf -- "$WORK_DIR"
}
trap cleanup EXIT

echo "Candidato: $CANDIDATE_DIR"
echo "Revisión Git: $REVISION"
echo "Versión: $VERSION ($BUILD_NUMERIC)"

WEB_DIR="$WORK_DIR/web"
mkdir -p -- "$WEB_DIR/assets"
if [ -f "$REPO_DIR/frontend/dist/pos/index.html" ] && [ -f "$REPO_DIR/frontend/dist/admin/index.html" ]; then
  echo ">> Reutilizando frontend precompilado en $REPO_DIR/frontend/dist..."
  cp -- "$REPO_DIR/frontend/dist/pos/index.html" "$WEB_DIR/pos.html"
  cp -- "$REPO_DIR/frontend/dist/admin/index.html" "$WEB_DIR/admin.html"
  rsync -a "$REPO_DIR/frontend/dist/pos/assets/" "$WEB_DIR/assets/"
  rsync -a "$REPO_DIR/frontend/dist/admin/assets/" "$WEB_DIR/assets/"
else
  FRONTEND_STAGE="$WORK_DIR/frontend"
  mkdir -p -- "$FRONTEND_STAGE"
  rsync -a --exclude=node_modules --exclude=dist --exclude=.vite --exclude=coverage \
    --exclude=release --exclude=build --exclude=e2e --exclude=electron \
    --exclude=.tanstack --exclude=.vscode --exclude=.husky \
    "$REPO_DIR/frontend/" "$FRONTEND_STAGE/"
  pnpm --dir "$FRONTEND_STAGE" install --frozen-lockfile --store-dir "$WORK_DIR/pnpm-store"
  pnpm --dir "$FRONTEND_STAGE" run build:balanzapro-pos
  pnpm --dir "$FRONTEND_STAGE" run build:balanzapro-admin

  cp -- "$FRONTEND_STAGE/dist/pos/index.html" "$WEB_DIR/pos.html"
  cp -- "$FRONTEND_STAGE/dist/admin/index.html" "$WEB_DIR/admin.html"
  rsync -a "$FRONTEND_STAGE/dist/pos/assets/" "$WEB_DIR/assets/"
  rsync -a "$FRONTEND_STAGE/dist/admin/assets/" "$WEB_DIR/assets/"
fi

MOTOR_STAGE="$WORK_DIR/motor-local"
mkdir -p -- "$MOTOR_STAGE"
rsync -a --exclude=bin --exclude=web "$REPO_DIR/tools/motor-local/" "$MOTOR_STAGE/"
mkdir -p -- "$MOTOR_STAGE/web"
rsync -a "$WEB_DIR/" "$MOTOR_STAGE/web/"
(
  cd "$MOTOR_STAGE"
  GOOS=windows GOARCH=amd64 CGO_ENABLED=0 GOWORK=off go build -trimpath -buildvcs=false -ldflags="-s -w -H=windowsgui" -o "$PACKAGE_DIR/MotorLocal.exe" .
)

build_go_tool() {
  local module_dir="$1"
  local output="$2"
  local package="$3"
  (
    cd "$REPO_DIR/$module_dir"
    GOOS=windows GOARCH=amd64 CGO_ENABLED=0 GOWORK=off go build -trimpath -buildvcs=false -ldflags="-s -w -H=windowsgui" -o "$PACKAGE_DIR/$output" "$package"
  )
}
build_go_tool "tools/desktop-pos" "BalanzaPro-POS.exe" "."
build_go_tool "tools/desktop-admin" "BalanzaPro-Administrativo.exe" "."
build_go_tool "tools/printer-agent" "printer-agent.exe" "./cmd/printer-agent"
build_go_tool "tools/scale-agent" "scale-agent.exe" "./cmd/scale-agent"

BACKEND="$PACKAGE_DIR/backend"
mkdir -p -- "$BACKEND"
for dir in app bootstrap config resources routes vendor; do
  test -d "$REPO_DIR/$dir" || { echo "Falta el directorio requerido: $dir" >&2; exit 2; }
  rsync -a "$REPO_DIR/$dir/" "$BACKEND/$dir/"
done
mkdir -p -- "$BACKEND/database/migrations" "$BACKEND/database/seeders" "$BACKEND/public"
rsync -a "$REPO_DIR/database/migrations/" "$BACKEND/database/migrations/"
cp -- "$REPO_DIR/database/seeders/RolesAndPermissionsSeeder.php" "$BACKEND/database/seeders/RolesAndPermissionsSeeder.php"
cp -- "$REPO_DIR/artisan" "$BACKEND/artisan"
cp -- "$REPO_DIR/server.php" "$BACKEND/server.php"
cp -- "$REPO_DIR/composer.json" "$BACKEND/composer.json"
cp -- "$REPO_DIR/composer.lock" "$BACKEND/composer.lock"
cp -- "$REPO_DIR/public/index.php" "$BACKEND/public/index.php"
for file in robots.txt favicon.ico .htaccess; do
  if [[ -f "$REPO_DIR/public/$file" ]]; then cp -- "$REPO_DIR/public/$file" "$BACKEND/public/$file"; fi
done
mkdir -p -- "$BACKEND/storage/app/public" "$BACKEND/storage/framework/cache/data" \
  "$BACKEND/storage/framework/sessions" "$BACKEND/storage/framework/views" "$BACKEND/storage/logs"
if find "$BACKEND" -type f \( -name '.env' -o -name '.env.*' -o -name '*.sqlite' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' \) -print -quit | grep -q .; then
  echo "El backend genérico contiene un archivo de configuración o base privada." >&2
  exit 2
fi

RUNTIME_SOURCE="$REPO_DIR/build/windows-runtime/frankenphp"
for required in php.exe php.ini frankenphp.exe php8ts.dll ext/php_pdo_sqlite.dll ext/php_sqlite3.dll ext/php_zip.dll ext/php_mbstring.dll ext/php_fileinfo.dll ext/php_openssl.dll ext/php_curl.dll ext/php_gd.dll ext/php_intl.dll ext/php_sodium.dll; do
  test -f "$RUNTIME_SOURCE/$required" || { echo "Falta el componente PHP requerido: $required" >&2; exit 2; }
done
mkdir -p -- "$PACKAGE_DIR/frankenphp/ext"
find "$RUNTIME_SOURCE" -maxdepth 1 -type f -name '*.dll' -exec cp -- '{}' "$PACKAGE_DIR/frankenphp/" \;
for file in php.exe frankenphp.exe php.ini README.md license.txt; do
  if [[ -f "$RUNTIME_SOURCE/$file" ]]; then cp -- "$RUNTIME_SOURCE/$file" "$PACKAGE_DIR/frankenphp/$file"; fi
done
rsync -a "$RUNTIME_SOURCE/ext/" "$PACKAGE_DIR/frankenphp/ext/"

WEBVIEW_LOCK="$REPO_DIR/scripts/installer/webview2.lock.json"
mapfile -t WEBVIEW_LOCK_VALUES < <(python3 - "$WEBVIEW_LOCK" <<'PYLOCK'
import json, pathlib, sys
lock = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding='utf-8'))
for key in ('resolved_url', 'sha256', 'size_bytes', 'source_last_modified'):
    print(lock[key])
PYLOCK
)
WEBVIEW_URL="${WEBVIEW_LOCK_VALUES[0]}"
WEBVIEW_SHA="${WEBVIEW_LOCK_VALUES[1]}"
WEBVIEW_SIZE_EXPECTED="${WEBVIEW_LOCK_VALUES[2]}"
WEBVIEW_RELEASE="${WEBVIEW_LOCK_VALUES[3]}"
WEBVIEW="$PACKAGE_DIR/MicrosoftEdgeWebView2RuntimeInstallerX64.exe"
curl --fail --location --retry 3 --connect-timeout 30 --output "$WEBVIEW" "$WEBVIEW_URL"
WEBVIEW_SIZE="$(stat -c %s "$WEBVIEW")"
WEBVIEW_ACTUAL_SHA="$(sha256sum "$WEBVIEW" | cut -d ' ' -f1)"
if [[ "$WEBVIEW_SIZE" != "$WEBVIEW_SIZE_EXPECTED" || "$WEBVIEW_ACTUAL_SHA" != "$WEBVIEW_SHA" ]] || ! file "$WEBVIEW" | grep -q 'PE32'; then
  echo "El instalador independiente de WebView2 no coincide con el archivo x64 fijado." >&2
  exit 2
fi
cp -- "$WEBVIEW_LOCK" "$PACKAGE_DIR/webview2.lock.json"

cp -- "$REPO_DIR/scripts/installer/install-helper.php" "$PACKAGE_DIR/installer-helper.php"
cp -- "$REPO_DIR/scripts/installer/installer-preflight.ps1" "$PACKAGE_DIR/installer-preflight.ps1"
cat > "$PACKAGE_DIR/LEEME.txt" <<'README'
BALANZAPRO — INSTALACIÓN LOCAL
================================

Esta PC central guarda los datos de la tienda en:
C:\ProgramData\BalanzaPro

INSTALACIÓN NUEVA
1. Descarga el ZIP privado de datos desde Configuración de empresa.
2. Deja BalanzaPro-Setup.exe y el archivo BalanzaPro-datos-*.zip en la misma carpeta.
3. Ejecuta el Setup. Se verifican la empresa, los hashes y la integridad de SQLite.
4. Al terminar, abre BalanzaPro POS o BalanzaPro Administrativo.

ACTUALIZACIÓN
Ejecuta el nuevo Setup sobre la instalación actual. El Setup ignora cualquier ZIP
adyacente, crea una copia coherente de SQLite y conserva los archivos de recuperación.
No borres C:\ProgramData\BalanzaPro.

OTRAS TERMINALES
Desde otra PC de la tienda, abre en el navegador la dirección LAN que muestra el Motor
Local de esta PC. Esa terminal usa esta base local y no instala otra copia de SQLite.

IMPRESORA Y BALANZA
La PC central incluye los conectores locales. Una balanza requiere un perfil que coincida
con su transporte y protocolo. El Setup deja la lectura desactivada si no hay perfil;
no inventa un peso. La caja permite seguir con ingreso manual o con etiquetas de peso.

PERFILES DISPONIBLES EN ESTA VERSION
- Serie/USB que Windows presenta como COM: scale_transport=serial y scale_device=COM3.
  El número COM y velocidad/paridad deben coincidir con la balanza.
- Ethernet con salida de texto por TCP: scale_transport=tcp y scale_tcp_address=IP:puerto.
- USB HID que entrega el peso como texto ASCII: scale_transport=hid y scale_device=ruta HID.
- scale_protocol admite generic y torrey. El analizador genérico entiende tramas de texto
  comunes; Torrey admite el formato implementado para ese protocolo.

Para cambiar un perfil, cierra las ventanas POS y Administración, edita
C:\ProgramData\BalanzaPro\config.json y reinicia Windows. Conserva los campos de empresa.
Ejemplo para COM3; agrega estos campos al objeto JSON existente:
  "scale_transport": "serial",
  "scale_device": "COM3",
  "scale_protocol": "generic",
  "scale_baud_rate": 9600,
  "scale_data_bits": 8,
  "scale_parity": "none",
  "scale_stop_bits": 1,
  "scale_decimal_separator": ".",
  "scale_unit": "kg"

Las balanzas USB que crean un puerto COM usan el perfil serie. Las balanzas HID con
tramas binarias propietarias y protocolos de red distintos de TCP de texto requieren un
adaptador específico y deben probarse con el modelo real; no se declaran compatibles por
el solo hecho de ser USB o de red. Si no hay lectura estable, usa ingreso manual o etiquetas.
README

for required in MotorLocal.exe BalanzaPro-POS.exe BalanzaPro-Administrativo.exe printer-agent.exe scale-agent.exe installer-helper.php installer-preflight.ps1 backend/database/seeders/RolesAndPermissionsSeeder.php LEEME.txt frankenphp/php.exe frankenphp/frankenphp.exe MicrosoftEdgeWebView2RuntimeInstallerX64.exe; do
  test -f "$PACKAGE_DIR/$required" || { echo "El paquete está incompleto: $required" >&2; exit 2; }
done

SOURCE_HASH="$(
  {
    find "$REPO_DIR/app" "$REPO_DIR/bootstrap" "$REPO_DIR/config" "$REPO_DIR/database/migrations" "$REPO_DIR/database/seeders" \
      "$REPO_DIR/resources" "$REPO_DIR/routes" "$REPO_DIR/vendor" "$REPO_DIR/frontend" \
      "$REPO_DIR/tools/motor-local" "$REPO_DIR/tools/desktop-pos" "$REPO_DIR/tools/desktop-admin" \
      "$REPO_DIR/tools/printer-agent" "$REPO_DIR/tools/scale-agent" "$REPO_DIR/scripts/installer" \
      -type f ! -path '*/bin/*' ! -path '*/node_modules/*' ! -path '*/dist/*' ! -path '*/.vite/*' \
      ! -path '*/coverage/*' ! -path "$REPO_DIR/frontend/release/*" ! -path "$REPO_DIR/frontend/build/*" \
      ! -path "$REPO_DIR/frontend/e2e/*" ! -path "$REPO_DIR/frontend/electron/*" \
      ! -path "$REPO_DIR/frontend/.tanstack/*" ! -path "$REPO_DIR/frontend/.vscode/*" \
      ! -path "$REPO_DIR/frontend/.husky/*" ! -name '.env' ! -name '.env.*' -print0
    for file in artisan server.php composer.json composer.lock public/index.php public/favicon.ico public/robots.txt public/.htaccess scripts/build_full_go_package.sh frontend/.env.pos frontend/.env.admin; do
      if [[ -f "$REPO_DIR/$file" ]]; then printf '%s\0' "$REPO_DIR/$file"; fi
    done
  } | sort -z | xargs -0 sha256sum | sha256sum | cut -d ' ' -f1
)"
SOURCE_DIRTY=0
if git -C "$REPO_DIR" status --porcelain | grep -q .; then SOURCE_DIRTY=1; fi
NODE_VERSION="$(node --version)"
PNPM_VERSION="$(pnpm --version)"
GO_VERSION="$(go version)"
NSIS_VERSION="$(makensis -VERSION | head -n 1)"
RUNTIME_HASH="$(
  find "$PACKAGE_DIR/frankenphp" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d ' ' -f1
)"
python3 - "$PACKAGE_DIR" "$VERSION" "$BUILD_NUMERIC" "$UTC_BUILD" "$REVISION" "$SOURCE_HASH" "$RUNTIME_HASH" "$WEBVIEW_RELEASE" "$WEBVIEW_SHA" "$SOURCE_DIRTY" "$NODE_VERSION" "$PNPM_VERSION" "$GO_VERSION" "$NSIS_VERSION" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
records = {}
for path in sorted(p for p in root.rglob('*') if p.is_file() and p.name != 'payload-manifest.json'):
    data = path.read_bytes()
    records[path.relative_to(root).as_posix()] = {'size_bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
manifest = {
    'format': 'balanzapro-windows-x64-payload',
    'format_version': 1,
    'version': sys.argv[2],
    'build_version': sys.argv[3],
    'built_at_utc': sys.argv[4],
    'source_commit': sys.argv[5],
    'source_tree_sha256': sys.argv[6],
    'php_runtime_sha256': sys.argv[7],
    'webview2_release': sys.argv[8],
    'webview2_sha256': sys.argv[9],
    'source_worktree_dirty': sys.argv[10] == '1',
    'build_tools': {
        'node': sys.argv[11],
        'pnpm': sys.argv[12],
        'go': sys.argv[13],
        'nsis': sys.argv[14],
    },
    'files': records,
}
(root / 'payload-manifest.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
PY

OUTFILE="$CANDIDATE_DIR/BalanzaPro-Setup.exe"
makensis -V3 -D"PACKAGE_DIR=$PACKAGE_DIR" -D"OUTPUT_PATH=$OUTFILE" \
  -D"APP_VERSION=$VERSION" -D"BUILD_VERSION=$BUILD_NUMERIC" \
  "$REPO_DIR/scripts/installer/installer.nsi"

SETUP_SIZE="$(stat -c %s "$OUTFILE")"
if [[ "$SETUP_SIZE" -lt 100000000 ]] || ! file "$OUTFILE" | grep -q 'PE32'; then
  echo "El Setup generado no contiene un instalador Windows x64 esperado." >&2
  exit 2
fi
SETUP_SHA="$(sha256sum "$OUTFILE" | cut -d ' ' -f1)"
python3 - "$CANDIDATE_DIR/BalanzaPro-Setup.manifest.json" "$VERSION" "$BUILD_NUMERIC" "$UTC_BUILD" "$REVISION" "$SOURCE_HASH" "$OUTFILE" "$SETUP_SHA" "$SETUP_SIZE" "$WEBVIEW_SIZE" "$WEBVIEW_SHA" "$WEBVIEW_RELEASE" "$SOURCE_DIRTY" "$NODE_VERSION" "$PNPM_VERSION" "$GO_VERSION" "$NSIS_VERSION" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
manifest = {
    'format': 'balanzapro-windows-installer',
    'format_version': 1,
    'name': 'BalanzaPro-Setup.exe',
    'version': sys.argv[2],
    'build_version': sys.argv[3],
    'built_at': sys.argv[4],
    'source_commit': sys.argv[5],
    'source_tree_sha256': sys.argv[6],
    'size_bytes': int(sys.argv[9]),
    'sha256': sys.argv[8],
    'webview2_size_bytes': int(sys.argv[10]),
    'webview2_sha256': sys.argv[11],
    'webview2_release': sys.argv[12],
    'source_worktree_dirty': sys.argv[13] == '1',
    'build_tools': {
        'node': sys.argv[14],
        'pnpm': sys.argv[15],
        'go': sys.argv[16],
        'nsis': sys.argv[17],
    },
    'architecture': 'windows-x64',
}
path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
PY
sha256sum "$OUTFILE" "$CANDIDATE_DIR/BalanzaPro-Setup.manifest.json" > "$CANDIDATE_DIR/SHA256SUMS"
test "$(sha256sum "$OUTFILE" | cut -d ' ' -f1)" = "$SETUP_SHA"
python3 - "$PACKAGE_DIR/payload-manifest.json" "$PACKAGE_DIR" <<'PY'
import hashlib, json, pathlib, sys
manifest_path, root = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
for name, record in manifest['files'].items():
    data = (root / name).read_bytes()
    if len(data) != record['size_bytes'] or hashlib.sha256(data).hexdigest() != record['sha256']:
        raise SystemExit('Falló la comprobación del paquete: ' + name)
print('Payload validado:', len(manifest['files']), 'archivos')
PY

echo "Setup candidato: $OUTFILE"
echo "Manifiesto: $CANDIDATE_DIR/BalanzaPro-Setup.manifest.json"
echo "Tamaño: $SETUP_SIZE bytes"
echo "SHA-256: $SETUP_SHA"
echo "La salida queda como candidato; no se copió a public/downloads."

if [[ "${1:-}" == "--publish" ]]; then
  for confirmation in WINDOWS_CLEAN_INSTALL_VERIFIED TENANT_IMPORT_VERIFIED LAN_INSTALL_VERIFIED SCALE_HARDWARE_VERIFIED; do
    if [[ "${!confirmation:-}" != "1" ]]; then
      echo "Publicación bloqueada: falta confirmar $confirmation=1 con evidencia real." >&2
      exit 3
    fi
  done
  DOWNLOAD_DIR="$REPO_DIR/public/downloads"
  mkdir -p -- "$DOWNLOAD_DIR"
  install -m 0644 -- "$OUTFILE" "$DOWNLOAD_DIR/BalanzaPro-Setup.exe"
  install -m 0644 -- "$CANDIDATE_DIR/BalanzaPro-Setup.manifest.json" "$DOWNLOAD_DIR/BalanzaPro-Setup.manifest.json"
  echo "Setup publicado en $DOWNLOAD_DIR tras las verificaciones requeridas."
fi
