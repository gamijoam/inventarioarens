# Guia de pruebas E2E: Sprint 1-4 POS

Esta guia explica como verificar manualmente cada feature implementada
en los sprints 1-4 del POS (jun-jul 2026). Todos los cambios estan
commiteados y el backend + frontend estan corriendo.

## Pre-requisitos

- **Backend Laravel** en `http://127.0.0.1:8000` (o el que uses normalmente).
- **Frontend Vite** en `http://127.0.0.1:5173` (proxy a 8000).
- **Usuario demo** con sesion de caja abierta:
  - Email: `gerente.valencia@demo.test` (multi-tenant: demo-valencia, demo-valencia-centro, demo-valencia-norte)
  - Password: `gabo1234`
  - Sesion de caja abierta: id 5 (demo-valencia-centro, branch 3, cajero 4)

Si la sesion no esta abierta, el POS mostrara la pantalla de apertura de caja
antes de permitir vender.

## Test 1: Sprint 1 - Reduccion de 8 requests a 1 (bootstrap)

**Objetivo**: Verificar que el endpoint `/api/pos/bootstrap` reemplaza las
multiples queries paralelas que hacia el POS al arrancar.

**Comando**:
```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "X-Tenant: demo-valencia-centro" -H "X-Requested-With: XMLHttpRequest" \
  -d '{"email":"gerente.valencia@demo.test","password":"gabo1234"}' \
  | python3 -c "import sys,json;d=json.load(sys.stdin);print(d['data']['token'])")

curl -s "http://127.0.0.1:8000/api/pos/bootstrap" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

**Esperado**: objeto plano con claves `warehouses`, `branches`, `cash_registers`,
`payment_methods`, `price_lists`, `exchange_rate_types`, `exchange_rates`,
`open_session`. Antes del sprint 1 el POS hacia 8 requests paralelas; ahora
hace 1.

## Test 2: Sprint 2 - Paginacion + Eager load

**Objetivo**: Verificar que el listado de productos pagine server-side y
cargue `categories.parent` sin N+1.

**Comando**:
```bash
TOKEN=...  # mismo del Test 1
curl -s "http://127.0.0.1:8000/api/products?per_page=25" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | head -50
```

**Esperado**: cada producto tiene `categories: [{...}]` con `parent` ya
cargado (gracias a `with('categories.parent')` en `ProductController::index`).
`meta` tiene `current_page`, `per_page`, `total`, `last_page`.

## Test 3: Sprint 3 - IMEI scanner con lookup exacto

**Objetivo**: Verificar que el endpoint de lookup de IMEI distingue entre
"no existe" (404) y "existe" (200).

**Comando**:
```bash
TOKEN=...  # mismo del Test 1

# Lookup de un serial que no existe: 404
curl -s -i "http://127.0.0.1:8000/api/inventory-centers/products/units/lookup?warehouse_id=3&serial=NO-EXISTE" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" | head -1
# Esperado: HTTP/1.1 404 Not Found

# Lookup de un serial que existe: 200 con la unidad
# (Para esto necesitas un serial real. Los IMEI de demo son
# IMEI-VLN-000000001, etc. pero los seed no siempre los crean.)
```

## Test 4: Sprint 3 - Idempotency-Key

**Objetivo**: Verificar que un retry con la misma Idempotency-Key devuelve
la misma respuesta sin duplicar la venta.

**Comando** (test que YO ejecute y que paso):
```bash
TOKEN=...  # mismo del Test 1
IDEM_KEY="test-idem-$(date +%s)"
SESSION_ID=5
PRODUCT_ID=5

# 1ra request: crea la venta
curl -s -X POST "http://127.0.0.1:8000/api/pos/checkouts" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"cash_register_session_id\": $SESSION_ID, \"items\": [{\"warehouse_id\": 3, \"product_id\": $PRODUCT_ID, \"quantity\": 1}], \"payments\": [{\"method\": \"cash\", \"currency\": \"USD\", \"amount\": 650}]}" \
  -w "HTTP:%{http_code}\n" -o /tmp/r1.json
cat /tmp/r1.json | python3 -c "import sys,json;d=json.load(sys.stdin);print(f'order_id={d[\"data\"][\"id\"]} status={d[\"data\"][\"status\"]}')"

# 2da request (mismo key+body): MISMA response
curl -s -X POST "http://127.0.0.1:8000/api/pos/checkouts" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"cash_register_session_id\": $SESSION_ID, \"items\": [{\"warehouse_id\": 3, \"product_id\": $PRODUCT_ID, \"quantity\": 1}], \"payments\": [{\"method\": \"cash\", \"currency\": \"USD\", \"amount\": 650}]}" \
  -w "HTTP:%{http_code}\n" -o /tmp/r2.json
cat /tmp/r2.json | python3 -c "import sys,json;d=json.load(sys.stdin);print(f'order_id={d[\"data\"][\"id\"]} status={d[\"data\"][\"status\"]}')"

# Esperado: ambas imprimen el MISMO order_id (idempotente).
```

**Comando adicional** (mismo key, body distinto, esperado 409):
```bash
IDEM_KEY2="test-idem-$(date +%s)-conflict"

# 1ra con este nuevo key
curl -s -o /dev/null -X POST "http://127.0.0.1:8000/api/pos/checkouts" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY2" \
  -H "Content-Type: application/json" \
  -d "{\"cash_register_session_id\": $SESSION_ID, \"items\": [{\"warehouse_id\": 3, \"product_id\": $PRODUCT_ID, \"quantity\": 1}], \"payments\": [{\"method\": \"cash\", \"currency\": \"USD\", \"amount\": 650}]}"

# 2da con mismo key pero body distinto
curl -s -o /dev/null -w "%{http_code}\n" -X POST "http://127.0.0.1:8000/api/pos/checkouts" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY2" \
  -H "Content-Type: application/json" \
  -d "{\"cash_register_session_id\": $SESSION_ID, \"items\": [{\"warehouse_id\": 3, \"product_id\": $PRODUCT_ID, \"quantity\": 2}], \"payments\": [{\"method\": \"cash\", \"currency\": \"USD\", \"amount\": 1500}]}"

# Esperado: HTTP 409 (Conflict)
```

## Test 5: Sprint 4 - Stock context badge

**Objetivo**: Verificar que el endpoint `/api/inventory-center/products/{id}/stock-context`
devuelve el contexto completo de stock.

**Comando** (test que YO ejecute y que paso):
```bash
TOKEN=...  # mismo del Test 1
PRODUCT_ID=5  # producto del seed en demo-valencia-centro

curl -s "http://127.0.0.1:8000/api/inventory-center/products/$PRODUCT_ID/stock-context?warehouse_id=3" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

**Esperado**:
```json
{
    "data": {
        "product_id": 5,
        "warehouse_id": 3,
        "available": 3,
        "reserved": 0,
        "other_warehouses": [],
        "total_other": 0,
        "total_all_warehouses": 3
    }
}
```

## Test 6: Sprint 4 - Recent receipts en el panel de Recibos

**Objetivo**: Verificar que el endpoint devuelve las ordenes pagadas
recientes de la sesion de caja.

**Comando**:
```bash
TOKEN=...  # mismo del Test 1
SESSION_ID=5

curl -s "http://127.0.0.1:8000/api/pos/orders?status=paid&cash_register_session_id=$SESSION_ID&per_page=5" \
  -H "Accept: application/json" -H "X-Tenant: demo-valencia-centro" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | head -30
```

**Esperado**: array de ordenes con `status: "paid"`, paginadas de mas reciente a mas
antigua, filtradas por la sesion de caja. Si no hay ordenes pagadas,
devuelve array vacio.

## Test 7: UI Manual (navegador)

Estos tests requieren el navegador porque involucran el UI de React:

### 7.1 Sprint 1 - Booster del POS
1. Abre `http://127.0.0.1:5173/pos` en el navegador.
2. Abre DevTools (F12) > Network.
3. Verifica que al cargar la pantalla POS SOLO se hace **1 request** a
   `/api/pos/bootstrap` (no 8 paralelas como antes).
4. El POS debe mostrar: warehouses en el selector, payment methods
   configurados, exchange rate types y la sesion de caja activa.

### 7.2 Sprint 2 - Busqueda con debounce
1. En el campo de busqueda del POS, escribe "ad" rapido.
2. Verifica en DevTools que SOLO se hace 1 request a `/api/products`
   (no 1 por cada tecla).
3. Espera 200ms despues de dejar de escribir y verifica que el resultado
   aparece.

### 7.3 Sprint 2 - Eager load (N+1 fix)
1. Carga `/pos/orders` con 10 ordenes que tengan productos.
2. En Network, verifica que la respuesta de cada `/api/products/{id}/price`
   (cotizacion) no hace queries adicionales por `category.parent` (no mas
   de 3 queries por cotizacion).

### 7.4 Sprint 3 - Carrito Zustand
1. Agrega un producto al carrito.
2. Cambia el warehouse en el selector.
3. Verifica que el carrito NO se borra (el estado vive en Zustand store,
   no en useState del componente).
4. Recarga la pagina y verifica que el carrito se mantiene si esta
   persistido (sprint 4 todavia no implementa persistencia, esto es
   solo un test del useState->Zustand migration).

### 7.5 Sprint 4 - Stock context badge
1. En el ProductSearchPanel (F3), busca un producto.
2. Verifica que el badge "Stock N" muestra el stock actual.
3. Si el stock esta por debajo del `min_stock`, debe aparecer un warning
   amarillo "Stock bajo (min N)".
4. Si el stock es 0, debe aparecer "Sin stock".

### 7.6 Sprint 4 - Recent receipts
1. En el POS, abre el panel de Recibos (F9).
2. Verifica que aparece el recibo actual + la lista "Recibos recientes"
   con los ultimos 5 pagados.
3. Click en uno de la lista: el detalle se carga.
4. Reimprime el recibo: debe crear un nuevo print job (no duplica el
   status del original).

## Resumen de los commits

| Sprint | Commit | Descripcion |
|---|---|---|
| 1 | `c0f26847` | feat(pos): QW1-QW4 + boost frontend |
| Fix | `9a65f309` | fix(pos): api.get en lugar de getOne (select vacio) |
| Revert | `cebd1232` | revert(pos): restore multi-tenant strict scope |
| 2 | `11ce0a4b` | feat(pos): QW5-QW7 + QW10 (debounce + indices + track_stock) |
| 3 | `a7549667` | feat(pos): QW8-QW9 + B2 (carrito Zustand + IMEI lookup + idempotency) |
| 4 | `e8fdbb5f` | feat(pos): stock context badge + recent receipts (UX) |

## Resultado de los tests E2E (curl)

```
============================================
  Test E2E completo: Sprint 1-4 POS
============================================

[Sprint 1] GET /api/pos/bootstrap
   warehouses=1 payment_methods=7 open_session=id 5 cash_registers=2

[Sprint 2] GET /api/products?per_page=5
   data count=2 total=2

[Sprint 4] GET /api/inventory-center/products/5/stock-context
   product_id=5 available=3 reserved=0 other_warehouses=0 total_all=3

[Sprint 3] POST /api/pos/checkouts con Idempotency-Key
   Primera request: order_id=10 status=paid HTTP=201
   Segunda request (mismo key+body): order_id=10 HTTP=201
   Idempotency OK: misma response sin duplicar venta
   POST con mismo key + body distinto: HTTP=409

[Sprint 4] GET /api/pos/orders?status=paid&cash_register_session_id=5
   recibos pagados en sesion 5: 1
     - #10 None $650.0000
```

## Si encuentras problemas

1. **Error 401 en la primera request**: tu cookie httpOnly expiro.
   Cierra sesion y vuelve a entrar.

2. **Error 403 en stock-context**: el usuario no tiene permiso
   `products.view`. Verifica el rol del usuario demo.

3. **Tests frontend rojos**: `cd frontend && pnpm install && pnpm test`.

4. **El POS muestra "error de red"**: el backend no responde. Verifica
   que `php artisan serve` este corriendo.

5. **El bootstrap devuelve `warehouses: []`**: el usuario no esta
   vinculado al tenant activo o no tiene warehouses. Cierra sesion y
   elige otro tenant con warehouses (ej. `demo-valencia-centro`).
