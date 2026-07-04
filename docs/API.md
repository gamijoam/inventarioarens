# Catalogo de APIs

Todas las rutas actuales usan el prefijo global de Laravel:

```txt
/api
```

Las rutas de autenticacion publicas se indican en su seccion. El resto de rutas actuales requiere:

- usuario autenticado;
- token `Bearer` valido o usuario autenticado en pruebas;
- tenant resuelto;
- header recomendado: `X-Tenant: <slug-del-tenant>`;
- pertenencia activa del usuario al tenant.

## Autenticacion

Modulo: `Auth`

Archivo de rutas:

```txt
app/Modules/Auth/routes.php
```

Controller:

```txt
App\Modules\Auth\Controllers\AuthController
```

### Consultar empresas disponibles para login

```txt
POST /api/auth/tenants
```

Body:

```json
{
  "email": "usuario@example.test",
  "password": "password-seguro"
}
```

Reglas:

- no requiere `X-Tenant`;
- valida credenciales;
- devuelve solo empresas donde el usuario esta activo;
- sirve para que el frontend permita escoger empresa cuando el usuario pertenece a varias.

### Iniciar sesion

```txt
POST /api/auth/login
```

Requiere:

```txt
X-Tenant: <slug-del-tenant>
```

Body:

```json
{
  "email": "usuario@example.test",
  "password": "password-seguro",
  "device_name": "navegador"
}
```

Reglas:

- valida correo y clave;
- valida que el usuario pertenezca activamente al tenant enviado;
- crea un token de API asociado a ese tenant;
- el token se guarda hasheado en `auth_tokens`, no en texto plano;
- el token vence inicialmente a los 30 dias;
- el frontend debe enviar `Authorization: Bearer <token>` en llamadas protegidas;

## Dashboard

Modulo: `Dashboard`

Archivo de rutas:

```txt
app/Modules/Dashboard/routes.php
```

Controller:

```txt
App\Modules\Dashboard\Controllers\DashboardController
```

### Resumen del negocio

```txt
GET /api/dashboard/summary
```

Permisos aceptados:

```txt
finance_reports.view
reports.view
sales.view
pos.view
products.view
cash_register.view
```

Query params:

```txt
period=today|week|month
date_from=YYYY-MM-DD
date_to=YYYY-MM-DD
low_stock_threshold=3
```

Respuesta:

```json
{
  "data": {
    "currency": "USD",
    "period": {
      "from": "2026-07-03",
      "to": "2026-07-03"
    },
    "sales": {
      "confirmed_count": 3,
      "total_base_amount": 270
    },
    "pos": {
      "paid_orders_count": 1,
      "paid_base_amount": 95
    },
    "cash_register": {
      "open_sessions_count": 1
    },
    "inventory": {
      "low_stock_count": 1,
      "low_stock_threshold": 3,
      "low_stock_items": []
    },
    "finance": {
      "accounts_receivable_balance_base_amount": 120,
      "accounts_payable_balance_base_amount": 45,
      "accounts_receivable_count": 1,
      "accounts_payable_count": 1
    }
  }
}
```

Reglas:

- requiere `api.auth` y `tenant`;
- no modifica datos;
- usa consultas agregadas para sumas y conteos;
- la lista de bajo stock se limita a 5 items;
- evita cargar colecciones grandes para reducir riesgo de N+1 en la portada.
- un token emitido para una empresa no puede usarse con el `X-Tenant` de otra empresa.

## Centro de Inventario

Modulo: `InventoryCenter`

Archivo de rutas:

```txt
app/Modules/InventoryCenter/routes.php
```

Controller:

```txt
App\Modules\InventoryCenter\Controllers\InventoryCenterController
```

### Resumen del centro de inventario

```txt
GET /api/inventory-center/summary
```

Permiso requerido:

```txt
products.view o inventory.view
```

Query params:

```txt
search=Samsung
tracking_type=quantity|serialized
stock_status=all|available|low|out
low_stock_threshold=3
limit=24
page=1
```

Respuesta:

```json
{
  "data": {
    "filters": {
      "search": "Samsung",
      "tracking_type": null,
      "stock_status": "all",
      "low_stock_threshold": 3,
      "limit": 24,
      "page": 1
    },
    "metrics": {
      "total_products": 3,
      "serialized_products": 1,
      "quantity_products": 2,
      "available_quantity": 17,
      "reserved_quantity": 2,
      "damaged_quantity": 1,
      "low_stock_count": 1,
      "without_stock_count": 1
    },
    "alerts": [
      {
        "type": "without_base_price",
        "severity": "danger",
        "title": "Sin precio base",
        "count": 2,
        "message": "Productos sin precio base configurado.",
        "action": "Asignar precio antes de vender en POS.",
        "product_names": ["Samsung A06", "Cargador 25W"]
      }
    ],
    "products": [
      {
        "id": 1,
        "name": "Samsung A06",
        "sku": "A06-001",
        "tracking_type": "quantity",
        "base_price": 120,
        "sale_currency": "USD",
        "stock": {
          "available": 5,
          "reserved": 2,
          "damaged": 0,
          "status": "available"
        }
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 24,
      "total": 120,
      "last_page": 5,
      "from": 1,
      "to": 24,
      "has_previous": false,
      "has_next": true
    }
  }
}
```

Reglas:

- requiere `api.auth` y `tenant`;
- no modifica datos;
- usa `stock_balances` como lectura rapida y productos activos como catalogo;
- agrega stock por producto en base de datos antes de responder;
- devuelve `alerts` con alertas operativas agregadas para productos incompletos o que requieren atencion;
- las alertas iniciales cubren stock bajo, sin stock, sin precio base, sin garantia y listas de precio incompletas;
- no consulta almacenes uno por uno desde el frontend;
- limita el listado a maximo 50 productos por llamada;
- devuelve metadatos de paginacion para navegar inventarios grandes;
- permite filtrar productos por `tracking_type`, para separar serializados de productos por cantidad;
- respeta tenant y no mezcla productos ni saldos entre empresas.

### Exportación del centro de inventario

```txt
GET /api/inventory-center/export
```

Permiso requerido:

```txt
products.view o inventory.view
```

Query params:

```txt
search=Samsung
tracking_type=quantity|serialized
stock_status=all|available|low|out
low_stock_threshold=3
```

Respuesta:

```txt
text/csv; charset=UTF-8
```

Columnas del CSV:

```txt
Producto;SKU;Tipo de control;Moneda;Precio base;Disponible;Reservado;Dañado;Estado de stock
```

Reglas:

- requiere `api.auth` y `tenant`;
- usa los mismos filtros del resumen del Centro de Inventario;
- exporta productos activos del tenant actual;
- no pagina el resultado porque genera un archivo de trabajo;
- usa separador `;` para facilitar apertura en hojas de cálculo en español;
- no modifica datos ni genera movimientos de inventario.

### Acciones masivas de productos

```txt
POST /api/inventory-center/products/bulk-action
```

Permiso requerido:

```txt
products.update
```

Body:

```json
{
  "product_ids": [1, 2, 3],
  "action": "assign_warranty_policy",
  "payload": {
    "warranty_policy_id": 5
  }
}
```

Acciones soportadas:

```txt
activate
deactivate
assign_warranty_policy
assign_exchange_rate_type
fill_missing_price_list
update_price_list
```

Payload por acción:

- `activate`: no requiere `payload`;
- `deactivate`: no requiere `payload`;
- `assign_warranty_policy`: requiere `payload.warranty_policy_id`;
- `assign_exchange_rate_type`: requiere `payload.sale_exchange_rate_type_id`;
- `fill_missing_price_list`: requiere `payload.price_list_id`, `payload.strategy` y `payload.currency`;
- `update_price_list`: requiere `payload.price_list_id`, `payload.strategy` y `payload.currency`.

Estrategias para `fill_missing_price_list` y `update_price_list`:

- `base_price`: copia el precio base del producto en la lista seleccionada;
- `fixed_price`: usa el monto enviado en `payload.price`;
- `percent_over_base`: calcula el precio usando `payload.percent` sobre el precio base.

Ejemplo para completar precios faltantes en una lista:

```json
{
  "product_ids": [1, 2, 3],
  "action": "fill_missing_price_list",
  "payload": {
    "price_list_id": 2,
    "strategy": "percent_over_base",
    "percent": 20,
    "currency": "USD"
  }
}
```

Ejemplo para actualizar o crear precios de una lista:

```json
{
  "product_ids": [1, 2, 3],
  "action": "update_price_list",
  "payload": {
    "price_list_id": 2,
    "strategy": "fixed_price",
    "price": 25,
    "currency": "USD"
  }
}
```

Respuesta:

```json
{
  "data": {
    "action": "assign_warranty_policy",
    "requested_count": 3,
    "updated_count": 2,
    "skipped_count": 1,
    "updated": [
      {"id": 1, "name": "Samsung A06", "sku": "A06-001"}
    ],
    "skipped": [
      {"id": 3, "name": "Cable USB", "reason": "Sin cambios necesarios."}
    ]
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y permiso `products.update`;
- acepta hasta 200 productos por solicitud;
- todos los `product_ids` deben pertenecer al tenant actual;
- la garantía o tasa seleccionada debe pertenecer al tenant actual;
- la lista de precio seleccionada debe pertenecer al tenant actual;
- `fill_missing_price_list` solo crea precios faltantes y no sobrescribe precios ya existentes del producto en esa lista;
- `update_price_list` crea el precio si no existe y actualiza el precio si ya existe;
- si una estrategia necesita precio base y el producto no lo tiene, ese producto se omite con motivo visible;
- cada producto modificado genera un registro en `product_audits`;
- las acciones se ejecutan en transacción y bloquean los productos seleccionados durante la actualización.

### Detalle de producto en Centro de Inventario

```txt
GET /api/inventory-center/products/{product}
```

Permiso requerido:

```txt
products.view
```

Respuesta:

```json
{
  "data": {
    "product": {
      "id": 1,
      "name": "Samsung A06",
      "sku": "A06-001",
      "tracking_type": "serialized",
      "base_price": 120,
      "sale_currency": "USD",
      "sale_exchange_rate_type": null,
      "warranty_policy": null,
      "is_active": true
    },
    "stock": {
      "totals": {
        "available": 3,
        "reserved": 1,
        "damaged": 1
      },
      "by_warehouse": [
        {
          "warehouse_id": 1,
          "warehouse_name": "Tienda",
          "warehouse_code": "STORE",
          "branch_name": "Principal",
          "available": 2,
          "reserved": 1,
          "damaged": 0
        }
      ]
    },
    "serials": {
      "total": 2,
      "items": [
        {
          "id": 1,
          "serial_type": "imei",
          "serial_number": "860001000000001",
          "status": "available",
          "warehouse_name": "Tienda"
        }
      ]
    },
    "recent_movements": [
      {
        "id": 1,
        "type": "purchase",
        "quantity": 3,
        "reason": "Entrada inicial",
        "warehouse_name": "Tienda",
        "created_by_name": "Gerente"
      }
    ]
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y permiso `products.view`;
- respeta la policy de productos para no permitir productos de otra empresa;
- agrega stock por almacen desde `stock_balances`;
- limita seriales/IMEIs a 50 registros para no cargar listas masivas;
- limita movimientos recientes a 10 registros;
- sirve para inspeccionar un producto sin modificar inventario.

### Seriales/IMEI paginados de producto

```txt
GET /api/inventory-center/products/{product}/serials
```

Permiso requerido:

```txt
products.view
```

Query params:

```txt
search=860001
status=all|available|reserved|sold|damaged|removed|warranty_hold
warehouse_id=1
limit=24
page=1
```

Respuesta:

```json
{
  "data": {
    "filters": {
      "search": "860001",
      "status": "available",
      "warehouse_id": 1,
      "limit": 24,
      "page": 1
    },
    "data": [
      {
        "id": 10,
        "serial_type": "imei",
        "serial_number": "860001000000001",
        "status": "available",
        "warehouse_id": 1,
        "warehouse_name": "Tienda"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 24,
      "total": 80,
      "last_page": 4,
      "from": 1,
      "to": 24,
      "has_previous": false,
      "has_next": true
    }
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y policy de producto;
- no modifica datos;
- evita cargar todos los IMEI de un producto masivo en una sola respuesta;
- permite buscar por serial e IMEI;
- permite filtrar por estado y almacen;
- no devuelve seriales de otra empresa.

### Movimientos paginados de producto

```txt
GET /api/inventory-center/products/{product}/movements
```

Permiso requerido:

```txt
products.view
```

Query params:

```txt
search=Entrada
type=all|purchase|purchase_return|sale|sale_return|adjustment_in|adjustment_out|transfer_in|transfer_out|return_in|return_out|damaged|reserved|released
warehouse_id=1
date_from=2026-07-01
date_to=2026-07-04
limit=24
page=1
```

Respuesta:

```json
{
  "data": {
    "filters": {
      "search": "Entrada",
      "type": "purchase",
      "warehouse_id": 1,
      "date_from": "2026-07-01",
      "date_to": "2026-07-04",
      "limit": 24,
      "page": 1
    },
    "data": [
      {
        "id": 20,
        "type": "purchase",
        "quantity": 3,
        "unit_cost": 80,
        "reason": "Entrada inicial",
        "reference_type": null,
        "reference_id": null,
        "warehouse_id": 1,
        "warehouse_name": "Tienda",
        "created_by": 5,
        "created_by_name": "Gerente",
        "created_at": "2026-07-04T12:00:00.000000Z"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 24,
      "total": 120,
      "last_page": 5,
      "from": 1,
      "to": 24,
      "has_previous": false,
      "has_next": true
    }
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y policy de producto;
- no modifica datos;
- permite filtrar historial grande sin cargar todo el Kardex en memoria;
- busca por motivo o tipo de referencia;
- no devuelve movimientos de otra empresa.

### Stock de producto por almacen

```txt
GET /api/inventory-center/products/{product}/stock-by-warehouse
```

Permisos aceptados:

```txt
products.view
inventory.view
```

Respuesta:

```json
{
  "data": {
    "data": [
      {
        "warehouse_id": 1,
        "warehouse_name": "Tienda",
        "warehouse_code": "STORE",
        "branch_id": 1,
        "branch_name": "Principal",
        "available": 2,
        "reserved": 1,
        "damaged": 0
      }
    ]
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y policy de producto;
- no modifica datos;
- lee desde `stock_balances`;
- no devuelve saldos de almacenes o productos de otra empresa.

### Auditoria paginada de producto

```txt
GET /api/inventory-center/products/{product}/audits
```

Permiso requerido:

```txt
products.view
```

Query params:

```txt
search=gerente@empresa.com
action=all|created|updated|deactivated
limit=24
page=1
```

Respuesta:

```json
{
  "data": {
    "filters": {
      "search": "gerente@empresa.com",
      "action": "updated",
      "limit": 24,
      "page": 1
    },
    "data": [
      {
        "id": 50,
        "action": "updated",
        "changes": {
          "before": {
            "base_price": 10
          },
          "after": {
            "base_price": 12
          }
        },
        "created_by": 5,
        "created_by_name": "Gerente",
        "created_by_email": "gerente@empresa.com",
        "created_at": "2026-07-04T12:00:00.000000Z"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 24,
      "total": 80,
      "last_page": 4,
      "from": 1,
      "to": 24,
      "has_previous": false,
      "has_next": true
    }
  }
}
```

Reglas:

- requiere `api.auth`, `tenant` y policy de producto;
- no modifica datos;
- permite filtrar por accion de auditoria;
- permite buscar por nombre o correo del usuario que hizo el cambio;
- si la tabla `product_audits` no existe, responde lista vacia para no bloquear el detalle;
- no devuelve auditoria de productos de otra empresa.

### Ver sesion actual

```txt
GET /api/auth/me
```

Requiere token `Bearer` y `X-Tenant`.

Respuesta:

- usuario actual;
- empresa actual;
- roles en la empresa actual;
- permisos efectivos en la empresa actual.

### Cerrar sesion actual

```txt
POST /api/auth/logout
```

Regla:

- revoca solo el token usado en la peticion.

### Cerrar todas las sesiones de la empresa actual

```txt
POST /api/auth/logout-all
```

Regla:

- revoca todos los tokens activos del usuario dentro de la empresa actual;
- no revoca tokens del mismo usuario en otras empresas.

## Productos

Archivo de rutas:

```txt
app/Modules/Products/routes.php
```

Controller:

```txt
App\Modules\Products\Controllers\ProductController
```

### Listar productos

```txt
GET /api/products
```

Permiso requerido:

```txt
products.view
```

### Crear producto

```txt
POST /api/products
```

Permiso requerido:

```txt
products.create
```

Body:

```json
{
  "name": "Samsung A06",
  "sku": "SAMSUNG-A06",
  "tracking_type": "serialized",
  "base_price": 100,
  "sale_currency": "VES",
  "sale_exchange_rate_type_id": 2,
  "warranty_policy_id": 1,
  "is_active": true
}
```

Reglas:

- `sku` es unico dentro de la empresa actual;
- `tracking_type` puede ser `quantity` o `serialized`;
- si no se envia `tracking_type`, el producto queda como `quantity`;
- los productos con `serialized` pueden tener unidades en `product_units` con IMEI o serial;
- si un producto ya tiene unidades serializadas, no se puede cambiar su `tracking_type`.
- `base_price` es el precio base interno en `USD`;
- `sale_currency` puede ser `USD` o `VES`;
- `sale_exchange_rate_type_id` permite asignar una tasa sugerida, por ejemplo `BCV` o `PARALELO`.
- `warranty_policy_id` permite asignar una politica de garantia de la empresa actual.
- la respuesta puede incluir `sale_exchange_rate_type` y `warranty_policy` para que el frontend pueda editar sin hacer consultas adicionales.

### Ver producto

```txt
GET /api/products/{product}
```

Permiso requerido:

```txt
products.view
```

### Consultar precio calculado

```txt
GET /api/products/{product}/price
GET /api/products/{product}/price?price_list_id=1
```

Permiso requerido:

```txt
products.view
```

Reglas:

- usa `base_price` como precio interno en `USD`;
- si se envia `price_list_id`, usa el precio activo del producto para esa lista;
- si no se envia `price_list_id` y existe una lista predeterminada con precio activo para el producto, usa esa lista;
- si no hay lista aplicable, usa el precio base del producto;
- si el producto tiene `sale_exchange_rate_type_id`, usa ese tipo de tasa;
- si el precio de lista tiene `exchange_rate_type_id`, ese tipo de tasa tiene prioridad sobre la tasa del producto;
- si no tiene tipo de tasa asignado, usa el tipo de tasa predeterminado de la empresa;
- si `sale_currency = VES`, requiere una tasa activa;
- devuelve precio en `USD`, equivalente en `VES`, tipo de tasa, valor de tasa usado y origen del precio;
- esta cotizacion no mueve inventario ni crea venta.

Respuesta con lista:

```json
{
  "data": {
    "product_id": 15,
    "price_list_id": 2,
    "price_list_name": "Precio al mayor",
    "price_source": "price_list",
    "base_price_usd": 10,
    "sale_currency": "USD",
    "sale_price": 10,
    "price_usd": 10,
    "price_ves": 5000,
    "exchange_rate_type_id": 1,
    "exchange_rate_type_code": "BCV",
    "exchange_rate": 500
  }
}
```

### Listas de precio

```txt
GET /api/price-lists
POST /api/price-lists
PATCH /api/price-lists/{priceList}
DELETE /api/price-lists/{priceList}
```

Permisos:

```txt
GET: products.view
POST/PATCH/DELETE: products.update
```

Body para crear/editar:

```json
{
  "name": "Precio al mayor",
  "code": "MAYOR",
  "description": "Precio usado para clientes mayoristas",
  "is_default": false,
  "is_active": true,
  "sort_order": 10,
  "payment_method_ids": [1, 2]
}
```

Reglas:

- `code` es unico por empresa y se normaliza a mayusculas;
- solo una lista puede quedar como predeterminada por empresa;
- `DELETE` no borra fisicamente; marca la lista como inactiva y quita `is_default`;
- una lista inactiva no puede ser usada para cotizar productos.
- `payment_method_ids` es opcional;
- si se envian métodos de pago, todos deben pertenecer a la empresa actual;
- si una lista tiene métodos asociados, POS solo puede cobrar productos de esa lista con esos métodos;
- si una lista no tiene métodos asociados, queda abierta y mantiene compatibilidad con cualquier método POS válido.

### Métodos de pago

```txt
GET /api/payment-methods
POST /api/payment-methods
PATCH /api/payment-methods/{paymentMethod}
DELETE /api/payment-methods/{paymentMethod}
```

Permisos:

```txt
GET: payment_methods.view
POST/PATCH/DELETE: payment_methods.update
```

Query params:

```txt
active_only=1
```

Body para crear/editar:

```json
{
  "name": "Zelle",
  "code": "ZELLE",
  "method": "zelle",
  "currency_mode": "USD",
  "requires_reference": true,
  "is_active": true,
  "sort_order": 10
}
```

Métodos operativos:

- `cash`
- `card`
- `mobile_payment`
- `transfer`
- `zelle`
- `external_financing`
- `other`

Modos de moneda:

- `USD`: solo permite pagos en dólares;
- `VES`: solo permite pagos en bolívares;
- `flexible`: permite pagos en dólares o bolívares.

Reglas:

- `code` es único por empresa y se normaliza a mayúsculas;
- `DELETE` no borra físicamente; marca el método como inactivo;
- un método inactivo no puede usarse en checkout POS;
- si `requires_reference` está activo, POS debe enviar `reference`;
- las listas de precio pueden asociarse a métodos de pago para limitar cómo se cobra cada precio.

### Precios de producto por lista

```txt
GET /api/products/{product}/prices
PUT /api/products/{product}/prices
```

Permisos:

```txt
GET: products.view
PUT: products.update
```

Body:

```json
{
  "prices": [
    {
      "price_list_id": 1,
      "price": 10,
      "currency": "USD",
      "exchange_rate_type_id": 1,
      "is_active": true
    },
    {
      "price_list_id": 2,
      "price": 20,
      "currency": "USD",
      "exchange_rate_type_id": 1,
      "is_active": true
    }
  ]
}
```

Reglas:

- `price_list_id` debe pertenecer a la empresa actual;
- `currency` puede ser `USD` o `VES`;
- si el precio esta en `VES`, la cotizacion requiere una tasa activa para calcular el equivalente base en `USD`;
- `exchange_rate_type_id` es opcional y, si se envia, debe pertenecer a la empresa actual;
- se hace `upsert` por producto y lista: si ya existe, se actualiza.
- ventas/POS pueden enviar `price_list_id` por item para copiar historicamente la lista y el precio usado.

### Historial de precios de producto

```txt
GET /api/products/{product}/price-history
```

Permiso:

```txt
products.view
```

Respuesta:

```json
{
  "data": [
    {
      "id": 45,
      "action": "updated",
      "price_list_id": 2,
      "price_list_name": "Precio al mayor",
      "price_list_code": "MAYOR",
      "before": {
        "price_list_id": 2,
        "price": 10,
        "currency": "USD",
        "exchange_rate_type_id": 1,
        "is_active": true
      },
      "after": {
        "price_list_id": 2,
        "price": 12,
        "currency": "USD",
        "exchange_rate_type_id": 1,
        "is_active": true
      },
      "created_by_name": "Gerente Caracas",
      "created_by_email": "gerente.caracas@demo.test",
      "created_at": "2026-07-04T12:00:00.000000Z"
    }
  ]
}
```

Reglas:

- lee los cambios registrados en `product_audits`;
- incluye cambios manuales desde `PUT /api/products/{product}/prices`;
- incluye cambios masivos realizados desde `POST /api/inventory-center/products/bulk-action`;
- devuelve los cambios más recientes primero;
- no modifica datos.

### Actualizar producto

```txt
PATCH /api/products/{product}
PUT /api/products/{product}
```

Permiso requerido:

```txt
products.update
```

Body aceptado:

```json
{
  "name": "Samsung A06 128GB",
  "sku": "SAMSUNG-A06-128",
  "tracking_type": "serialized",
  "base_price": 125,
  "sale_currency": "USD",
  "sale_exchange_rate_type_id": 1,
  "warranty_policy_id": 2,
  "is_active": true
}
```

Reglas:

- todos los campos son editables de forma parcial;
- `sku` sigue siendo unico por empresa y puede repetirse en otra empresa;
- `sale_exchange_rate_type_id` y `warranty_policy_id` deben pertenecer a la empresa actual;
- si el producto ya tiene unidades serializadas, no se puede cambiar `tracking_type`;
- cada cambio real genera auditoria en `product_audits`;
- si no hubo cambios reales, la API responde el producto sin crear auditoria adicional;
- los errores de validacion se devuelven en `errors` para que el cliente WPF los muestre en espanol.

### Desactivar producto

```txt
DELETE /api/products/{product}
```

Permiso requerido:

```txt
products.delete
```

Regla:

- no borra fisicamente el producto; marca `is_active = false`.

## Sucursales

Archivo de rutas:

```txt
app/Modules/Branches/routes.php
```

Controller:

```txt
App\Modules\Branches\Controllers\BranchController
```

### Listar sucursales

```txt
GET /api/branches
```

Permiso requerido:

```txt
branches.view
```

### Crear sucursal

```txt
POST /api/branches
```

Permiso requerido:

```txt
branches.create
```

Body:

```json
{
  "name": "Principal",
  "code": "MAIN",
  "status": "active"
}
```

Reglas:

- `code` es unico dentro de la empresa actual;
- `status` puede ser `active` o `inactive`;
- si no se envia `status`, queda como `active`.

### Ver sucursal

```txt
GET /api/branches/{branch}
```

Permiso requerido:

```txt
branches.view
```

### Actualizar sucursal

```txt
PATCH /api/branches/{branch}
PUT /api/branches/{branch}
```

Permiso requerido:

```txt
branches.update
```

### Desactivar sucursal

```txt
DELETE /api/branches/{branch}
```

Permiso requerido:

```txt
branches.delete
```

Regla:

- no borra fisicamente la sucursal; marca `status = inactive`.

## Almacenes

Archivo de rutas:

```txt
app/Modules/Warehouses/routes.php
```

Controller:

```txt
App\Modules\Warehouses\Controllers\WarehouseController
```

### Listar almacenes

```txt
GET /api/warehouses
```

Permiso requerido:

```txt
warehouses.view
```

### Crear almacen

```txt
POST /api/warehouses
```

Permiso requerido:

```txt
warehouses.create
```

Body:

```json
{
  "branch_id": 1,
  "name": "Almacen tienda",
  "code": "WH-STORE",
  "status": "active"
}
```

Reglas:

- `branch_id` debe pertenecer a la empresa actual;
- `code` es unico dentro de la empresa actual;
- `status` puede ser `active` o `inactive`;
- si no se envia `status`, queda como `active`.

### Ver almacen

```txt
GET /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.view
```

### Actualizar almacen

```txt
PATCH /api/warehouses/{warehouse}
PUT /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.update
```

### Desactivar almacen

```txt
DELETE /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.delete
```

Regla:

- no borra fisicamente el almacen; marca `status = inactive`.

## Moneda y tasas

Archivo de rutas:

```txt
app/Modules/Currency/routes.php
```

Controllers:

```txt
App\Modules\Currency\Controllers\ExchangeRateTypeController
App\Modules\Currency\Controllers\ExchangeRateController
```

### Listar tipos de tasa

```txt
GET /api/currency/rate-types
```

Permiso requerido:

```txt
currency.view
```

### Crear tipo de tasa

```txt
POST /api/currency/rate-types
```

Permiso requerido:

```txt
currency.manage
```

Body:

```json
{
  "code": "BCV",
  "name": "Tasa BCV",
  "is_default": true,
  "is_active": true
}
```

Reglas:

- `code` es unico dentro de la empresa actual;
- puede existir mas de un tipo de tasa para `USD` a `VES`, por ejemplo `BCV` y `PARALELO`;
- solo un tipo de tasa queda como predeterminado por empresa.

### Ver tipo de tasa

```txt
GET /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.view
```

### Actualizar tipo de tasa

```txt
PATCH /api/currency/rate-types/{type}
PUT /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.manage
```

### Desactivar tipo de tasa

```txt
DELETE /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- no borra fisicamente el tipo de tasa; marca `is_active = false`.

### Listar historial de tasas

```txt
GET /api/currency/rates
```

Permiso requerido:

```txt
currency.view
```

### Consultar tasas activas actuales

```txt
GET /api/currency/rates/current
GET /api/currency/rates/current?rate_type_code=BCV
```

Permiso requerido:

```txt
currency.view
```

### Crear tasa

```txt
POST /api/currency/rates
```

Permiso requerido:

```txt
currency.manage
```

Body:

```json
{
  "exchange_rate_type_id": 1,
  "base_currency": "USD",
  "quote_currency": "VES",
  "rate": 500,
  "effective_at": "2026-07-02T08:00:00-04:00",
  "is_active": true,
  "source": "Manual"
}
```

Reglas:

- la moneda base inicial es `USD`;
- la moneda cotizada inicial es `VES`;
- `rate` debe ser mayor que cero;
- `exchange_rate_type_id` debe pertenecer a la empresa actual;
- si se crea con `is_active = true`, se desactivan las tasas activas anteriores del mismo tipo y par de monedas;
- activar una tasa `BCV` no desactiva una tasa `PARALELO`.

Esta es la API para cargar una nueva tasa del dia o una tasa manual. Ejemplo: crear una nueva tasa `BCV = 500` o `PARALELO = 600`.

### Ver tasa

```txt
GET /api/currency/rates/{rate}
```

Permiso requerido:

```txt
currency.view
```

### Activar tasa

```txt
PATCH /api/currency/rates/{rate}/activate
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- solo queda activa una tasa por empresa, tipo de tasa, moneda base y moneda cotizada.

### Desactivar tasa

```txt
PATCH /api/currency/rates/{rate}/deactivate
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- no borra fisicamente la tasa historica; marca `is_active = false`.

## Clientes

Archivo de rutas:

```txt
app/Modules/Customers/routes.php
```

Controller:

```txt
App\Modules\Customers\Controllers\CustomerController
```

### Listar clientes

```txt
GET /api/customers
```

Permiso requerido:

```txt
customers.view
```

### Crear cliente

```txt
POST /api/customers
```

Permiso requerido:

```txt
customers.create
```

Body:

```json
{
  "name": "Cliente Mostrador",
  "document_type": "V",
  "document_number": "12345678",
  "phone": "04141234567",
  "email": "cliente@example.com",
  "fiscal_address": "Caracas",
  "is_generic": false,
  "is_active": true
}
```

Reglas:

- `document_type` puede ser `V`, `E`, `J`, `G` o `P`;
- `document_type + document_number` es unico dentro de la empresa actual;
- dos empresas distintas pueden registrar el mismo documento sin mezclarse;
- `is_generic` permite crear un cliente generico como `Consumidor final`;
- desactivar un cliente no borra ventas historicas.

### Ver cliente

```txt
GET /api/customers/{customer}
```

Permiso requerido:

```txt
customers.view
```

### Actualizar cliente

```txt
PATCH /api/customers/{customer}
PUT /api/customers/{customer}
```

Permiso requerido:

```txt
customers.update
```

### Desactivar cliente

```txt
DELETE /api/customers/{customer}
```

Permiso requerido:

```txt
customers.delete
```

Regla:

- no borra fisicamente el cliente; marca `is_active = false`.

## Proveedores

Archivo de rutas:

```txt
app/Modules/Suppliers/routes.php
```

Controller:

```txt
App\Modules\Suppliers\Controllers\SupplierController
```

### Listar proveedores

```txt
GET /api/suppliers
```

Permiso requerido:

```txt
suppliers.view
```

### Crear proveedor

```txt
POST /api/suppliers
```

Permiso requerido:

```txt
suppliers.create
```

Body:

```json
{
  "name": "Distribuidora Demo",
  "document_type": "J",
  "document_number": "123456789",
  "phone": "02121234567",
  "email": "compras@example.com",
  "fiscal_address": "Caracas",
  "notes": "Proveedor principal",
  "is_active": true
}
```

Reglas:

- `document_type` puede ser `V`, `E`, `J`, `G` o `P`;
- `document_type + document_number` es unico dentro de la empresa actual;
- dos empresas distintas pueden registrar el mismo documento sin mezclarse;
- desactivar un proveedor no borra compras historicas.

### Ver proveedor

```txt
GET /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.view
```

### Actualizar proveedor

```txt
PATCH /api/suppliers/{supplier}
PUT /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.update
```

### Desactivar proveedor

```txt
DELETE /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.delete
```

Regla:

- no borra fisicamente el proveedor; marca `is_active = false`.

## Compras

Archivo de rutas:

```txt
app/Modules/Purchases/routes.php
```

Controller:

```txt
App\Modules\Purchases\Controllers\PurchaseOrderController
```

### Listar compras

```txt
GET /api/purchases
```

Permiso requerido:

```txt
purchases.view
```

### Crear compra en borrador

```txt
POST /api/purchases
```

Permiso requerido:

```txt
purchases.create
```

Body:

```json
{
  "supplier_id": 1,
  "document_number": "FAC-001",
  "issued_at": "2026-07-02",
  "due_date": "2026-07-16",
  "purchase_currency": "USD",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2,
      "unit_cost": 80,
      "serial_units": [
        {
          "serial_type": "imei",
          "serial_number": "860001000000001"
        }
      ]
    }
  ]
}
```

Reglas:

- crear una compra la deja en `draft`;
- crear una compra no aumenta inventario;
- `supplier_id` es opcional, pero si se envia debe pertenecer a la empresa actual;
- `document_number` representa la factura o documento del proveedor y es unico por empresa;
- `issued_at` y `due_date` documentan emision y vencimiento de la factura;
- `warehouse_id` y `product_id` deben pertenecer a la empresa actual;
- `purchase_currency` puede ser `USD` o `VES`;
- si `purchase_currency = VES`, se debe enviar `exchange_rate_type_id` o existir un tipo de tasa predeterminado activo;
- las compras en `VES` guardan snapshot de tipo de tasa, codigo y valor;
- `unit_cost` se guarda en la moneda de la compra y tambien se calcula como costo base en `USD`;
- productos serializados requieren un serial o IMEI por cada unidad comprada;
- productos por cantidad no aceptan seriales.

### Ver compra

```txt
GET /api/purchases/{purchaseOrder}
```

Permiso requerido:

```txt
purchases.view
```

### Recibir compra

```txt
PATCH /api/purchases/{purchaseOrder}/receive
```

Permiso requerido:

```txt
purchases.approve
```

Reglas:

- si se envia sin body, recibe todo lo pendiente;
- si se envia `items`, recibe solo las cantidades indicadas;
- solo se reciben compras en `draft` o `partially_received`;
- recibir una compra genera movimientos `purchase` en inventario por cada item recibido;
- cada item guarda `received_quantity` acumulado;
- cada item queda enlazado al ultimo `stock_movement_id` generado;
- si el producto es serializado, se crean unidades en `product_units` como disponibles;
- una compra con recepcion parcial queda en estado `partially_received`;
- una compra completamente recibida queda en estado `received`;
- la cuenta por pagar se crea o actualiza segun el monto recibido, no segun lo pendiente sin recibir.

Body para recepcion parcial:

```json
{
  "items": [
    {
      "purchase_item_id": 1,
      "quantity": 1
    }
  ]
}
```

Body para recepcion parcial serializada:

```json
{
  "items": [
    {
      "purchase_item_id": 1,
      "quantity": 1,
      "serial_units": [
        {
          "serial_type": "imei",
          "serial_number": "860001000000001"
        }
      ]
    }
  ]
}
```

### Cancelar compra en borrador

```txt
PATCH /api/purchases/{purchaseOrder}/cancel
```

Permiso requerido:

```txt
purchases.create
```

Regla:

- en esta fase solo se cancelan compras en `draft`; compras recibidas requeriran reverso/devolucion controlada mas adelante.

## Devoluciones a proveedor

Archivo de rutas:

```txt
app/Modules/PurchaseReturns/routes.php
```

Controller:

```txt
App\Modules\PurchaseReturns\Controllers\PurchaseReturnController
```

### Listar devoluciones a proveedor

```txt
GET /api/purchase-returns
```

Permiso requerido:

```txt
purchase_returns.view
```

### Crear devolucion a proveedor

```txt
POST /api/purchase-returns
```

Permiso requerido:

```txt
purchase_returns.create
```

Body:

```json
{
  "purchase_order_id": 1,
  "reason": "Mercancia defectuosa",
  "items": [
    {
      "purchase_item_id": 1,
      "quantity": 1,
      "product_unit_ids": [1],
      "reason": "Equipo devuelto al proveedor"
    }
  ]
}
```

Reglas:

- solo se pueden devolver compras recibidas;
- una devolucion a proveedor no borra ni cancela la compra original;
- no se puede devolver mas cantidad que la comprada menos devoluciones previas;
- cada item genera movimiento de inventario `purchase_return`;
- si el producto es serializado, se debe enviar una unidad por cada cantidad devuelta;
- las unidades serializadas devueltas quedan con estado `removed`;
- todos los ids deben pertenecer a la empresa actual.

### Ver devolucion a proveedor

```txt
GET /api/purchase-returns/{purchaseReturn}
```

Permiso requerido:

```txt
purchase_returns.view
```

## Cuentas por pagar

Archivo de rutas:

```txt
app/Modules/AccountsPayable/routes.php
```

Controller:

```txt
App\Modules\AccountsPayable\Controllers\AccountsPayableController
```

### Listar cuentas por pagar

```txt
GET /api/accounts-payable
```

Permiso requerido:

```txt
accounts_payable.view
```

Reglas:

- las cuentas por pagar se crean automaticamente al recibir una compra;
- no hay endpoint publico para crear deuda manual en esta fase;
- cada cuenta queda asociada a la compra y al proveedor;
- el saldo principal se guarda en `USD` base;
- si la compra o el pago usan `VES`, se guarda el snapshot de tasa usado;
- las devoluciones a proveedor reducen el saldo pendiente sin borrar la compra.

### Ver cuenta por pagar

```txt
GET /api/accounts-payable/{accountsPayable}
```

Permiso requerido:

```txt
accounts_payable.view
```

Incluye:

- proveedor;
- compra origen;
- montos originales;
- montos devueltos;
- montos pagados;
- saldo pendiente;
- pagos registrados.

### Registrar pago a proveedor

```txt
POST /api/accounts-payable/{accountsPayable}/payments
```

Permiso requerido:

```txt
accounts_payable.pay
```

Body:

```json
{
  "payment_currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 1,
  "method": "pago movil",
  "reference": "PM-001",
  "notes": "Abono a factura proveedor"
}
```

Reglas:

- `payment_currency` puede ser `USD` o `VES`;
- los pagos en `VES` requieren una tasa activa o `exchange_rate` explicito;
- el pago guarda tipo de tasa, codigo y valor exacto usado;
- no se permite pagar mas que el saldo pendiente;
- una cuenta pagada no acepta nuevos pagos;
- todos los ids deben pertenecer a la empresa actual.

## Cuentas por cobrar

Archivo de rutas:

```txt
app/Modules/AccountsReceivable/routes.php
```

Controller:

```txt
App\Modules\AccountsReceivable\Controllers\AccountsReceivableController
```

### Listar cuentas por cobrar

```txt
GET /api/accounts-receivable
```

Permiso requerido:

```txt
accounts_receivable.view
```

Reglas:

- las cuentas por cobrar se crean automaticamente al confirmar una venta;
- no hay endpoint publico para crear deuda manual en esta fase;
- cada cuenta queda asociada a la venta y opcionalmente al cliente;
- el saldo principal se guarda en `USD` base;
- si el cobro usa `VES`, se guarda el snapshot de tasa usado;
- las devoluciones de venta reducen el saldo pendiente sin borrar la venta.

### Ver cuenta por cobrar

```txt
GET /api/accounts-receivable/{accountsReceivable}
```

Permiso requerido:

```txt
accounts_receivable.view
```

### Registrar cobro de cliente

```txt
POST /api/accounts-receivable/{accountsReceivable}/payments
```

Permiso requerido:

```txt
accounts_receivable.collect
```

Body:

```json
{
  "payment_currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 1,
  "method": "pago movil",
  "reference": "PM-CLIENTE-001",
  "notes": "Abono de cliente"
}
```

Reglas:

- `payment_currency` puede ser `USD` o `VES`;
- los cobros en `VES` requieren una tasa activa o `exchange_rate` explicito;
- el cobro guarda tipo de tasa, codigo y valor exacto usado;
- no se permite cobrar mas que el saldo pendiente;
- una cuenta pagada no acepta nuevos cobros;
- todos los ids deben pertenecer a la empresa actual.

## Ajustes financieros

Archivo de rutas:

```txt
app/Modules/FinancialAdjustments/routes.php
```

Controller:

```txt
App\Modules\FinancialAdjustments\Controllers\FinancialAdjustmentController
```

### Listar ajustes financieros

```txt
GET /api/financial-adjustments
```

Permiso requerido:

```txt
financial_adjustments.view
```

### Crear ajuste financiero

```txt
POST /api/financial-adjustments
```

Permiso requerido:

```txt
financial_adjustments.create
```

Body:

```json
{
  "account_type": "receivable",
  "account_id": 1,
  "currency": "USD",
  "amount": 10,
  "reason": "Descuento posterior a la venta",
  "notes": "Ajuste autorizado por gerencia"
}
```

Valores de `account_type`:

- `receivable`: aplica a una cuenta por cobrar;
- `payable`: aplica a una cuenta por pagar.

Reglas:

- un ajuste financiero reduce el saldo pendiente de la cuenta indicada;
- no mueve inventario;
- no crea comprobante de pago porque no representa dinero recibido o entregado;
- no puede superar el saldo pendiente de la cuenta;
- puede registrarse en `USD` o `VES`;
- si se registra en `VES`, guarda snapshot de tipo de tasa, codigo y valor usado;
- se usa para descuentos posteriores, notas de credito financieras, redondeos o ajustes autorizados;
- si hay mercancia devuelta, debe usarse `SalesReturns` o `PurchaseReturns`, no este modulo.

### Ver ajuste financiero

```txt
GET /api/financial-adjustments/{financialAdjustment}
```

Permiso requerido:

```txt
financial_adjustments.view
```

## Comprobantes de pago y cobro

Archivo de rutas:

```txt
app/Modules/PaymentReceipts/routes.php
```

Controller:

```txt
App\Modules\PaymentReceipts\Controllers\PaymentReceiptController
```

### Listar comprobantes

```txt
GET /api/payment-receipts
```

Permiso requerido:

```txt
payment_receipts.view
```

### Ver comprobante

```txt
GET /api/payment-receipts/{paymentReceipt}
```

Permiso requerido:

```txt
payment_receipts.view
```

### Anular comprobante

```txt
PATCH /api/payment-receipts/{paymentReceipt}/void
```

Permiso requerido:

```txt
payment_receipts.void
```

Body:

```json
{
  "reason": "Error de impresion"
}
```

Reglas:

- los comprobantes se emiten automaticamente al registrar un cobro de cliente o un pago a proveedor;
- un pago POS `captured` tambien genera comprobante porque se refleja como cobro de cliente;
- el comprobante guarda snapshot de cliente/proveedor, moneda, monto, metodo, referencia, tipo de tasa y tasa usada;
- `receipt_number` es correlativo por empresa;
- anular un comprobante solo anula el documento historico;
- anular un comprobante no revierte el pago original, no reabre la cuenta y no mueve caja ni inventario;
- todos los comprobantes respetan tenant y permisos.

## Reportes financieros

Archivo de rutas:

```txt
app/Modules/FinanceReports/routes.php
```

Controller:

```txt
App\Modules\FinanceReports\Controllers\FinanceReportController
```

### Resumen financiero

```txt
GET /api/finance-reports/summary
```

Permiso requerido:

```txt
finance_reports.view
```

Filtros:

- `date_from`
- `date_to`
- `status`
- `customer_id`
- `supplier_id`

Incluye:

- total por cobrar en `USD`;
- total por pagar en `USD`;
- cantidad de cuentas pendientes, parciales, pagadas y vencidas;
- cobros recibidos en el periodo;
- pagos hechos a proveedores en el periodo;
- balance neto: por cobrar menos por pagar.

### Cuentas por cobrar financieras

```txt
GET /api/finance-reports/receivables
```

Permiso requerido:

```txt
finance_reports.view
```

### Cuentas por pagar financieras

```txt
GET /api/finance-reports/payables
```

Permiso requerido:

```txt
finance_reports.view
```

Reglas:

- los reportes financieros son solo lectura;
- no crean ni modifican cuentas;
- usan `USD` como moneda base de resumen;
- respetan tenant y permisos;
- no mezclan cuentas, pagos, clientes ni proveedores entre empresas.

## Ventas

Archivo de rutas:

```txt
app/Modules/Sales/routes.php
```

Controller:

```txt
App\Modules\Sales\Controllers\SaleController
```

### Listar ventas

```txt
GET /api/sales
```

Permiso requerido:

```txt
sales.view
```

### Crear venta en borrador

```txt
POST /api/sales
```

Permiso requerido:

```txt
sales.create
```

Body:

```json
{
  "customer_id": 1,
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2,
      "product_unit_ids": []
    }
  ]
}
```

Body para producto serializado:

```json
{
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 10,
      "quantity": 1,
      "product_unit_ids": [55]
    }
  ]
}
```

Reglas:

- crear una venta la deja en `draft`;
- crear una venta no descuenta inventario;
- copia el precio actual del producto;
- copia moneda, tipo de tasa y valor exacto de tasa;
- `customer_id` es opcional;
- si se envia `customer_id`, debe pertenecer a la empresa actual;
- `warehouse_id` y `product_id` deben pertenecer a la empresa actual;
- si el producto es serializado, se debe enviar un `product_unit_id` por cada unidad vendida;
- si el producto es por cantidad, no debe enviar `product_unit_ids`;
- `product_unit_ids` queda guardado en `sale_items` para saber que IMEI o serial salio en esa venta.

### Ver venta

```txt
GET /api/sales/{sale}
```

Permiso requerido:

```txt
sales.view
```

### Confirmar venta

```txt
PATCH /api/sales/{sale}/confirm
```

Permiso requerido:

```txt
sales.create
```

Reglas:

- solo confirma ventas en `draft`;
- valida stock disponible;
- valida que los IMEIs o seriales sigan disponibles, pertenezcan al producto y esten en el almacen de la venta;
- descuenta inventario con movimiento `sale`;
- enlaza los movimientos de inventario con la venta;
- marca los IMEIs o seriales vendidos como `sold` y los enlaza al movimiento de salida;
- si dos ventas o cajas intentan confirmar el mismo IMEI, solo la primera puede completarse.

### Cancelar venta

```txt
PATCH /api/sales/{sale}/cancel
```

Permiso requerido:

```txt
sales.cancel
```

Regla:

- en esta fase solo se cancelan ventas en `draft`; ventas confirmadas requeriran devolucion/reverso controlado mas adelante.

## Devoluciones de venta

Archivo de rutas:

```txt
app/Modules/SalesReturns/routes.php
```

Controller:

```txt
App\Modules\SalesReturns\Controllers\SalesReturnController
```

### Listar devoluciones de venta

```txt
GET /api/sales-returns
```

Permiso requerido:

```txt
sales_returns.view
```

### Crear devolucion de venta

```txt
POST /api/sales-returns
```

Permiso requerido:

```txt
sales_returns.create
```

Body:

```json
{
  "sale_id": 1,
  "reason": "Cliente devolvio el producto",
  "items": [
    {
      "sale_item_id": 1,
      "quantity": 1,
      "condition": "sellable",
      "product_unit_ids": [1],
      "reason": "Producto en buen estado"
    }
  ]
}
```

Condiciones iniciales:

- `sellable`: vuelve como disponible para venta;
- `damaged`: vuelve marcado como danado para revision.

Reglas:

- solo se pueden devolver ventas confirmadas;
- una devolucion no borra ni cancela la venta original;
- no se puede devolver mas cantidad que la vendida menos devoluciones previas;
- cada item genera movimiento de inventario `sale_return`;
- si el producto es serializado, se debe enviar una unidad por cada cantidad devuelta;
- los IMEIs o seriales devueltos deben estar registrados en `sale_items.product_unit_ids` de ese item vendido;
- si la unidad vuelve como `sellable`, queda disponible;
- si la unidad vuelve como `damaged`, queda marcada como danada;
- todos los ids deben pertenecer a la empresa actual.

### Ver devolucion de venta

```txt
GET /api/sales-returns/{salesReturn}
```

Permiso requerido:

```txt
sales_returns.view
```

## Inventario

## Entradas de productos

Archivo de rutas:

```txt
app/Modules/ProductEntries/routes.php
```

Controller:

```txt
App\Modules\ProductEntries\Controllers\ProductEntryController
```

### Listar entradas

```txt
GET /api/product-entries
```

Permiso requerido:

```txt
product_entries.view
```

### Crear entrada

```txt
POST /api/product-entries
```

Permiso requerido:

```txt
product_entries.create
```

Body para producto por cantidad:

```json
{
  "reason": "Carga inicial",
  "reference": "GUIA-001",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 2,
      "quantity": 20,
      "unit_cost": 10
    }
  ]
}
```

Body para producto serializado:

```json
{
  "reason": "Compra Samsung A06",
  "reference": "FACT-IMEI-001",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 30,
      "unit_cost": 80,
      "serial_units": [
        { "serial_type": "imei", "serial_number": "860900000000001" },
        { "serial_type": "imei", "serial_number": "860900000000002" }
      ]
    }
  ]
}
```

Reglas:

- una entrada puede tener uno o varios productos;
- cada item genera movimiento `purchase` en inventario;
- si el producto es `quantity`, no acepta `serial_units`;
- si el producto es `serialized`, debe recibir un IMEI o serial por cada unidad;
- los IMEIs o seriales no pueden repetirse dentro de la entrada;
- los IMEIs o seriales no pueden existir previamente en la empresa actual;
- las unidades serializadas quedan en `product_units` con estado `available`;
- la entrada es operativa/manual y no crea cuenta por pagar;
- si se necesita compra formal con proveedor y deuda, se debe usar `Purchases`.

### Ver entrada

```txt
GET /api/product-entries/{productEntry}
```

Permiso requerido:

```txt
product_entries.view
```

## Salidas de productos

Archivo de rutas:

```txt
app/Modules/ProductExits/routes.php
```

Controller:

```txt
App\Modules\ProductExits\Controllers\ProductExitController
```

### Listar salidas

```txt
GET /api/product-exits
```

Permiso requerido:

```txt
product_exits.view
```

### Crear salida

```txt
POST /api/product-exits
```

Permiso requerido:

```txt
product_exits.create
```

Motivos iniciales:

- `damaged`
- `lost`
- `internal_use`
- `warranty`
- `administrative`
- `other`

Body para producto por cantidad:

```json
{
  "reason": "internal_use",
  "reference": "USO-001",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 2,
      "quantity": 3
    }
  ]
}
```

Body para producto serializado:

```json
{
  "reason": "warranty",
  "reference": "GAR-001",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2,
      "product_unit_ids": [10, 11]
    }
  ]
}
```

Reglas:

- una salida puede tener uno o varios productos;
- si el motivo es `damaged`, mueve cantidad de disponible a danado;
- si el motivo es distinto de `damaged`, descuenta disponible con movimiento `adjustment_out`;
- si el producto es `quantity`, no acepta `product_unit_ids`;
- si el producto es `serialized`, debe recibir una unidad disponible por cada cantidad;
- los IMEIs seleccionados deben pertenecer al producto, almacen y empresa actual;
- no se puede sacar un IMEI vendido, removido, danado o de otro almacen;
- si la salida es una venta debe usarse `Sales` o `POS`;
- si la salida es devolucion a proveedor debe usarse `PurchaseReturns`.

### Ver salida

```txt
GET /api/product-exits/{productExit}
```

Permiso requerido:

```txt
product_exits.view
```

## Transferencias de inventario

Archivo de rutas:

```txt
app/Modules/InventoryTransfers/routes.php
```

Controller:

```txt
App\Modules\InventoryTransfers\Controllers\InventoryTransferController
```

### Listar transferencias

```txt
GET /api/inventory-transfers
```

Permiso requerido:

```txt
inventory_transfers.view
```

### Crear transferencia interna

```txt
POST /api/inventory-transfers
```

Permiso requerido:

```txt
inventory_transfers.create
```

Body para producto por cantidad:

```json
{
  "type": "internal",
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "reason": "Reposicion de sucursal",
  "reference": "TRAS-001",
  "items": [
    {
      "product_id": 2,
      "quantity": 4
    }
  ]
}
```

Body para producto serializado:

```json
{
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "reason": "Traslado de IMEIs",
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "product_unit_ids": [10, 11]
    }
  ]
}
```

Reglas:

- una transferencia puede tener uno o varios productos;
- en esta fase solo se permite `type = internal`;
- origen y destino deben ser almacenes distintos de la misma empresa;
- cada item genera dos movimientos de kardex: `transfer_out` en origen y `transfer_in` en destino;
- si el producto es `quantity`, no acepta `product_unit_ids`;
- si el producto es `serialized`, debe recibir una unidad disponible por cada cantidad;
- los IMEIs seleccionados deben estar disponibles en el almacen origen;
- al completar la transferencia, los IMEIs siguen disponibles pero cambian de almacen;
- la transferencia usa bloqueo de balances para evitar stock negativo cuando hay operaciones simultaneas;
- los traslados entre empresas se modelaran como solicitud interempresa con aceptacion/rechazo, no como movimiento directo.

### Ver transferencia

```txt
GET /api/inventory-transfers/{inventoryTransfer}
```

Permiso requerido:

```txt
inventory_transfers.view
```

## Solicitudes de transferencia interempresa

Archivo de rutas:

```txt
app/Modules/InventoryTransferRequests/routes.php
```

Controller:

```txt
App\Modules\InventoryTransferRequests\Controllers\InventoryTransferRequestController
```

### Listar solicitudes visibles

```txt
GET /api/inventory-transfer-requests
```

Permiso requerido:

```txt
inventory_transfer_requests.view
```

La empresa actual ve solicitudes donde es origen o destino.

### Crear solicitud

```txt
POST /api/inventory-transfer-requests
```

Permiso requerido:

```txt
inventory_transfer_requests.create
```

Body usando slug de empresa destino:

```json
{
  "destination_tenant_slug": "empresa-destino",
  "from_warehouse_id": 1,
  "reason": "Envio entre empresas",
  "reference": "TREQ-001",
  "items": [
    {
      "product_id": 2,
      "quantity": 4
    }
  ]
}
```

Body usando correo de un usuario de la empresa destino:

```json
{
  "destination_user_email": "gerente.destino@demo.test",
  "from_warehouse_id": 1,
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "product_unit_ids": [10, 11]
    }
  ]
}
```

Reglas:

- la solicitud no mueve inventario al crearse;
- la empresa destino debe ser distinta a la empresa origen;
- si se usa correo, debe pertenecer a una unica empresa activa;
- los productos e IMEIs solicitados pertenecen a la empresa origen;
- los IMEIs quedan guardados como snapshot para recrearlos en la empresa destino si la solicitud se acepta;
- la empresa destino debe aceptar o rechazar.

### Aceptar solicitud

```txt
POST /api/inventory-transfer-requests/{inventoryTransferRequest}/accept
```

Permiso requerido:

```txt
inventory_transfer_requests.respond
```

Body:

```json
{
  "destination_warehouse_id": 5,
  "response_notes": "Aceptado por sucursal destino",
  "items": [
    {
      "request_item_id": 1,
      "destination_product_id": 8
    }
  ]
}
```

Reglas:

- solo la empresa destino puede aceptar;
- debe indicar almacen destino y producto destino por cada item;
- el producto destino debe tener el mismo tipo de control: cantidad o serializado;
- al aceptar, la empresa origen genera salida `adjustment_out`;
- al aceptar, la empresa destino genera entrada `purchase`;
- en serializados, el IMEI original queda `removed` en origen y se crea disponible en destino;
- si ya no hay stock suficiente en origen, la aceptacion falla y no se mueve nada.

### Rechazar solicitud

```txt
POST /api/inventory-transfer-requests/{inventoryTransferRequest}/reject
```

Permiso requerido:

```txt
inventory_transfer_requests.respond
```

### Cancelar solicitud

```txt
POST /api/inventory-transfer-requests/{inventoryTransferRequest}/cancel
```

Permiso requerido:

```txt
inventory_transfer_requests.cancel
```

### Ver solicitud

```txt
GET /api/inventory-transfer-requests/{inventoryTransferRequest}
```

Permiso requerido:

```txt
inventory_transfer_requests.view
```

Archivo de rutas:

```txt
app/Modules/Inventory/routes.php
```

Controller:

```txt
App\Modules\Inventory\Controllers\InventoryMovementController
```

Servicio usado:

```txt
App\Modules\Inventory\Services\AuthorizedInventoryMovementService
```

### Registrar entrada por compra

```txt
POST /api/inventory/purchases
```

Permiso requerido:

```txt
purchases.create
```

Body:

```json
{
  "warehouse_id": 1,
  "product_id": 1,
  "quantity": 10,
  "unit_cost": 80,
  "reason": "Compra inicial"
}
```

Movimiento creado:

```txt
purchase
```

### Registrar salida por venta

```txt
POST /api/inventory/sales
```

Permiso requerido:

```txt
sales.create
```

Movimiento creado:

```txt
sale
```

### Ajuste positivo

```txt
POST /api/inventory/adjustments/in
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
adjustment_in
```

### Ajuste negativo

```txt
POST /api/inventory/adjustments/out
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
adjustment_out
```

### Reservar stock

```txt
POST /api/inventory/reservations
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
reserved
```

### Liberar reserva

```txt
POST /api/inventory/releases
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
released
```

### Marcar stock danado

```txt
POST /api/inventory/damages
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
damaged
```

### Transferir entre almacenes

```txt
POST /api/inventory/transfers
```

Permiso requerido:

```txt
inventory.transfer
```

Body:

```json
{
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "product_id": 1,
  "quantity": 4,
  "reason": "Reposicion"
}
```

Movimientos creados:

```txt
transfer_out
transfer_in
```

## Reportes

Archivo de rutas:

```txt
app/Modules/Reports/routes.php
```

Controller:

```txt
App\Modules\Reports\Controllers\InventoryReportController
```

Permiso requerido para todos los endpoints:

```txt
reports.view
```

### Stock actual

```txt
GET /api/reports/stock
```

Filtros:

- `warehouse_id`
- `product_id`

### Bajo stock

```txt
GET /api/reports/stock/low
```

Filtros:

- `threshold`
- `warehouse_id`
- `product_id`

### Movimientos

```txt
GET /api/reports/movements
```

Filtros:

- `warehouse_id`
- `product_id`
- `type`
- `date_from`
- `date_to`

## Kardex

Archivo de rutas:

```txt
app/Modules/Kardex/routes.php
```

Controller:

```txt
App\Modules\Kardex\Controllers\KardexController
```

### Kardex por producto

```txt
GET /api/kardex/products/{product}
GET /api/kardex/products/{product}?warehouse_id=1
GET /api/kardex/products/{product}?date_from=2026-07-01&date_to=2026-07-31
```

Permiso requerido:

```txt
kardex.view
```

Respuesta:

- producto consultado;
- almacen filtrado, si aplica;
- saldo inicial;
- saldo final;
- movimientos ordenados cronologicamente;
- cantidad de entrada;
- cantidad de salida;
- saldo corrido por movimiento;
- referencia al documento origen.

Reglas:

- Kardex no duplica datos;
- Kardex lee `stock_movements`;
- `warehouse_id` debe pertenecer a la empresa actual;
- `product` debe pertenecer a la empresa actual;
- `date_from` y `date_to` permiten calcular saldo inicial y saldo final del periodo;
- movimientos de entrada incluyen `purchase`, `sale_return`, `adjustment_in`, `transfer_in`, `return_in` y `released`;
- movimientos de salida incluyen `sale`, `adjustment_out`, `transfer_out`, `return_out`, `damaged` y `reserved`.

## POS

Archivo de rutas:

```txt
app/Modules/POS/routes.php
```

Controller:

```txt
App\Modules\POS\Controllers\PosOrderController
```

### Listar ordenes POS

```txt
GET /api/pos/orders
```

Permiso requerido:

```txt
pos.view
```

Respuesta:

- ordenes POS de la empresa actual;
- venta asociada;
- pagos registrados.

### Crear checkout POS

```txt
POST /api/pos/checkouts
```

Permiso requerido:

```txt
pos.checkout
```

Body:

```json
{
  "cash_register_session_id": 1,
  "customer_id": 1,
  "customer_name": "Cliente mostrador",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "price_list_id": 1,
      "quantity": 2,
      "product_unit_ids": []
    }
  ],
  "payments": [
    {
      "payment_method_id": 1,
      "method": "cash",
      "currency": "USD",
      "amount": 200,
      "status": "captured"
    }
  ]
}
```

Metodos de pago iniciales:

- `cash`
- `card`
- `mobile_payment`
- `transfer`
- `zelle`
- `external_financing`
- `other`

Estados de pago iniciales:

- `captured`: cuenta como pago valido para cerrar la orden;
- `pending`: queda registrado, pero no confirma la venta;
- `failed`: queda registrado, pero no confirma la venta.

Reglas:

- POS requiere `cash_register_session_id`;
- `customer_id` es opcional y debe pertenecer a la empresa actual cuando se envia;
- `customer_name` puede conservar el nombre mostrado en ticket aunque exista `customer_id`;
- la caja debe estar abierta;
- la caja debe pertenecer al cajero autenticado;
- POS crea una venta en `Sales`;
- para productos serializados, POS debe enviar `product_unit_ids` igual que `Sales`;
- el IMEI o serial vendido queda guardado en `sale_items` y visible en la venta asociada a la orden POS;
- POS registra los pagos en `pos_payments`;
- POS puede recibir `payment_method_id` para usar un método de pago configurado;
- POS guarda `payment_method_id` en `pos_payments` para auditoría histórica;
- si un item usa una lista de precio con métodos restringidos, todos los pagos deben usar métodos permitidos por esa lista;
- si una lista restringida no recibe `payment_method_id`, backend intenta resolverlo con `method` y `currency`;
- si no puede resolver un método compatible, el checkout falla con mensaje de validación;
- si el método configurado exige referencia, `reference` es obligatorio;
- si el método configurado es `USD`, solo acepta pagos `USD`;
- si el método configurado es `VES`, solo acepta pagos `VES`;
- si el método configurado es `flexible`, acepta pagos `USD` o `VES`;
- solo pagos `captured` suman al total pagado;
- cada pago `captured` crea un movimiento `pos_payment` en la caja asociada;
- cada pago `captured` se refleja como cobro automatico en `AccountsReceivable` cuando POS confirma la venta;
- si los pagos capturados cubren el total base, POS confirma la venta y descuenta inventario mediante `Sales`;
- si el pago queda pendiente, la orden POS queda `open` y la venta queda `draft`;
- si el pago queda pendiente, no se crea cobro automatico en cuentas por cobrar;
- si dos cajas intentan vender la ultima unidad, la segunda operacion debe fallar por stock insuficiente;
- pagos en `VES` requieren una tasa activa y guardan snapshot de tipo de tasa, codigo y valor;
- pagos con financiadoras externas pueden usar `external_provider`, `reference` y `metadata`.

Ejemplo de pago en bolivares:

```json
{
  "method": "mobile_payment",
  "currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 2,
  "reference": "PM-001",
  "status": "captured"
}
```

Ejemplo de financiadora externa pendiente:

```json
{
  "method": "external_financing",
  "currency": "USD",
  "amount": 100,
  "status": "pending",
  "external_provider": "Financiadora Demo",
  "reference": "SOL-1001"
}
```

### Ver orden POS

```txt
GET /api/pos/orders/{posOrder}
```

Permiso requerido:

```txt
pos.view
```

## Caja

Archivo de rutas:

```txt
app/Modules/CashRegister/routes.php
```

Controller:

```txt
App\Modules\CashRegister\Controllers\CashRegisterSessionController
```

### Listar sesiones de caja

```txt
GET /api/cash-register/sessions
```

Permiso requerido:

```txt
cash_register.view
```

### Abrir caja

```txt
POST /api/cash-register/sessions
```

Permiso requerido:

```txt
cash_register.open
```

Body:

```json
{
  "branch_id": 1,
  "cashier_id": 1,
  "opening_currency": "USD",
  "opening_amount": 50,
  "notes": "Inicio de turno"
}
```

Reglas:

- `cashier_id` es opcional; si no se envia, se usa el usuario autenticado;
- la sucursal debe pertenecer a la empresa actual;
- un cajero no puede tener dos cajas abiertas al mismo tiempo;
- si el monto inicial esta en `VES`, debe existir una tasa activa.

### Ver sesion de caja

```txt
GET /api/cash-register/sessions/{cashRegisterSession}
```

Permiso requerido:

```txt
cash_register.view
```

### Registrar movimiento de caja

```txt
POST /api/cash-register/sessions/{cashRegisterSession}/movements
```

Permiso requerido:

```txt
cash_register.move
```

Body:

```json
{
  "type": "inflow",
  "method": "cash",
  "currency": "VES",
  "amount": 50000,
  "exchange_rate_type_id": 1,
  "reference": "ING-1",
  "notes": "Entrada manual"
}
```

Tipos iniciales:

- `inflow`
- `outflow`
- `adjustment`

Metodos iniciales:

- `cash`
- `card`
- `mobile_payment`
- `transfer`
- `zelle`
- `external_financing`
- `other`

Reglas:

- una caja cerrada no acepta movimientos;
- `outflow` resta al monto esperado;
- `inflow` y `adjustment` suman al monto esperado en esta fase;
- movimientos en `VES` guardan snapshot de tipo de tasa, codigo y valor.

### Cerrar caja

```txt
PATCH /api/cash-register/sessions/{cashRegisterSession}/close
```

Permiso requerido:

```txt
cash_register.close
```

Body:

```json
{
  "counted_currency": "USD",
  "counted_amount": 110,
  "closing_notes": "Faltante reportado"
}
```

Reglas:

- calcula diferencia entre monto contado y monto esperado;
- cambia la sesion a `closed`;
- despues del cierre no se pueden registrar nuevos movimientos.

## Control de accesos

Modulo: `AccessControl`

Todas las rutas requieren usuario autenticado, tenant resuelto con `X-Tenant` y permisos del tenant actual.

### Listar usuarios de la empresa

```txt
GET /api/users
```

Permiso requerido:

```txt
users.view
```

Devuelve usuarios vinculados a la empresa actual, su estado en esa empresa y sus roles de esa empresa.

### Crear o vincular usuario

```txt
POST /api/users
```

Permiso requerido:

```txt
users.create
```

Body:

```json
{
  "name": "Cajero Principal",
  "email": "cajero@example.test",
  "password": "password-seguro",
  "roles": ["Vendedor"]
}
```

Reglas:

- si el correo no existe, crea el usuario;
- si el correo ya existe, lo vincula o reactiva en la empresa actual;
- los roles deben existir dentro de la empresa actual;
- un mismo correo puede pertenecer a varias empresas con roles distintos;
- la accion queda registrada en `audit_logs`.

### Ver usuario de la empresa

```txt
GET /api/users/{user}
```

Permiso requerido:

```txt
users.view
```

### Actualizar nombre de usuario

```txt
PATCH /api/users/{user}
```

Permiso requerido:

```txt
users.update
```

### Activar o inactivar usuario en la empresa

```txt
PATCH /api/users/{user}/status
```

Permiso requerido:

```txt
users.update
```

Body:

```json
{
  "status": "inactive"
}
```

Regla:

- el estado aplica solo al vinculo con la empresa actual; no afecta otras empresas donde el usuario tambien exista;
- no se puede inactivar el ultimo usuario activo con rol `Owner` o `Administrador`;
- la accion queda registrada en `audit_logs`.

### Asignar roles a usuario

```txt
PATCH /api/users/{user}/roles
```

Permiso requerido:

```txt
users.update
```

Body:

```json
{
  "roles": ["Vendedor", "Supervisor POS"]
}
```

Reglas:

- no se puede quitar el ultimo rol `Owner` o `Administrador` activo de la empresa;
- la accion queda registrada en `audit_logs`.

### Ver permisos efectivos del usuario

```txt
GET /api/users/{user}/permissions
```

Permiso requerido:

```txt
users.view
```

### Listar roles de la empresa

```txt
GET /api/roles
```

Permiso requerido:

```txt
roles.view
```

### Crear rol

```txt
POST /api/roles
```

Permiso requerido:

```txt
roles.create
```

Body:

```json
{
  "name": "Supervisor POS",
  "permissions": ["pos.view", "pos.checkout", "cash_register.view"]
}
```

### Ver rol

```txt
GET /api/roles/{role}
```

Permiso requerido:

```txt
roles.view
```

### Actualizar rol

```txt
PATCH /api/roles/{role}
```

Permiso requerido:

```txt
roles.update
```

Body:

```json
{
  "name": "Supervisor de caja",
  "permissions": ["cash_register.view", "cash_register.move"]
}
```

Regla:

- los roles base no pueden cambiar de nombre;
- la accion queda registrada en `audit_logs`.

### Actualizar permisos de un rol

```txt
PATCH /api/roles/{role}/permissions
```

Permiso requerido:

```txt
roles.update
```

Body:

```json
{
  "permissions": ["pos.view", "cash_register.view"]
}
```

Regla:

- la accion queda registrada en `audit_logs`.

### Eliminar rol

```txt
DELETE /api/roles/{role}
```

Permiso requerido:

```txt
roles.delete
```

Regla:

- no se pueden eliminar roles base del sistema: `Owner`, `Administrador`, `Gerente`, `Vendedor`, `Almacen`, `Auditor`;
- la accion queda registrada en `audit_logs` antes de eliminar el rol.

### Catalogo de permisos

```txt
GET /api/permissions
```

Permisos requeridos:

```txt
roles.view o users.view
```

Devuelve los permisos agrupados por modulo para construir pantallas de configuracion de roles.

## Garantias

Modulo: `Warranties`

Esta primera fase cubre politicas de garantia reutilizables, asignacion al producto y snapshot historico al vender.

### Listar politicas de garantia

```txt
GET /api/warranty-policies
```

Permiso requerido:

```txt
warranty_policies.view
```

### Crear politica de garantia

```txt
POST /api/warranty-policies
```

Permiso requerido:

```txt
warranty_policies.manage
```

Body:

```json
{
  "name": "Android 30 dias",
  "duration_days": 30,
  "coverage_type": "store",
  "conditions": "Cubre defectos de fabrica."
}
```

Tipos iniciales de cobertura:

- `store`
- `manufacturer`
- `none`

### Ver politica de garantia

```txt
GET /api/warranty-policies/{warrantyPolicy}
```

Permiso requerido:

```txt
warranty_policies.view
```

### Actualizar politica de garantia

```txt
PATCH /api/warranty-policies/{warrantyPolicy}
```

Permiso requerido:

```txt
warranty_policies.manage
```

### Desactivar politica de garantia

```txt
DELETE /api/warranty-policies/{warrantyPolicy}
```

Permiso requerido:

```txt
warranty_policies.manage
```

Reglas:

- la politica no se borra fisicamente; queda `is_active = false`;
- el nombre es unico por empresa;
- una politica de una empresa no puede asignarse a productos de otra empresa;
- los productos pueden recibir `warranty_policy_id` en `POST /api/products` y `PATCH /api/products/{product}`;
- al crear una venta, cada item copia nombre, duracion, tipo y condiciones de la politica del producto;
- al confirmar una venta, cada item con politica guarda `warranty_starts_at` y `warranty_expires_at`;
- si la politica cambia despues, la venta conserva su snapshot historico.

### Listar casos de garantia

```txt
GET /api/warranty-claims
```

Permiso requerido:

```txt
warranties.view
```

### Crear caso de garantia

```txt
POST /api/warranty-claims
```

Permiso requerido:

```txt
warranties.create
```

Body:

```json
{
  "sale_item_id": 1,
  "product_unit_id": 10,
  "quantity": 1,
  "customer_name": "Cliente garantia",
  "customer_phone": "04120000000",
  "issue_description": "Equipo no enciende.",
  "received_notes": "Sin golpes visibles."
}
```

Reglas:

- el item vendido debe pertenecer al tenant actual;
- la venta debe estar confirmada;
- el item debe tener snapshot de garantia;
- la garantia no debe estar vencida;
- productos serializados requieren `product_unit_id`;
- en productos serializados, `product_unit_id` debe estar registrado en `sale_items.product_unit_ids` del item vendido;
- una unidad con caso abierto no puede abrir otro caso;
- si se recibe una unidad serializada, queda en estado `warranty_hold`;
- crear el caso no mueve dinero ni inventario contable en esta fase.

### Ver caso de garantia

```txt
GET /api/warranty-claims/{warrantyClaim}
```

Permiso requerido:

```txt
warranties.view
```

### Revisar caso de garantia

```txt
PATCH /api/warranty-claims/{warrantyClaim}/review
```

Permiso requerido:

```txt
warranties.review
```

Body:

```json
{
  "status": "approved",
  "diagnosis": "Puerto de carga defectuoso.",
  "resolution_type": "repair",
  "resolution_notes": "Se aprueba reparacion."
}
```

Estados permitidos en revision:

- `under_review`
- `approved`
- `rejected`

Resoluciones iniciales:

- `repair`
- `replacement`
- `refund`
- `rejected`
- `pending_review`

### Resolver caso de garantia

```txt
PATCH /api/warranty-claims/{warrantyClaim}/resolve
```

Permiso requerido:

```txt
warranties.resolve
```

Body para reemplazo con IMEI:

```json
{
  "resolution_type": "replacement",
  "replacement_product_unit_id": 25,
  "resolution_notes": "Se entrega equipo nuevo por garantia."
}
```

Body para rechazo:

```json
{
  "resolution_type": "rejected",
  "resolution_notes": "No cubre garantia por humedad."
}
```

Body para reembolso desde caja:

```json
{
  "resolution_type": "refund",
  "refund_currency": "USD",
  "refund_amount": 100,
  "refund_method": "cash",
  "refund_cash_register_session_id": 1,
  "refund_reference": "REF-GAR-001",
  "resolution_notes": "Reembolso en efectivo por garantia."
}
```

Body para reembolso contra saldo pendiente:

```json
{
  "resolution_type": "refund",
  "refund_currency": "USD",
  "refund_amount": 100,
  "apply_to_receivable_balance": true,
  "resolution_notes": "Reembolso aplicado al saldo pendiente."
}
```

Reglas:

- en esta fase ejecuta `replacement`, `rejected` y `refund`;
- `replacement` exige que el caso este `approved` con `resolution_type = replacement`;
- si el producto es serializado, `replacement_product_unit_id` debe estar disponible y pertenecer al mismo producto;
- el IMEI recibido por garantia queda `damaged`;
- el IMEI entregado como reemplazo queda `sold`;
- el reemplazo genera movimiento de inventario `adjustment_out` con referencia al caso de garantia;
- `rejected` cierra el caso y devuelve el IMEI original a `sold` si estaba en `warranty_hold`;
- `refund` exige que el caso este `approved` con `resolution_type = refund`;
- `refund` puede salir de una caja abierta con `refund_cash_register_session_id`;
- `refund` puede aplicarse contra saldo pendiente con `apply_to_receivable_balance = true`;
- no se puede usar caja y rebaja de saldo en el mismo reembolso;
- el monto base del reembolso no puede superar el monto vendido para ese item;
- si el producto serializado se reembolsa, el IMEI recibido por garantia queda `damaged`;
- resolver un caso lo marca como `closed`;
- en reembolso por caja se crea movimiento `outflow` en `cash_register_movements`;
- en reembolso contra saldo se crea ajuste en `financial_adjustments`.

### Entregar caso de garantia

```txt
PATCH /api/warranty-claims/{warrantyClaim}/deliver
```

Permiso requerido:

```txt
warranties.deliver
```

Body:

```json
{
  "resolution_notes": "Equipo entregado al cliente."
}
```

Regla:

- solo se pueden entregar casos `approved` o `rejected`.

## Respuestas y errores comunes

### Sin autenticacion

```txt
401 Unauthorized
```

### Sin permiso

```txt
403 Forbidden
```

### Recurso fuera del tenant actual

```txt
422 Unprocessable Entity
```

### Tenant inexistente o no resuelto

```txt
404 Not Found
```

## Reglas importantes

- Ninguna API debe permitir acceder a datos de otro tenant.
- Las APIs protegidas deben usar token `Bearer` valido o usuario autenticado en pruebas.
- Los tokens de autenticacion deben estar ligados al tenant para evitar uso cruzado entre empresas.
- Ninguna API debe saltarse policies, permisos o servicios autorizados.
- Las APIs de productos deben respetar SKU unico por tenant.
- Las APIs de sucursales y almacenes deben respetar codigo unico por tenant.
- Un almacen nunca debe apuntar a una sucursal de otra empresa.
- Las APIs de moneda deben permitir multiples tipos de tasa por empresa, como `BCV` y `PARALELO`.
- Las APIs de clientes deben vivir en el modulo `Customers` y no mezclar documentos entre empresas.
- Las APIs de proveedores deben vivir en el modulo `Suppliers` y no mezclar documentos entre empresas.
- Las APIs de compras deben vivir en el modulo `Purchases` y usar `Inventory` para recibir stock.
- Las APIs de compras no deben mover inventario al crear borradores.
- Las APIs de devoluciones a proveedor deben vivir en el modulo `PurchaseReturns`.
- Las devoluciones a proveedor deben crear movimientos `purchase_return`, no borrar compras historicas.
- Las APIs de cuentas por pagar deben vivir en el modulo `AccountsPayable`.
- Las cuentas por pagar se crean al recibir compras y se reducen con pagos o devoluciones a proveedor.
- Las APIs de ventas deben copiar precio y tasa exacta usada, no recalcular historia.
- Las APIs de ventas pueden asociar `customer_id`, pero solo del tenant actual.
- Las APIs de cuentas por cobrar deben vivir en el modulo `AccountsReceivable`.
- Las cuentas por cobrar se crean al confirmar ventas y se reducen con cobros o devoluciones de venta.
- Las APIs de comprobantes deben vivir en el modulo `PaymentReceipts`.
- Los comprobantes se generan desde cobros y pagos, y su anulacion no revierte la transaccion original.
- Las APIs de ajustes financieros deben vivir en el modulo `FinancialAdjustments`.
- Los ajustes financieros reducen saldos sin mover inventario ni representar pagos reales.
- Las APIs de reportes financieros deben vivir en el modulo `FinanceReports`.
- Los reportes financieros son solo lectura y resumen cuentas por cobrar, cuentas por pagar, cobros y pagos.
- Las APIs de devoluciones de venta deben vivir en el modulo `SalesReturns`.
- Las devoluciones de venta deben crear movimientos `sale_return`, no borrar ventas historicas.
- Las APIs de POS deben vivir en el modulo `POS` y usar `Sales` como motor de venta.
- Las APIs de POS no deben descontar inventario directamente.
- Las APIs de POS deben asociar checkouts a una caja abierta cuando sean ventas de mostrador.
- Las APIs de POS pueden asociar `customer_id`, pero solo del tenant actual.
- Las APIs de POS deben reflejar pagos `captured` como cobros automaticos en cuentas por cobrar cuando confirman una venta.
- Los pagos POS `pending` no deben cerrar venta ni crear cobros automaticos.
- Las APIs de caja deben vivir en el modulo `CashRegister`, separadas de POS.
- Las APIs de caja deben guardar diferencias de cierre sin alterar ventas historicas.
- Las APIs de inventario modifican stock solo mediante servicios del modulo `Inventory`.
- Las APIs de entradas de productos deben vivir en el modulo `ProductEntries`.
- Las entradas operativas pueden cargar IMEIs/seriales, pero no reemplazan una compra formal con proveedor.
- Las APIs de salidas de productos deben vivir en el modulo `ProductExits`.
- Las salidas operativas no reemplazan ventas, POS ni devoluciones a proveedor.
- Las APIs de transferencias documentadas deben vivir en el modulo `InventoryTransfers`.
- Las transferencias internas mueven stock entre almacenes de una misma empresa; las interempresa requieren solicitud y aceptacion antes de mover inventario.
- Las solicitudes interempresa deben vivir en `InventoryTransferRequests` y no deben mover stock al crearse.
- Las APIs de reportes son solo lectura.
- Las APIs de Kardex son solo lectura y deben calcular saldos desde `stock_movements`.
- Las APIs de usuarios, roles y permisos deben vivir en el modulo `AccessControl`.
- Los roles y permisos deben resolverse por tenant usando el `tenant_id` de Spatie Permission.
- Un usuario puede pertenecer a varias empresas, pero sus roles, permisos y estado deben evaluarse por empresa.
- Las APIs de acceso deben auditar creacion/vinculacion de usuarios, cambios de estado, cambios de roles y cambios de permisos.
- Una empresa no puede quedarse sin usuario activo con rol `Owner` o `Administrador`.
- Las politicas de garantia deben vivir en el modulo `Warranties`.
- Las ventas deben copiar snapshot de garantia para no recalcular historia si cambia la politica del producto.
