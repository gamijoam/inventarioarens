#!/usr/bin/env bash
# scripts/build-installer-direct.sh
# Compila y empaqueta el instalador BalanzaPro-Setup.exe y su manifiesto.
#
# Uso:
#   bash scripts/build-installer-direct.sh [--version=1.0.0] [--target=balanzapro] [--publish] [--skip-frontend]

set -Eeuo pipefail

REPO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="1.0.1"
TARGET="balanzapro"
PUBLISH=false
SKIP_FRONTEND=false

for arg in "$@"; do
  case "$arg" in
    --version=*)
      VERSION="${arg#*=}"
      ;;
    --target=*)
      TARGET="${arg#*=}"
      ;;
    --publish)
      PUBLISH=true
      ;;
    --skip-frontend)
      SKIP_FRONTEND=true
      ;;
    *)
      echo "Opción desconocida: $arg"
      exit 1
      ;;
  esac
done

echo "============================================================"
echo "==> Generador de Instalador Windows Todo-en-Uno (.EXE)"
echo "    Versión: $VERSION"
echo "    Instancia destino: $TARGET"
echo "    Publicar en web: $PUBLISH"
echo "============================================================"

# Exportar la versión usada por el empaquetador. Las verificaciones de publicación
# deben venir del operador; este script nunca las marca como completadas.
export APP_VERSION="$VERSION"

if [ "$PUBLISH" = true ]; then
  for confirmation in WINDOWS_CLEAN_INSTALL_VERIFIED TENANT_IMPORT_VERIFIED LAN_INSTALL_VERIFIED SCALE_HARDWARE_VERIFIED; do
    if [ "${!confirmation:-}" != "1" ]; then
      echo "Publicación bloqueada: falta confirmar $confirmation=1 tras una prueba real." >&2
      exit 3
    fi
  done
fi

# Ejecutar el empaquetador integral
PUBLISH_FLAG=""
if [ "$PUBLISH" = true ]; then
  PUBLISH_FLAG="--publish"
fi

cd "$REPO_DIR"

if [ -f "$REPO_DIR/scripts/build_full_go_package.sh" ]; then
  echo ">> Ejecutando script de empaquetado NSIS y Go..."
  bash "$REPO_DIR/scripts/build_full_go_package.sh" $PUBLISH_FLAG
else
  echo "Error: No se encontró $REPO_DIR/scripts/build_full_go_package.sh"
  exit 1
fi

# Si se solicitó publicar en una instancia específica distinta del repo actual
if [ "$PUBLISH" = true ]; then
  TARGET_DOWNLOADS="/opt/${TARGET}-cloud/public/downloads"
  mkdir -p "$TARGET_DOWNLOADS"
  
  if [ -f "$REPO_DIR/public/downloads/BalanzaPro-Setup.exe" ]; then
    if [ "$(realpath "$REPO_DIR/public/downloads")" != "$(realpath "$TARGET_DOWNLOADS")" ]; then
      echo ">> Sincronizando instalador y manifiesto en $TARGET_DOWNLOADS..."
      cp -f "$REPO_DIR/public/downloads/BalanzaPro-Setup.exe" "$TARGET_DOWNLOADS/"
      cp -f "$REPO_DIR/public/downloads/BalanzaPro-Setup.manifest.json" "$TARGET_DOWNLOADS/"
    fi
    chown -R www-data:www-data "$TARGET_DOWNLOADS"
    
    echo ">> Verificando archivos publicados:"
    ls -lh "$TARGET_DOWNLOADS/BalanzaPro-Setup.exe"
    ls -lh "$TARGET_DOWNLOADS/BalanzaPro-Setup.manifest.json"
  fi
fi

echo "============================================================"
echo ">> [EXITO] Generacion del instalador completada correctamente."
echo "============================================================"
