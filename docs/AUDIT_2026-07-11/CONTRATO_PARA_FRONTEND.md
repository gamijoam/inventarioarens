# Contrato de Backend para Frontend

> **Para:** IA / frontend developer que consume la API REST de INVENTARIOARENS.
> **Backend:** Laravel 13 + PostgreSQL 16, multi-tenant.
> **URL pública:** `https://app.miinventariofacil.com/api` (HTTPS, Let's Encrypt).
> **VPS:** `217.216.80.158`. NO confundir con MiInventarioFácil (otro SaaS en `212.28.176.157`).

---

## 1. Autenticación — Multi-tenant

### Login flow

1. `POST /api/auth/tenants` (público) con `{email}` → lista de tenants donde el user está `active`.
2. Usuario selecciona empresa → `POST /api/auth/login` con `X-Tenant: <slug>` + `{email, password, device_name}` → devuelve Bearer token + user + tenant + roles + permisos efectivos.
3. Cada llamada autenticada: `Authorization: Bearer <token>` + `X-Tenant: <slug>`.
4. `GET /api/auth/me` devuelve user + tenant + roles + permisos.
5. `POST /api/auth/switch-tenant` — cambia de tenant (devuelve nuevo token).
6. `POST /api/auth/logout` / `POST /api/auth/logout-all`.

### Headers requeridos en toda llamada autenticada

```
Authorization: Bearer eyJ0...
X-Tenant: demo-valencia
Content-Type: application/json
Accept: application/json
```

### Errores de auth

- `401` — Bearer missing, inválido, expirado o revocado.
- `403` — Token pertenece a otro tenant / user no es `active` en el tenant pedido.
- `404` — Tenant slug no existe.

---

## 2. Multi-tenancy

**Single PostgreSQL DB compartido.** Cada tabla de negocio tiene `tenant_id`. Los modelos usan global scope `TenantScope` que filtra automáticamente.

- Un usuario puede pertenecer a múltiples tenants.
- Mismo email puede tener roles distintos en distintas empresas.
- Un token de tenant A **no funciona** para tenant B (el middleware `tenant` valida).
- Headers del response incluyen `X-Tenant-Slug` para debugging.

---

## 3. Modelo de dinero — Doble cuenta USD/VES

**Moneda base = USD. Moneda operativa = VES.**

Cada movimiento monetario tiene snapshot del tipo de tasa y rate value:

```json
{
  "amount": "150.0000",          // en la currency del movimiento
  "amount_base": "150.0000",     // USD
  "amount_local": "90000.0000",  // VES (si rate fue 600)
  "exchange_rate_type_id": 2,
  "exchange_rate_type_code": "PARALELO",
  "exchange_rate": "600.000000"
}
```

### Tipos de tasa (por tenant)
- `BCV` (default, suele ser el oficial)
- `PARALELO` (mercado paralelo)
- `TIENDA`, `PROMOCIONAL`, etc. (custom por tenant)

### Conversión
- **USD a VES:** `amount_local = amount_base * exchange_rate`
- **VES a USD:** `amount_base = amount_local / exchange_rate`

### Snapshots históricos
**NUNCA recalcular historicos.** Cada fila guarda el rate congelado al momento de la transacción. Aunque cambien las tasas después, los montos históricos no se modifican.

---

## 4. Inventario — Por movimientos

**No existe `stock` como columna en `products`.** El stock se calcula desde `stock_movements`:

| Tipo | Efecto |
|---|---|
| `purchase`, `sale_return`, `transfer_in`, `adjustment_in` | `+quantity_available` |
| `sale`, `purchase_return`, `transfer_out`, `adjustment_out` | `-quantity_available` |
| `reserved` | `+quantity_reserved`, `-quantity_available` |
| `released` | `-quantity_reserved`, `+quantity_available` |
| `damaged` | `+quantity_damaged` |

### IMEIs / serializados

Productos con `tracking_type = 'serialized'` tienen filas individuales en `product_units`:

| Estado | Significado |
|---|---|
| `available` | Disponible para venta |
| `reserved` | En una orden POS pending o traslado en preparación |
| `sold` | Vendido (sale_items guarda product_unit_ids) |
| `damaged` | Dañado (no vendible) |
| `warranty_hold` | En proceso de garantía |
| `removed` | Removido del inventario (acepta pérdida en traslado) |

### Kardex

`GET /api/kardex/products/{product}?date_from=&date_to=&warehouse_id=` → historial cronológico de movimientos con running balance.

---

## 5. Permisos (95 totales)

Catálogo: `App\Support\Permissions\BasePermissions::PERMISSIONS`.

Convención de nombres: `<modulo>.<verbo>`. Ejemplos:

| Permiso | Significado |
|---|---|
| `pos.view`, `pos.checkout` | Ver POS, cobrar |
| `products.view`, `products.create`, `products.update` | Productos |
| `customers.view`, `customers.create`, `customers.update` | Clientes |
| `suppliers.*` | Proveedores |
| `purchases.view`, `purchases.create`, `purchases.receive`, `purchases.cancel` | Compras |
| `sales.view`, `sales.create`, `sales.confirm`, `sales.cancel` | Ventas |
| `cash_register.view`, `cash_register.open`, `cash_register.move`, `cash_register.close` | Caja |
| `accounts_receivable.view`, `accounts_receivable.collect` | CxC |
| `accounts_payable.view`, `accounts_payable.pay` | CxP |
| `inventory_transfers.view`, `inventory_transfers.create`, `inventory_transfers.prepare`, `inventory_transfers.dispatch`, `inventory_transfers.receive`, `inventory_transfers.cancel`, `inventory_transfers.resolve_differences`, `inventory_transfers.admin` | Traslados |
| `warranty_policies.view`, `warranty_policies.manage` | Garantías (policies) |
| `warranties.view`, `warranties.create`, `warranties.review`, `warranties.deliver`, `warranties.resolve` | Garantías (claims) |
| `reports.view`, `finance_reports.view` | Reportes |
| `users.create`, `users.update` | Usuarios |
| `roles.create`, `roles.update` | Roles |
| `currencies.view`, `currencies.manage` | Tasas |

### 5 roles predefinidos

| Rol | Permisos |
|---|---|
| `Owner` | Todos |
| `Administrador` | Todos |
| `Gerente` | Casi todos (excepto `users.*`, `roles.*`, `settings.manage`, `ai.configure`) |
| `Vendedor` | `sales.*`, `pos.*`, `cash_register.*` |
| `Almacen` | `inventory.*`, `inventory_transfers.*`, `purchases.*`, `product_entries.*`, `product_exits.*` |
| `Auditor` | Read-only en todo |

---

## 6. Estructura de Response

### Recurso único

```json
{
  "data": {
    "id": 123,
    "name": "Samsung A06",
    ...
  }
}
```

### Colección

```json
{
  "data": [...],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 25,
    "to": 25,
    "total": 117
  }
}
```

### Error de validación

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["El campo field_name es requerido."]
  }
}
```

### Error genérico

```json
{
  "message": "Recurso no encontrado."
}
```

---

## 7. Códigos de estado HTTP

| Code | Uso |
|---|---|
| 200 | OK |
| 201 | Created |
| 204 | No content (delete) |
| 400 | Bad request |
| 401 | Unauthorized |
| 403 | Forbidden (permission, tenant mismatch) |
| 404 | Not found |
| 409 | Conflict (state transition no permitida) |
| 422 | Validation error (FormRequest failed) |
| 500 | Server error (bug) |

---

## 8. Paginación

```http
GET /api/products?page=2&per_page=50
```

`per_page` máximo: 100. Default: 25.

---

## 9. Filtros y búsqueda

Patrón estándar:
- `?search=texto` — búsqueda full-text en campos relevantes.
- `?status=active|inactive|pending|...` — filtro por estado.
- `?date_from=2026-07-01&date_to=2026-07-31` — rangos de fecha.
- `?warehouse_id=5` — FK filter.
- `?with=relation1,relation2` — eager load de relaciones.

---

## 10. Endpoints clave por sección

### Auth (público)
- `POST /api/auth/tenants` — listar tenants del email.
- `POST /api/auth/login` — login.
- `GET /api/auth/me` — info sesión actual.
- `POST /api/auth/switch-tenant` — cambiar de tenant.
- `POST /api/auth/logout` — cerrar sesión.
- `POST /api/auth/logout-all` — cerrar todas las sesiones.

### Dashboard
- `GET /api/dashboard/summary` — KPIs del tenant.

### Productos
- `GET /api/products` — listado paginado.
- `POST /api/products` — crear.
- `GET /api/products/{id}` — ver detalle.
- `PATCH /api/products/{id}` — actualizar.
- `DELETE /api/products/{id}` — desactivar.
- `GET /api/products/{id}/price?price_list_id=N` — quote.
- `GET /api/products/{id}/price-history` — historial de precios.

### Listas de precios
- `GET/POST/PATCH/DELETE /api/price-lists` — CRUD.

### Clientes
- `GET/POST/PATCH/DELETE /api/customers` — CRUD.
- `GET /api/customers/{id}/pos-history` — historial POS.

### Proveedores
- `GET/POST/PATCH/DELETE /api/suppliers` — CRUD.

### Inventario (operacional directo)
- `POST /api/inventory/purchases` — registrar compra rápida.
- `POST /api/inventory/sales` — registrar venta rápida.
- `POST /api/inventory/adjustments/in` — ajuste entrada.
- `POST /api/inventory/adjustments/out` — ajuste salida.
- `POST /api/inventory/reservations` — reservar stock.
- `POST /api/inventory/releases` — liberar reserva.
- `POST /api/inventory/damages` — marcar dañado.
- `POST /api/inventory/transfers` — transferencia directa.

### Inventory Center
- `GET /api/inventory-center/summary` — métricas (total productos, stock bajo, etc.).
- `GET /api/inventory-center/export?format=csv` — exportar.
- `POST /api/inventory-center/products/bulk-action` — acción masiva.
- `GET /api/inventory-center/movements` — listado.
- `GET /api/inventory-center/products/{id}` — detalle con stock por almacén.
- `GET /api/inventory-center/products/{id}/serials` — IMEIs/seriales.
- `GET /api/inventory-center/products/{id}/movements` — kardex.
- `GET /api/inventory-center/products/{id}/audits` — auditoría del producto.

### Sucursales y almacenes
- `GET/POST/PATCH/DELETE /api/branches`
- `GET/POST/PATCH/DELETE /api/warehouses`

### Caja
- `GET/POST/PATCH /api/cash-register/registers` — CRUD cajas físicas.
- `GET /api/cash-register/sessions` — sesiones.
- `POST /api/cash-register/sessions` — abrir sesión.
- `GET /api/cash-register/sessions/{id}` — detalle.
- `POST /api/cash-register/sessions/{id}/movements` — movimiento manual.
- `PATCH /api/cash-register/sessions/{id}/close` — cerrar con cuadre.

### POS
- `GET /api/pos/orders` — órdenes POS.
- `GET /api/pos/orders/{id}` — detalle.
- `POST /api/pos/checkouts` — cobrar venta (el endpoint principal).
- `POST /api/pos/orders/{id}/payments` — agregar pagos (financiamiento).
- `POST /api/pos/orders/{id}/cancel` — cancelar orden abierta.

### Ventas
- `GET/POST /api/sales` — CRUD.
- `GET /api/sales/{id}` — detalle.
- `PATCH /api/sales/{id}/confirm` — confirmar venta draft.
- `PATCH /api/sales/{id}/cancel` — cancelar venta confirmada.

### Devoluciones
- `GET/POST /api/sales-returns` — devoluciones de clientes.
- `GET /api/sales-returns/{id}` — detalle.

### Compras
- `GET/POST /api/purchases` — órdenes de compra.
- `GET /api/purchases/{id}` — detalle.
- `PATCH /api/purchases/{id}/receive` — recibir compra (parcial o total).
- `PATCH /api/purchases/{id}/cancel` — cancelar.

### Devoluciones a proveedores
- `GET/POST /api/purchase-returns`.

### Cuentas por cobrar
- `GET /api/accounts-receivable` — listado.
- `GET /api/accounts-receivable/{id}` — detalle.
- `POST /api/accounts-receivable/{id}/payments` — cobrar.

### Cuentas por pagar
- `GET /api/accounts-payable` — listado.
- `GET /api/accounts-payable/{id}` — detalle.
- `POST /api/accounts-payable/{id}/payments` — pagar.

### Recibos de pago
- `GET /api/payment-receipts` — listado.
- `GET /api/payment-receipts/{id}` — detalle.
- `PATCH /api/payment-receipts/{id}/void` — anular (no reversa el pago, solo marca).

### Ajustes financieros
- `GET/POST /api/financial-adjustments`.

### Garantías
- `GET/POST/PATCH/DELETE /api/warranty-policies` — CRUD de policies.
- `GET/POST /api/warranty-claims` — claims.
- `GET /api/warranty-claims/{id}` — detalle.
- `PATCH /api/warranty-claims/{id}/review` — revisión.
- `PATCH /api/warranty-claims/{id}/deliver` — entrega.
- `PATCH /api/warranty-claims/{id}/resolve` — resolución (replacement/refund/rejected).

### Traslados logísticos
- `GET/POST /api/inventory-transfers` — CRUD.
- `GET /api/inventory-transfers/{id}` — detalle.
- `POST /api/inventory-transfers/{id}/prepare` — preparar.
- `POST /api/inventory-transfers/{id}/dispatch` — despachar.
- `POST /api/inventory-transfers/{id}/receive` — recibir.
- `POST /api/inventory-transfers/{id}/cancel` — cancelar.
- `POST /api/inventory-transfers/{id}/resolve-differences` — resolver diferencias.

### Traslados inter-company (entre empresas)
- `GET /api/inventory-transfer-requests` — listar (devuelve las que el tenant actual es origen O destino).
- `POST /api/inventory-transfer-requests` — crear solicitud (status inicial: `requested`).
- `GET /api/inventory-transfer-requests/{id}` — ver detalle (requiere que el tenant sea origen o destino).
- `POST /api/inventory-transfer-requests/{id}/accept` — aceptar (solo destino, mueve stock entre tenants).
- `POST /api/inventory-transfer-requests/{id}/reject` — rechazar (solo destino, sin movimiento de stock).
- `POST /api/inventory-transfer-requests/{id}/cancel` — cancelar (solo origen, sin movimiento de stock).

**Modelos de payload (crear / aceptar):**

`POST /api/inventory-transfer-requests`:
```json
{
  "destination_tenant_slug": "tenant-b",        // o destination_user_email (una u otra, required_without)
  "destination_user_email": "admin@b.com",     // alternativa: email de un usuario del tenant destino
  "from_warehouse_id": 5,
  "reason": "Traslado por venta especial",
  "reference": "PO-123",
  "notes": "Mover 5 unidades del modelo X",
  "items": [
    {
      "product_id": 10,
      "quantity": 5,
      "product_unit_ids": [42, 43, 44, 45, 46]   // solo si el producto es serialized (IMEI/serial)
    }
  ]
}
```

`POST /api/inventory-transfer-requests/{id}/accept`:
```json
{
  "destination_warehouse_id": 7,                // almacén destino en el tenant destino
  "response_notes": "Recibido OK",
  "items": [
    {
      "request_item_id": 12,                  // id del item en la solicitud original
      "destination_product_id": 22            // producto en el catálogo del tenant destino (puede ser otro id)
    },
    {
      "request_item_id": 13,
      "destination_product_id": 22
    }
  ]
}
```

**Reglas críticas del UI (para evitar que el usuario se confunda):**
- Un usuario NO puede transferir a su mismo tenant (request rechazada con 422).
- Solo el tenant **destino** ve los botones "Aceptar" / "Rechazar".
- Solo el tenant **origen** ve el botón "Cancelar".
- El catálogo de productos del destino es independiente: cada tenant tiene sus propios `product_id`. El sistema valida que el producto destino tenga el mismo `tracking_type` (serialized o quantity) que el origen.
- Los IMEIs/seriales se capturan como **snapshot** al crear la solicitud. Si el destino los acepta, se crean como nuevos `ProductUnit` con el mismo `serial_type`+`serial_number` en el tenant destino (si ya existe, error 422).

**Estados de la solicitud (4):**
- `requested` — creada por origen, esperando respuesta del destino
- `rejected` — destino la rechazó (sin movimiento de stock)
- `cancelled` — origen la canceló antes de que el destino responda
- `completed` — destino la aceptó (movimiento de stock entre tenants se ejecutó)

**Shape del resource (InventoryTransferRequestResource):**
```json
{
  "id": 42,
  "sequence": 1,
  "document_number": "TREQ-1-000001",
  "origin_tenant_id": 1,
  "destination_tenant_id": 2,
  "from_warehouse_id": 5,
  "destination_warehouse_id": 7,
  "status": "completed",
  "reason": "...",
  "reference": "...",
  "notes": "...",
  "response_notes": "...",
  "requested_by": 10,
  "responded_by": 22,
  "requested_at": "2026-07-11T10:00:00-04:00",
  "responded_at": "2026-07-11T14:30:00-04:00",
  "completed_at": "2026-07-11T14:30:00-04:00",
  "items": [
    {
      "id": 1,
      "origin_product_id": 10,
      "destination_product_id": 22,
      "quantity": "5.0000",
      "product_unit_ids": [42, 43, 44, 45, 46],
      "serial_units": [
        {"serial_type": "imei", "serial_number": "860001000001"},
        ...
      ],
      "out_stock_movement_id": 100,
      "in_stock_movement_id": 101
    }
  ]
}
```

### Kardex
- `GET /api/kardex/products/{id}?date_from=&date_to=&warehouse_id=` — historial.

### Reportes
- `GET /api/reports/stock` — stock por almacén.
- `GET /api/reports/stock/low` — productos con stock bajo.
- `GET /api/reports/movements` — movimientos de inventario.
- `GET /api/finance-reports/summary` — resumen financiero.
- `GET /api/finance-reports/receivables` — CxC detallado.
- `GET /api/finance-reports/payables` — CxP detallado.

### Portal Admin (cross-tenant)
- `GET /api/admin-portal/dashboard` — dashboard global.
- `GET /api/admin-portal/operational-reports` — reportes operativos.
- `GET /api/admin-portal/pos-sales` — ventas POS (todas las empresas).
- `GET /api/admin-portal/pos-sales/{id}` — detalle.
- `GET /api/admin-portal/transfers` — traslados (todas las empresas).
- `GET /api/admin-portal/transfers/summary` — KPIs.
- `GET /api/admin-portal/transfers/{id}` — detalle.
- `POST /api/admin-portal/transfers/{id}/prepare|dispatch|receive|cancel|resolve-differences`.

### Moneda / Tasas
- `GET/POST/PATCH/DELETE /api/currency/rate-types`.
- `GET/POST/PATCH/DELETE /api/currency/rates`.
- `GET /api/currency/rates/current`.
- `PATCH /api/currency/rates/{id}/activate|deactivate`.

### Métodos de pago
- `GET/POST/PATCH/DELETE /api/payment-methods`.

### Access Control
- `GET /api/access-control/permissions` — catálogo completo.
- `GET/POST/PATCH/DELETE /api/access-control/roles`.
- `PATCH /api/access-control/roles/{id}/permissions`.
- `GET/POST/PATCH /api/access-control/users`.
- `PATCH /api/access-control/users/{id}/status`.
- `PATCH /api/access-control/users/{id}/roles`.
- `GET /api/access-control/users/{id}/permissions`.

### Sync
- `POST /api/sync/tokens` — emitir token de sync (manager auth).
- `POST /api/sync/nodes` — registrar nodo.
- `POST /api/sync/events/push` — enviar eventos.
- `GET /api/sync/events/pull` — recibir eventos.
- `POST /api/sync/events/{event_uuid}/ack` — confirmar aplicación.
- `GET /api/sync/status` — estado del nodo.
- `GET /api/sync/local-readiness` — readiness local.
- `POST /api/sync/local-readiness` — marcar readiness.

---

## 11. Convenciones de fechas y números

- **Fechas:** ISO 8601 con timezone (`2026-07-11T14:30:00-04:00`).
- **Decimales:** siempre 4 dígitos para money, 6 para rates. Strings en JSON.
- **Bools:** `true`/`false` (no 1/0).
- **Nullables:** omitir la key o enviar `null`.

---

## 12. Errores comunes que el frontend debe manejar

| Error | Causa | Frontend debe |
|---|---|---|
| 401 + `token_hash` inválido | Token expirado o revocado | Redirigir a login |
| 403 + `Tu usuario no tiene permiso` | Falta permission | Mostrar mensaje + hide action |
| 403 + `Token does not belong to this tenant` | Token de otro tenant | Forzar switch-tenant |
| 422 con `errors.field` | Validación FormRequest failed | Highlight field con mensaje |
| 422 con `errors.items` (array) | Validación per-item | Highlight row con mensaje |
| 409 con state machine error | Transición no permitida | Refrescar vista + mensaje claro |
| 500 con `Server Error` | Bug del backend | Mostrar error genérico + log a soporte |

---

## 13. Datos demo para desarrollo

```bash
# 4 tenants demo (Caracas y Valencia, cada uno con 2 sucursales):
gerente.caracas@demo.test / password   → demo-caracas-este, demo-caracas-norte
gerente.valencia@demo.test / password  → demo-valencia-centro, demo-valencia-norte

# Cada tenant tiene:
#   1 branch + 1 warehouse + 2 cajas físicas + 2 productos + stock inicial
# Cajero: cajero.caracas@demo.test / password (rol Vendedor)
```

Comando para sembrar:
```bash
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
```

---

## 14. Webhook / eventos push (futuro)

Por ahora, **el frontend debe hacer polling**. WebSocket está planeado como acelerador pero polling sigue siendo la fuente confiable (15/30/60s).

Frecuencias recomendadas:
- Dashboard: 60s.
- POS: 15s (cuando está activo).
- Listados admin: 30s.
- Kardex: 60s.

Para "live updates", consultar `GET /api/sync/status` cada 30s y mostrar indicador visual.

---

## 15. Faltante en el API (P4 del roadmap)

1. **OpenAPI spec** — todavía no hay. El frontend debe mantener su propia documentación de tipos hasta que se implemente.
2. **Versionado `/api/v1`** — todavía no hay. Asumir `/api` por ahora.
3. **Idempotency keys** — todavía no hay. No implementar retry automático en POST hasta P3-5.
4. **Webhooks** — todavía no hay. Solo polling.

---

## 16. Cambios recientes que afectan al frontend

### 2026-07-11 — P0-1 + P0-2 (Backend hardening)

**Refund cap (P0-1):**
- El backend ahora rechaza refunds que excedan el monto realmente cobrado (post-descuento) por línea de venta.
- Antes era posible reembolsar más de lo pagado en ventas con descuento.
- Frontend debe mostrar el cap correcto al cajero: `sale_item.base_total_amount / sale_item.quantity * claim.quantity` (no `base_unit_price * claim.quantity`).

**Return totals en CxC (P0-2):**
- El `returned_base_amount` ahora refleja el precio descontado, no el precio de lista.
- Si frontend muestra balances de CxC, los valores ahora son menores (más precisos).
- Antes podía aparecer balance "fantasma" después de returns con descuento.

No hay breaking changes en los endpoints. Solo los valores son más correctos.
