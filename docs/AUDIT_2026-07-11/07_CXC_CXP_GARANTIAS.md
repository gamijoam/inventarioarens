# Auditoría CxC/CxP + Garantías — 2026-07-11

**Score: 6.5 / 10**

| Aspecto | Puntaje | Comentario |
|---|---|---|
| Atomicidad y locks | 8 | `lockForUpdate` consistente en todas las rutas monetarias |
| Multi-tenancy | 8 | BelongsToTenant + Policy + tests cross-tenant |
| Money arithmetic | **4** | **2 bugs críticos** por descuento ignorado (refund + return) |
| Sequence numbering | 5 | Race en `nextSequence` pattern |
| Audit trail | **5** | Solo warranties audited; AR/AP/payments/voids NO |
| State machine | 7 | Lifecycle correcto en happy paths |

---

## 1. Lo confirmado

- AR opened en `Sale::STATUS_CONFIRMED` en same `DB::transaction`, idempotent via `firstOrCreate(['sale_id'=>...])`.
- AP opened en `PurchaseOrder::STATUS_RECEIVED|STATUS_PARTIALLY_RECEIVED`, idempotent.
- AR/AP `lockForUpdate()` en cada `registerPayment`/`applySalesReturn`/`applyPurchaseReturn`.
- Payment atomicity: lock AR → validate → insert payment → mutate balance → save → `PaymentReceiptService` issue.
- Status calculation correct: balance ≤ 0 → PAID + paid_at; past due_date + balance > 0 → OVERDUE.
- Payment rate snapshot per row.
- `PaymentReceipt` is idempotent (UNIQUE `[tenant_id, source_type, source_id]`).
- Refund mutual exclusivity (cash OR AR adjustment, never both).
- Replacement IMEI lifecycle: original → `WARRANTY_HOLD` → `DAMAGED`; replacement → `SOLD`.
- Composite FKs `[tenant_id, *]` enforced in DB.
- Tenant scope via global `TenantScope` + policy `ownsResource()`.
- Warranty audit trail: 4 events recorded.
- Refund cap validated contra `sale_item.base_unit_price * quantity` (**BUG**).
- `PaymentReceipt` void action with `voided_by`, `voided_at`, `void_reason`.

---

## 2. Issues CRITICAL — money leaks

### 3.1 Refund cap ignora discount — cliente puede refund más de lo pagado
- **Archivo:** `app/Modules/Warranties/Services/WarrantyClaimService.php:494-503`
- **Qué:** `$maxRefundBase = $saleItem->base_unit_price * $claim->quantity` (list price). Pero cliente fue charged `sale_item.base_total_amount` (i.e. `base_unit_price * qty - discount_base_amount`).
- **Ejemplo:** Item: `base_unit_price=100, qty=2, discount=50 → base_total=150`. Cliente pagó 150. Refund cap dice `200`. Refund 150 = **fuga de 50 USD**.
- **Fix:** Usar `(float) $saleItem->base_total_amount` (el actual charge neto de discount).
- **Estado:** ✅ **RESUELTO 2026-07-11** en P0-1. Ver `tests/Feature/Warranties/WarrantyRefundDiscountTest.php`.

### 3.2 Return totals ignoran discount en AR
- **Archivo:** `app/Modules/AccountsReceivable/Services/AccountsReceivableService.php:197-228`
- **Qué:** `$returnedBase += $saleItem->base_unit_price * $quantity` y `localReturnAmount` usa `base_unit_price * quantity * exchange_rate`.
- **Efecto:** Cliente returns 1 unit (list 100, discounted a 75). `returned_base_amount = 100`. Balance = `original - 100 - collected - adjusted`. Si cliente paid full 150 y returns 1 → balance = `150 - 100 - 0 - 0 = 50` → cliente appears to owe 50 USD for the 1 remaining unit whose actual charge fue 75. **Money leak + silent clamp.**
- **Fix:** `$returnedBase += base_total_amount * quantity / sale_item.quantity` (per-unit discounted price).
- **Estado:** ✅ **RESUELTO 2026-07-11** en P0-2. Ver `tests/Feature/AccountsReceivable/AccountsReceivableReturnDiscountTest.php`.

### 3.3 Sequence race en `payment_receipts` y `financial_adjustments`
- **Archivos:** `PaymentReceiptService.php:122-135`, `FinancialAdjustmentService.php:96-100`
- **Qué:** Pattern `lockForUpdate` + read max + insert. `SELECT … FOR UPDATE` con `ORDER BY sequence DESC LIMIT 1` no row-lock nada en PostgreSQL a menos que la row exista Y concurrent transactions race.
- **Efecto:** Dos parallel inserts leen `lastSequence=N`, calculan N+1, segundo INSERT viola UNIQUE → 500.
- **Fix:** `pg_advisory_xact_lock(hashtext('payment_receipt:' . tenant_id))` wrapping read+insert.

### 3.4 `coverage_type = NONE` no bloquea claims
- **Archivo:** `Warranties/Services/WarrantyClaimService.php:505-524` (`assertWarrantyEligible`)
- **Qué:** Solo chequea `warranty_policy_id === null` y `warranty_expires_at`. `WarrantyPolicy::COVERAGE_NONE` existe pero nunca enforced.
- **Fix:** `if ($saleItem->warranty_coverage_type === WarrantyPolicy::COVERAGE_NONE) throw ...`.

---

## 3. Issues HIGH

### 3.5 AR creado para toda confirmed sale, incluyendo 100% cash sales
- **Archivo:** `Sales/Services/SaleService.php:152` (`createForSale`)
- **Fix:** Skip AR cuando `sale.total_base_amount == sum(pos_payments.amount_base)`, OR add hard-delete sweep of zero-balance ARs older than N days, OR introduce `sale.credit_origin` flag.

### 3.6 `RESOLUTION_REPAIR` accepted en review, NOT implemented en `resolve()`
- **Archivo:** `Warranties/Requests/ReviewWarrantyClaimRequest.php:24-30` vs `Services/...:164-171`
- **Fix:** Either remove `RESOLUTION_REPAIR` from review validation, or implement `resolveRepair()` no-op.

### 3.7 Refund uses CURRENT rate, not historical — violates AGENTS.md §8.5
- **Archivos:** `CashRegisterService.php:262-289` (`resolveAmount`), `FinancialAdjustmentService.php:121-149`
- **Qué:** Warranty refunds siempre pull latest active exchange rate.
- **Fix:** Freeze rate at resolution time, store in `refund_exchange_rate_type_id`/`refund_exchange_rate` (columns exist).

### 3.8 Refund to AR bloqueado cuando balance ya está 0 (already collected)
- **Archivo:** `FinancialAdjustments/Services/FinancialAdjustmentService.php:162-171` (`assertAdjustable`)
- **Fix:** Allow adjustments on PAID accounts con cap separado.

### 3.9 `deliver()` is silently bypass-able después de `resolve()`
- **Archivo:** `Warranties/Services/WarrantyClaimService.php:122-148` vs `:150-173`
- Inconsistent: deliver sets `DELIVERED`, then resolve dice "must be APPROVED".

---

## 4. Issues MEDIUM

- **3.10:** No audit log para AR/AP payments, voids, financial adjustments.
- **3.11:** `PaymentReceipt` void no reverse anything (orphan state).
- **3.12:** `recalculate()` silently clamps negative balance con `max(0.0, …)`.
- **3.13:** AP `original_base_amount` grows en cada partial receipt; PAID can flip back to PARTIAL.
- **3.14:** `WarrantyClaim::RESOLUTION_PENDING_REVIEW` existe pero nunca matched en service.
- **3.15:** AR model missing exchange_rate snapshot columns.
- **3.16:** FKs missing on `refund_financial_adjustment_id` y `refund_cash_register_movement_id` en warranty_claims (minor).

---

## 5. Propuestas

| # | Propuesta | Esfuerzo | Estado |
|---|---|---|---|
| P1 | Fix refund cap para usar `base_total_amount` | XS | ✅ **HECHO 2026-07-11** |
| P2 | Fix return totals en `returnedTotalsForSale` + `localReturnAmount` | XS | ✅ **HECHO 2026-07-11** |
| P3 | Replace `lockForUpdate` sequence pattern con `pg_advisory_xact_lock` o `INSERT … ON CONFLICT` | S | Pendiente |
| P4 | Reject claims cuando `warranty_coverage_type === COVERAGE_NONE` | XS | Pendiente |
| P5 | Skip AR creation on `Sale::confirm` cuando 100% cash paid | S | Pendiente |
| P6 | Implementar `resolveRepair()` o remove `RESOLUTION_REPAIR` | S | Pendiente |
| P7 | Allow `FinancialAdjustment` on PAID accounts | M | Pendiente |
| P8 | Audit-log AR/AP payments, voids, financial adjustments | M | Pendiente |
| P9 | Document or change refund rate policy | XS | Pendiente |
| P10 | Stop re-filling `original_base_amount` on `createForPurchase` once AP has payments | S | Pendiente |
| P11 | `coverage_type === COVERAGE_MANUFACTURER` semantics | M | Pendiente |
| P12 | Add `closed_at`, `closed_by` columns en `warranty_claims` | XS | Pendiente |
| P13 | Overdue cron: `ar:mark-overdue`, `ap:mark-overdue` | S | Pendiente |
| P14 | Split `WarrantyPolicyApiTest` → `WarrantyPolicyApiTest` + `WarrantyClaimApiTest` | XS | Pendiente |
| P15 | Add columns `exchange_rate_type_id/_code/_rate` to AR | S | Pendiente |
| P16 | `coverage_type === COVERAGE_MANUFACTURER` resolution branch | M | Pendiente |

---

## 6. Money-leak path (Top Priority)

Si arreglás solo 2 líneas, tapás los 2 leaks:

1. ~~`AccountsReceivableService.php:211`~~ ✅ **HECHO**
2. ~~`WarrantyClaimService.php:496`~~ ✅ **HECHO**

Ambos eran 5-line patches. Ambos bloqueaban leak confirmado en cada transacción con descuento.

---

## 7. Edge cases

1. Discount on sale_item — leaks (3.1, 3.2). No test hasta hoy. ✅ Cubierto
2. Fully paid POS sale crea `STATUS_PAID` AR con `$0` balance; no cleanup mechanism.
3. Sale returns AFTER full payment — `returned > collected` scenario; balance clamps to 0, money missing silently.
4. Refund contra fully paid AR — adjustment rejected.
5. Multiple POS payments en same sale en mixed currencies.
6. `refund_amount` cap para non-USD refund — VES refund de arbitrary amount could be capped inconsistently si rate changes.
7. `coverage_type = NONE` + serialized product.
8. Re-resolving a closed claim.
9. `receiveReservedUnitsForSaleItem` — release path solo handles `STATUS_RESERVED` units.
10. `replacement_product_unit_id` validation en serialized case.
11. `refund_currency` mismatch con `apply_to_receivable_balance`.
12. `PaymentReceipt` void no notifica underlying payment or AR.
13. `paid_at` reset.
14. Warranty claim `customer_phone` free-text — no format validation.
15. `assertClaimableQuantity` race condition: two simultaneous POSTs for same IMEI.
