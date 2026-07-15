# CURRENCY_MODULE

> Modulo de tipos de tasa y rates historicas (frontend + backend).
> Estado: 2026-07-14. Frontend completo y funcional.

## Vision general

Permite gestionar:
- **Tipos de tasa** (BCV, Paralelo, BCVPlus, etc): la categoria de tasa.
- **Rates historicas** (BCV 2026-07-14 = 36.50): el valor puntual de una tasa en una fecha.

El sistema mantiene UNA rate activa por (tipo, conversion). Cuando creas una nueva rate
marcada como activa, las anteriores del mismo (tipo, base_currency, quote_currency) se
desactivan automaticamente (lo hace el backend en `ExchangeRateActivationService`).

## Backend

### Modelos

`app/Modules/Currency/Models/`:

- `ExchangeRateType` (fillable: code, name, is_default, is_active)
- `ExchangeRate` (fillable: exchange_rate_type_id, base_currency, quote_currency,
  rate, effective_at, is_active, source)

### Endpoints API

`app/Modules/Currency/routes.php`:

| Metodo | Ruta | Permiso | Descripcion |
|---|---|---|---|
| GET | `/api/currency/rate-types` | `currency.view` | Lista paginada de tipos |
| POST | `/api/currency/rate-types` | `currency.manage` | Crea tipo |
| GET | `/api/currency/rate-types/{type}` | `currency.view` | Ver uno |
| PATCH | `/api/currency/rate-types/{type}` | `currency.manage` | Actualiza |
| DELETE | `/api/currency/rate-types/{type}` | `currency.manage` | Soft delete (is_active=false) |
| GET | `/api/currency/rates` | `currency.view` | Lista paginada de rates (con `rate_type_code` opcional) |
| GET | `/api/currency/rates/current` | `currency.view` | Solo rates activas (filtrable por `rate_type_code`) |
| POST | `/api/currency/rates` | `currency.manage` | Crea rate (auto-desactiva anteriores si `is_active=true`) |
| GET | `/api/currency/rates/{rate}` | `currency.view` | Ver uno |
| PATCH | `/api/currency/rates/{rate}/activate` | `currency.manage` | Activa rate (desactiva anteriores del mismo tipo+conversion) |
| PATCH | `/api/currency/rates/{rate}/deactivate` | `currency.manage` | Desactiva rate |

### Permisos

`app/Support/Permissions/BasePermissions.php`:

- `currency.view` (todos los roles la tienen menos Auditor que solo lectura)
- `currency.manage` (Administrador, Owner, Gerente)

Asignaciones por rol (en `BasePermissions::ROLE_PERMISSIONS`):
- **Owner, Administrador**: ambos permisos
- **Gerente**: ambos
- **Vendedor, Almacen, Auditor**: solo `currency.view`

## Frontend

### Estructura

- `src/routes/_authed/inventory/currency.tsx`: pagina con Tabs (Tipos | Tasas).
- `src/components/layout/Sidebar.tsx`: item "Tipos de tasa" en el submenu de Inventario.
- `src/features/inventory-center/api.ts`: hooks (`useExchangeRateTypes`,
  `useExchangeRates`, `useCreateExchangeRateType`, `useUpdateExchangeRateType`,
  `useDeleteExchangeRateType`, `useCreateExchangeRate`,
  `useActivateExchangeRate`, `useDeactivateExchangeRate`).
- `src/features/inventory-center/schemas.ts`: schemas Zod:
  - `StoreExchangeRateTypeSchema` (code, name, is_default, is_active)
  - `StoreExchangeRateSchema` (exchange_rate_type_id, base/quote_currency, rate, effective_at, source, is_active)
  - `EXCHANGE_RATE_CURRENCIES` (constante: USD, EUR, VES)
- `src/features/inventory-center/catalogs/ExchangeRateTypesManager.tsx`: CRUD de tipos.
- `src/features/inventory-center/catalogs/ExchangeRatesManager.tsx`: tabla historica + form
  para nueva rate, con filtros por tipo y rango de fechas.
- `src/features/inventory-center/components/InlineExchangeRateTypeCreate.tsx`: dialog
  para crear tipo de tasa inline desde el form de producto.
- `src/features/inventory-center/components/ProductForm.tsx`: integra el inline create
  al lado del dropdown de "Tipo de tasa".

### UX

- Pagina con 2 tabs: "Tipos" y "Tasas historicas".
- En "Tipos": tabla con code, nombre, badge de "Predeterminado" o "Activo/Inactivo",
  botones de editar/eliminar. Boton "+ Nuevo tipo de tasa".
- En "Tasas": tabla con fecha efectiva, tipo, conversion (USD->VES), tasa formateada, badge
  Activa/Inactiva, boton Activar/Desactivar. Filtros por tipo y rango de fechas. Boton "+ Nueva tasa"
  con form completo (tipo, conversion, tasa, fecha).
- En ProductForm: dropdown de "Tipo de tasa" con boton "+ Nuevo tipo de tasa" al lado.
  Al confirmar, auto-selecciona el nuevo tipo en el form.

## Sync

El backend genera eventos `exchange_rate_type.created/updated` y
`exchange_rate.created/updated` en `sync_outbox` para sync entre local y cloud. El flujo
ya esta cubierto por el sistema de sync existente (ver `docs/SYNC_OPERATIONS.md`).

## Setup (one-time, ya hecho)

Los permisos `currency.view` y `currency.manage` ya estaban en
`BasePermissions.php` antes de este commit. El seeder los crea en todas las tenants
cuando se ejecuta `php artisan db:seed --class=RolesAndPermissionsSeeder --force`.

Si en una BD muy vieja no estuvieran:

```bash
ssh root@217.216.80.158 "cd /opt/inventarioarens-cloud && php artisan db:seed --class=RolesAndPermissionsSeeder --force"
```

## Pendientes (fuera de este modulo)

- **Multi-currency con conversion automatica** (USD -> VES, EUR -> VES): actualmente
  hay que crear la rate manualmente cada vez que cambia. Un job automatico seria
  ideal (cron que detecta cambios en BCV API oficial y crea una nueva rate).

- **Grafica historica** en el modulo de Reports: actualmente se muestra tabla
  plana. Una grafica de tendencia (recharts/chart.js) daria mas visibilidad.

- **Conversion automatica en ventas** (cuando un producto tiene `base_price` en USD
  y se vende en VES): actualmente el POS requiere seleccionar manualmente la rate
  o usar la ultima activa. Un servicio automatico de conversion esta deferred.

## Commits relacionados

| Commit | Descripcion |
|---|---|
| (frontend) feat(currency): pagina de gestion + Sidebar + inline create + tests |  |

## Verificacion

| Check | Resultado |
|---|---|
| `pnpm typecheck` | OK |
| `pnpm lint` | OK |
| `pnpm test` | 104/104 OK (incluye 9 nuevos para ExchangeRate schemas) |
| `pnpm build` | OK |