# INVENTORY_MODULE_DEFERRED

> Items que afectan la UX del modulo de inventario pero NO son del modulo de inventario.
> Estan anotados aqui para que se implementen en su modulo correspondiente.
> NO son bloqueantes para dar por pulido el modulo de inventario actual.

## Estado del modulo de inventario (frontend)

| Pantalla | URL | Estado |
|---|---|---|
| Listado de productos | `/inventory` | Funcional |
| Crear producto | boton "+ Nuevo producto" | Funcional |
| Detalle de producto | `/inventory/$productId` | Funcional |
| Edicion de producto | dialog desde detalle | Funcional (fix reciente del loop infinito) |
| Kardex | tab "Kardex" en detalle | Funcional (fix reciente del schema) |
| Auditoria | tab "Auditoria" en detalle | Funcional (fix reciente del schema) |
| Precios por lista | tab "Precios" en detalle | Funcional (fix reciente del token + schema) |
| Catalogos (marcas/categorias/tags) | `/inventory/catalogs` | Funcional (submenu en Sidebar + inline create desde ProductForm) |
| Tipos de tasa y rates historicas | `/inventory/currency` | **Funcional** (submenu Sidebar + inline create + 9 tests) |
| Catalogos administrativos (sucursales, almacenes, garantias, listas de precios) | `/inventory/admin` | **Funcional** (submenu Sidebar + 12 tests + 3 inline creates) |
| Bulk actions | menu contextual en listado | Funcional (al menos los dialogs) |
| Exportar CSV | boton "Exportar CSV" en listado | Funcional |

## Pendiente DENTRO del modulo de inventario (pulir)

- [ ] Submenu de "Catalogos" en el Sidebar con link a `/inventory/catalogs`.
  Esto lo arreglo en este commit para que el user pueda acceder a la pagina.
- [ ] Notar que para crear un producto con marca/categoria/tag/warranty, el user
  debe ir primero a `/inventory/catalogs` y crearlos alli. No hay UI
  inline para crearlos desde el dialog de producto. Esto queda deferred a
  "inline create" en una iteracion futura.
- [ ] Edge case: si el user quiere asignar un `sale_exchange_rate_type` o
  `warranty_policy`, esas listas se cargan via lookups. Si no existen
  registros, los dropdowns aparecen vacios. El user debe crearlos desde
  el modulo correspondiente (Currency, Warranties). Ver seccion "Fuera del
  inventario" abajo.

## Pendiente FUERA del modulo de inventario (deferred a otros modulos)

Estos items los maneja el modulo que corresponda. NO son parte del modulo
de inventario, aunque el user los descubra intentando crear un producto.

### Modulo Currency (no implementado en frontend)

- ~~**Exchange rate types** (BCV, Paralelo, etc.)~~ **HECHO** 2026-07-14: ver `docs/CURRENCY_MODULE.md`.

### Modulo Warranties (no implementado en frontend)

- ~~**Warranty policies** (cobertura, duracion)~~ **HECHO** 2026-07-15: integrado en `/inventory/admin` tab "Garantias" + inline create desde ProductForm.

### Modulo Products / PriceList (no implementado en frontend)

- ~~**Price lists** (AL MAYOR, Detal, etc.)~~ **HECHO** 2026-07-15: integrado en `/inventory/admin` tab "Listas de precios" + inline create desde PricesEditor.

### Modulo Warehouses (no implementado en frontend)

- ~~**Warehouses** (Almacenes)~~ **HECHO** 2026-07-15: integrado en `/inventory/admin` tab "Almacenes" + Branches como prerequisito.

### Modulo Branches (no implementado en frontend)

- ~~**Branches** (Sucursales)~~ **HECHO** 2026-07-15: integrado en `/inventory/admin` tab "Sucursales" + inline create desde Warehouses.

### Warehouse Locations (jerarquico, deferred)

- Backend completo en `app/Modules/Warehouses/Controllers/WarehouseLocationController.php`
  (CRUD de ubicaciones dentro de almacenes: pasillo, estante, nivel). El frontend
  no tiene UI todavia.
  - Pendiente: agregar a `/inventory/admin` un 5to tab "Ubicaciones" o
    gestion dentro del detalle de un almacen (modal).
  - Backend: `GET /api/warehouses/{warehouse}/locations` ya existe.

### Tenant Switcher (en Topbar)

- El menu "Cambiar empresa" del Topbar dice "Proximamente: selector completo".
  - Pendiente: implementar selector de tenants del user con switch-tenant API.
  - Backend: `POST /api/auth/switch-tenant` ya existe.

## Cambios aplicados en este commit

- Sidebar: agregar submenu "Catalogos" con link a `/inventory/catalogs`.
- Acentos faltantes en `ProductRelations.tsx` (HTML entities + "Categorias", "Garantia", "dias").
- Otros acentos en el modulo de inventario segun se encuentren.

## Verificacion

- `pnpm test`: 95/95 OK
- `pnpm build`: OK
- Hard refresh: `/inventory/catalogs` debe ser accesible desde el Sidebar.