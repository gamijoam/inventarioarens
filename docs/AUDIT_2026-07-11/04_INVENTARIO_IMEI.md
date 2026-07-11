# Auditoría Inventario + IMEI — 2026-07-11

**Score: 6 / 10**
**Estado:** Atómico + lock + composite FK bien aplicados. Faltan reservation TTL, reconciliación ledger↔balance, índices fecha, tracking_type enforcement.

---

## 1. Lo confirmado

| # | Qué | Dónde |
|---|---|---|
| 1 | Atomicidad: `StockMovement::create` + `$balance->save()` en `DB::transaction` | `InventoryMovementService.php:138, 162, 186, 232, 257, 290, 311` |
| 2 | Pessimistic locking en `stock_balances` antes de read-modify-write | `InventoryMovementService.php:371` (`->lockForUpdate()` en `balanceFor()`) |
| 3 | Composite unique `stock_balances(tenant_id, warehouse_id, product_id)` | migration `2026_07_02_183000_create_stock_balances_table.php:21` |
| 4 | Composite unique `stock_movements(tenant_id, id)` | migration `2026_07_02_191500_add_tenant_unique_to_stock_movements_table.php:11-12` |
| 5 | Composite unique `product_units(tenant_id, serial_type, serial_number)` | migration `2026_07_02_192000_create_product_units_table.php:23` |
| 6 | Triple-layered tenant isolation | `BelongsToTenant.php:14` global scope + DB composite FKs + `InventoryMovementService.php:391-398` runtime assert |
| 7 | IMEI status state machine existe en constants | `ProductUnit.php:28-33` |
| 8 | Sold/reserved IMEI no re-sold / double-reserved under documented happy-path | `SaleService.php:225` + `PosCheckoutService.php:670, 697` |
| 9 | Cross-tenant reference rejected at app layer antes de write | `InventoryMovementService.php:391-398` |
| 10 | Insufficient-stock guard para `available` y `reserved` buckets | `InventoryMovementService.php:407-412` |
| 11 | Composite FK cross-tenant guards at DB level | migrations + `InventorySchemaIsolationTest.php:101-118` |
| 12 | Permissions gating on direct inventory endpoints | `AuthorizedInventoryMovementService.php:23, 33, 43, 53, 63, 73, 83, 95` |
| 13 | `warehouse_id != to_warehouse_id` validation | `InventoryTransferRequest.php:29` (`'different:from_warehouse_id'`) |

---

## 2. Issues CRITICAL

### C1. Sin TTL/expiry para `reserved` stock movements
- **Archivo:** `StockMovement.php` no tiene `expires_at` column. No hay Artisan command o Scheduled task que expire stale reservations.
- **Impacto:** Permanent stock leak para serialized products (IMEI stuck `reserved`). Under-utilization de available stock para quantity products.

### C2. `StockMovement` NO es append-only en práctica
- AGENTS.md §5 dice "append-only" pero todas las FKs son `cascadeOnDelete` → deleting a `Product` o `Warehouse` destruye todo el ledger history.
- **Archivo:** migration `2026_07_02_182000_create_stock_movements_table.php:28-35`
- **Impacto:** Auditable financial history puede ser silently wiped por un product/warehouse delete. Tampering via raw SQL undetected.

### C3. `tracking_type` NO es enforced por `InventoryMovementService`
- Producto serializado puede venderse/ajustarse/transferirse por quantity a través de `/api/inventory/*` endpoints.
- Tracking-type validation solo vive en higher-level services (`PosCheckoutService.php:623`, `SaleService.php:176`, etc.).
- **Impacto:** User con `inventory.sale-operation` y sin POS access puede vender un producto serializado sin IMEIs.

### C4. Sin reconciliación entre `stock_balances` y `stock_movements`
- Aggregate se escribe alongside movement, nunca re-computed from ledger.
- Sin Artisan command, Scheduled task, o unit test que recompute balances.
- **Archivo:** `tools/audit_create_test_transfer.php:49` usa raw `updateOrInsert` en `stock_balances` fuera del service (drift vector).
- **Impacto:** Drift entre ledger (truth) y balance (cache) es permanente y undetectable.

---

## 3. Issues HIGH

### H1. Sin idempotencia en stock endpoints
- Double-submit del mismo request crea 2 `StockMovement` rows y decrementa `quantity_available` twice.
- No `Idempotency-Key` header ni unique reference check en `InventoryMovementRequest`/`InventoryTransferRequest`.

### H2. Sin DB CHECK constraint en `quantity_available/reserved/damaged >= 0`
- Negative stock prevention es puramente app-layer.
- Bypass via raw SQL (seeder, sync hand-fix) puede producir balances negativos sin DB error.

### H3. `StockMovement.type` es `string`, no enum/CHECK
- Typo puede ser insertado; PHP constant gates validation pero no enforced at insert.

### H4. TOCTOU race cuando auto-crea `StockBalance`
- `balanceFor()` line 366-382 hace SELECT FOR UPDATE pero si no existe row, cae a `StockBalance::create()` sin lock.
- Dos concurrent first-time sales para mismo `(tenant, warehouse, product)` ambas hit unique constraint.

---

## 4. Issues MEDIUM

- **M1:** `product_units.status` transitions no validados por state machine.
- **M2:** Sin lock en `products` row cuando `tracking_type` mutated.
- **M3:** `LOWER(product_units.serial_number) LIKE ?` no usa unique index.
- **M4:** Sin CASCADE rule clarification para `released_stock_movement_id`/`acquired_stock_movement_id`.
- **M5:** `stock_movements.unit_cost` puede ser null y no constrained a currency.
- **M6:** Sin `created_at` index en `stock_movements` — date-range reports table-scan.
- **M7:** Sin standalone index en `stock_balances(tenant_id)`.
- **M8:** `InventoryTransferItem::$product_unit_ids` es non-atomic `jsonb` list.

---

## 5. Propuestas

### P1. Reservation TTL + scheduled sweeper [M]
```php
$table->timestamp('reservation_expires_at')->nullable()->after('reference_id');
$table->index(['reservation_expires_at']);
```
- `reserve(..., ?DateTimeInterface $expiresAt = null)` con default `now()->addMinutes(30)`.
- Artisan command `inventory:expire-reservations` cada 5 min.
- Cleanup: `UPDATE stock_movements SET type='released' WHERE type='reserved' AND reservation_expires_at < now()` + restore IMEIs.

### P2. Append-only enforcement [S]
- Add `static::updating` and `static::deleting` listeners en `StockMovement` que throw.
- Cambiar FKs a `nullOnDelete`, mover deletes (product/HW) a un service que emite compensating `adjustment_out` movements.

### P3. tracking_type enforcement at service boundary [S]
Add `assertTrackingCompatible(Product $product, array $serialUnitIds)` en `InventoryMovementService::validateOperation`.

### P4. DB CHECK constraints + ENUM-cast for type [S]
```sql
ALTER TABLE stock_movements
  ADD CONSTRAINT stock_movements_type_check CHECK (type IN (...)),
  ADD CONSTRAINT stock_movements_quantity_positive CHECK (quantity > 0);
ALTER TABLE stock_balances
  ADD CONSTRAINT stock_balances_non_negative
  CHECK (quantity_available >= 0 AND quantity_reserved >= 0 AND quantity_damaged >= 0);
```

### P5. Reconciliation command [M]
- `php artisan inventory:reconcile {tenant?}` suma `stock_movements` por type y compara a `stock_balances`. Logs drift; `--fix` para overwrite.
- Schedule nightly.

### P6. Idempotency-Key support [M]
Accept `Idempotency-Key` header on `/api/inventory/*`. Store en `stock_movements.idempotency_key`, UNIQUE per `(tenant_id, idempotency_key)`. Replay returns cached response.

### P7. Index additions [XS]

```sql
-- Date range reports
CREATE INDEX stock_movements_tenant_created_at_idx ON stock_movements (tenant_id, created_at);
CREATE INDEX stock_movements_tenant_warehouse_created_at_idx ON stock_movements (tenant_id, warehouse_id, created_at);
CREATE INDEX stock_movements_tenant_product_created_at_idx ON stock_movements (tenant_id, product_id, created_at);
CREATE INDEX stock_movements_tenant_type_created_at_idx ON stock_movements (tenant_id, type, created_at);

-- Kardex optimization
CREATE INDEX stock_balances_tenant_warehouse_idx ON stock_balances (tenant_id, warehouse_id);

-- IMEI search by lowercased serial
CREATE INDEX product_units_serial_number_lower_idx ON product_units (tenant_id, LOWER(serial_number));

-- Reservation sweeper
CREATE INDEX stock_movements_reservation_expiry_idx ON stock_movements (reservation_expires_at) WHERE type = 'reserved';
```

### P8. IMEI search functional index [XS]
```sql
CREATE INDEX product_units_serial_number_lower_idx ON product_units (tenant_id, LOWER(serial_number));
```

### P9. StockBalance upsert en `balanceFor` [XS]
```php
return StockBalance::query()->updateOrCreate(
    ['tenant_id' => $warehouse->tenant_id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id],
    []
)->lockForUpdate()->first() ?? throw new \RuntimeException('Balance create failure');
```

### P10. Mover `product_unit_ids` a join table [L]
Replace `sale_items.product_unit_ids jsonb` con `sale_item_product_unit(sale_item_id, product_unit_id, role)`.

---

## 6. Edge cases no cubiertos

| # | Edge case | Impacto |
|---|---|---|
| E1 | Network retry de `POST /api/pos/checkouts` con mismo payload | Duplicate sale, double stock decrement, double cash-register movement |
| E2 | POS pending order abandoned at WPF client (crash, power loss) | Unit locked forever, balance held forever |
| E3 | First-sale-of-product race | Two POS terminals scan SKU con zero stock y race para crear primer `StockBalance` row |
| E4 | Reverse sync: remote applies `stock_movement` event antes de su `stock_balance` aggregate | Cloud stock balance permanentemente en 0 |
| E5 | Soft-deleted Product where `is_active=false` pero old `StockBalance` rows remain | Dashboard continúa contando el SKU |
| E6 | Receiving transfer con fewer units than dispatched | Difference handling podría ser confuso |
| E7 | Sync initial snapshot marking movement con `reference_type='sync_snapshot'` | Podría colisionar con manual movement con same business key |
| E8 | Product unit re-import via snapshot | `applyProductUnit` resetea `acquired_stock_movement_id` a null, erasing provenance |
| E9 | Cross-tenant ledger consistency | Cross-tenant visibility de movements via request item IDs |
| E10 | Decimal rounding edge | Quantities son `decimal(18,4)`, multiplicación OK |
| E11 | Sold IMEI replacement via warranty | No audit row emitted on original `ProductUnit` after replacement completes |
| E12 | Dual-write race entre local y cloud | Outbox pattern protege individual events pero no cross-event balance consistency |

---

## 7. Test gaps

| # | Gap | Riesgo |
|---|---|---|
| T1 | First-sale race condition | Customer-facing 500 error |
| T2 | Idempotency: same POST called twice | Double-decrement not caught |
| T3 | Cross-tenant `product_unit` re-use | Cross-tenant data leakage risk |
| T4 | `tracking_type` enforcement en raw `/api/inventory/*` endpoint | C3 |
| T5 | Reconciliation test | C4 |
| T6 | DB CHECK constraint test | H2 |
| T7 | sale_return → reserve flow | Confirm return restores available |
| T8 | IMEI lifecycle state-machine test | M1 |
| T9 | Concurrent sale test (2 POS terminals selling last unit) | Race regression detector |
| T10 | Expired-reservation test | C1 (después de P1) |
| T11 | cascadeOnDelete en `stock_movements` | C2 |
| T12 | cascadeOnDelete en `stock_balances` | C2 |
| T13 | Cross-tenant Warehouse deletion impact | FK isolation test |
| T14 | product_unit_ids jsonb orphans | M8 |
| T15 | Performance test Kardex contra 10k+ movements | M6 |
| T16 | Sync test para stock balance drift | E4 |
| T17 | InventoryTransferRequest::accept cross-tenant path con mismatched products | Existing partial coverage |
