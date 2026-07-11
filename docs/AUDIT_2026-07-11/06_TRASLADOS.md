# Auditoría Traslados Logísticos — 2026-07-11

**Score: 7.0 / 10**

| Aspecto | Puntaje | Comentario |
|---|---|---|
| Transacciones y locks | 8 | `lockForUpdate` consistente en transfers y balances |
| Multi-tenancy | 8 | BelongsToTenant + Policy + tests cross-tenant |
| State machine rigor | **5** | **Gaps serios**: estados fantasma; sin enum; sin tabla de transiciones explícita |
| Stock + IMEI lifecycle | 7 | Funciona pero tiene fugas en edge cases |
| Auditoría | 7 | Cubre happy paths; no audita cambios de items individuales |
| Concurrencia / idempotencia | **6** | Sin idempotency keys; race en `nextSequence` |

---

## 1. Lo confirmado

### 1.1 Aislamiento multi-tenant (rigor probado)
- Tests: `test_standard_api_index_does_not_leak_other_tenant_transfers`, `test_standard_api_prepare_rejects_cross_tenant_user`, `test_standard_api_cancel_rejects_cross_tenant_user`.
- `BelongsToTenant` en todos los models.
- `InventoryTransferPolicy::ownsResource()` compara `tenant_id`.
- Composite FKs con `tenant_id` en migrations.

### 1.2 Transacciones y locks
- Todas las mutaciones (`prepare`, `dispatch`, `receive`, `cancel`, `resolveDifferences`, `create`) en `DB::transaction()` + `lockForUpdate()`.

### 1.3 Stock movements en orden correcto (logistics mode)
- `reserve` → `dispatchReservedTransfer` (transfer_out) → `receiveTransfer` (transfer_in).
- Movement types diferenciados: `reserved`, `transfer_out`, `transfer_in`, `released`, `adjustment_out`.

### 1.4 Validaciones de IMEI en dispatch/receive
- `validatePreparedProductUnits()` valida que IMEIs existan, pertenezcan al producto/almacén origen, status `AVAILABLE`.
- `validateReceivedProductUnits()` valida que IMEIs received estén en `RESERVED` y pertenezcan al transfer.

### 1.5 Doble cuenta + IMEI lifecycle consistente en receive
- `moveProductUnits()` al recibir cambia `warehouse_id`, status a `AVAILABLE`, setea `acquired_stock_movement_id`.

### 1.6 Cancelación con rollback correcto
- Solo cancela desde `requested|prepared|prepared_with_differences`.
- Si está preparado, llama `release()` por item (libera reserved) y restaura `STATUS_AVAILABLE` en IMEIs.

### 1.7 Resolution de diferencias
- 4 acciones explícitas en `InventoryTransferItem`: `unresolved`, `investigating`, `accepted_loss`, `returned_to_origin`, `adjusted_manually`.
- Cálculo correcto del `resolution_status` agregado.

### 1.8 Sync outbox emitido en cada movimiento de stock + cambios de unidad
- `syncCatalog->stockMovementCreated()` invocado en cada mutación.
- `syncCatalog->productUnitUpdated()` en cada cambio de IMEI.

### 1.9 Cross-tenant + inter-company requests
- `InventoryTransferRequestController::index()` filtra por `origin_tenant_id` o `destination_tenant_id`.
- `InventoryTransferRequestPolicy::accept()` valida que user esté en tenant DESTINO.

### 1.10 Audit logs consistentes
- Cada transición emite `inventory_transfer.{created|prepared|dispatched|received|cancelled|differences_resolved}` con `oldValues` y `newValues`.

### 1.11 Document numbering con unique constraints
- `unique(['tenant_id', 'sequence'])`, `unique(['tenant_id', 'document_number'])`, `unique(['tenant_id', 'guide_number'])`.

---

## 2. Issues CRITICAL

### C1. Estados `in_preparation`, `in_reception`, `rejected` son FANTASMA
- **Archivo:** `InventoryTransfer.php:62,66,69` (constantes), `:74,78,81` (ALL_STATUSES).
- Búsqueda exhaustiva: ningún `update(['status' => STATUS_IN_PREPARATION])` ni similar.
- Comportamiento real: `prepare()` salta `requested → prepared|prepared_with_differences`. `receive()` salta `dispatched → completed|completed_with_differences`. Ningún endpoint lleva transfer a `STATUS_REJECTED`.
- **Impacto:** `AdminTransferService::availableActionsFor()` sugiere acciones para estados inalcanzables → botones dead en UI. Discrepancia docs↔código.

### C2. Race condition en `nextSequence()`
- **Archivo:** `InventoryTransferService.php:1250-1258`
- `lockForUpdate()` con `WHERE ... ORDER BY sequence DESC LIMIT 1` solo lockea fila del último sequence.
- Two concurrent POSTs cuando table tiene `sequence=100`: ambos leen 100, calculan 101, segundo INSERT viola UNIQUE → 500.

### C3. `reserve_on_request` declarado pero no implementado
- **Archivo:** `TenantTransferSetting.php:14` declara `reserve_on_request` boolean.
- Realidad: `TenantTransferSetting` se busca en 0 archivos. Reserva nunca ocurre en `create()` para logistics.

### C4. `validateReceivedProductUnits` no valida IMEI count vs received_quantity
- **Archivo:** `InventoryTransferService.php:1179-1226`
- Producto serializado, receive con `received_unit_ids = []` y `received_quantity = 3` → pasa silencioso. Crea `transfer_in` por 3 unidades pero ninguna `product_unit` nueva.
- **Impacto:** Inconsistencia stock balance vs product_units en producto serializado.

---

## 3. Issues HIGH

### H1. `dispatch()` permite despachar con TODOS los items en `prepared_quantity = 0`
- **Archivo:** `InventoryTransferService.php:318-404`
- `if ($preparedQuantity <= 0) continue;` sale del loop sin crear movements.
- Transfer pasa a `dispatched` con 0 unidades → estado huérfano.

### H2. `prepare()` permite preparar `prepared_quantity = 0` en todos los items
- **Archivo:** `InventoryTransferService.php:235-267`
- Update con `prepared_quantity=0` se ejecuta, transfer va a `prepared_with_differences` sin stock reservado.

### H3. `inventory_transfers` referencia `tenant_transfer_settings` pero lógica de fallback no existe
- `validation_mode` se pide per-request con default `'simple'`. `TenantTransferSetting.validation_mode` no se consulta nunca.

### H4. Documentación menciona transiciones que NO existen
- Plan: 10 estados. Realidad: 7 estados. 3 fantasma.

### H5. Resolución parcial no tiene transición de salida
- Si hay items con `investigating`, transfer queda en `resolution_status=partial` y `status=completed_with_differences`.
- Llamar `resolveDifferences()` otra vez falla con "no tiene diferencias pendientes".

---

## 4. Issues MEDIUM

### M1. Sin idempotency keys en endpoints
- `POST /cancel` con prepared: dos veces libera el reservado DOS veces → stock leak.

### M2. Auditoría no captura qué items cambiaron
- `audit->record` recibe summary, NO los items modificados uno por uno.

### M3. `cancelled_by` no se limpia cuando transfer se cancela dos veces
- Segunda cancelación deja `cancelled_at`/`cancelled_by` actualizados.

### M4. El campo `quantity` en `inventory_transfer_items` es redundante con `requested_quantity`

### M5. IMEI con `STATUS_REMOVED` vía `accept_loss` no emite audit log del unit

### M6. `dispatchReservedTransfer` valida `quantity > 0` pero `dispatch()` itera con `if ($preparedQuantity <= 0) continue`

### M7. Test coverage gap: no hay test que valide doble dispatch concurrente

### M8. `Guide.status` no tiene estado `cancelled` — cuando se cancela un transfer en logistics, guide queda huérfano

---

## 5. Propuestas

### P1. Crear enum `TransferState` con tabla de transiciones [S]
```php
enum InventoryTransferState: string {
    case REQUESTED = 'requested';
    case PREPARED = 'prepared';
    // ...
    public function canTransitionTo(self $to): bool { /* whitelist */ }
}
```

### P2. Implementar `nextSequence()` con Postgres advisory lock [S]
```php
DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["transfer_seq_{$tenantId}"]);
return ((int) InventoryTransfer::query()->max('sequence')) + 1;
```

### P3. Idempotency middleware global [M]
Tabla `idempotency_keys (key, user_id, route, payload_hash, response_status, response_body, created_at)` con índice único y TTL 24h.

### P4. Eliminar estados fantasma o implementarlos [M]
Recomiendo Opción B (borrar) por simplicidad.

### P5. Implementar `reserve_on_request` desde `TenantTransferSetting` [M]

### P6. Agregar validación de IMEI count vs quantity en receive [XS]
```php
if ($product->requiresSerializedTracking() && count($receivedUnitIds) !== (int) $receivedQuantity) {
    throw ValidationException::withMessages(...);
}
```

### P7. Cerrar guía en cancel + agregar guía `cancelled` [S]
- En `cancel()`: `$transfer->guide?->update(['status' => InventoryTransferGuide::STATUS_CANCELLED])`.

### P8. Tabla de auditoría por item [S]
- Emitir `audit->record('inventory_transfer_item.difference_resolved', $item, ...)`.

### P9. Test de doble dispatch concurrente [M]

### P10. Filtros faltantes en `inventory_transfers` index [XS]
- `GET /api/inventory-transfers` no acepta `warehouse_id`, `date_from`, `date_to`, `search`.

---

## 6. Tests recomendados (cubren gaps)

1. `test_concurrent_create_does_not_collide_on_sequence`
2. `test_double_cancel_only_releases_reserved_once`
3. `test_double_dispatch_only_creates_one_transfer_out`
4. `test_resolve_differences_can_change_investigating_to_close_action`
5. `test_prepare_with_zero_prepared_quantity_in_all_items_is_rejected`
6. `test_dispatch_with_zero_prepared_quantity_is_rejected`
7. `test_cancelled_transfer_guide_status_becomes_cancelled`
8. `test_serialized_receive_with_zero_received_unit_ids_rejected`
9. `test_partial_resolution_can_be_completed`
10. `test_audit_log_records_individual_item_resolution`

---

## 7. Index recommendations

```sql
CREATE INDEX inv_transfer_items_resolution_idx ON inventory_transfer_items (tenant_id, inventory_transfer_id, resolution_status);
CREATE INDEX inv_transfer_items_difference_idx ON inventory_transfer_items (tenant_id, inventory_transfer_id, difference_quantity);
CREATE INDEX inv_transfers_processed_at_idx ON inventory_transfers (tenant_id, processed_at);
CREATE INDEX inv_transfers_dispatched_at_idx ON inventory_transfers (tenant_id, dispatched_at);
CREATE INDEX product_units_status_idx ON product_units (tenant_id, status);
CREATE INDEX inv_transfer_requests_origin_status_idx ON inventory_transfer_requests (origin_tenant_id, status, requested_at);
```
