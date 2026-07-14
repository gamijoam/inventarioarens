# Build y Deploy

Este proyecto es **backend puro** (Laravel 13 + PHP 8.3+ + PostgreSQL 16). No hay frontend en el repo:
se sirve como API REST bajo `/api/*` y el cliente web se construye como proyecto separado (pendiente).

## Setup local (primera vez)

```bash
# 1. Clonar e instalar dependencias PHP
git clone https://github.com/gamijoam/inventarioarens.git
cd inventarioarens
composer install

# 2. Configurar entorno
cp .env.example .env
php artisan key:generate

# 3. Crear DB local (ajustar credenciales en .env si difieren)
# DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5434 DB_DATABASE=inventory_arens
# DB_USERNAME=inventory_arens DB_PASSWORD=secret
php artisan migrate --force

# 4. (Opcional) Sembrar datos demo multiempresa
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
```

**Requisitos locales**:
- PHP 8.3+ (recomendado 8.4 via Laragon).
- Composer 2.x.
- PostgreSQL 16 (o usar Docker Compose: `docker compose up -d postgres`).

## Dev con hot reload

```bash
# Opción A: comandos separados en terminales
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0

# Opción B: todo junto con concurrently
composer dev
```

## Tests

### Tests Feature (PHPUnit)

```bash
# Suite completa
php vendor/bin/phpunit

# Solo un módulo
php vendor/bin/phpunit tests/Feature/AdminPortal/

# Un archivo específico
php vendor/bin/phpunit tests/Feature/AdminPortal/AdminTransferActionsTest.php

# Con isolation (si hay "duplicate table" en local)
php vendor/bin/phpunit --process-isolation
```

> Si la suite completa tira errores de "duplicate table" / "undefined table" al correrla local con
> `RefreshDatabase`, es por concurrencia en la DB de testing. En el server no se reproduce. Solución:
> correr archivo por archivo o usar `--process-isolation`.

### Tests cross-tenant

Hay un set explícito de tests que verifica que un usuario de tenant A **no pueda** acceder/modificar
traslados de tenant B. Sirven como guardia contra regresiones cuando se agregan nuevos endpoints o
policies.

- `tests/Feature/Inventory/InventorySchemaIsolationTest.php` — schema-level isolation (branches, warehouses, stock movements, balances, FK constraints).
- `tests/Feature/Inventory/InventoryAuthorizationTest.php` — service-level (gates, authorized service, transfer cross-tenant).
- `tests/Feature/AdminPortal/AdminTransfersListTest.php::test_admin_does_not_see_other_tenant_transfers` — listing isolation.
- `tests/Feature/AdminPortal/AdminTransferActionsTest.php` — 7 tests cross-tenant (show, prepare, dispatch, receive, cancel, resolve, missing X-Tenant).
- `tests/Feature/InventoryTransfers/InventoryTransferApiTest.php` — 3 tests cross-tenant (prepare, cancel, index leak).

```bash
vendor/bin/phpunit --filter "cross_tenant|other_tenant|detail_audit|standard_api_index_does_not_leak"
```

Si vas a agregar un endpoint nuevo, **agregá un test cross-tenant** que verifique que user de tenant B
no puede tocar recursos de tenant A. El patron es: crear tenant A y tenant B, usuario en cada uno con
permisos completos, intentar la accion cross-tenant, esperar 403 (o 404 si el endpoint oculta existencia
de recursos), y verificar que el estado del recurso en tenant A **no cambio**.

## Deploy al VPS

El server ya tiene PHP, Composer y PostgreSQL instalados. El flujo normal es:

```bash
ssh -i C:\Users\gafit\.ssh\webadmin-vps root@217.216.80.158
cd /opt/inventarioarens-cloud

sudo /usr/bin/env git pull
sudo /usr/bin/env composer install --no-dev --optimize-autoloader
sudo /usr/bin/env php artisan migrate --force
sudo /usr/bin/env php artisan optimize:clear       # limpia config, cache, views, routes
```

> ⚠️ **Nunca** uses `php artisan view:cache` en este proyecto. Cachea las vistas compiladas y los
> cambios en `.blade.php` no se ven hasta que se limpia el cache. `optimize:clear` ya limpia las vistas,
> entre otras cosas. (Aplica si en el futuro se reintroducen Blades del nuevo frontend.)

## Stack del VPS

- Nginx + PHP-FPM 8.4 + PostgreSQL 16 nativo (NO Docker).
- HTTPS con Let's Encrypt.
- DNS A: `app.miinventariofacil.com → 217.216.80.158`.
- Endpoint público: `https://app.miinventariofacil.com/api/*`.
- Bootstrap: `scripts/cloud-api-bootstrap-vps.sh`.

## CI/CD (GitHub Actions)

El repo tiene un workflow en `.github/workflows/ci.yml` que corre automáticamente:

- **Job `phpunit`** — Levanta Postgres 15 como service, migra la DB de testing, corre la suite Feature
  con `--testsuite Feature`. Si falla, el workflow se frena acá.

(Antes había un job `playwright` para E2E del portal web; se eliminó junto con el frontend el
2026-07-13. Cuando se construya el nuevo frontend, se reincorporará.)

## Troubleshooting deploy

| Síntoma | Causa probable | Fix |
|---|---|---|
| 401 en API tras deploy | Sesión cacheada con token viejo | `php artisan optimize:clear` (limpia cache de config) |
| Cambios en `.blade.php` no se ven | `view:cache` activo | `php artisan view:clear` (o `optimize:clear`) |
| Worker de sync abre ventana negra | El Scheduled Task apunta al .cmd directo, no al VBS | Re-registrar el task con `scripts/sync-worker-task.ps1 install -TenantSlug <slug>` |
| Migración falla en deploy | Schema drift entre local y server | `php artisan migrate:status` + revisar `database/migrations/` |
| Composer falla por memoria | PHP `memory_limit` bajo | `php -d memory_limit=-1 /usr/bin/composer install` |

## Historial de cambios del flujo de build

- **2026-07-13** — Se elimina el frontend completo: portal web Blade/JS + WPF escritorio + Vite/Tailwind/Playwright.
  El proyecto pasa a ser backend API puro. `routes/web.php` borrado, `package.json`/`vite.config.js`/
  `playwright.config.js` borrados, `desktop/` borrado, CI pierde el job `playwright`.
- **2026-07-10** — Se untrackea `public/build/` (commit de housekeeping). 19 archivos de assets
  desversionados; assets regenerados en cada deploy (ya no aplica — no hay frontend que compilar).
- **2026-07-10** — Se agrega CI/CD en `.github/workflows/ci.yml` con dos jobs: `phpunit` (suite Feature
  contra Postgres 15) y `playwright` (5 tests E2E del portal). Si rompe, el PR queda en rojo.