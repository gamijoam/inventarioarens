# Auditoría Backend INVENTARIOARENS — Resumen Ejecutivo

**Fecha:** 2026-07-11
**Auditor:** opencode (PHP/Laravel specialist)
**Scope:** Backend completo (PHP/Laravel 13 / PostgreSQL 16). Read-only.
**Método:** 10 agentes en paralelo, cada uno con scope delimitado.

---

## 1. Veredicto global

**Score general del backend: 6.8 / 10**

El backend tiene **fundaciones sólidas** (multi-tenancy maduro, sync bidireccional funcional, money double-accounting riguroso, inventario por movimientos), pero tiene **6 bugs críticos de dinero / sync**, **cobertura de tests solo Bearer-token en 10/391 tests**, **cero caché / cero queue / cero observabilidad**, y **3 god-services (>900 líneas)** que necesitan descomposición antes de seguir creciendo.

Después de P0 + P1 (~23h de trabajo) el score sube a **8.0**.

---

## 2. Top scores por dimensión

| Dimensión | Score | Comentario |
|---|---:|---|
| Multi-tenancy | **8.5** | Trait + scope + middleware + FK compuestas + policies + tests cross-tenant. Solo falta `TenantTransferSetting` sin trait (dormant). |
| Auth base | 6.5 | Token + tenant binding correctos. Faltan throttle, password reset, headers de seguridad, CORS. |
| Sync engine | 6 | Outbox + dedup + snapshot funcionan. Faltan `payload_hash` verification, retry ceiling, structured logs. |
| Inventario + IMEI | 6 | Atómico + lock + composite FK. Faltan reservation TTL, append-only enforcement, reconciliación, índices fecha. |
| POS + Caja | 7 | Doble cuenta + snapshot + serializados. Bug crítico: USD `amount_local = NULL` rompe VES accounting. |
| Traslados | 7 | State machine principal OK. **3 estados fantasma** (`in_preparation`, `in_reception`, `rejected`). Race en `nextSequence()`. |
| CxC/CxP + Garantías | 6.5 | Pagos + snapshot OK. **2 bugs de dinero** por descuento ignorado (refund cap + return totals). |
| API design | 7 | RESTful + FormRequest + Resources. Faltan OpenAPI, versionado, rate limit, throttle. |
| Performance | 5.5 | 0 caché, 0 queue, 0 slow log. N+1 confirmado en `AdminTransferService::index()`. 10+ índices fecha faltantes. |
| Calidad de código | 6 | Convenciones respetadas. God-services en transfers/sync/POS. 145 `DB::table` raw. Service locator pervasive. |
| Cobertura de tests | 7 | 391 Feature tests / 23 módulos cubiertos. **0 tests unit** reales. Solo 10 tests con Bearer real. |

---

## 3. Bugs críticos que pierden plata o rompen sync

### C1. Refund cap ignora descuentos → cliente recibe más de lo pagado
- **Archivo:** `app/Modules/Warranties/Services/WarrantyClaimService.php:494-503`
- **Código actual:**
  ```php
  $maxRefundBase = round((float) $claim->saleItem->base_unit_price * (float) $claim->quantity, 4);
  ```
- **Impacto:** Item: `base_unit_price=100, qty=2, discount=50 → base_total=150`. Cliente pagó 150. Cap dice `200`. **Fuga de 50 USD por reembolso.**
- **Fix:** Usar `$saleItem->base_total_amount` (lo realmente cobrado).

### C2. Sales return totals ignoran descuentos → balance CxC queda mal
- **Archivo:** `app/Modules/AccountsReceivable/Services/AccountsReceivableService.php:197-228`
- **Código actual (línea 211):**
  ```php
  $returnedBase += round((float) $saleItem->base_unit_price * $quantity, 4);
  ```
- **Impacto:** Cliente devuelve 1 ítem con descuento, `returned_base=100` en vez de `75`. Balance de CxC queda en `+25` fantasma. Combinado con el clamp `max(0.0, …)` del recalc, el sistema lo traga silencioso.

### C3. Idempotencia rota en sync outbox
- **Archivo:** `app/Modules/Sync/Services/SyncCatalogOutboxService.php:332-340`
- **Código actual:**
  ```php
  $key = $eventType.':'.$aggregateType.':'.$aggregateId.':'.Str::uuid();  // ❌ uuid() en cada call
  ```
- **Impacto:** El `Str::uuid()` rompe el dedup. **Cada reintento de la misma operación se trata como evento nuevo.** También en `ExchangeRateController:138` y `ExchangeRateTypeController:107`.

### C4. Varios writers de catálogo NO envuelven business write + outbox en transacción
- **Archivos:**
  - `CustomerController.php:54-55, 77-79, 88-89`
  - `ProductController.php:57-61, 171-191, 215-221, 232-234`
  - `ExchangeRateTypeController.php:75-80, 89-90`
  - `ExchangeRateController.php:104-107, 116-118`
- **Impacto:** Si el INSERT del negocio commitea pero el INSERT del `sync_outbox` falla (deadlock, unique violation), el dato existe pero el evento no se emite nunca. **Divergencia permanente entre local y nube.**

### C5. USD payments guardan `amount_local = NULL` → rompe la doble cuenta
- **Archivo:** `app/Modules/POS/Services/PosCheckoutService.php:506-525` (`resolvePayment`)
- **Impacto:** Pagos en USD quedan con `amount_local=null`. `paid_local_amount` subreporta (línea 109 usa `?? 0.0`). `expected_local_amount` en caja excluye USD. AGENTS.md §8.5 viola su propia regla. **Reportes VES mienten.**

### C6. `payload_hash` se calcula y nunca se verifica
- **Archivos:** `SyncTransportService.php:85`, `SyncWorkerService.php:315` (escriben), 0 sitios que lean.
- **Impacto:** Detección de tamper inexistente. Si alguien mitm-edita el JSON, el receptor lo aplica sin chistar.

---

## 4. Issues HIGH (debilidades estructurales)

### H1. Tres estados fantasma en Traslados: `in_preparation`, `in_reception`, `rejected`
- **Archivo:** `InventoryTransfer.php:62,66,69`
- Existen como constantes pero **ningún endpoint ni transición los escribe**.
- `AdminTransferService:303-315` sugiere acciones para estados inalcanzables → botones muertos en el UI.
- Plan dice 10 estados, código tiene 7 reales. **Discrepancia docs↔código.**

### H2. Race condition real en `nextSequence()`
- **Archivo:** `InventoryTransferService.php:1250-1258`
- `SELECT … FOR UPDATE` solo lockea la fila del último sequence, no el rango a insertar.
- Dos requests concurrentes → ambos calculan N+1 → segundo INSERT viola UNIQUE → 500 feo.

### H3. `cancel()` de traslado puede liberar reservado DOS veces
- **Archivo:** `InventoryTransferService.php:617`
- Sin idempotency-key. Si la red parpadea y el cliente reintenta el POST `/cancel`, `release()` corre dos veces → `quantity_reserved` queda negativo → `ensureEnough` falla.

### H4. Bug en `validateReceivedProductUnits`: IMEI count vs received_quantity no se valida
- **Archivo:** `InventoryTransferService.php:1179-1226`
- Producto serializado, receive con `received_unit_ids = []` y `received_quantity = 3` → pasa silencioso → `stock_balance` += 3 pero `product_units` no se crean → **inconsistencia stock vs IMEIs reales**.

### H5. Bug en AR: PAID → PARTIAL flip silencioso en partial receipts
- **Archivo:** `AccountsPayableService.php:50-62`
- `createForPurchase` re-llena `original_base_amount` cada vez que llega un nuevo receipt. Si ya estaba PAID con un pago parcial, el nuevo receipt revierte el estado a PARTIAL y resetea `paid_at`. Sin audit.

### H6. Sin throttle / brute-force en login
- `POST /api/auth/login` y `POST /api/auth/tenants` no tienen rate limit.
- Email enumeration: respuesta diferente para email existente vs no (diferencia de tamaño + ~250ms de timing).

### H7. Cero logs estructurados en módulo Sync
- `grep "Log::|logger("` en `app/Modules/Sync/` → **0 matches**.
- Sin trazas de: eventos emitidos, eventos pushed, eventos pulled, applies que fallaron, payload_hash mismatch, retries.

### H8. `available_at` es código muerto
- Siempre `now()` en `SyncOutboxService:69,94` y `SyncInitialSnapshotService:421`. Nunca se setea a futuro.

### H9. Sin reservation TTL → reservas para siempre
- `PosOrder` con `external_financing` (pending) reserva stock sin expiry.
- Si el cliente abandona la app, IMEI queda `reserved` para siempre.
- `StockMovement` no tiene `expires_at`.

---

## 5. Issues MEDIUM (deuda técnica, calidad, perf)

Resumen de los 30 issues medium (ver detalle en cada documento):

- **Performance:** 0 caché, 0 queue, 0 slow log, N+1 en `AdminTransferService`, 10+ índices fecha faltantes, `CACHE_STORE=database` (Redis existe pero no se usa).
- **Calidad:** 145 llamadas `app(TenantManager::class)`, 6 controllers sin service, god-services en Transfers (1068 líneas), Sync (913), POS (754).
- **Tests:** 0 tests unit reales, solo 10/391 con Bearer token, helpers duplicados 32+ veces.
- **API:** Sin OpenAPI, sin versionado `/api/v1`, sin CORS, sin rate limit headers.
- **Sync:** Events faltantes (`sale.cancelled`, `cash.movement.created`), `pushLocalEvents` marca todos como processed aunque cloud rechace algunos, `applyPosOrder` rompe append-only.

---

## 6. Lo que está BIEN (no tocar)

- Multi-tenancy defense-in-depth (trait + scope + middleware + FK compuestas + policy + tests cross-tenant). 92 modelos con `BelongsToTenant` + 49 FKs compuestas.
- Money double-accounting con snapshot del rate en 84% de servicios que tocan dinero.
- `lockForUpdate` consistente en todas las rutas monetarias.
- Composite unique `['tenant_id', 'sku']` etc. en TODA tabla de negocio.
- Token + tenant cross-validation en login.
- SHA-256 hashed tokens, revocación estructurada.
- Idempotency en outbox con `UNIQUE(tenant_id, idempotency_key)`.
- Snapshot bootstrap para nodos nuevos.
- Anti-loop pull: excluye `origin_node_id = $nodeId`.
- Cross-tenant FKs a nivel DB.
- Validaciones `Rule::exists(...)->where('tenant_id', $tid)`.
- IMEI state machine completo (6 estados).
- Sesiones multi-cashier aisladas.
- `PerformanceProbe` aplicado en portal admin.
- `chunkById(200)` en sync snapshot.

---

## 7. Roadmap priorizado

Ver **[ROADMAP.md](ROADMAP.md)** para checklist tachable detallado.

```
SEMANA 1   → P0 (10h) + P1 (13h) = ~23h   = bloquea dinero + abre puerta a seguridad
SEMANA 2   → P2 (21h)                     = mejora 10x la latencia y visibilidad
SEMANA 3-4 → P3 (6 sem) pero priorizado   = god-services primero, debt después
SEMANA 5-6 → P4 (mes 2)                   = API DX + tests + OpenAPI
```

### Top 3 quick wins (cada uno < 1 hora)
1. **P0-1** (30 min): Fix refund cap.
2. **P0-2** (30 min): Fix return totals.
3. **P2-1** (30 min): Cambiar `CACHE_STORE=redis` en VPS.

---

## 8. Para que la IA del frontend consuma bien la API

Ver **[CONTRATO_PARA_FRONTEND.md](CONTRATO_PARA_FRONTEND.md)** para el detalle completo.

Resumen ejecutivo:
- Todos los endpoints autenticados requieren `Authorization: Bearer <token>` + `X-Tenant: <slug>`.
- Multi-tenancy via `tenant_id` global scope + Spatie permissions con `teams = tenant_id`.
- Money: cada monto tiene `*_base_amount` (USD) + `*_local_amount` (VES) + snapshot del rate.
- Paginación: usar `?page=` + `?per_page=` (estilo Laravel estándar).
- Errores: `{message, errors: {field: [...]}}` con HTTP status 422 para validación.
- Permisos: 95 permisos en `App\Support\Permissions\BasePermissions` con prefijo `<modulo>.<verbo>`.

---

## 9. Documentación que falta para el frontend

1. OpenAPI spec auto-generado (no existe).
2. `/api/v1/*` versionado (no existe).
3. Catálogo de códigos de error HTTP por módulo (no existe).
4. Tests E2E por sección (solo existen 5 specs del portal traslados).

---

## 10. Convención para tracking

Cuando un fix se completa:
1. Editar el item en `ROADMAP.md` cambiando `- [ ]` → `- [x]`.
2. Agregar la fecha y commit hash al lado: `- [x] P0-1 — 2026-07-11 — abc1234`.
3. Si se descubre un nuevo issue durante el fix, agregarlo al final del documento correspondiente con severidad.

Toda implementación debe seguir AGENTS.md §9.5 (disciplina de tests obligatoria).
