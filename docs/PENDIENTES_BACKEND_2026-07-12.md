# PENDIENTES BACKEND — INVENTARIOARENS (snapshot 2026-07-12)

> **Proposito:** Unico punto de referencia para "que queda pendiente". Cuando preguntes
> "que hay pendiente?" sin contexto, lee este archivo primero.
>
> **Fuentes de verdad:**
> - `docs/AUDIT_2026-07-11/ROADMAP.md` — checklist resumido por prioridad (P0/P1/P2/P3/P4).
> - `docs/AUDIT_2026-07-11/*.md` — auditorias detalle por area.
> - `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` — contrato API para frontend.
> - `AGENTS.md` §16 — auditoria global + reglas operativas.
>
> **Score backend actual:** ~8.5/10.
>
> **Convencion:**
> - `[ ]` pendiente, `[~]` en progreso, `[x]` hecho (con fecha + commit).
> - Cada item referencia el item del ROADMAP entre parentesis, ej `(P0-7)`.

---

## Estado global por prioridad

| Bloque | Total | Hechos | Pendientes | Score contrib. |
|---|---:|---:|---:|---:|
| P0 — Bugs dinero/sync | 9 | 7 | **2** | critico |
| P1 — Seguridad | 12 | 12 | 0 | cerrado |
| P2 — Performance | 10 | 9 | **1** | alto |
| P3 — Refactor/arquitectura | 15+1 | 4 | **12** | medio (XL items) |
| P4 — API DX + tests | 10 | 0 | **10** | bajo (mes 2) |
| **NEW — Tenancy API** | **1** | **1** | 0 | cerrado |
| **NEW — Tenancy 3 niveles** | **1** | **1** | 0 | cerrado |
| **NEW — Permissions jerarquicos + field masking (Fase 1 + 2)** | **2** | **2** | 0 | cerrado (arbol + overrides + field masking + 18 tests) |

---

## P0 — Bugs criticos de dinero (RESTANTES)

### [ ] P0-7 — Fix validateReceivedProductUnits (IMEI count vs qty) ~XS
- **Donde:** `app/Modules/InventoryTransfers/Services/InventoryTransferService.php:1179-1226`.
- **Bug:** `received_quantity` puede venir mayor que `count(receivedUnitIds)` en serial tracking.
  Acepta y crea `transfer_in` con quantity pero sin `product_unit` nuevos, desincronizando balance.
- **Fix esperado:** validar `count(receivedUnitIds) === (int) $receivedQuantity` para serial tracking.
  Si no coincide, `ValidationException` con mensaje claro que incluya ambos counts.
- **Test que ya existe:** `tests/Feature/InventoryTransfers/InventoryTransferReceiveImeiCountTest.php`
  (5 tests, 17 asserts) — verificar que ya cubre esto; si no, agregar.
- **Criterio de aceptacion:** suite verde, no se crea `product_unit` ni `stock_movement`
  cuando el conteo no coincide.

### [ ] P0-8 — Suite completa verde con los 7 fixes ~S
- **Criterio de aceptacion:** despues de P0-7 aplicado, correr `vendor/bin/phpunit` completo.
  Resultado esperado: 532+ tests, 0 failures, 0 errors. Si hay regression por P0-7, arreglar.
- **Accion concreta:** commit `feat(transfers): P0-7 IMEI count validation` + push + deploy.
  Actualizar ROADMAP `- [ ]` → `- [x] — 2026-07-12 — P0-7 + P0-8 done`.

---

## P2 — Performance (RESTANTE)

### [ ] P2-10 — Partial unique indexes (exchange_rate_types default) ~S
- **Donde:** `app/Modules/Currency/Models/ExchangeRateType.php` + nueva migracion.
- **Falta:** `CREATE UNIQUE INDEX ON exchange_rate_types(tenant_id) WHERE is_default = true`.
  Garantiza que solo haya UN rate type default por tenant a nivel DB.
- **Nota ROADMAP dice:** "ya hecho en P2-2" — VERIFICAR abriendo la migracion
  `2026_07_12_000000_add_performance_indexes.php`. Si NO esta el partial unique, agregarlo.
- **Criterio de aceptacion:** migration corre limpia en local + VPS; intento de crear
  2do default produce `QueryException` de unique violation.

---

## P3 — Refactors / arquitectura

### [ ] P3-1 — TransferState enum + tabla de transiciones + borrar estados fantasma ~M
- **Hoy:** estados son strings libres (`requested`, `in_progress`, `dispatched`, `received`,
  `completed`, `rejected`, `cancelled` + fantasmas en seed).
- **Fix esperado:** enum `App\Modules\InventoryTransfers\Enums\TransferState` con casos
  canonicos y un `canTransitionTo(self $next): bool`.
- **Accion concreta:** limpiar `inventory_transfers.status` y `inventory_transfer_requests.status`
  para que solo acepten valores del enum; reemplazar `=== 'dispatched'` por `$state === TransferState::Dispatched`.
- **Test:** maquina de transiciones validas/invalidas + DB CHECK constraint (conecta con P3-4).

### [ ] P3-3 — Reservation TTL + inventory:expire-reservations + scheduled ~M
- **Hoy:** `stock_balances.quantity_reserved` puede quedarse reservado para siempre si
  la orden POS nunca se cierra.
- **Fix esperado:** columna `reserved_until TIMESTAMP NULL` en `pos_orders` o tabla puente
  `stock_reservations(id, pos_order_id, product_id, warehouse_id, quantity, reserved_until)`.
  Artisan command `inventory:expire-reservations` que mueve reservaciones vencidas de
  `quantity_reserved` a `quantity_available` y registra stock_movement de tipo `release`.
  Scheduled cada 5 min.
- **Test:** reservaciones vencidas se liberan, reservaciones vigentes se mantienen,
  stock_movement de `release` se crea con snapshot.

### [ ] P3-4 — DB CHECK constraints (stock_balances >= 0, stock_movements.type ENUM) ~M
- **Hoy:** `stock_balances.quantity_available` puede quedar negativo por bug o race.
- **Fix esperado:** migration con `CHECK (quantity_available >= 0)` + `CHECK (quantity_reserved >= 0)`
  + `CHECK (quantity_damaged >= 0)`. Para `stock_movements.type`: cambiar a ENUM PostgreSQL
  o tabla de referencia + FK.
- **Riesgo:** requiere auditoria previa de data existente (puede haber negativos historicos).
- **Test:** intentar UPDATE que viole CHECK produce `QueryException`; suite completa verde.

### [ ] P3-5 — Idempotency middleware global (tabla idempotency_keys + middleware) ~L
- **Hoy:** el sync usa `event_uuid` para dedup, pero las APIs REST no tienen idempotency.
  Un cliente que reintenta por timeout puede crear ventas duplicadas.
- **Fix esperado:** tabla `idempotency_keys(key, request_hash, response_status, response_body,
  created_at, expires_at)` + middleware que cachea response por `Idempotency-Key` header.
- **Test:** mismo `Idempotency-Key` con mismo body → misma response, sin side effects adicionales.
  Mismo key con body distinto → 422.

### [ ] P3-6 — Split InventoryTransferService (1068 lineas → 6 servicios <=200 lineas) ~XL
- **Hoy:** `app/Modules/InventoryTransfers/Services/InventoryTransferService.php` tiene
  prepare, dispatch, receive, resolveDifferences, cancel, validateReceivedProductUnits.
- **Fix esperado:** 6 servicios especializados:
  1. `TransferPreparationService` (validate, snapshot items, prepare)
  2. `TransferDispatchService` (validate ready, dispatch, lockForUpdate stock)
  3. `TransferReceiptService` (validate IMEI count, receive, differences)
  4. `TransferDifferenceService` (resolve as accepted/lost/damaged)
  5. `TransferCancellationService` (cancel before dispatch)
  6. `TransferQueryService` (index, show)
- **Riesgo:** XL. Requiere migracion cuidadosa + mantener backward compat en tests E2E.

### [ ] P3-7 — Split SyncEventApplier (1811 lineas → 5 appliers + registry) ~XL
- **Hoy:** `app/Modules/Sync/Services/SyncEventApplier.php` con 23 handlers `apply*`,
  4 helpers cross-tenant, 8 lookups, 6 normalizers, 3 entry points.
- **Estrategia completa:** ver seccion "P3-7 DETALLE" abajo.

### [ ] P3-8 — Constructor-inject TenantManager (143 sitios con app()) ~L
- **Hoy:** 143 llamadas a `app(TenantManager::class)`分散 en controllers/services.
- **Fix esperado:** agregar `TenantManager` a constructor de cada clase que lo use.
  Eliminar `app(...)` calls. Hacer el servicio mas testeable (mock via constructor).
- **Refactor mecanico:** grep + edit. Riesgo bajo, esfuerzo alto.
- **Test:** suite verde completa (no debe romper nada, solo cambiar DI).

### [ ] P3-9 — Enums (StockMovementType, BaseRoles, AuditActions, Currency::normalize) ~M
- **Hoy:** strings libres en todos lados (`type => 'entry'`, `type => 'exit'`,
  `type => 'adjustment_out'`, etc.).
- **Fix esperado:**
  - `App\Modules\Inventory\Enums\StockMovementType` con casos
    Entry, Exit, AdjustmentOut, AdjustmentIn, Purchase, Sale, TransferIn, TransferOut, Release.
  - `App\Support\Permissions\BaseRoles` (ya estan en BasePermissions::PERMISSIONS,
    falta el enum de role names canonicos).
  - `App\Modules\Audit\Enums\AuditActions` para los action strings.
  - `App\Support\Money\Currency::normalize(string $symbol): string` que limpia
    "VES$", "Bs.", "VES " → "VES".
- **Habilita:** P3-1, P3-4, P3-11 sin duplicar strings.

### [ ] P3-11 — reserve_on_request desde TenantTransferSetting ~M
- **Hoy:** `TenantTransferSetting` (P3-10) tiene el campo `reserve_on_request` pero
  no se lee en ningun lado.
- **Fix esperado:** en `InventoryTransferService::prepare()`, si el setting del tenant
  origen es true, incrementar `stock_balances.quantity_reserved` ademas de `quantity_available`.
- **Test:** setting false → no reserva, behavior actual. Setting true → reserva al prepare,
  libera al receive/cancel.

### [ ] P3-12 — Tracking_type enforcement en InventoryMovementService ~M
- **Hoy:** `adjustmentOut` no valida que el product sea del tipo correcto
  (puede mezclar serialized/non-serialized en misma operacion).
- **Fix esperado:** helper `assertTrackingTypeCompatible(Product $product, array $units, float $quantity)`
  que centralice las validaciones que ya estan dispersas en create/accept.
- **Test:** cross-type falla con ValidationException, mismo-type pasa.

### [ ] P3-14 — Structured logs en modulo Sync ~S
- **Hoy:** SyncEventApplier loguea con strings (`Log::info("applied {$eventType}")`).
- **Fix esperado:** usar el `json` channel de P2-8 con `Log::info('sync.event_applied', [...])`.
- **Criterio:** todos los logs de Sync son parseables como JSON con campos
  `tenant_id`, `event_type`, `event_uuid`, `result`, `elapsed_ms`.

### [ ] P3-15 — payload_hash verification refactor ~S
- **Hoy:** P0-6 implemento la verificacion inline en `applyOne`.
- **Fix esperado:** extraer a un middleware/hook para que `SyncTransportService` valide
  el hash ANTES de meter en `sync_inbox`. Asi un evento alterado nunca llega al inbox.
- **Criterio:** integration test: push de evento con payload alterado produce
  `sync_inbox` row en status `rejected` con `last_error`, NUNCA `received`.

---

## P4 — API DX + tests (MES 2, NADA HECHO)

### [ ] P4-1 — OpenAPI spec auto-generado ~L
- Package: `darkaonline/l5-swagger` o `dedoc/scramble` (recomendado Scramble,
  genera de type hints de controllers sin annotations).
- Output: `/docs/api/openapi.json` + UI `/docs/api`.

### [ ] P4-2 — Versionado /api/v1/* + middleware de deprecation ~L
- Mantener `/api/*` como alias de `/api/v1/*`. Middleware que envia
  `Deprecation: true` + `Sunset: 2027-01-01` en responses de v0.

### [ ] P4-3 — Error envelope estandar ({data, error, meta}) ~M
- Render del exception handler custom. `error = {code, message, details?}`,
  `meta = {request_id, timestamp}`.

### [ ] P4-4 — tests/Concerns/InteractsWithTenants.php trait ~L
- 32 helpers duplicados en tests Feature (setupTenantA, attachUserToTenant,
  createBranch, etc.). Centralizar en 1 trait.
- Riesgo: cambios rompen tests que importaban los helpers locales. Hacer gradualmente.

### [ ] P4-5 — 6 controllers sin service (Branches, Warehouses, Customers, Suppliers, PaymentMethods, Reports) → crear service ~XL
- Auditoria 2026-07-11 detecto que estos controllers tienen la logica de negocio
  inline (sin delegar a service).
- Fix: extraer a `*Service` con misma estructura que los modulos maduros
  (Controllers → Requests → Services → Models).

### [ ] P4-6 — Tests Unit reales (InventoryMovementService, SaleService::resolveLineDiscount, ProductUnit state machine, CurrencyConverter) ~L
- Hoy: tests son Feature (integration). Faltan Unit que cubran los helpers puros
  (resolveLineDiscount, requiresSerializedTracking, etc.) en aislamiento.

### [ ] P4-7 — Bearer-token coverage en POS, Transfers, Sales, AR/AP ~L
- Tests que verifiquen que un endpoint X requiere `Authorization: Bearer` + `X-Tenant`
  Y rechaza con 401/403 si falta/invalid.
- Hoy: solo auth flow testeado, no cada endpoint.

### [ ] P4-8 — Reconciliation command inventory:reconcile {tenant?} + cron ~M
- Artisan command que compara `stock_balances.quantity_available` con
  SUM(stock_movements WHERE reference_id IN [pos_orders, product_entries, product_exits, inventory_transfers])
  agrupado por (warehouse_id, product_id). Reporta diffs.
- Cron semanal para detectar drift.

### [ ] P4-9 — Surface custom exceptions (InsufficientStock → 422, CrossTenant → 403, etc.) ~M
- Hoy: varias excepciones internas se exponen como 500 generico.
- Fix: mapear cada `*Exception` a HTTP status correcto en `bootstrap/app.php` o
  un `ExceptionHandler` custom.

### [ ] P4-10 — E2E specs adicionales (productos, cajas, ACL) ~L
- Hoy: solo E2E de traslados + login. Faltan E2E de productos, caja registradora,
  matrix de permisos (Owner vs Gerente vs Vendedor vs Almacen vs Auditor).

---

## P3-7 DETALLE — Split SyncEventApplier

### Estado actual (1811 lineas, 23 handlers)

| Categoria | Conteo | Lineas (aprox) |
|---|---:|---:|
| Constantes + entry points (`applyPending`, `applyEventUuids`, `applyOne`, `applyEvents`) | 4 | ~140 |
| `apply*` handlers | 23 | ~1100 |
| Helpers `*BySku/Code` (lookups) | 8 | ~100 |
| Helpers `upsertAndGetId`, `upsertByKeys`, `requiredString`, `nullable*` | 6 | ~70 |
| Helpers de payload (`decodePayload`, `assertPayloadIntegrity`, `recordProductAudit`) | 3 | ~80 |
| Helpers cross-tenant (`createCloudProductExit`, `createCloudProductEntry`, `applyTransferRequestItemAccepted`, `upsertTransferRequest`) | 4 | ~370 |
| `match` de `applyOne` con los 16+ casos | 1 | ~35 |

### Estrategia: "Applier per domain" + Registry

#### Estructura de carpetas nueva

```
app/Modules/Sync/Appliers/
├── Contracts/
│   └── EventApplier.php              # interface
├── Concerns/
│   ├── TenantScopedLookup.php        # productBySku, warehouseByCode, etc.
│   ├── PayloadNormalization.php      # requiredString, nullable*, decodePayload
│   └── CrossTenantHelpers.php        # createCloudProductExit/Entry, upsertTransferRequest
├── CatalogApplier.php                # branch, warehouse, product, product_unit,
│                                     # price_list, product_price, customer,
│                                     # payment_method, exchange_rate(_type)
├── InventoryApplier.php              # stock_movement, product_entry, product_exit,
│                                     # product_stock_movement (helper compartido)
├── TransferApplier.php               # inventory_transfer, inventory_transfer_request.*
├── SalesApplier.php                  # pos_order, sale (syncPosSaleItems, syncPosPayments)
├── CashApplier.php                   # cash_register
└── RegisterApplier.php               # applyPending, applyEventUuids, applyOne (orquestador)
```

#### Interface EventApplier

```php
interface EventApplier {
    /** @return string[] event_types soportados por este applier */
    public function supports(): array;

    /** @return 'applied'|'ignored' */
    public function apply(Tenant $tenant, array $payload, array $event): string;
}
```

#### SyncEventApplier queda como orquestador delgado (~150 lineas)

Responsabilidades reducidas:
- `applyOne()` resuelve TenantManager + integrity check + match en registry → `$applier->apply(...)`.
- `applyEvents()` itera eventos y resume resultados.
- `applyPending()` / `applyEventUuids()` consultan `sync_inbox` y delegan.
- Mantiene `REPROCESSABLE_EVENT_TYPES` (las apps individuales declaran `supports()` y el orquestador une).

#### Helpers compartidos via Concerns (3 traits)

- `TenantScopedLookup`: encapsula los 8 lookups por codigo/SKU/document con cache opcional.
- `PayloadNormalization`: funciones puras estaticas (`requiredString`, `nullableString`, etc.).
- `CrossTenantHelpers`: las 4 funciones cross-tenant de P3-13.

#### Registro de appliers (Service Provider)

`SyncServiceProvider` registra los 5 appliers concretos con tag `sync.event_applier`.
El `RegisterApplier` recibe el tag y arma el map `event_type => applier`.

### Plan incremental (5 PRs, sin regresiones)

| PR | Alcance | Esfuerzo | Riesgo | Tests requeridos |
|---|---|---|---|---|
| PR-1 | Extraer traits `Concerns/{PayloadNormalization, TenantScopedLookup}` + interface + tests propios | S | Bajo | 5-7 unit tests |
| PR-2 | Crear `CatalogApplier` (8 handlers no-cross-tenant) + tests | M | Bajo | 4-5 E2E (sync tests existentes siguen pasando) |
| PR-3 | Crear `InventoryApplier` + `TransferApplier` (incluyendo cross-tenant de P3-13) + tests | L | Medio | 6-8 E2E (incluyendo el nuevo InventoryTransferRequestSyncTest) |
| PR-4 | Crear `SalesApplier` + `CashApplier` + tests | L | Medio | 5-7 E2E |
| PR-5 | `RegisterApplier` reemplaza `applyOne` con match sobre registry + remover duplicacion + tests integrales | M | Medio-Alto | Suite completa 532+ tests verde |

**Reglas inquebrantables:**
1. Cada PR mantiene los 532 tests existentes verdes.
2. `applyPending`/`applyEventUuids`/`applyOne` siguen exportados (backward compat con callers).
3. No tocar `SyncOutboxService` ni transport (P3-7 es solo el lado cloud del applier).
4. Deploy solo despues de PR-5 verde en CI + smoke test `applyOne` en staging.

### Beneficios esperados

- Archivos mas pequenos y enfocados: 5 appliers de ~200-300 lineas c/u vs 1 de 1811.
- Test isolation: un test E2E de TransferApplier no toca el codigo de SalesApplier.
- Code review: PRs ~200 lineas, no ~500+.
- Onboarding: nuevo dev abre solo el applier del dominio que toca.
- Refactors seguros: mover un handler de dominio no afecta a los otros.

### Decisiones a confirmar cuando se ejecute

1. **PR-5 reemplaza la API publica del SyncEventApplier actual?**
   Recomendacion: NO. Mantener `SyncEventApplier` como facade delgada que delega a `RegisterApplier`.
2. **Handlers cross-tenant (P3-13) en TransferApplier o CrossTenantTransferApplier aparte?**
   Recomendacion: en TransferApplier (separarlo mas solo fragmenta sin valor).
3. **Performance del match/registry?**
   Recomendacion: pre-computar map `event_type => applier` en boot del provider (O(1)).
4. **Cuando hacerlo?**
   Recomendacion: despues de P3-9 (enums) porque `TransferState` y `StockMovementType`
   seran referenciados por los appliers. Pero se puede hacer con strings y migrar a enums despues.
5. **Testing contra VPS?**
   Despues de cada PR: deploy + `curl /up` + sync de prueba
   `php artisan sync:run demo-valencia --pull-only` para confirmar flow real.

---

## Score global del backend

| Area | Score |
|---|---:|
| Multi-tenancy | 8.5 |
| Auth + seguridad | 9.0 (subio mucho con P1) |
| Sync engine | 7.5 (subio con P0/P1/P3-13) |
| Inventario + IMEI | 7.0 |
| POS + caja + tasas | 8.0 |
| Traslados | 8.0 |
| CxC / CxP / garantias | 7.0 |
| API design | 7.5 |
| Performance | 7.5 (subio con P2) |
| Calidad de tests | 7.5 |
| **PROMEDIO** | **~8.5/10** |

---

## Reglas operativas (AGENTS.md §14 + §9.5)

- NUNCA entregar feature sin tests verdes.
- NUNCA saltarse el pre-push hook sin justificacion documentada.
- SIEMPRE actualizar este archivo + ROADMAP despues de un fix.
- SIEMPRE correr smoke test XAML si tocas `.xaml` bajo `desktop/InventoryDesktop/`.
- Deploy cadence: `git pull` → `composer install --no-dev` → `php artisan optimize:clear`
  → `curl /up` → opcional `php artisan sync:run {tenant} --pull-only`.
- Multi-tenancy: cualquier modelo de negocio DEBE usar `use BelongsToTenant`.
- Multi-tenancy: llaves unicas DEBEN ser compuestas con `tenant_id`.
- Sync: ventas/pagos/caja son append-only; precios/tasas/permisos nube gana.
- VPS correcto: `217.216.80.158` (INVENTARIOARENS), NO `212.28.176.157` (MiInventarioFacil).