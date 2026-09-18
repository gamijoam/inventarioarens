#!/usr/bin/env bash
# scripts/build-balanzapro.sh
# Compila el frontend para BalanzaPro en su propia rama y limpia caches.
set -euo pipefail

BALANZAPRO_DIR="/opt/balanzapro-cloud"
echo "=== Compilando frontend para BalanzaPro ==="
cd "$BALANZAPRO_DIR/frontend"
pnpm run build

# Copiar el build de dist/admin a dist/ para servicio web
if [ -d "$BALANZAPRO_DIR/frontend/dist/admin" ]; then
  cp -rf "$BALANZAPRO_DIR/frontend/dist/admin/"* "$BALANZAPRO_DIR/frontend/dist/"
fi

chown -R www-data:www-data "$BALANZAPRO_DIR/frontend/dist"

echo "=== Limpiando caches de Laravel ==="
php "$BALANZAPRO_DIR/artisan" optimize:clear
systemctl reload php8.4-fpm

echo "=== Listo: app.balanzapro.com actualizado con exito ==="
