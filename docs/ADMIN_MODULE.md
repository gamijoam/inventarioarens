# ADMIN_MODULE

> Catalogos administrativos de empresa: sucursales, almacenes, politicas de garantia y listas de precios.
> Estado: 2026-07-15. Frontend completo y funcional.

## Vision general

Pagina unica `/inventory/admin` con 4 Tabs que centraliza la configuracion administrativa de la
empresa. Estos catalogos NO son del producto en si (como marca/categoria/tag que viven en
`/inventory/catalogs`) sino de la estructura de la empresa: donde opera fisicamente, como cubre
garantias, y como segmenta precios.

**Por que una sola pagina?** Ya teniamos `/inventory/catalogs` con Marcas/Categorias/Tags.
Agregar 4 Tabs mas a esa pagina la dejaria con 7 tabs. Preferimos separar en dos paginas
claras: catalogos de producto (catalogs) vs configuracion de empresa (admin).

## Backend

### Endpoints

| Metodo | Ruta | Permiso | Descripcion |
|---|---|---|---|
| GET/POST/PATCH/DELETE | `/api/branches` | `branches.view` / `branches.manage` | CRUD de sucursales |
| GET/POST/PATCH/DELETE | `/api/warehouses` | `warehouses.view` / `warehouses.manage` | CRUD de almacenes (requiere `branch_id`) |
| GET/POST/PATCH/DELETE | `/api/warranty-policies` | `warranty_policies.view` / `warranty_policies.manage` | CRUD de politicas de garantia |
| GET/POST/PATCH/DELETE | `/api/price-lists` | `products.view` / `products.update` | CRUD de listas de precios (backend usa permisos de Products, no de price_lists) |

### Modelos

- `Branch` (fillable: name, code, status). Tabla `branches`. Tenant-scoped.
- `Warehouse` (fillable: branch_id, name, code, status). Tabla `warehouses`. Tenant-scoped.
- `WarrantyPolicy` (fillable: name, duration_days, coverage_type, conditions, is_active).
  Tabla `warranty_policies`. Tenant-scoped. Coverage: store | manufacturer | none.
- `PriceList` (fillable: name, code, description, is_default, is_active, sort_order).
  Tabla `price_lists`. Tenant-scoped. Sync via `SyncCatalogOutboxService`.

### Permisos

`app/Support/Permissions/BasePermissions.php`:

- `branches.view/manage`, `warehouses.view/manage`
- `warranty_policies.view/manage`
- Para price lists el backend reusa `products.view` y `products.update` (PriceListController
  hace `abort_unless($request->user()->can('products.view'))` y `Gate::authorize('update')`
  via `ProductPolicy`).

Asignaciones por rol (en `BasePermissions::ROLE_PERMISSIONS`):
- **Owner, Administrador**: todos los manage.
- **Gerente**: branches.manage, warehouses.manage, warranty_policies.manage + products.update
  (para price lists).
- **Vendedor, Almacen, Auditor**: solo view (excepto Almacen que no tiene ni siquiera
  branches/warehouses.manage por ahora; lo tiene por `warehouses.view`/`branches.view`).

**Importante**: el frontend DEBE usar `WARRANTY_POLICIES_MANAGE` (no `_CREATE` ni `_UPDATE`)
para politicas de garantia, porque el backend unifica create/update/destroy bajo
`warranty_policies.manage`. Esto esta documentado en el AGENTS.md y en
`frontend/src/permissions/constants.ts`.

## Frontend

### Estructura

- `src/routes/_authed/inventory/admin.tsx`: pagina con Tabs.
- `src/components/layout/Sidebar.tsx`: item "Administracion" en el submenu de Inventario (icono
  `Settings`, permiso `PRODUCTS_VIEW`).
- `src/features/inventory-center/catalogs/`:
  - `BranchesManager.tsx` (CRUD basico).
  - `WarehousesManager.tsx` (CRUD con select de sucursal + inline create de sucursal).
  - `WarrantyPoliciesManager.tsx` (CRUD con select de tipo de cobertura).
  - `PriceListsManager.tsx` (CRUD basico con switches default/active).
- `src/features/inventory-center/components/`:
  - `InlineWarrantyPolicyCreate.tsx` (dialog mini para crear desde el ProductForm).
  - `InlinePriceListCreate.tsx` (dialog mini para crear desde el PricesEditor).
- `src/features/inventory-center/api.ts`: hooks
  - Queries: `useBranches`, `useWarehouses`, `useWarrantyPolicies`, `usePriceLists`.
  - Mutations: `useCreateBranch`/`Update`/`Delete`, idem para Warehouse, WarrantyPolicy, PriceList.
- `src/features/inventory-center/schemas.ts`:
  - `BranchSchema`, `WarehouseSchema`, `WarrantyPolicySchema`, `PriceListSchema` (lectura).
  - `StoreBranchSchema`, `StoreWarehouseSchema`, `StoreWarrantyPolicySchema`,
    `StorePriceListSchema` (formularios, con transform uppercase/trim).
  - `WARRANTY_COVERAGE_TYPES` y `WARRANTY_COVERAGE_LABELS` (constantes).
- `src/features/inventory-center/queries.ts`: `catalogKeys.branches()` agregado.
- `src/permissions/constants.ts`: `WARRANTY_POLICIES_MANAGE` agregado.
- `src/features/inventory-center/components/ProductForm.tsx`: integrado
  `InlineWarrantyPolicyCreate` al dropdown de "Politica de garantia".
- `src/features/inventory-center/components/PricesEditor.tsx`: cuando no hay listas, muestra
  `InlinePriceListCreate` en el empty state.

### UX

- Pagina con 4 Tabs: Sucursales | Almacenes | Garantias | Listas de precios.
- Iconos: Building2 (sucursales), Warehouse, ShieldCheck, ListOrdered.
- Patron consistente: tabla + boton "+ Nuevo X" + dialog de form + ConfirmDialog para eliminar.
- Form de Warehouses: select de sucursal + boton "+ Sucursal" inline (al lado del dropdown)
  que abre un mini-form para crear la sucursal sin salir del dialog. Si se confirma, el form
  padre se auto-selecciona la nueva sucursal.
- Form de Warranty Policies: select con los 3 tipos de cobertura (`store` = Tienda,
  `manufacturer` = Fabricante, `none` = Sin garantia).
- Form de Price Lists: switches para `is_default` y `is_active` lado a lado.

### Inline creates

- En `ProductForm.tsx` (al lado del dropdown "Politica de garantia"): boton "+ Nueva politica"
  que abre `InlineWarrantyPolicyCreate`. Al confirmar, el form padre se auto-selecciona.
- En `PricesEditor.tsx` (empty state): si no hay listas de precios configuradas, muestra
  `InlinePriceListCreate` con texto motivador en vez de un mensaje vacio frio.
- En `WarehousesManager.tsx` (dentro del form dialog): el "+ Sucursal" inline resuelve el
  chicken-and-egg de "no hay sucursales para crear almacenes".

## Verificacion

| Check | Resultado |
|---|---|
| `pnpm typecheck` | OK |
| `pnpm lint` | OK |
| `pnpm test` | **116/116** OK (12 nuevos para los 4 schemas: branch x3, warehouse x2, warranty x4, price list x3) |
| `pnpm build` | OK |

## Como probarlo en el local

1. **Hard refresh** del navegador.
2. Login con `gabo@gabo.com` / `gabo1234` / `mi-empresa`.
3. En el Sidebar, submenu "Inventario" ahora tiene un nuevo item "Administracion" (icono engranaje).
4. Click "Administracion" → vas a `/inventory/admin`.
5. **Tab Sucursales**: crear 1 sucursal (ej: "Centro", code "CENTRO").
6. **Tab Almacenes**: crear 1 almacen. Si no hay sucursales, el dropdown estara vacio;
   el boton "+ Sucursal" inline al lado permite crear una al vuelo.
7. **Tab Garantias**: crear 1 politica (ej: "Garantia 30 dias", duracion 30, cobertura Tienda).
8. **Tab Listas de precios**: crear 1 lista (ej: "Detal", code "RETAIL").
9. Volver a Productos → Crear producto:
   - En "Politica de garantia" hay boton "+ Nueva politica" al lado.
   - Tab Precios: si no hay listas, el empty state muestra el inline create.

## Pendientes (fuera de este modulo)

En `docs/INVENTORY_MODULE_DEFERRED.md` queda:
- **Tenant switcher** en el Topbar (selector de empresas del user).
- **Warehouses jerarquicos**: `warehouse_locations` (tabla) tiene backend pero no hay UI de gestion.

## Commits relacionados

| Commit | Descripcion |
|---|---|
| (frontend) feat(admin): pagina de catalogos administrativos |  |
