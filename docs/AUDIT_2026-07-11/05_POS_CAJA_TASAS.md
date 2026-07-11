# Auditoría POS + Caja + Tasas — 2026-07-11

**Score: 7 / 10**
**Estado:** Arquitectura sólida (transacciones, locks, snapshots). Issues en USD `amount_local=NULL`, reconciliación multi-currency, y payment-method validation bypass.

---

## 1. Lo confirmado

- **Rate snapshot en cada movimiento monetario** — `PosPayment`, `CashRegisterMovement` migrations líneas 20-22 / 21-23. Snapshot escrito en `PosCheckoutService.php:98-100` y copiado intacto en caja en `CashRegisterService.php:173-175`.
- **No historical rate recalculation** — Conversiones usan active rate al movement time. `recordPosPayment` copia el payment's frozen snapshot.
- **Decimal casts consistentes** — `decimal:4` para money, `decimal:6` para rates.
- **Session lifecycle guards** — `assertOpen` en cada mutating op; `assertCashRegisterCanSell` valida session OPEN + cashier ownership + physical register ACTIVE.
- **One open session per physical register** — Enforced under `lockForUpdate` en el register row.
- **POS status transitions guarded** — `addPayments`/`cancelPending` requieren `STATUS_OPEN`.
- **IMEI / serialized discipline** — Strong.
- **Warranty snapshot** — Policy name/duration/coverage/conditions copiados en draft.
- **Exchange rate single-active invariant** — `ExchangeRateActivationService.php:12-23` desactiva siblings en transaction.
- **Idempotent CxC bridging** — `registerPosPayment` dedupes on `POS-PAYMENT-{id}` reference.
- **Discount sanity bounds** — Percent ≤ 100, fixed ≤ line total, no negative line.
- **Tenant + permission policies** — `PosOrderPolicy`/`CashRegisterSessionPolicy` combinan `ownsResource` + `hasTenantPermission`.

---

## 2. Issues HIGH

### H1. USD payments guardan `amount_local = NULL`, rompiendo double-accounting
- **Archivo:** `PosCheckoutService::resolvePayment` líneas 506-525
- Para USD payment sin `exchange_rate_type_id`, `if` at 506 is false, entonces `exchangeRate` queda null y `amount_local` retorna null (521).
- **Impacto:** `paid_local_amount` subreporta (109 adds `?? 0.0`). `expected_local_amount` en caja excluye USD. AGENTS.md §8.5 viola su propia regla.
- **Fix:** Resolver default active rate para USD también.

### H2. Multi-currency reconciliation math es incorrecta
- `close()` acepta un solo `counted_currency` + `counted_amount`, convierte a ambos base & local al **today's** active rate (`CashRegisterService.php:136-140` → `resolveAmount`), luego computa `difference_base = counted_base − expected_base`.
- `expected_base`/`expected_local` fueron accumulated desde **historical snapshot rates** per movement.
- Comparing current-rate conversion contra snapshot-rate expectations yields a difference que conflate FX drift con true cash variance.
- Drawer con USD y VES no puede ser contado (only one currency per close).

### H3. Multi-currency session opening no soportado (USD + VES float)
- `open()` acepta solo un `opening_currency`/`opening_amount`.
- Imposible seed ambos drawers.

### H4. Payment-method validation bypass cuando no active method resuelve y no price-list restriction
- `validatePaymentMethods` líneas 379-391: si `resolveConfiguredPaymentMethod` returns null Y `restrictedPriceLists` está vacío, el loop `continue`s.
- Payment se persiste con `payment_method_id = null` (`PosCheckoutService.php:92`), skipping `allowsCurrency`, `requires_reference`, y `method`-match checks.

---

## 3. Issues MEDIUM

### M1. Sin DB-level uniqueness / lock para "one open session per cashier"; race condition
- Cashier check 70-79 es plain `exists()` sin lock.
- Two concurrent `open` requests para mismo cashier sin physical register pueden ambas pasar y crear dos OPEN sessions.

### M2. Session `cancel` lifecycle es dead code
- `CashRegisterSession::STATUS_CANCELLED` está definido pero no hay service method, controller action, o route para cancelar session.

### M3. Discount `reason` no mandatory
- `resolveLineDiscount` trata reason como opcional.
- No permission gate on applying discounts ni max-discount cap.

### M4. `active` rate selection ignora `effective_at <= now`
- `activeRateFor` picks `is_active=true` ordered by `latest('effective_at')` SIN `where('effective_at','<=',now())`.
- Future-dated active rate sería aplicado prematuramente a ventas de hoy.

### M5. Pending (`external_financing`) orders hold reserved stock indefinitely
- Cuando `!coversTotal`, order stays OPEN y inventory is reserved.
- Sin TTL/expiry job o reservation-age cap.

### M6. Overpayment / change (vuelto) no manejado
- `coversTotal` compara solo base; `paid_base_amount` puede exceder `total_base_amount` sin change computed/recorded.
- No `change_amount` field en `PosOrder`.

---

## 4. Issues LOW

- **L1:** `cashier_id` exists-rule no tenant-scoped
- **L2:** `PosPayment` sin `created_by` / cashier column
- **L3:** `ADJUSTMENT` movements solo pueden increase totals
- **L4:** Sin enforced single `is_default` per exchange_rate_type
- **L5:** Session puede abrirse sin physical register pero luego no puede vender
- **L6:** Sessions sin physical register no protegidas por el physical-register open-check

---

## 5. Propuestas

| # | Propuesta | Esfuerzo |
|---|---|---|
| P1 (H1) | Resolver default active rate para USD también en `resolvePayment`/`resolveAmount`. Backfill via data migration para existing null rows. | M |
| P2 (H2/H3) | Model per-currency drawers: opening/counted/expected/difference como arrays keyed por currency | L |
| P3 (H4) | En `validatePaymentMethods`, cuando no active method resuelve, **reject** methods que inherently need config | S |
| P4 (M1) | Postgres **partial unique index** `UNIQUE (tenant_id, cashier_id) WHERE status='open'` | S |
| P5 (M2) | Implementar `CashRegisterService::cancel()` + policy + route | S |
| P6 (M3) | Make `discount_reason` `required_with:discount_type` + permission gate | S |
| P7 (M4) | Add `->where('effective_at','<=', now())` a todos los `activeRateFor` queries | S |
| P8 (M5) | Scheduled command para expire/cancel stale pending orders + release reservations | M |
| P9 (M6) | Add `change_base_amount`/`change_local_amount` a `PosOrder` y compute overpayment | S |
| P10 (L1/L2) | Scope `cashier_id` exists rule a tenant; add `created_by` a `pos_payments` | S |

---

## 6. Money integrity risks

1. **USD `amount_local = null`** → VES-side ledgers systematically under-count USD activity (H1). Highest risk.
2. **Current-rate vs snapshot-rate mismatch in reconciliation** (H2): `difference_*` blends FX movement con real variance — cashiers pueden ser blamed/absolved incorrectly.
3. **Float accumulation** en `paidBase`/`paidLocal` antes del final `round(...,4)` — acceptable at 4 dp.
4. **Payment-method bypass** (H4) deja un-validated currency/reference payments post — undermines currency_mode/requires_reference contract.
5. **`coversTotal` base-only** (`:559`): order paid entirely in VES es judged "covered" via converted base; si VES rate at payment differs from item-quote rate, base-side rounding puede marcar slightly-short order paid (±0.0001 tolerance).
6. No `is_default` uniqueness on rate types (L4) → nondeterministic default rate selection puede silently pick wrong rate for conversions.

---

## 7. Edge cases

- **Currency mismatch:** VES payment sin active rate correctamente throws. USD payment silently drops VES accounting (H1).
- **Partial payments:** handled — order stays OPEN, stock reserved, completable via `addPayments`. Sin cap on number of pending payments, sin TTL (M5).
- **Mixed rate types in one cart:** cada payment puede llevar different `exchange_rate_type_id` y cada sale line lleva own quote rate. Sin validation forcing single rate type per order.
- **Refunds/voids:** POS no tiene refund endpoint; cancel bloqueado una vez payments captured → forces external `SalesReturn`/`recordWarrantyRefund`.
- **Overpayment:** accepted, no change recorded (M6).
- **Consumidor Final / generic customer:** modeled solo como nullable `customer_id` + free-text `customer_name`. Sin explicit generic-customer flag.
- **Concurrent opens (same cashier, no register):** double open possible (M1).
- **Future-dated active rate:** applied early (M4).

---

## 8. Index recommendations

1. **Partial unique** `cash_register_sessions (tenant_id, cashier_id) WHERE status='open'` and `(tenant_id, cash_register_id) WHERE status='open'`
2. **`pos_payments (tenant_id, pos_order_id, status)`** — soporta `addPayments` y CxC sync
3. **`cash_register_movements (tenant_id, source_type, source_id)`** — soporta idempotency/lookups
4. **`exchange_rates (tenant_id, exchange_rate_type_id, is_active, effective_at)`** — soporta `activeRateFor` hot path con `effective_at <= now`
5. **Partial unique** `exchange_rate_types (tenant_id) WHERE is_default`
6. **`pos_orders (tenant_id, status, created_at)`** — soporta `latest()` filtrado por status
