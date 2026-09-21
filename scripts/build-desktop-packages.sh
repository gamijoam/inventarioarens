#!/usr/bin/env bash
set -euo pipefail

echo '==================================================='
echo '  COMPILANDO FRONTEND BALANZAPRO (WEB Y DESKTOP)  '
echo '==================================================='

cd /opt/balanzapro-cloud/frontend

# 1. Compilar para la Web
echo '[1/3] Compilando frontend web para app.balanzapro.com...'
pnpm run build
if [ -d 'dist/admin' ]; then
  cp -rf dist/admin/* dist/
fi
chown -R www-data:www-data dist
php /opt/balanzapro-cloud/artisan optimize:clear >/dev/null 2>&1 || true

# 2. Compilar Desktop Administrativo (app.asar)
echo '[2/3] Compilando paquete Desktop Administrativo...'
pnpm run electron:build:balanzapro-admin -- --win --dir

# 3. Compilar Desktop POS (app.asar)
echo '[3/3] Compilando paquete Desktop Punto de Venta (POS)...'
pnpm run electron:build:balanzapro-pos -- --win --dir

echo '==================================================='
echo '  COMPILACION COMPLETADA EXITOSAMENTE             '
echo '  Archivos listos en:                             '
echo '  - release/balanzapro-admin/win-unpacked/resources/app.asar'
echo '  - release/balanzapro-pos/win-unpacked/resources/app.asar'
echo '==================================================='
