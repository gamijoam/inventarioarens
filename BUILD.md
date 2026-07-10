# Build y Deploy

Este proyecto usa **Laravel 12 + Vite 8** para el frontend y **pnpm** como package manager. Los assets compilados (`public/build/`) **no se versionan en git** — se regeneran en cada setup/deploy. Esto evita problemas de hashes distintos entre Windows y Linux, y mantiene el repo liviano.

## Setup local (primera vez)

```bash
# 1. Clonar e instalar dependencias PHP
git clone https://github.com/gamijoam/inventarioarens.git
cd inventarioarens
composer install
cp .env.example .env
php artisan key:generate

# 2. Instalar dependencias JS y buildear los assets
pnpm install
pnpm run build

# 3. Para desarrollo con hot reload:
pnpm run dev
```

**Si la app se ve sin estilos o sin JS** después de clonar, el 99% de las veces es que olvidaste el `pnpm run build`.

## Deploy al server (VPS)

El server ya tiene PHP, Node y pnpm instalados. El flujo normal es:

```bash
ssh mavis@217.216.80.158
cd /opt/inventarioarens-cloud

sudo /usr/bin/env git pull
sudo /usr/bin/env pnpm install --frozen-lockfile   # respeta pnpm-lock.yaml
sudo /usr/bin/env pnpm run build                   # regenera public/build/
sudo /usr/bin/env php artisan optimize:clear       # limpia config, cache, views, routes
```

> ⚠️ **Nunca** uses `php artisan view:cache` en este proyecto. Cachea las vistas compiladas y los cambios en `.blade.php` no se ven hasta que se limpia el cache. `optimize:clear` ya limpia las vistas, entre otras cosas.

## Tests

### Tests feature (PHP / PHPUnit)

```bash
# Suite completa
php vendor/bin/phpunit

# Solo un módulo
php vendor/bin/phpunit tests/Feature/AdminPortal/

# Un archivo específico
php vendor/bin/phpunit tests/Feature/AdminPortal/AdminTransferActionsTest.php
```

> Si la suite completa tira errores de "duplicate table" / "undefined table" al correrla local con `RefreshDatabase`, es por concurrencia en la DB de testing. En el server no se reproduce. Solución: correr archivo por archivo o usar `--process-isolation`.

### Tests E2E (Playwright)

Cobertura del UI del portal: login, listado, drawer, filtros, export CSV.

```bash
# Solo la primera vez (instala ~150 MB de chromium)
pnpm install
pnpm e2e:install

# Correr todos los tests E2E
pnpm e2e

# Solo un test
pnpm e2e tests/e2e/portal-translados.spec.js
```

Variables de entorno utiles:

| Variable | Default | Proposito |
|---|---|---|
| `BASE_URL` | `http://127.0.0.1:8000` | URL del portal a testear |
| `E2E_USER` | `gerente.valencia@demo.test` | Email del usuario de prueba |
| `E2E_PASSWORD` | `password` | Password del usuario de prueba |
| `E2E_TENANT_SLUG` | `demo-valencia` | Slug del tenant |

Los tests detectan automaticamente si el portal esta en modo dev bypass (sin form de login) y skipean el paso. Si tu setup NO usa bypass, configura `E2E_USER` y `E2E_PASSWORD` con credenciales reales.

## Por qué `public/build/` no está en git

| Problema que resuelve | Cómo |
|---|---|
| Hashes distintos por OS | Windows genera `admin-AAAA.js`, Linux genera `admin-BBBB.js`. Cada `pnpm run build` cambiaba el árbol. |
| Repo inflado | ~0.5 MB de binarios (CSS, JS, woff/woff2) que se regeneran. |
| Riesgo de inconsistencia | Si commiteás un build a mano y olvidás regenerar en deploy, podés quedar con código viejo en producción. |

El `.gitignore` ya tiene `/public/build` en la línea 19, así que los próximos builds no se commitean ni por accidente.

## Estructura de scripts pnpm

| Script | Uso |
|---|---|
| `pnpm run dev` | Servidor de desarrollo con HMR (hot module replacement). NO usar en producción. |
| `pnpm run build` | Build de producción. Genera `public/build/` con assets minificados. |
| `pnpm run build:qa` | Build con config QA (VITE_API_URL distinto). Usar solo si el deploy requiere esa variante. |

## Troubleshooting deploy

| Síntoma | Causa probable | Fix |
|---|---|---|
| CSS/JS no carga en producción | Falta `pnpm run build` | Buildear en el server |
| Cambios en `.blade.php` no se ven | `view:cache` activo | `php artisan view:clear` (o `optimize:clear` que ya lo incluye) |
| Worker de sync abre ventana negra | El Scheduled Task apunta al .cmd directo, no al VBS | Re-registrar el task con `scripts/sync-worker-task.ps1 install -TenantSlug <slug>` |
| 401 en API tras deploy | Sesión cacheada con token viejo | `php artisan optimize:clear` (limpia cache de config) |

## Historial de cambios del flujo de build

- **2026-07-10** — Se untrackea `public/build/` (commit de housekeeping). Antes: 19 archivos de assets en git (842+ líneas entre CSS/JS/fonts). Después: assets generados en cada setup/deploy, `.gitignore` ya los excluía.
- **2026-07-10** — Se agrega CI/CD en `.github/workflows/ci.yml` con dos jobs: `phpunit` (corre todos los tests Feature con `--process-isolation` contra Postgres 15) y `playwright` (corre los 5 tests E2E del portal contra chromium). Se corre automaticamente en cada push a `main`/`develop` y en cada PR. Si rompe, el PR queda en rojo y no se puede mergear.

## CI/CD (GitHub Actions)

El repo tiene un workflow en `.github/workflows/ci.yml` que corre automaticamente:

- **Job `phpunit`** — Levanta Postgres 15 como service, migra la DB de testing, corre los ~90 tests Feature con `--process-isolation`. Si falla, el workflow se frena aca.
- **Job `playwright`** — Necesita que `phpunit` pase primero (`needs: phpunit`). Levanta Postgres, migra, siembra el demo data (`gerente.valencia@demo.test`), buildea los assets Vite, levanta `php artisan serve` en background, descarga chromium, corre los 5 tests E2E del portal. Si falla, sube el `playwright-report/` como artifact del run.

Para correrlo localmente sin GitHub:

```bash
# Equivalente del job phpunit
docker run --rm -d --name test-pg -e POSTGRES_USER=inventory_arens -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=inventory_arens_testing -p 5432:5432 postgres:15-alpine
DB_HOST=127.0.0.1 DB_PORT=5432 vendor/bin/phpunit --testsuite Feature --process-isolation
docker stop test-pg
```

O instalar [`act`](https://github.com/nektos/act) para correr el workflow literal en Docker.

## Tests de tenant isolation

Hay un set explicito de tests que verifica que un usuario de tenant A **no pueda** acceder/modificar traslados de tenant B. Sirven como guardia contra regresiones cuando se agregan nuevos endpoints o policies.

- `tests/Feature/Inventory/InventorySchemaIsolationTest.php` — schema-level isolation (branches, warehouses, stock movements, balances, FK constraints).
- `tests/Feature/Inventory/InventoryAuthorizationTest.php` — service-level (gates, authorized service, transfer cross-tenant).
- `tests/Feature/AdminPortal/AdminTransfersListTest.php::test_admin_does_not_see_other_tenant_transfers` — listing isolation (admin portal).
- `tests/Feature/AdminPortal/AdminTransferActionsTest.php` — 7 tests cross-tenant (show, prepare, dispatch, receive, cancel, resolve, missing X-Tenant).
- `tests/Feature/InventoryTransfers/InventoryTransferApiTest.php` — 3 tests cross-tenant (prepare, cancel, index leak).

Para correrlos todos juntos:
```bash
vendor/bin/phpunit --filter "cross_tenant|other_tenant|detail_audit|standard_api_index_does_not_leak"
```

Si vas a agregar un endpoint nuevo, **agregá un test cross-tenant** que verifique que user de tenant B no puede tocar recursos de tenant A. El patron es: crear tenant A y tenant B, usuario en cada uno con permisos completos, intentar la accion cross-tenant, esperar 403 (o 404 si el endpoint oculta existencia de recursos), y verificar que el estado del recurso en tenant A **no cambio**.
