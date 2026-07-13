#!/usr/bin/env bash
# deploy-platform-master.sh
# Aplica los commits de SaaS Master 3 niveles (d3f7f350 -> 16713e93) en el VPS.
# Ejecutar en: webadmin@217.216.80.158 (VPS Ubuntu 24.04 + nginx + php-fpm + postgres nativo).
#
# Idempotente: cada paso se puede re-ejecutar si uno solo falla.

set -euo pipefail

APP_DIR="/opt/inventarioarens-cloud"
PHP_BIN="/usr/bin/php8.4"

cd "${APP_DIR}"

echo "=== [1/6] Pull ==="
sudo -u webadmin git pull --ff-only

echo "=== [2/6] Composer install (solo si hay cambios en composer.lock/.json) ==="
if git diff HEAD@{1} --name-only -- composer.lock composer.json 2>/dev/null | grep -q .; then
  sudo -u webadmin composer install --no-dev --no-interaction --optimize-autoloader
else
  echo "  (sin cambios en composer, se salta composer install)"
fi

echo "=== [3/6] Migraciones pendientes ==="
sudo -u webadmin ${PHP_BIN} artisan migrate --force

echo "=== [4/6] Limpiar caches ==="
sudo -u webadmin ${PHP_BIN} artisan optimize:clear
sudo -u webadmin ${PHP_BIN} artisan config:cache
sudo -u webadmin ${PHP_BIN} artisan route:cache

echo "=== [5/6] Verificar rutas nuevas ==="
sudo -u webadmin ${PHP_BIN} artisan route:list --path=master 2>&1 | head -20

echo "=== [6/6] Smoke test del endpoint platform-login ==="
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' \
  -X POST 'https://app.miinventariofacil.com/api/auth/platform-login' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data '{"email":"noexiste@invalid.test","password":"x"}' || true

echo "=== Fin del deploy ==="
echo "Recordatorio: el comando artisan access:create-platform-admin sigue disponible"
echo "para promover/crear Platform Admins desde SSH."
