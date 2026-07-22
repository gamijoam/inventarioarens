#!/usr/bin/env bash
# build-toolbox.sh - Arma el .zip portable del CLI inventoryarens.
#
# Uso: bash scripts/build-toolbox.sh
# Output: dist/inventoryarens-toolbox-vX.Y.Z.zip
#
# El zip contiene todo lo que el tecnico necesita para instalar/desinstalar
# el sync worker y el printer agent en Win o Linux. Single-file, sin instalar
# dependencias externas (solo Python 3.8+).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -oP 'VERSION = "\K[0-9.]+' "$REPO_ROOT/bin/inventoryarens" | head -1)"
OUT_DIR="$REPO_ROOT/dist"
OUT_FILE="$OUT_DIR/inventoryarens-toolbox-v${VERSION}.zip"

if [ -z "$VERSION" ]; then
    echo "Error: no pude extraer VERSION del CLI" >&2
    exit 1
fi

echo "Building inventoryarens-toolbox v${VERSION}..."
echo "Output: $OUT_FILE"

mkdir -p "$OUT_DIR"

# Limpiar zip previo si existe
rm -f "$OUT_FILE"

# Sanity check: el CLI no debe tener BOM UTF-8 (algunos editores commitean
# con BOM y eso rompe el shebang en Linux: el kernel ve ﻿#!/ como path
# del interprete y falla con "No existe el fichero o el directorio").
if head -c 3 "$REPO_ROOT/bin/inventoryarens" | grep -q $'\xef\xbb\xbf'; then
    echo "WARN: bin/inventoryarens tiene BOM UTF-8. Quitando..." >&2
    sed -i '1s/^\xEF\xBB\xBF//' "$REPO_ROOT/bin/inventoryarens"
fi

# Estructura temporal para el zip (en /tmp).
STAGE="$(mktemp -d)"
trap "rm -rf '$STAGE'" EXIT

# 1) CLI core (cross-platform)
install -m 0755 "$REPO_ROOT/bin/inventoryarens" "$STAGE/inventoryarens"

# 2) Wrappers Windows
install -m 0755 "$REPO_ROOT/bin/inventoryarens.bat" "$STAGE/"
install -m 0755 "$REPO_ROOT/bin/inventoryarens.ps1" "$STAGE/"

# 3) systemd files (Linux) - en subdir systemd/
mkdir -p "$STAGE/systemd"
install -m 0644 "$REPO_ROOT/systemd/"*.service "$STAGE/systemd/"
install -m 0644 "$REPO_ROOT/systemd/"*.timer "$STAGE/systemd/"

# 4) Windows Task Scheduler wrappers
mkdir -p "$STAGE/windows"
install -m 0755 "$REPO_ROOT/windows/"*.ps1 "$STAGE/windows/"

# 5) Documentacion
install -m 0644 "$REPO_ROOT/docs/OPERATIONS.md" "$STAGE/README.md"
install -m 0644 "$REPO_ROOT/docs/TUTORIAL.md" "$STAGE/TUTORIAL.md"

# 6) Licencia
if [ -f "$REPO_ROOT/LICENSE" ]; then
    install -m 0644 "$REPO_ROOT/LICENSE" "$STAGE/LICENSE"
fi

# 7) Quickstart embebido
cat > "$STAGE/QUICKSTART.txt" <<'EOF'
INVENTARIOARENS Toolbox vX.Y.Z
=============================

INSTALACION:
  Linux:
    1. Descomprime el zip:  unzip inventoryarens-toolbox-v*.zip
    2. cd inventoryarens-toolbox
    3. ./inventoryarens install sync

  Windows (cmd):
    1. Descomprime el zip
    2. Abre cmd en la carpeta descomprimida
    3. inventoryarens.bat install sync

  Windows (PowerShell):
    1. Descomprime el zip
    2. Abre PowerShell como Admin
    3. .\inventoryarens.ps1 install sync

COMANDOS PRINCIPALES:
  inventoryarens install sync       Emite token + configura auto-start
  inventoryarens status              Health check del sistema
  inventoryarens logs sync            Tail del log del worker
  inventoryarens uninstall sync       Detiene y elimina auto-start
  inventoryarens token rotate         Re-emite token sin reinstalar
  inventoryarens install printer      (Fase 2) Instala printer agent
  inventoryarens update               git pull + composer + migrate + restart

MAS INFO: ver docs/OPERATIONS.md
EOF

# Generar el zip
cd "$STAGE"
zip -r "$OUT_FILE" . -x "*.pyc" "__pycache__/*"

cd "$REPO_ROOT"
echo ""
echo "Listo. Zip creado en: $OUT_FILE"
ls -lh "$OUT_FILE" | awk '{print "  tamano: " $5}'

# Verificar contenido
echo ""
echo "Contenido del zip:"
unzip -l "$OUT_FILE" 2>&1 | tail -n +4 | head -n -2 | awk '{print "  " $4}' | grep -v "^$" | head -20
