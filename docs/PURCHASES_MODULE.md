# PURCHASES_MODULE

> Modulo de Compras (PurchaseOrders) — incluye el flujo de recepcion de
> mercancia con impacto en stock, WAC y CxP.
> Estado: 2026-07-15. Backend completo (FASE 0). UI en FASE 1+.

## Vision general

El modulo de Compras modela el ciclo completo de adquisicion de mercancia
a un proveedor: desde el **borrador** (con snapshot de tasa de cambio) hasta
la **recepcion** (que afecta stock, WAC y CxP) y la posterior **cancelacion**
(solo en estado `draft`).

A diferencia de `ProductEntry` (entradas manuales de stock sin proveedor),
las compras SI tienen `supplier_id` y SI generan automaticamente una cuenta
por pagar (CxP) al recibir la mercancia.

## Backend (FASE 0 ya implementada)

### Modelos

- `PurchaseOrder` (fillable: supplier_id, status, document_number,
  issued_at, due_date, purchase_currency, exchange_rate_type_id,
  exchange_rate_type_code, exchange_rate, total_base_amount,
  total_local_amount, received_base_amount, received_local_amount,
  created_by, received_at, cancelled_at).
- `PurchaseItem` (fillable: purchase_order_id, warehouse_id, product_id,
  quantity, received_quantity, unit_cost, total_cost, base_unit_cost,
  base_total_cost, serial_units, stock_movement_id).

### Estados de una compra

| Status | Significado | Que pasa |
|---|---|---|
| `draft` | Borrador inicial | NO afecta stock. Solo se puede recibir o cancelar. |
| `partially_received` | Recepcion parcial | Stock + CxP parciales. Se puede recibir mas. |
| `received` | Totalmente recibida | Stock + CxP completos. `received_at` poblado. |
| `cancelled` | Cancelada | Solo desde `draft`. `cancelled_at` poblado. |

### Endpoints

`app/Modules/Purchases/routes.php` (todos bajo `['api.auth', 'tenant']`):

| Metodo | Ruta | Permiso | Descripcion |
|---|---|---|---|
| GET | `/api/purchases` | `purchases.view` | Lista paginada con filtros: `search`, `status`, `supplier_id`, `date_from`, `date_to`, `limit` |
| POST | `/api/purchases` | `purchases.create` | Crea el **borrador** (no afecta stock) |
| GET | `/api/purchases/{id}` | `purchases.view` | Detalle completo con supplier, items, stock_movement |
| PATCH | `/api/purchases/{id}/receive` | **`purchases.approve`** | Recibe mercancia (parcial o total) |
| PATCH | `/api/purchases/{id}/cancel` | `purchases.create` | Cancela compra en estado `draft` |

> **IMPORTANTE**: el endpoint `receive` usa `purchases.approve`, NO
> `purchases.receive`. El frontend debe usar `PURCHASES_APPROVE` para
> verificar el permiso (ver `frontend/src/permissions/constants.ts`).
> `PURCHASES_RECEIVE` se mantiene como alias historico pero NO es lo que
> el backend chequea.

### Flujo de `receive` (FASE 0: WAC + Sync integrados)

`PurchaseOrderService::receive()` ejecuta:

1. `DB::transaction` + `lockForUpdate` sobre la orden.
2. Por cada item a recibir:
   - Valida cantidad pendiente y seriales.
   - `InventoryMovementService::purchase()` crea `StockMovement type='purchase'`
     + incrementa `stock_balances.quantity_available` + audit log.
   - Crea `ProductUnit` (1 fila por serial si `tracking_type='serialized'`).
   - **`InventoryValuationService::recalculate($product)` ← FASE 0**: actualiza
     `products.average_cost` con la formula WAC usando TODOS los movimientos
     historicos del producto (no solo el actual). Si el volumen crece mucho,
     mover a un Job en cola.
   - `InventoryValuationService::recalculate()` se llama **dentro** de la
     transaccion del receive, asi si el receive falla el WAC no se
     actualiza con datos parciales.
3. Recalcula `received_base_amount` y `received_local_amount`.
4. Determina nuevo status (`received` si todo, sino `partially_received`).
5. `AccountsPayableService::createForPurchase()` crea/actualiza una fila en
   `accounts_payables` con status `pending` y balance = total recibido.
6. **`SyncCatalogOutboxService::purchaseOrderReceived($po)` ← FASE 0**:
   emite evento a `sync_outbox` con los items efectivamente recibidos
   (los que tienen `stock_movement_id`). Items pendientes en una
   recepcion parcial NO se sincronizan.

### Sync de PurchaseOrder (FASE 0)

| Evento | Emite en local | Aplica en nube (SyncEventApplier) |
|---|---|---|
| `purchase_order.created` | `PurchaseOrderService::createDraft()` despues de crear el draft. | Crea fila en `purchase_orders` con metadata minima (no items). |
| `purchase_order.received` | `PurchaseOrderService::receive()` despues de la mutacion. | **Crea un `product_entry` en la nube** con el `document_number` del PO, replicando `product_entry_items` + `stock_movements` + `stock_balance`. Idempotente por (tenant_id, document_number). |

Esto preserva la trazabilidad de la fuente (la compra) y mantiene el stock
sincronizado entre local y nube. En la nube la compra se ve como una
`product_entry` estandar con la nota "Proveedor: X | Doc: PO-123".

`PurchaseOrderService::cancelDraft()` NO emite sync (es local-only, no
afecta stock). `REPROCESSABLE_EVENT_TYPES` en `SyncEventApplier` incluye
`purchase_order.created` y `purchase_order.received` para que un reintento
pueda reprocesarlos.

### Tests backend (FASE 0)

| Test | Verifica |
|---|---|
| `PurchaseWacRecalculationTest::test_wac_is_recalculated_after_receiving_purchase` | Que `products.average_cost` se actualiza tras una compra simple. |
| `PurchaseWacRecalculationTest::test_wac_blends_old_and_new_when_receiving_partial_purchase` | Que una 2da compra con costo distinto blend correctamente con el WAC previo. |
| `PurchaseOrderSyncTest::test_purchase_order_created_event_persists_metadata_in_cloud` | Que `applyPurchaseOrderCreated` persiste metadata basica. |
| `PurchaseOrderSyncTest::test_purchase_order_received_event_creates_product_entry_in_cloud` | Que `applyPurchaseOrderReceived` crea `product_entry` + `product_entry_item` + `stock_movement` + `stock_balance`. |
| `PurchaseOrderSyncTest::test_purchase_order_received_is_idempotent` | Que re-procesar el mismo evento NO duplica stock. |

## Permisos

`app/Support/Permissions/BasePermissions.php`:

- `purchases.view`
- `purchases.create` (incluye `cancel`)
- `purchases.approve` (para `receive`)

Asignaciones por rol (en `BasePermissions::ROLE_PERMISSIONS`):
- **Owner, Administrador**: los 3.
- **Gerente**: los 3.
- **Almacen**: `purchases.view` y `purchases.create` (NO `purchases.approve`:
  para que el almacen cree drafts pero no los reciba sin aprobacion).
- **Vendedor, Auditor**: solo `purchases.view`.

Esto modela un flujo de aprobacion de 2 pasos: el almacen crea el draft,
un gerente aprueba al recibir la mercancia. El backend refuerza esto
en `PurchaseOrderPolicy::receive()` con `purchases.approve`.

## Frontend (✅ COMPLETO: FASE 0-5)

### Estructura (FASE 1-5 entregadas)

```
frontend/src/features/purchases/
├── schemas.ts              # Zod schemas: PurchaseSchema, PurchaseItemSchema,
│                           #   StorePurchaseSchema, ReceivePurchaseSchema,
│                           #   PurchaseListFiltersSchema + constantes.
├── schemas.test.ts         # 18 tests (parsing, validaciones, defaults).
├── queries.ts              # purchaseKeys jerarquicos.
├── api.ts                  # usePurchases (filtros), usePurchase,
│                           #   useCreatePurchase, useReceivePurchase,
│                           #   useCancelPurchase, useProductsForPurchase +
│                           #   invalidateAffectedProducts (cache).
├── PurchasesManager.tsx    # Tabla + filtros + filas expandibles.
└── components/
    ├── ProductAutocomplete.tsx   # Typeahead single-select (SKU/BC/nombre).
    ├── SupplierAutocomplete.tsx  # Typeahead single-select.
    ├── ImeiListInput.tsx         # N inputs dinamicos para IMEIs/seriales.
    ├── PurchaseItemRow.tsx       # Card editable de item (sin scroll horiz).
    ├── PurchaseFormDialog.tsx    # Dialog full-width para crear draft.
    ├── ReceiveDialog.tsx         # Dialog para recibir mercancia.
    ├── ReceiveItemRow.tsx        # Fila del dialog de recepcion.
    ├── PurchaseSummary.tsx       # Card visual con Stepper + totales + progress.
    ├── PurchaseSummary.test.tsx  # 12 tests (Stepper, totales, progress, items).
    ├── QuickActionsBar.tsx       # Botones contextuales por estado.
    └── QuickActionsBar.test.tsx  # 7 tests (botones segun estado).

frontend/src/routes/_authed/purchases.tsx   # Pagina con todos los dialogs.
frontend/src/components/layout/Sidebar.tsx   # Item "Compras" (icono ShoppingBag).
```

### Variantes soportadas

| Variante | Soporte | Como |
|---|---|---|
| **A — Producto simple** | ✅ | `quantity + unit_cost` en `PurchaseItemRow`. |
| **C — Serializado (IMEI/serial)** | ✅ | `ImeiListInput` se renderiza cuando `product.tracking_type === 'serialized'`. Auto-init con N inputs vacios segun `expectedQuantity`. Validacion por regex + unicidad. |
| **B — Empaque mayor (cajas x unidades)** | ⏸️ Deferred | Requiere migracion `units_per_purchase` en `products` + logica en services. |

### Fases del frontend (cronologia)

| Fase | Commit | Descripcion |
|---|---|---|
| FASE 1 | `d623c383` | Listado + filtros + Sidebar + 18 tests de schemas. |
| FASE 2 | `316437d` | PurchaseFormDialog con captura de IMEIs (variante C). |
| FASE 3 | `422ad521` | ReceiveDialog + fix layout cards (sin scroll horiz). |
| FASE 4 | `a31b9d95` | PurchaseSummary (Stepper visual) + QuickActionsBar + filas expandibles. |
| FASE 5 | (este commit) | Tests de componentes (12+7) + documentacion completa. |

### Fixes aplicados durante el desarrollo

- `634d91bd` invalidacion de cache de productos al recibir (TanStack Query).
- `edc3a96f` columna Stock + filtro por almacen + StockTab refresca.
- `b0f0f55` alias `available_stock` con AS explicito en `withSum` (Eloquent usa `{relation}_sum_{column}` por defecto).
- `898da79f` color `text-text-primary` y `font-medium` en celda Disponible del StockTab.
- `40428b1` StockTab lee `s.available` (no `s.quantity`) + schema `ProductStockSchema` corregido al shape real del backend.

## Verificacion (FASE 0)

| Check | Resultado |
|---|---|
| `phpunit tests/Feature/Purchases/` | 10/10 OK (8 existentes + 2 WAC) |
| `phpunit tests/Feature/Sync/` | OK (incluye 3 nuevos de PurchaseOrder) |
| `phpunit tests/Feature/ProductEntries/` | OK |
| `phpunit tests/Feature/Inventory/` | OK |
| VPS pull | OK (rebase limpio) |

## Commits relacionados

| Commit | Descripcion |
|---|---|
| `01c86b5f` | feat(purchases): cablear WAC automatico + sync outbox/applier (FASE 0) |

## Pendientes (siguiente fase)

- **FASE 1**: Listado de compras en `/purchases` con tabla + filtros + Sidebar.
- **FASE 2**: Dialog para crear draft (PurchaseFormDialog) con header + items
  + totales en vivo + soporte inline create de supplier.
- **FASE 3**: ReceiveDialog con captura de IMEIs y dialog de cancelar.
- **FASE 4**: Polish (QuickActionsBar, PurchaseSummary visual, IMEI scanner).
- **FASE 5**: Tests + documentacion.
- **Variante B** (cajas/empaques): migracion + service + tests cuando se priorice.
