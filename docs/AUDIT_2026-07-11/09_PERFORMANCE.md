# Auditoría Performance + Observability — 2026-07-11

**Score: 5.5 / 10**

Zero caché, zero queue, zero observability. N+1 confirmado en `AdminTransferService::index()`. 10+ índices fecha faltantes.

---

## 1. Lo confirmado

| Hallazgo | Evidencia |
|---|---|
| Multi-tenant index discipline | 99 `$table->index(...)` declaraciones prefijadas por `tenant_id` |
| Composite FK `[tenant_id, id]` | 92 modelos usan `BelongsToTenant` |
| `PerformanceProbe` aplicado | `InventoryCenterSummaryService` líneas 19-66, etc. |
| `chunkById(200, ...)` en sync | `SyncInitialSnapshotService.php` 12 streams |
| `lockForUpdate` y `DB::transaction` disciplinados | todas las rutas monetarias |
| `token_hash` UNIQUE index | `2026_07_03_120000_create_auth_tokens_table.php:16` |
| `sync_outbox(tenant_id, status, available_at)` | `2026_07_05_210100_create_sync_outbox_table.php:36` |
| `sync_inbox(tenant_id, status, received_at)` | `2026_07_05_210200_create_sync_inbox_table.php:29` |
| Eager loading en `AdminTransferService::detail()` | línea 214 |
| `exists()` para checks baratos | 20 usos (vs 65 `count()`) |
| Doble cuenta + snapshot rate | pos_payments, cash_register_movements, accounts_*_payments, exchange_rates |
| Foreign keys compuestas sin autoincrement issue | UNIQUE [tenant_id, sequence] |

---

## 2. Issues CRITICAL

### 3.1 — Cero capa de caché
`grep "Cache::"` en todo `app/` → **0 matches**.

`PosCheckoutService` consulta datos de referencia en cada checkout sin caché:
- `PriceList::where('is_default', true)` por cada item sin lista explícita
- `PriceList::with('paymentMethods')->whereIn('id', $priceListIds)` por cada venta
- `PaymentMethod::where('is_active', true)` por cada pago
- `ExchangeRateType::findOrFail()` + `ExchangeRate::where(...is_active)` por cada pago en VES

`config/cache.php:18` usa `CACHE_STORE=database` por defecto — cuando Redis está disponible. Database driver ~10x más lento que redis.

### 3.2 — N+1 confirmado en `AdminTransferService::index()`
`app/Modules/AdminPortal/Services/AdminTransferService.php:172-179`:

```php
$itemsCount = (int) DB::table('inventory_transfer_items')
    ->where('inventory_transfer_id', $row->id)
    ->count();
$differencesCount = (int) DB::table('inventory_transfer_items')
    ->where('inventory_transfer_id', $row->id)
    ->where('difference_quantity', '!=', 0)
    ->count();
```

Se ejecuta dentro de `->map(fn ($row) => $this->mapTransfer($row))` sobre cada fila. Con `limit=25` son **50 queries extra por request**.

### 3.3 — N+1 / multi-query en `InventoryCenterSummaryService::metrics()`
8 queries para una pantalla de inventario. Múltiples patterns similares en `AdminDashboardService::summary()` (**15+ queries**).

---

## 3. Issues HIGH

### 3.4 — Índices faltantes en columnas de fecha/ordenamiento

| Columna | Falta índice | Migración |
|---|---|---|
| `sales.confirmed_at` | **Sí** | `2026_07_02_200000_create_sales_table.php` |
| `pos_orders.paid_at` | **Sí** | `2026_07_02_203000_create_pos_orders_table.php` |
| `pos_orders.opened_at` | **Sí** | igual |
| `inventory_transfers.processed_at` | **Sí** | `2026_07_02_228000_create_inventory_transfers_table.php` |
| `accounts_receivables.due_date` | **Sí** | `2026_07_02_219000_create_accounts_receivables_table.php` |
| `accounts_payables.due_date` | **Sí** | igual payables |
| `cash_register_sessions.opened_at` | **Sí** | |
| `warranty_claims.received_at` | **Sí** | |
| `exchange_rates.effective_at` | parcial | parcialmente cubierto |
| `auth_tokens.expires_at` | **Sí** | se ejecuta en cada request |

### 3.5 — `stock_balances` sin índice en `quantity_available`
Filtrado `where('quantity_available', '<=', $threshold)` sin índice puede ser O(warehouses × products).

### 3.6 — Cero infraestructura de queue
`grep -E "dispatch\(|ShouldQueue|Queue::|->onQueue\("` → 0 matches reales.

Procesos síncronos dentro del request HTTP:
- `AuditLogger::record()` → INSERT por cada acción
- `SyncCatalogOutboxService` → INSERT por cada cambio admin
- `InventoryMovementService::recordMovement()` → INSERT + auditoría
- `PosCheckoutService::recordOrderSyncEvent()` → INSERT en outbox + load 10 relaciones

### 3.7 — `cache_store=database` y `queue=database` en producción
`.env:41` confirma `CACHE_STORE=database`. Redis configurado pero no usado. Cambiar a redis es 5 minutos.

---

## 4. Issues MEDIUM

- **3.8:** Sin observabilidad: Horizon, Telescope, slow log, request_id, JSON logs. 0 matches.
- **3.9:** `PosCheckoutService::recordOrderSyncEvent` carga 10 relaciones sin condicional (733-743).
- **3.10:** `InventoryCenterProductDetailService::recentMovements` ordena por `latest('id')` sin índice combinado.
- **3.11:** `pos_payments(status)` no indexado.
- **3.12:** `exchange_rate_types(is_default AND is_active)` sin índice compuesto.

---

## 5. Propuestas

| # | Propuesta | Esfuerzo |
|---|---|---|
| 4.1 | `TenantReferenceCache` service para payment_methods, price_lists, exchange_rates | S |
| 4.2 | `CACHE_STORE=redis` + `QUEUE_CONNECTION=redis` en `.env` del VPS | S |
| 4.3 | Refactor N+1 en `AdminTransferService::index()` | M |
| 4.4 | Refactor `InventoryCenterSummaryService::metrics()` a query única | M |
| 4.5 | Índices nuevos + tests de regresión (migration `2026_07_11_xxxxxx_add_performance_indexes.php`) | M |
| 4.6 | `AssignRequestId` + `SlowRequestLogger` middlewares | M |
| 4.7 | Structured JSON logging channel en `config/logging.php` | M |
| 4.8 | Convertir `PerformanceProbe` a JSON estructurado | S |
| 4.9 | Reducir payload de `PosCheckoutService::recordOrderSyncEvent` | S |
| 4.10 | `KardexService` paginar o limitar | S |
| 4.11 | `AdminTransferService::export()` usar `lazy()` | S |
| 4.12 | `exchange_rate_types.is_default` partial unique | S |

---

## 6. SQL migration snippets (listos para aplicar)

```sql
-- 2026_07_11_000001_add_performance_indexes.php

CREATE INDEX sales_tenant_confirmed_at_idx ON sales (tenant_id, confirmed_at);
CREATE INDEX pos_orders_tenant_paid_at_idx ON pos_orders (tenant_id, paid_at);
CREATE INDEX pos_orders_tenant_opened_at_idx ON pos_orders (tenant_id, opened_at);
CREATE INDEX inv_transfers_tenant_processed_at_idx ON inventory_transfers (tenant_id, processed_at);
CREATE INDEX crs_tenant_opened_at_idx ON cash_register_sessions (tenant_id, opened_at);
CREATE INDEX wc_tenant_received_at_idx ON warranty_claims (tenant_id, received_at);

CREATE INDEX ar_tenant_status_due_idx ON accounts_receivables (tenant_id, status, due_date);
CREATE INDEX ap_tenant_status_due_idx ON accounts_payables (tenant_id, status, due_date);

CREATE INDEX auth_tokens_lookup_idx ON auth_tokens (token_hash, revoked_at, expires_at);

CREATE INDEX sm_tenant_product_date_idx ON stock_movements (tenant_id, product_id, created_at);
CREATE INDEX sm_tenant_date_idx ON stock_movements (tenant_id, created_at);

CREATE INDEX pos_payments_tenant_status_idx ON pos_payments (tenant_id, status);
CREATE INDEX sync_outbox_tenant_processed_at_idx ON sync_outbox (tenant_id, processed_at);

-- Partial
CREATE INDEX stock_balances_low_stock_idx
  ON stock_balances (tenant_id, product_id, warehouse_id)
  WHERE quantity_available > 0 AND quantity_available <= 10;

CREATE UNIQUE INDEX exchange_rate_types_default_idx
  ON exchange_rate_types (tenant_id)
  WHERE is_default = true AND is_active = true;
```

---

## 7. Caching strategy

### Qué cachear, TTL, invalidación

| Recurso | TTL | Key | Invalidación |
|---|---|---|---|
| `payment_methods` activos del tenant | 10 min | `tenant:{tid}:payment_methods:active` | En `PaymentMethod::saved/deleted` |
| `price_lists` activas/default | 10 min | `tenant:{tid}:price_lists:active` | En `PriceList` events |
| `exchange_rates` activa por tipo | **60 seg** | `tenant:{tid}:rate:active:{type_id}` | En `ExchangeRate::is_active` change |
| `exchange_rate_types` default | 10 min | `tenant:{tid}:rate_type:default` | En `ExchangeRateType` events |
| `warehouses` activas | 30 min | `tenant:{tid}:warehouses:active` | En `Warehouse` events |
| `branches` activas | 30 min | `tenant:{tid}:branches:active` | En `Branch` events |
| `cash_registers` activas del branch | 30 min | `tenant:{tid}:cash_registers:branch:{bid}` | En `CashRegister` events |
| `dashboard:summary:{tenant}:{period}:{date}` | **5 min** | por tenant + período | Auto-expira (5min) |
| `users:roles:permissions` | **5 min** | `user:{uid}:tenant:{tid}:perms` | En role/permission change |
| `tenant:{tid}:sync_readiness` | 30 seg | — | Auto-expira |

### Patrón recomendado: Cache::forget() selectivo por prefijo

```php
Cache::forget("tenant:{$tenantId}:payment_methods:active");
```

---

## 8. Slow query log PostgreSQL (VPS)

```sql
ALTER DATABASE inventory_arens SET log_min_duration_statement = 500;
ALTER DATABASE inventory_arens SET log_lock_waits = on;
ALTER DATABASE inventory_arens SET log_temp_files = 0;
ALTER DATABASE inventory_arens SET track_activities = on;
ALTER DATABASE inventory_arens SET track_counts = on;
ALTER DATABASE inventory_arens SET track_io_timing = on;
```

Y `shared_preload_libraries = 'pg_stat_statements'`.

---

## 9. Queue opportunities

| # | Job | Esfuerzo |
|---|---|---|
| 1 | `RecordSyncOutboxJob` — mover INSERT en `sync_outbox` a cola | M |
| 2 | `GeneratePaymentReceiptPdfJob` | M |
| 3 | `BulkInventoryMovementJob` para cargas masivas | L |
| 4 | `RecomputeDashboardJob` cada 5 min | S |
| 5 | `CleanupSyncOutboxJob` scheduled | S |
| 6 | `ProcessPosOrderPaymentReceiptsJob` | M |
| 7 | `RefreshLowStockAlertsJob` hourly | S |
| 8 | `SendWarrantyNotificationJob` | S |

---

## 10. Observability gaps

1. **No logs anywhere in `app/Modules/Sync/`.** Add at least:
   - `INFO`: `sync.outbox.recorded`, `sync.push.batch`, `sync.pull.batch`, `sync.apply.failed`
   - `WARNING`: `sync.payload_hash.mismatch`, `dlq.enqueue`, `retry.ceiling.hit`
   - `ERROR`: `sync.transport.unreachable`, `sync.attempts.exhausted`

2. **No metrics.** Add `PerformanceProbe::measure()` alrededor de las 4 fases sync: push, pull, apply, acknowledge.

3. **No health endpoint for "is sync healthy?"** `status()` retorna counts pero no boolean.

4. **No aggregation of `last_error` across tenants.** Single tenant's `sync_inbox.last_error` no se surface en admin UI.

5. **No dead-letter visibility.** Once `status=failed`, no UI surfaces these.

6. **No event-level trace correlation.** A `request_id` or `trace_id` no se propaga from origin node → cloud → other nodes.

7. **No alerting hook.** `SyncReadinessService::markFailed()` escribe `status=error` pero nada notifica (no email, no Slack, no log).

---

## Resumen ejecutivo

| Categoría | Estado | Acción prioritaria |
|---|---|---|
| Multi-tenant indexes | ✅ Excelente | Mantener |
| Composite FK `[tenant_id, id]` | ✅ Excelente | Mantener |
| Eager loading | ✅ Mayormente OK | Fix N+1 en `AdminTransferService::index()` |
| `count() vs exists()` | ✅ Correcto | OK |
| `chunk() / lazy()` | ⚠️ Solo en sync snapshot | Agregar a KardexService y export |
| Column pruning (`select`) | ⚠️ Selectivo | Mejorar en sync payload |
| **Cache layer** | ❌ **AUSENTE** | **Crear `TenantReferenceCache` + cambiar `CACHE_STORE=redis`** |
| **Queue** | ❌ **AUSENTE** | **Mover sync_outbox INSERT + dashboard precompute a jobs** |
| **Observability** | ❌ **AUSENTE** | **Request ID + slow log + JSON logs + pg_stat_statements** |
| Índices de fecha | ❌ **Faltan 10+** | Migración propuesta §6 |

**Top 3 quick wins:**
1. Cambiar `CACHE_STORE=redis` + `QUEUE_CONNECTION=redis` (30 min).
2. Aplicar migration de índices propuestos en §6 (0 código tocado).
3. Activar `ALTER DATABASE … SET log_min_duration_statement = 500` (15 min).
