# Auditoría API Design — 2026-07-11

**Score: 7 / 10**

RESTful + FormRequest + Resources bien aplicados. Faltan OpenAPI spec, versionado, rate limit headers, throttle middleware.

---

## 1. Estructura de rutas

- `routes/api.php` es thin aggregator que carga `routes.php` de cada módulo bajo `['api.auth', 'tenant']`.
- Todas las rutas prefijo `/api` automáticamente.
- 32 módulos exponen rutas.
- `routes/web.php` solo tiene `/` y `/admin`.

## 2. URL patterns observados

- `apiResource` (e.g., `Route::apiResource('products', ProductController::class)`) — RESTful estándar.
- Acciones custom fuera de `apiResource` (e.g., `GET /api/products/{product}/price`, `PATCH /api/inventory-transfers/{id}/prepare`).
- `GET /api/admin-portal/*` para vistas cross-tenant del admin.

## 3. HTTP status codes usados

| Code | Uso | Ejemplo |
|---|---|---|
| 200 | OK (resource, update) | GET, PATCH |
| 201 | Created | POST store |
| 204 | No content (delete) | DELETE |
| 401 | Unauthorized (api.auth) | Bearer missing/invalid |
| 403 | Forbidden (gate, tenant mismatch, ownership) | tenant A token en tenant B |
| 404 | Not found (route, tenant not found) | Slug inexistente |
| 422 | Validation error | FormRequest failed |
| 429 | (NO usado — sin throttle) | — |
| 500 | Server error | Bug no manejado |

## 4. Response shape

- Mayormente consistente: `{data: {...}, meta: {...}}` o `{data: [...]}` para colecciones.
- Paginación: Laravel estándar `?page=N` + `meta: {current_page, last_page, per_page, total}`.
- Errores: `{message: "...", errors: {field: ["..."]}}` (Laravel FormRequest default).

## 5. Validación

- FormRequest usado extensivamente (>50 archivos).
- `Rule::exists(...)->where('tenant_id', $tid)` en 40+ archivos (defensa 2).
- Inline validation mínimo.

## 6. Autorización

- 111 `Gate::authorize` calls.
- 9 `abort_unless($request->user()?->can(...))` calls.
- Policies registradas en `AppServiceProvider::boot()` (22 mappings).

## 7. Observado en samples

- `ProductController`, `PriceListController`, `InventoryTransferController`, `AdminTransfersController`, `AuthController` siguen patrones consistentes.
- Resource classes para serialización.
- `FormRequest` para validación.
- `Gate::authorize` en métodos críticos.

## 8. Issues

### Falta OpenAPI / Swagger spec
- No hay `darkaonline/l5-swagger`, `dedoc/scramble`, o similar en composer.json.
- Frontend AI necesita contrato machine-readable.

### Falta versionado `/api/v1/*`
- Cambios breaking rompen clientes sin política de deprecation.

### Sin rate limit middleware
- 0 `throttle:` en routes/api.php.
- Solo se puede confiar en nginx para rate limiting.

### Sin CORS configuration
- `config/cors.php` no existe.
- `fruitcake/php-cors` está en composer.lock pero nunca registrado.

### Sin rate limit headers
- Clientes no saben cuándo parar.

### Sin ETag / If-Match
- Clientes no pueden condicionar updates.

### Bulk endpoints faltan
- Para cargas iniciales de tenants nuevos, no hay `POST /api/bulk/products`.

### Falta idempotency keys
- POST mutaciones pueden causar duplicados en network retries.

## 9. Propuestas

| # | Propuesta | Esfuerzo |
|---|---|---|
| 1 | Agregar `darkaonline/l5-swagger` o `dedoc/scramble` para OpenAPI auto-generado | 1 día |
| 2 | Mover todo a `/api/v1/*` con alias `/api/*` deprecated | 2 días |
| 3 | `RateLimiter::for('api', ...)` global con 60 req/min por IP | 2 h |
| 4 | `config/cors.php` + register `fruitcake/php-cors` | 2 h |
| 5 | Idempotency middleware global (P3-5 del roadmap) | 1 día |
| 6 | ETag middleware + support `If-Match` | 1 día |
| 7 | Bulk endpoints `POST /api/v1/bulk/products`, etc. | 3 días |
| 8 | Search unificado `/api/v1/search?q=...&type=...` | 1 día |
| 9 | `/api/v1/health` con tenant + sync readiness + queue size | 1 día |
| 10 | Error envelope estándar explícito (Response macro) | 1 día |

## 10. Endpoints prioritarios para documentar

1. `/api/auth/*` (5 endpoints)
2. `/api/pos/*` (POS checkouts, orders)
3. `/api/cash-register/*` (registers, sessions)
4. `/api/inventory/*` (movimientos directos)
5. `/api/inventory-transfers/*` (logistics lifecycle)
6. `/api/inventory-transfer-requests/*` (inter-company)
7. `/api/sales/*` (sales lifecycle)
8. `/api/sales-returns/*`
9. `/api/purchases/*`
10. `/api/purchase-returns/*`
11. `/api/accounts-receivable/*`, `/api/accounts-payable/*`
12. `/api/payment-receipts/*`
13. `/api/financial-adjustments/*`
14. `/api/warranty-policies/*`, `/api/warranty-claims/*`
15. `/api/sync/*` (push, pull, ack, tokens, status)
16. `/api/admin-portal/*` (dashboard, transfers, POS sales, reports)
17. `/api/access-control/*` (permissions, roles, users)
18. `/api/dashboard/*`

Total: ~200 endpoints a documentar.
