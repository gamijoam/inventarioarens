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

```bash
# Suite completa
php vendor/bin/phpunit

# Solo un módulo
php vendor/bin/phpunit tests/Feature/AdminPortal/

# Un archivo específico
php vendor/bin/phpunit tests/Feature/AdminPortal/AdminTransferActionsTest.php
```

> Si la suite completa tira errores de "duplicate table" / "undefined table" al correrla local con `RefreshDatabase`, es por concurrencia en la DB de testing. En el server no se reproduce. Solución: correr archivo por archivo o usar `--process-isolation`.

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
