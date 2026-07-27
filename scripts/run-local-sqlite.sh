#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_PATH="${DB_PATH:-$ROOT_DIR/storage/framework/local-dev.sqlite}"
API_PORT="${API_PORT:-8787}"
FRONTEND_PORT="${FRONTEND_PORT:-5173}"

command -v php >/dev/null || { printf 'php no esta instalado.\n' >&2; exit 1; }
command -v pnpm >/dev/null || { printf 'pnpm no esta instalado.\n' >&2; exit 1; }

export APP_ENV=local
export APP_DEBUG=false
export APP_URL="http://127.0.0.1:${API_PORT}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_PATH"
export DB_FOREIGN_KEYS=true
export DB_BUSY_TIMEOUT=5000
export DB_JOURNAL_MODE=WAL
export DB_SYNCHRONOUS=NORMAL
export DB_TRANSACTION_MODE=IMMEDIATE
export LOCAL_TECHNICAL_CONSOLE_ENABLED=true
export LOCAL_TECHNICAL_CONSOLE_CLOUD_URL="${LOCAL_TECHNICAL_CONSOLE_CLOUD_URL:-https://app.miinventariofacil.com/api}"
export APP_ALLOWED_ORIGINS_FOR_CSRF="http://127.0.0.1:${FRONTEND_PORT},http://localhost:${FRONTEND_PORT}"
export SESSION_SECURE_COOKIE=false
export APP_FORCE_SECURE_COOKIES=false
export VITE_API_BASE_URL="http://127.0.0.1:${API_PORT}/api"

cd "$ROOT_DIR"
php artisan local:install-sqlite --database="$DB_PATH"

php artisan serve --host=127.0.0.1 --port="$API_PORT" >storage/logs/local-api.log 2>&1 &
API_PID=$!
pnpm --dir frontend dev --host 127.0.0.1 --port="$FRONTEND_PORT" >storage/logs/local-frontend.log 2>&1 &
FRONTEND_PID=$!

cleanup() {
    kill "$FRONTEND_PID" "$API_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

sleep 2
if command -v xdg-open >/dev/null; then
    xdg-open "http://127.0.0.1:${FRONTEND_PORT}/support" >/dev/null 2>&1 || true
fi

wait "$FRONTEND_PID"
