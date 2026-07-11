# Auditoría Sync Engine — 2026-07-11

**Score: 6 / 10**
**Estado:** Funcional, multi-tenant, transactional outbox con dedup cloud-side y snapshot bootstrap. Falta reliability hardening, observability y security en algunos endpoints.

---

## 1. Lo confirmado

| # | Fortaleza | Evidencia |
|---|---|---|
| 1 | Multi-tenancy isolation enforced at middleware and DB | `bootstrap/app.php:23-24`, `ResolveTenant.php:24-28` |
| 2 | Per-tenant idempotency on outbox | `sync_outbox.idempotency_key UNIQUE(tenant_id, idempotency_key)`, `SyncOutboxService::record()` short-circuits |
| 3 | Cloud-side dedup de pushed events | `SyncTransportService::pushEvents()` checks `event_uuid` antes de insert |
| 4 | Anti-loop pull filter | `SyncTransportService::pullEvents()` filtra `origin_node_id = $node->id` |
| 5 | Snapshot bootstrap on first registration | `SyncWorkerService::shouldRequestInitialSnapshot()` + cloud queueForNode |
| 6 | Outbox fan-out a todos los local nodes | `SyncOutboxService::record()` writes 1 row per local+active node |
| 7 | Source-tagging for cross-node sales | Migration adds `sync_source_node_code` + `sync_source_id` UNIQUE |
| 8 | Applier wraps each event en su propia transaction | `SyncEventApplier::applyEvents()` usa `DB::transaction(fn() => $this->applyOne(...))` |
| 9 | POS, CashRegister, InventoryTransfers emiten dentro de `DB::transaction` | PosCheckoutService.php:269,271,333; CashRegisterService.php:110,153; InventoryTransferService.php:89,90,246,312,362,... |
| 10 | Token storage hashed + revocable | `SyncTokenService::issue()` stores `hash('sha256', $plainToken)` |
| 11 | Worker token auto-discovery | `RunSyncCommand.php:40-46` lee `storage/app/sync-worker/sync-config.json` per tenant |
| 12 | REPROCESSABLE_EVENT_TYPES permite retries de "ignored" | `SyncEventApplier.php:14-46` |
| 13 | Payload es JSON + stored verbatim en ambos lados | `sync_outbox.payload`, `sync_inbox.payload` son `json` columns |
| 14 | Cloud-side mirror (relay) para multi-PC fan-out | `SyncTransportService::mirrorAppliedEventsToOutbox()` re-broadcast |
| 15 | Idempotency keys son UNIQUE | `UNIQUE(tenant_id, idempotency_key)` previene duplicates |

---

## 2. Issues CRÍTICOS

### C-1. Outbox emit NO está wrappeado en `DB::transaction` para varios catalog writes
**Sitios afectados:**
- `app/Modules/Customers/Controllers/CustomerController.php:54-55, 77-79, 88-89`
- `app/Modules/Products/Controllers/ProductController.php:57-61, 171-191, 215-221, 232-234`
- `app/Modules/Currency/Controllers/ExchangeRateTypeController.php:75-80, 89-90`
- `app/Modules/Currency/Controllers/ExchangeRateController.php:104-107, 116-118`

**Patrón incorrecto:**
```php
$product = Product::create($request->validated())->refresh();   // NOT in DB::transaction
$syncCatalog->productCreated($product);                          // outbox emit
```

**Impacto:** Si el INSERT del negocio commitea pero el INSERT del sync_outbox falla (DB blip, unique violation, deadlock), el business data y el event quedan out-of-sync para siempre. Remote node nunca recibe el cambio.

### C-2. `payload_hash` se calcula pero nunca se verifica
- Written: `SyncTransportService.php:85`, `SyncWorkerService.php:315`
- Read: nowhere (grep returns 0 matches)
- **Impacto:** Tamper detection es no-funcional. Engine confía en cualquier JSON que mande la fuente, aún si hash mismatches.

### C-3. Sin retry / sin DLQ / sin `attempts` ceiling
- `attempts` se incrementa una vez en `pullEvents` (`SyncTransportService.php:151`) pero **nadie lo lee**.
- Un evento que falla perpetuamente (ej. event type sin applier) loopará forever entre `pending → pulled → failed → pending`, generando network traffic y log noise indefinidamente.

---

## 3. Issues HIGH

### H-1. Race condition en `pullEvents` — sin row-level lock entre SELECT y UPDATE
```php
// SyncTransportService.php:128-155
$events = DB::table('sync_outbox')->where(...)->get();   // SELECT
DB::table('sync_outbox')->whereIn('id', $ids)->update([...]);  // UPDATE
```
Dos local nodes llamando `/sync/events/pull` simultáneamente obtendrán los mismos `pending` rows. `locked_at` se setea pero no se usa para filtrar.

### H-2. Cero `Log::*` / `logger(...)` calls en Sync module
- `grep "Log::|logger("` en `app/Modules/Sync` → **0 matches**.
- Sin structured logs para: events emitted, events pushed, events pulled, applies que fallaron, payloads dropped, retries.

### H-3. HTTP client sin retry, sin back-off, una falla aborta el cycle
- `SyncWorkerService::client()` (`SyncWorkerService.php:364-371`) hard-codes `->timeout(30)` y no usa `->retry()`.
- Un transient network blip durante `push` causa que `ensureSuccess()` throw, el daemon loop sleeps y reintenta from scratch.

### H-4. `pushLocalEvents()` marca TODOS los submitted events como `processed`, sin importar cloud's per-event result
- `SyncWorkerService.php:187-196`
- Cloud response contiene `received`, `duplicated`, `applied`, `ignored`, `failed`. El local worker no reconcilia contra `event_uuid`.

### H-5. `SyncInitialSnapshotService` snapshot cleanup edge case
- `clearPreviousSnapshot()` solo borra `initial-snapshot:{installationCode}:*` keys.
- Si node B joins tenant que ya tiene node A working, snapshot de registration previa puede overlap parcialmente.

---

## 4. Issues MEDIUM

### M-1. `available_at` es dead code
- Siempre `now()` en `SyncOutboxService.php:69,94` y `SyncInitialSnapshotService.php:421`.

### M-2. Events faltantes
- `SaleService::cancelDraft()` (`Sales/Services/SaleService.php:158-167`) NO emite sync event.
- `CashRegisterService::addMovement()` (`CashRegisterService.php:116-127`) no emite events para manual movements (sangría, depósito).
- `payment_method.deactivated` no se emite (solo `.updated`).

### M-3. Snapshot replay order not guaranteed
- `SyncEventApplier::applyPending()` ordena por `id` ascending.
- En fresh local node pulling from cloud, `branch.created` puede llegar antes de `product.created`. Applier falla spuriosamente.

### M-4. `applyPosOrder` rompe append-only safety
- `SyncEventApplier.php:655-723` (sale_items) y `:725-775` (pos_payments) hacen `delete whereNotIn sync_source_id`.
- Si cloud fans out evento de node A a node B, luego node A re-pulls, A borrará items de B.

### M-5. `AcknowledgeSyncEventRequest` no valida que el node owns the event's tenant
- Solo verifica `node['status'] === 'active'`.

### M-6. `applyProductPrice` emite `price.updated` AND `product_price.updated` para mismo payload
- `SyncEventApplier.php:147` match ambos pero emitter solo produce `.updated`.

### M-7. `pullEvents` no skip events que same node already has en `sync_inbox`
- Genera needless network traffic (mitigado por dedup pero bandwidth-wasted).

---

## 5. Issues LOW

- **L-1:** `event_uuid` uniqueness race en `SyncOutboxService::record()`
- **L-2:** `payload_hash` column nullable, tolerated missing
- **L-3:** `mirrorAppliedEventsToOutbox()` re-broadcasts ALL received events regardless of `target_scope`
- **L-4:** No request size limit en middleware (`PushSyncEventsRequest` allows 100 events)
- **L-5:** `acknowledge()` solo soporta `applied|failed`, no `ignored`
- **L-6:** `SyncReadinessService::mark()` sin Gate/permission check
- **L-7:** `registerNode()` sin permission gating (cualquier token puede registrar malicious node)
- **L-8:** Sin CLI command para purgar old `processed` rows

---

## 6. Propuestas

### P1 — Wrap ALL business writes + outbox emit en `DB::transaction` [M]
Modify: `CustomerController`, `ProductController`, `PriceListController`, `ExchangeRateTypeController.update/destroy`, `ExchangeRateController.activate/deactivate`, `ExchangeRateController` lines 106/107.

### P2 — Verify `payload_hash` on apply [S]
En `SyncEventApplier::applyOne()` línea 135: re-computar SHA-256 y comparar con `$event['payload_hash']`. Throw on mismatch.

### P3 — Retry policy con attempts ceiling + DLQ [M]
- Migration: `max_attempts SMALLINT DEFAULT 10`.
- En `SyncWorkerService::pushLocalEvents()` y `pullCloudEvents()`: skip events con `attempts >= max_attempts`, mark `failed`.
- `sync:dlq` command para list/purge DLQ events.

### P4 — Structured logging [S]
- INFO: `sync.outbox.recorded`, `sync.push.batch`, `sync.pull.batch`.
- WARNING: `sync.apply.failed`, `sync.payload_hash.mismatch`.
- ERROR: `sync.transport.unreachable`, `sync.attempts.exhausted`.

### P5 — Atomic pull con `SELECT … FOR UPDATE SKIP LOCKED` [M]
```sql
UPDATE sync_outbox SET locked_at = NOW(), attempts = attempts + 1
WHERE id IN (
    SELECT id FROM sync_outbox
    WHERE … AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL '5 minutes')
    ORDER BY id LIMIT $limit FOR UPDATE SKIP LOCKED
) RETURNING *;
```

### P6 — Reconcile push counts con `event_uuid` [S]
Comparar cloud's `data.received + data.duplicated` contra local `count($ids)`. Cloud needs to return list of confirmed uuids.

### P7 — Add missing events [S-M]
- `sale.cancelled` desde `SaleService::cancelDraft()`
- `cash.session.movement_created` desde `CashRegisterService::addMovement()`
- `product_price.deactivated`

### P8 — Apply-order sort para snapshot replay [M]
Sort events en dependency order: branches/warehouses → rate types/rates/payment methods → price lists/products → product prices → customers/product units/cash registers → stock movements/inventory transfers → POS orders.

### P9 — Permission gates en `registerNode`, `markReadiness`, `issueToken` [S]
Add `sync.manage` permission (Owner/Administrador).

### P10 — Per-tenant rate limiting on sync endpoints [S]
`throttle:sync-{tenant}` middleware, 1000 req/min/tenant.

### P11 — Cleanup command for old rows [S]
`sync:prune {--days=30} {--dry-run}` borra `processed` rows older than 30d.

---

## 7. Missing events / edge cases

| Missing Event | Where It Should Fire | Effect of Absence |
|---|---|---|
| `sale.cancelled` | `SaleService::cancelDraft()` line 158 | Cloud y otros PCs mantienen stale `confirmed` sale |
| `cash.session.movement_created` | `CashRegisterService::addMovement()` line 116 | Other PCs no ven movimientos manuales de caja |
| `product_price.deactivated` | Not emitted | Other nodes no distinguen soft-delete de edit |
| `warehouse.deactivated` / `branch.deactivated` | Not emitted | POS puede escribir a warehouse deshabilitado |
| `payment_method.deactivated` | Not emitted | POS permite payments via método deshabilitado |
| `purchase.*` / `purchase_return.*` | Not emitted | Cross-tenant accounting diverge |
| `inventory_transfer_request.*` | No events emitted | Other nodes nunca ven incoming requests |

---

## 8. Observability gaps

1. No logs anywhere in Sync module.
2. No metrics. Solo `markCompleted()` saves summary JSON.
3. No health endpoint for "is sync healthy?".
4. No aggregation de `last_error` across tenants.
5. No dead-letter visibility.
6. No event-level trace correlation.
7. No alerting hook.
