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
| Catalogos (marcas/categorias/tags) | `/inventory/catalogs` | Existe pero no hay link en el Sidebar. FIX EN ESTE COMMIT. |
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

- **Exchange rate types** (BCV, Paralelo, etc.): backend completo en
  `app/Modules/Currency/`. El frontend no tiene pagina para crearlos. Cuando
  el user intenta asignar `sale_exchange_rate_type_id` en un producto y no
  hay ninguno, el dropdown esta vacio.
  - Pendiente: crear `/currency/rate-types` o similar.
  - Backend: `GET /api/currency/rate-types` ya existe.

### Modulo Warranties (no implementado en frontend)

- **Warranty policies** (cobertura, duracion): backend completo en
  `app/Modules/Warranties/`. El frontend no tiene pagina para crearlas.
  - Pendiente: crear `/warranties/policies` o similar.
  - Backend: `GET /api/warranty-policies` ya existe.

### Modulo Products / PriceList (no implementado en frontend)

- **Price lists** (AL MAYOR, Detal, etc.): backend completo en
  `app/Modules/Products/Controllers/PriceListController.php`. El frontend
  no tiene pagina para crearlas. El `PricesEditor` muestra las listas
  existentes en el dropdown, pero si no hay ninguna, aparece vacio.
  - Pendiente: crear `/price-lists` o similar.
  - Backend: `GET /api/price-lists?active_only=1` ya existe.

### Modulo Warehouses (no implementado en frontend)

- **Warehouses** (Almacenes): backend completo en
  `app/Modules/Warehouses/`. El frontend solo permite seleccionar un
  warehouse_id en ciertos formularios pero no tiene pagina de gestion.
  - Pendiente: crear `/warehouses` o similar.
  - Backend: `GET /api/warehouses` ya existe.

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