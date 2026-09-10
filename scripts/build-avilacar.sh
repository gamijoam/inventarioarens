#!/usr/bin/env bash
# scripts/build-avilacar.sh
# Compila el frontend para Repuestos Avilacar en su propia rama y limpia caches.
set -euo pipefail

AVILACAR_DIR="/opt/repuestosavilacar-cloud"
echo "=== Compilando frontend para Repuestos Avilacar ==="
cd "$AVILACAR_DIR/frontend"
pnpm run build
chown -R www-data:www-data "$AVILACAR_DIR/frontend/dist"

echo "=== Limpiando caches de Laravel ==="
php "$AVILACAR_DIR/artisan" optimize:clear
systemctl reload php8.4-fpm

echo "=== Listo: app.repuestosavilacar.com actualizado con exito ==="
