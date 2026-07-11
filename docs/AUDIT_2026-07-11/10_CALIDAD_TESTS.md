# Auditoría Calidad de Código + Tests — 2026-07-11

**Score: Tests 7/10 — Code Quality 6/10**

---

## 0. Baseline cuantitativo

| Métrica | Valor |
|---|---|
| Total PHP files en `app/Modules` | 330 |
| Total Feature test files | 51 |
| Total Unit test files | 1 (boilerplate `ExampleTest`) |
| Total test methods | 391 |
| Total líneas de test code | 17,459 |
| `RefreshDatabase` uses | 51 files / 102 references |
| `actingAs()` usages | 543 |
| `withHeader('Authorization', 'Bearer …')` usages | 12 |
| `Mockery`, `Mock::`, `Bus::fake`, `Mail::fake`, `Event::fake`, `Queue::fake` | 0 each |
| `Http::fake` usages | 5 (todos en `SyncWorkerCommandTest.php`) |
| `app(TenantManager::class)` calls (service locator) | 143 |
| `DB::table(` usages en `app/Modules` | 145 |
| `Gate::authorize` calls | 111 |
| `interface ` declarations en `app/Modules` | 0 |
| `abstract class` declarations en `app/Modules` | 0 |
| Hardcoded stock-movement type strings | 66 |
| Hardcoded `'USD'` / `'VES'` literals | 21 |

---

## 1. CONFIRMED GOOD

1. **Multi-tenancy isolation cross-tenant tested.** `tests/Feature/Tenancy/TenantIsolationTest.php` (3 tests), `OperationalTenantIsolationTest.php` (1 large integration).
2. **Auth flow genuinely tested with Bearer tokens.** `tests/Feature/Auth/AuthApiTest.php` (10 tests). **Only test class que drives el `api.auth` middleware end-to-end.**
3. **Inventory state machine tested through HTTP layer.** 43 tests en `InventoryTransferApiTest.php`, 22 en `AdminTransferActionsTest.php`, 16 en `WarrantyPolicyApiTest.php`.
4. **Sync apply partially unit-tested.** `tests/Feature/Sync/SyncEventApplierTest.php` (5 tests) directly invokes applier.
5. **Sync worker exercised with `Http::fake`.** `tests/Feature/Sync/SyncWorkerCommandTest.php` (9 tests).
6. **Money arithmetic on POS verified through DB assertions.** `tests/Feature/POS/PosCheckoutApiTest.php:114` checks `paid_base_amount`, `amount_base`, `amount_local`, `exchange_rate_type_code`, `exchange_rate`.
7. **Demos seeders tested for idempotency.** `DemoDataSeederTest.php` y `MultiCompanyLoginDemoSeederTest.php`.
8. **Module structure consistent.** 22 de 34 modules exponen `Controllers/Models/Policies/Requests/Resources`.
9. **All tenant-scoped models use `BelongsToTenant` consistently.**
10. **Money helpers use `round(..., 4)`.**
11. **Money never recomputed from historical data.**

---

## 2. Issues de test coverage

| # | Sev | Issue |
|---|---|---|
| T1 | **H** | **Only 1 of 391 tests drives the Bearer token path.** Los 543 `actingAs()` calls mean **token-revocation, last_used_at, expires_at, IP/user-agent capture paths del middleware son exercised por exactamente 10 tests**. |
| T2 | **H** | **No unit tests at all.** Solo `ExampleTest.php`. Servicios tested solo via controllers. |
| T3 | **M** | **No tests for IMEI state machine transitions.** Full `available → reserved → sold → warranty_hold → damaged → removed` cycle traversed across multiple services sin dedicated test. |
| T4 | **M** | **No unit tests for money arithmetic.** Rounding, conversions, base/local balance invariant. |
| T5 | **M** | **No tests for queueable jobs.** No `app/Jobs` directory. |
| T6 | **M** | **No tests for rate-limit / throttling.** |
| T7 | **M** | **No tests for cache behavior.** `phpunit.xml` sets `CACHE_STORE=array`. |
| T8 | **M** | **Single E2E spec mostly stubbed.** `tests/e2e/portal-translados.spec.ts:130-135` skips permission-denied test. |
| T9 | **L** | **`SyncEventApplierTest` does not test failure semantics for each event type.** Solo 2 de 32 supported event types tested. |
| T10 | **L** | **No `TestCase` traits for test fixtures.** `useTenant`, `userInTenant`, `grantRole`, `warehousesAndProduct`, etc. duplicated in 41, 38, 32, 11, 6, 5, 5, 4, 3, 3, 3, 2, 2 test files. |

---

## 3. Issues de code quality

| # | Sev | Issue |
|---|---|---|
| Q1 | **H** | **Idempotency bug en `SyncCatalogOutboxService::eventKey`.** `Str::uuid()` en cada call rompe dedup. También en `ExchangeRateController:138` y `ExchangeRateTypeController:107`. |
| Q2 | **H** | **`InventoryTransferService` es 1068-line god-service.** |
| Q3 | **H** | **`SyncEventApplier` es 913-line god-service** con 145+ `DB::table` raw. |
| Q4 | **H** | **Service Locator anti-pattern pervasive.** 143 `app(TenantManager::class)` calls. |
| Q5 | **H** | **6 controllers hacen CRUD directamente, bypassing service layer:** `Branches`, `Warehouses`, `Customers`, `Suppliers`, `PaymentMethods`, `Reports`. |
| Q6 | **M** | **`PosCheckoutService` over-instrumented** con 27 `PerformanceProbe::measure` calls. |
| Q7 | **M** | **Status-string drift across codebase.** 66 literales `'purchase'`, `'sale'`, etc. en lugar de constants. |
| Q8 | **M** | **`SyncEventApplier::applyPosOrder` silently falls back a `'SYNC-ORIGEN-DESCONOCIDO'`** en línea 927. |
| Q9 | **M** | **Hardcoded role names** repetidos en tests. |
| Q10 | **M** | **`SyncCatalogOutboxService` over-instruments payload keys.** |
| Q11 | **M** | **Exception swallowing en `InventoryTransferService::create`** lines 83-87. |
| Q12 | **M** | **No exception → HTTP mapping for non-`ValidationException` cases.** 204 `ValidationException::withMessages` usos pero **0** custom exceptions con HTTP semantics. |
| Q13 | **L** | **`Controller.php` is empty base, but every controller extends it.** |
| Q14 | **L** | **Hardcoded URL paths en tests.** 200+ `'/api/...'` literals. |
| Q15 | **L** | **`Carbon::setTestNow` is used in 38 tests but never reset en `tearDown`.** |
| Q16 | **L** | **`AdminOperationalReportService`, `AdminPosSalesService`, `AdminTransferService` issue 3-4 `app(TenantManager::class)` calls each.** |
| Q17 | **L** | **Magic strings in audit action vocabulary.** |
| Q18 | **L** | **`PosCheckoutService::resolvePayment` mixes currency conversion con payment method validation.** |
| Q19 | **L** | **`SyncWorkerService::storeInboxEvent` uses 2 un-coordinated writes.** |
| Q20 | **L** | **Currency code normalization duplicated.** 13 places `strtoupper($payload['currency'] ?? 'USD')`. |

---

## 4. Propuestas (effort: S=≤4h, M=4-16h, L=>16h)

| # | Proposal | Effort | Addresses |
|---|---|---|---|
| P1 | Extract `tests/Concerns/InteractsWithTenants.php` trait (deduplica 32 helpers) | L | T10 |
| P2 | `actingAsToken($user, $tenant)` helper + Bearer-token tests para módulos críticos | L | T1 |
| P3 | `tests/Unit/Inventory/InventoryMovementServiceTest.php` (one test per public method) | M | T2 |
| P4 | `tests/Unit/Sales/SaleServiceTest.php` para `resolveLineDiscount` matrix | S | T4 |
| P5 | `tests/Unit/Inventory/ProductUnitStateMachineTest.php` | M | T3 |
| P6 | `tests/Unit/Sync/SyncEventApplierTest.php` con positive test per `apply*()` | M | T9 |
| P7 | Fix `SyncCatalogOutboxService::eventKey` (remover `Str::uuid()`) | S | Q1 |
| P8 | Split `InventoryTransferService` en 6 servicios ≤200 líneas | L | Q2 |
| P9 | Split `SyncEventApplier` en per-event classes | L | Q3 |
| P10 | Constructor-inject `TenantManager` en lugar de `app()` | M | Q4 |
| P11 | Enum `StockMovementType` para reemplazar 66 string literals | M | Q7 |
| P12 | Mover 5 base roles a `BaseRoles` constants | S | Q9 |
| P13 | Mover audit action vocabulary a `AuditActions` constants | S | Q17 |
| P14 | Surface `InsufficientStockException`/`InvalidStockQuantityException`/`CrossTenantInventoryReferenceException` en exception handler con HTTP semantics | S | Q12 |
| P15 | `Currency::normalize($code)` helper | S | Q20 |
| P16 | Wrap `SyncWorkerService::storeInboxEvent` en `DB::transaction()` | S | Q19 |
| P17 | Crear los 6 services faltantes en `Branches`, `Warehouses`, `Customers`, `Suppliers`, `PaymentMethods`, `Reports` | L | Q5 |
| P18 | `InteractsWithApi` trait con `path(name, params)` para reemplazar 200+ URL literals | M | T10, Q14 |
| P19 | `tearDown(): void { Carbon::setTestNow(); }` en tests que mutan time | S | Q15 |
| P20 | `AdminOperationalReportService`, `AdminPosSalesService`, `AdminTransferService` inject `TenantManager` | S | Q16 |
| P21 | Extract `CurrencyConverter` service desde `PosCheckoutService::resolvePayment` + `AccountsReceivableService::paymentAmounts` | M | Q18 |
| P22 | `IMeiStateMachine` value object con valid transitions | L | T3, Q11 |

---

## 5. Refactoring candidates (god services, duplication)

| File | Lines | Smell | Action |
|---|---:|---|---|
| `app/Modules/InventoryTransfers/Services/InventoryTransferService.php` | 1068 | God-service | Split per P8 |
| `app/Modules/Sync/Services/SyncEventApplier.php` | 913 | God-service | Split per P9 |
| `app/Modules/POS/Services/PosCheckoutService.php` | 754 | Over-instrumented, mixed concerns | Extract `CurrencyConverter` |
| `app/Modules/AdminPortal/Services/AdminPosSalesService.php` | 546 | Long, hybrid report + filters | Split |
| `app/Modules/Warranties/Services/WarrantyClaimService.php` | 505 | 3 large `resolve*()` methods | Split |
| `app/Modules/AdminPortal/Services/AdminOperationalReportService.php` | 447 | Pattern similar | Extract KpiCalculator |
| `app/Modules/InventoryCenter/Services/InventoryCenterSummaryService.php` | 423 | Heavily DB-coupled | InventoryReadModel |
| `app/Modules/Sync/Services/SyncInitialSnapshotService.php` | 387 | 11 queue methods duplicados | QueueOutboxChunk helper |
| `app/Modules/Sync/Services/SyncTransportService.php` | 329 | 10 `app(TenantManager)` calls | Inject |
| `tests/Feature/InventoryTransfers/InventoryTransferApiTest.php` | 2210 | 43 tests en 1 archivo | Split per scenario |
| `tests/Feature/POS/PosCheckoutApiTest.php` | 1429 | 22 tests | Split + use P1 trait |
| `tests/Feature/AdminPortal/AdminTransferActionsTest.php` | 648 | 22 tests, role literals duplicated | Use P1 trait |

---

## 6. Code smells con file:line

### 6.1 Hardcoded strings
- `Inventory/Services/InventoryMovementService.php:33, 55, 77, 90, 124, 148, 172, 195, 210, 242, 273, 274` — `type: 'purchase'`, etc.
- `Sync/Services/SyncEventApplier.php:216, 453, 464, 509, 510, 689, 753` — `strtoupper($payload['sale_currency'] ?? 'USD')`
- `Sync/Services/SyncEventApplier.php:927` — `'SYNC-ORIGEN-DESCONOCIDO'`
- `Sync/Services/SyncCatalogOutboxService.php:338` — `Str::uuid()` bug
- `Sync/Services/SyncOutboxService.php:71, 96` — `status: 'pending'` y `'processed'`
- `Auth/Services/AuthService.php:60, 67` — `Str::random(80)`, `now()->addDays(30)`

### 6.2 Test-helper duplication

| Helper | # duplicados |
|---|---:|
| `useTenant` | **41** |
| `grantRole` | **38** |
| `userInTenant` | **32** |
| `warehousesAndProduct` | **11** |
| `product` | **6** |
| `stock` | **5** |
| `balance` | **5** |
| `customer` | **3** |
| `units` | **3** |

---

## 7. Resumen recomendaciones (priority order)

1. **Fix idempotency bug en `SyncCatalogOutboxService::eventKey`** (Q1, P7) — 1 hour, previene sync duplicación en producción.
2. **Extract `InteractsWithTenants` trait** (P1) — biggest test-quality win.
3. **Bearer-token coverage** para POS, Transfers, Sales (P2) — closes the most dangerous gap.
4. **Split `InventoryTransferService` y `SyncEventApplier`** (P8, P9) — biggest code-quality wins.
5. **Replace `app(TenantManager::class)` con constructor injection** (P10) — single mechanical refactor.
6. **Unit tests para `InventoryMovementService` y `SaleService::resolveLineDiscount`** (P3, P4).
7. **Enums/constants para stock-movement types, audit actions, role names, currency codes** (P11, P13, P15, P12).

Estimated total para **H** issues: 6-8 semanas (1 dev). Mechanical refactors (P1, P7, P10, P11, P13, P15) tienen ROI desproporcionado.
