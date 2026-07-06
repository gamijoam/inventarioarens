#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/inventarioarens-cloud}"
APP_PORT="${APP_PORT:-8010}"
DB_NAME="${DB_NAME:-inventory_arens}"
DB_USER="${DB_USER:-postgres}"
PHP_BIN="${PHP_BIN:-php}"
SERVICE_NAME="${SERVICE_NAME:-inventarioarens-cloud-api}"
RUN_USER="${RUN_USER:-www-data}"
CLOUD_SEED_DEMO="${CLOUD_SEED_DEMO:-0}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Ejecuta este script como root en el VPS."
    exit 1
fi

if [ ! -f "$APP_DIR/artisan" ]; then
    echo "No se encontro Laravel en $APP_DIR."
    echo "Copia o clona el proyecto en esa carpeta antes de ejecutar este script."
    exit 1
fi

if [ -z "${DB_PASSWORD:-}" ]; then
    read -r -s -p "Clave PostgreSQL para $DB_USER: " DB_PASSWORD
    echo
fi

echo "==> Preparando base PostgreSQL local"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1 || \
    sudo -u postgres createdb "$DB_NAME"

cd "$APP_DIR"

echo "==> Preparando archivo .env de nube"
if [ ! -f .env ]; then
    cp .env.example .env
fi

set_env() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s#^${key}=.*#${key}=${value}#g" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env "APP_ENV" "production"
set_env "APP_DEBUG" "false"
set_env "APP_URL" "http://217.216.80.158:${APP_PORT}"
set_env "DB_CONNECTION" "pgsql"
set_env "DB_HOST" "127.0.0.1"
set_env "DB_PORT" "5432"
set_env "DB_DATABASE" "$DB_NAME"
set_env "DB_USERNAME" "$DB_USER"
set_env "DB_PASSWORD" "$DB_PASSWORD"

echo "==> Instalando dependencias si hace falta"
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --prefer-dist --optimize-autoloader
else
    echo "Composer no esta instalado. Instala composer y vuelve a ejecutar."
    exit 1
fi

echo "==> Preparando Laravel"
if ! grep -q "^APP_KEY=base64:" .env; then
    "$PHP_BIN" artisan key:generate --force
fi
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
if [[ "$CLOUD_SEED_DEMO" == "1" || "$CLOUD_SEED_DEMO" == "true" || "$CLOUD_SEED_DEMO" == "TRUE" ]]; then
    echo "==> Cargando datos demo de empresas, usuarios, productos y cajas"
    "$PHP_BIN" artisan db:seed --class=DemoDataSeeder --force
    "$PHP_BIN" artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
fi
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache

echo "==> Configurando servicio systemd en puerto $APP_PORT"
cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<SERVICE
[Unit]
Description=Inventario Arens Cloud API
After=network.target postgresql.service

[Service]
Type=simple
User=${RUN_USER}
Group=${RUN_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan serve --host=0.0.0.0 --port=${APP_PORT}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

chown -R "${RUN_USER}:${RUN_USER}" storage bootstrap/cache
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"

if command -v ufw >/dev/null 2>&1; then
    ufw allow "${APP_PORT}/tcp" || true
fi

echo "==> API nube preparada"
echo "URL: http://217.216.80.158:${APP_PORT}/api"
echo "Estado:"
systemctl --no-pager --full status "${SERVICE_NAME}" | sed -n '1,12p'
