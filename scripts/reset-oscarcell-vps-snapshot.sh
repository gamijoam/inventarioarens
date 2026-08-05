#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SNAPSHOT_DB="${OSCARCELL_SNAPSHOT_DB:-inventory_arens_oscarcell_vps}"
DUMP_FILE="${OSCARCELL_SNAPSHOT_DUMP:-$ROOT_DIR/storage/app/oscarcell-vps.dump}"
DB_HOST="${PGHOST:-${DB_HOST:-127.0.0.1}}"
DB_PORT="${PGPORT:-${DB_PORT:-5432}}"
DB_USER="${PGUSER:-${DB_USERNAME:-postgres}}"

if [[ "$SNAPSHOT_DB" != "inventory_arens_oscarcell_vps" ]]; then
    printf 'Refusing to reset database: %s\n' "$SNAPSHOT_DB" >&2
    printf 'This script is locked to inventory_arens_oscarcell_vps.\n' >&2
    exit 1
fi

if [[ ! -s "$DUMP_FILE" ]]; then
    printf 'Dump not found or empty: %s\n' "$DUMP_FILE" >&2
    printf 'Set OSCARCELL_SNAPSHOT_DUMP or place the dump at storage/app/oscarcell-vps.dump.\n' >&2
    exit 1
fi

if [[ -z "${PGPASSWORD:-}" ]]; then
    printf 'PGPASSWORD is required; it is never read from or written to the repository.\n' >&2
    exit 1
fi

command -v dropdb >/dev/null || { printf 'dropdb is required.\n' >&2; exit 1; }
command -v createdb >/dev/null || { printf 'createdb is required.\n' >&2; exit 1; }
command -v pg_restore >/dev/null || { printf 'pg_restore is required.\n' >&2; exit 1; }
command -v psql >/dev/null || { printf 'psql is required.\n' >&2; exit 1; }
command -v php >/dev/null || { printf 'php is required.\n' >&2; exit 1; }

export PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USER"
TOC_FILE="$(mktemp)"
trap 'rm -f "$TOC_FILE"' EXIT

printf 'Resetting isolated snapshot database: %s\n' "$SNAPSHOT_DB"
dropdb --if-exists "$SNAPSHOT_DB"
createdb -T template0 "$SNAPSHOT_DB"

# The VPS has pg_trgm; the local PostgreSQL installation does not. Omit only
# that extension and its three optional search indexes from the restore.
pg_restore --list "$DUMP_FILE" | awk '!/pg_trgm|_trgm_idx/' > "$TOC_FILE"
pg_restore --exit-on-error --no-owner --no-privileges --use-list="$TOC_FILE" --dbname="$SNAPSHOT_DB" "$DUMP_FILE"

psql -d "$SNAPSHOT_DB" -v ON_ERROR_STOP=1 -c "
    SELECT 1
    FROM tenants
    WHERE slug = 'oscar-cell'
      AND is_group = true;
    SELECT 1
    FROM products
    WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'oscarcell-yaracall')
    LIMIT 1;
"

DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USERNAME="$DB_USER" \
    DB_PASSWORD="$PGPASSWORD" DB_DATABASE="$SNAPSHOT_DB" \
    php "$ROOT_DIR/artisan" tinker --execute="
        App\\Models\\User::where('email', 'oscarcellmaster@gmail.com')
            ->update(['password' => Illuminate\\Support\\Facades\\Hash::make('gabo1234')]);
    " >/dev/null

printf 'Snapshot reset complete.\n'
printf 'Test user: oscarcellmaster@gmail.com / gabo1234\n'
printf 'Use DB_DATABASE=%s for the local Laravel server.\n' "$SNAPSHOT_DB"
