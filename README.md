# INVENTARIOARENS

Backend SaaS modular multi-tenant de **gestión de inventario + punto de venta**, escrito en
**Laravel 13 + PHP 8.3+ + PostgreSQL**. Es una **API REST pura** pensada para ser consumida
desde un cliente HTTP (web, móvil o CLI).

> **Estado (2026-07-13)**: el frontend anterior (portal web Blade/JS vanilla + escritorio WPF) fue
> eliminado por completo. El nuevo cliente frontend se construirá como proyecto separado en una
> fase posterior. Este repo es **backend puro**.

---

## Qué hace

- **Multi-tenant**: una sola base de datos con `tenant_id` + scope global. Un usuario puede pertenecer
  a varias empresas con roles distintos en cada una (Spatie Permission con `teams = tenant_id`).
- **Catálogo + inventario**: productos por tenant con control por cantidad o por unidades serializadas
  (IMEI/serial), stock por almacén, kardex, traslados internos y solicitudes interempresa.
- **Punto de venta**: ventas en mostrador con pagos mixtos USD/VES, snapshot de tasa por movimiento,
  cajas físicas multi-sucursal, sesiones de apertura/cierre y conciliaciones.
- **Compras y cuentas por pagar**: órdenes de compra, recepción parcial o total, devoluciones,
  generación automática de CxP al recibir.
- **Ventas y cuentas por cobrar**: confirmación de ventas, devoluciones, generación automática de CxC,
  cobros parciales o totales, comprobantes históricos.
- **Multi-moneda venezolano**: USD como base, VES como operativa, tipos de tasa configurables
  (`BCV`, `PARALELO`, tienda) con snapshot en cada movimiento monetario.
- **Sync local ↔ nube**: patrón Local-First + Outbox bidireccional para nodos locales que operan
  offline y sincronizan cuando hay conexión.
- **SaaS Master**: Platform Admins globales que gestionan grupos de empresas y spinoffs sin
  pertenecer a un tenant específico.
- **Warranties / IMEI / seriales**: políticas de garantía por tenant, claims con resolución
  (reemplazo/rechazo/reembolso), trazabilidad por unidad física.
- **Reportes operativos**: stock, bajo stock, movimientos, dashboards ejecutivos, finanzas (CxC/CxP).

---

## Stack

| Capa | Versión |
|---|---|
| PHP | 8.3+ (recomendado 8.4) |
| Laravel | 13.8 |
| Base de datos | PostgreSQL 16 (prod + local), 17-alpine (docker dev), 15 (CI) |
| Permisos | spatie/laravel-permission 8.1 con `teams` |
| Tests | PHPUnit 12.5.12 |
| Linter | Pint 1.27 |
| Multi-tenancy | `BelongsToTenant` trait + `TenantScope` + middleware `api.auth` + `tenant` |

**No incluye** frontend en el repo. Se consume vía API REST bajo `/api/*`.

---

## Quick start

```bash
git clone https://github.com/gamijoam/inventarioarens.git
cd inventarioarens
composer install
cp .env.example .env
php artisan key:generate

# Ajustar .env con tus credenciales locales de PostgreSQL
php artisan migrate --force

# (Opcional) sembrar datos demo
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
php artisan db:seed --class=DemoDataSeeder --force

# Levantar
php artisan serve
```

Más detalle en [`BUILD.md`](./BUILD.md). Antes de instalar en una PC nueva, revisa
[`docs/COSAS_POR_INSTALAR_PRIMERA_VEZ.md`](./docs/COSAS_POR_INSTALAR_PRIMERA_VEZ.md),
especialmente la extensión PHP `gd` para imágenes de productos.

---

## Endpoints

Catálogo completo en [`docs/API.md`](./docs/API.md). Superficies principales:

| Prefijo | Descripción |
|---|---|
| `/api/auth/*` | Login multi-tenant, switch-tenant, sesiones, logout. |
| `/api/auth/platform-login` | Login exclusivo para Platform Admins (SaaS Master). |
| `/api/master/*` | CRUD de grupos, spinoffs y Platform Admins (sin tenant). |
| `/api/products`, `/api/price-lists` | Catálogo y precios. |
| `/api/inventory-center/*` | Centro de Inventario (summary, productos, serials, movimientos, audits). |
| `/api/inventory/*` | Movimientos crudos de inventario (compras, ventas, ajustes, reservas, traslados). |
| `/api/inventory-transfers/*`, `/api/inventory-transfer-requests/*` | Traslados internos y solicitudes interempresa. |
| `/api/cash-register/*` | Cajas físicas, sesiones, movimientos, cierre. |
| `/api/pos/*` | Punto de venta (checkouts, órdenes, pagos). |
| `/api/sales/*`, `/api/sales-returns/*` | Ventas y devoluciones de venta. |
| `/api/purchases/*`, `/api/purchase-returns/*` | Compras y devoluciones de compra. |
| `/api/accounts-receivable/*`, `/api/accounts-payable/*` | CxC y CxP. |
| `/api/payment-receipts/*` | Comprobantes históricos. |
| `/api/financial-adjustments/*` | Ajustes financieros. |
| `/api/warranty-policies/*`, `/api/warranty-claims/*` | Garantías. |
| `/api/customers/*`, `/api/suppliers/*`, `/api/customer-groups/*` | Terceros. |
| `/api/branches/*`, `/api/warehouses/*` | Sucursales y almacenes. |
| `/api/currency/rate-types/*`, `/api/currency/rates/*` | Tipos de tasa y valores. |
| `/api/payment-methods/*` | Métodos de pago. |
| `/api/users/*`, `/api/roles/*`, `/api/permissions/*` | AccessControl. |
| `/api/sync/*` | Sync worker local↔nube (push, pull, ack, status, tokens). |
| `/api/dashboard/summary`, `/api/admin-portal/*` | Dashboards gerenciales. |
| `/api/reports/*`, `/api/finance-reports/*`, `/api/kardex/*` | Reportes. |
| `/api/tenants/*` | CRUD de tenants (cross-tenant desde Platform Admin). |

---

## Tests

```bash
php vendor/bin/phpunit                          # suite completa
php vendor/bin/phpunit tests/Feature/Inventory/ # un módulo
php vendor/bin/phpunit --process-isolation      # si hay "duplicate table" en local
```

Hay un set explícito de tests cross-tenant. Ver sección "Tests cross-tenant" en [`BUILD.md`](./BUILD.md).

---

## Documentación

- [`AGENTS.md`](./AGENTS.md) — contexto persistente para opencode (este archivo).
- [`BUILD.md`](./BUILD.md) — setup local, deploy, CI, troubleshooting.
- [`docs/COSAS_POR_INSTALAR_PRIMERA_VEZ.md`](./docs/COSAS_POR_INSTALAR_PRIMERA_VEZ.md) — checklist de extensiones, sync, frontend e impresión para preparar una PC o VPS.
- [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md) — fuente de verdad arquitectural.
- [`docs/MODULES.md`](./docs/MODULES.md) — mapa de los 34 módulos backend.
- [`docs/API.md`](./docs/API.md) — referencia completa de endpoints por módulo.
- [`docs/IMPLEMENTATION_LOG.md`](./docs/IMPLEMENTATION_LOG.md) — bitácora cronológica de cambios.
- [`docs/AUDIT_2026-07-11/`](./docs/AUDIT_2026-07-11/) — auditoría de backend (10 áreas, score 6.8/10).
- [`docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md`](./docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md) — contrato API para el frontend nuevo.

---

## Infraestructura

- **Local**: Windows + Laragon + PHP 8.4.23 + PostgreSQL 16 (`127.0.0.1:5434`, DB `inventory_arens`).
- **VPS nube**: `217.216.80.158` (Contabo Ubuntu 24.04), Nginx + PHP-FPM 8.4, PostgreSQL 16 nativo.
- **Dominio público**: `https://app.miinventariofacil.com/api` (HTTPS Let's Encrypt).
- **SSH al VPS**: `ssh -i C:\Users\gafit\.ssh\webadmin-vps root@217.216.80.158`.

⚠️ **No confundir con MiInventarioFácil** (otro SaaS en VPS distinto, FastAPI/Python). Ver
[`AGENTS.md` §2](./AGENTS.md) para la tabla de identificación.

---

## Licencia

MIT (heredado del skeleton de Laravel).
