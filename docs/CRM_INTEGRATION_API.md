# API de integracion CRM e IA

Esta API permite que un CRM consulte el catalogo y la disponibilidad de inventario
sin acceso directo a PostgreSQL/SQLite y sin permisos para modificar datos.

## Modelo de seguridad

- La credencial es un token exclusivo de CRM. No reutilizar tokens de login, sync o impresion.
- El token se almacena solo como SHA-256. El valor plano se muestra una unica vez al crearlo o rotarlo.
- Toda credencial pertenece a un tenant y siempre tiene fecha de expiracion.
- El header `X-Tenant` es opcional para esta API. Si se envia, debe coincidir con el tenant del token.
- Las respuestas read-only nunca incluyen `average_cost`, `last_purchase_cost`, margenes, clientes,
  ventas, pagos ni otra informacion financiera.
- Los accesos quedan registrados en `audit_logs` como `crm.api.access`.
- El limite por defecto es 60 solicitudes por minuto por token. Se configura con
  `CRM_RATE_LIMIT_PER_MINUTE`.
- Un balance se marca `is_stale=true` cuando su `as_of` tiene mas de 30 minutos. Se configura con
  `CRM_STOCK_STALE_AFTER_MINUTES`.

## Estrategia de tenant y sucursal

- Un `tenant` representa la empresa completa y puede tener muchas sucursales.
- Una sucursal no es un tenant independiente: `branches.tenant_id` la vincula a la empresa.
- Un almacen pertenece a una sucursal mediante `warehouses.branch_id`; la existencia se guarda en
  `stock_balances` por tenant, almacen y producto.
- Los IDs son los IDs internos de la empresa. Los codigos son estables por tenant. El `slug` de una
  sucursal se persiste y se genera desde el nombre al crearla; cambiar el nombre no lo cambia.
- Para la operacion CRM se exponen estas sucursales por sus slugs: `boca-de-aroa`, `chichiriviche`,
  `cumarebo`, `guigue`, `mirimire`, `tucacas` y `yaracal`.
- La sucursal `tiendas-arens` se excluye siempre de esta integracion, aunque exista en el tenant o
  este incluida en un token.
- El CRM debe cargar `branch_id`, `branch_code`, `branch_name` y `slug` desde `GET /branches`; no
  debe hardcodear IDs.

## Scopes

Los scopes permitidos son:

| Scope | Permite |
|---|---|
| `catalog.read` | Consultar productos activos y sus precios de venta |
| `inventory.read` | Consultar cantidades de inventario |
| `branches.read` | Consultar sucursales y almacenes autorizados |

Un token puede restringirse adicionalmente con `branch_ids` y/o `warehouse_ids`.

- `null`: sin restriccion para ese tipo de recurso dentro del tenant.
- `[]`: sin recursos autorizados.
- `branch_ids` y `warehouse_ids` combinados: se aplica la interseccion.
- Una solicitud directa a un recurso fuera del alcance responde `403`.

## Administracion de tokens

Estas rutas requieren un token de usuario normal, `X-Tenant` y el permiso
`settings.manage`.

### Crear

```http
POST /api/crm/integration-tokens
Authorization: Bearer <user-token>
X-Tenant: empresa-demo
Content-Type: application/json
```

```json
{
  "name": "CRM produccion",
  "scopes": ["catalog.read", "inventory.read", "branches.read"],
  "branch_ids": [12, 18],
  "warehouse_ids": [31, 32],
  "expires_at": "2027-08-29T00:00:00Z"
}
```

La respuesta `201` incluye `data.token` una sola vez. No guardar el token en el
repositorio ni enviarlo al modelo de IA; guardarlo en el secreto del conector CRM.

### Listar

```http
GET /api/crm/integration-tokens
Authorization: Bearer <user-token>
X-Tenant: empresa-demo
```

La respuesta incluye metadatos, scopes, restricciones, prefijo, expiracion y uso
reciente, pero nunca `token_hash` ni el token plano.

### Revocar y rotar

```http
DELETE /api/crm/integration-tokens/{id}
POST /api/crm/integration-tokens/{id}/rotate
```

Rotar reemplaza el secreto anterior. El secreto anterior queda invalido
inmediatamente y el nuevo se muestra una sola vez.

## Endpoints read-only

La autenticacion usa exclusivamente:

```http
Authorization: Bearer crm_<secret>
Accept: application/json
```

No se permite autenticacion por cookie en estos endpoints.

### Sucursales

```http
GET /api/v1/integrations/crm/branches?per_page=50
```

Devuelve solo sucursales activas y autorizadas por el token. Se puede usar
`status=inactive` o `status=all` para operadores que necesiten sincronizar
catalogos administrativos.

Cada elemento tiene esta forma:

```json
{
  "branch_id": 12,
  "branch_code": "CENTRO",
  "branch_name": "Sucursal Centro",
  "slug": "sucursal-centro",
  "status": "active",
  "location": null
}
```

### Almacenes

```http
GET /api/v1/integrations/crm/warehouses?branch_id=12
```

Devuelve almacenes autorizados y su sucursal. `branch_id` tambien se valida contra
el alcance del token.

### Catalogo

```http
GET /api/v1/integrations/crm/products?search=iphone&per_page=50
GET /api/v1/integrations/crm/products/{sku}
```

El producto incluye identificacion, descripcion, unidad, control de stock, precio
de venta, moneda, marca, categorias, imagen y estado. El catalogo es tenant-scoped;
la disponibilidad por sucursal/almacen se consulta por el endpoint de inventario.

### Disponibilidad

```http
GET /api/v1/integrations/crm/inventory/availability?sku=PHONE-A
GET /api/v1/integrations/crm/inventory/availability?branch_id=12
GET /api/v1/integrations/crm/inventory/availability?warehouse_id=31&per_page=100
GET /api/v1/integrations/crm/inventory/availability?product_ids[]=44&product_ids[]=45
GET /api/v1/integrations/crm/inventory/availability?sku=PHONE-A&branch_id=12&include_alternatives=true
```

Filtros soportados: `sku`, `product_id`, `product_ids` (maximo 100), `search`,
`branch_id`, `warehouse_id`, `include_alternatives` y `per_page` (maximo 100).

Respuesta de ejemplo:

```json
{
  "data": [
    {
      "product_id": 44,
      "sku": "PHONE-A",
      "name": "Phone A",
      "sale_price": 1200,
      "sale_currency": "USD",
      "unit_of_measure": "unit",
      "tracking_type": "quantity",
      "branch_id": 12,
      "branch_code": "CENTRO",
      "branch_name": "Sucursal Centro",
      "branch_slug": "sucursal-centro",
      "warehouse_id": 31,
      "warehouse_code": "CENTRO-01",
      "warehouse_name": "Almacen Centro",
      "available_quantity": 7,
      "reserved_quantity": 2,
      "damaged_quantity": 1,
      "has_availability": true,
      "as_of": "2026-08-29T14:20:00+00:00",
      "is_stale": false,
      "stock_source": "stock_balances",
      "last_sync_at": null,
      "requested_location": {
        "branch_id": 12,
        "branch_code": "CENTRO",
        "branch_name": "Sucursal Centro",
        "branch_slug": "sucursal-centro",
        "warehouse_id": 31,
        "warehouse_code": "CENTRO-01",
        "warehouse_name": "Almacen Centro",
        "available_quantity": 7,
        "reserved_quantity": 2,
        "damaged_quantity": 1,
        "has_availability": true,
        "as_of": "2026-08-29T14:20:00+00:00",
        "is_stale": false
      },
      "alternatives": [],
      "warehouses": [
        {
          "warehouse_id": 31,
          "warehouse_code": "CENTRO-01",
          "warehouse_name": "Almacen Centro",
          "branch_id": 12,
          "branch_code": "CENTRO",
          "branch_name": "Sucursal Centro",
          "branch_slug": "sucursal-centro",
          "available_quantity": 7,
          "reserved_quantity": 2,
          "damaged_quantity": 1,
          "as_of": "2026-08-29T14:20:00+00:00",
          "is_stale": false
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 1
  }
}
```

`available_quantity` es la existencia disponible, no la existencia fisica total.
`reserved_quantity` no se puede vender; `damaged_quantity` tampoco debe ofrecerse.
`as_of` representa la fecha mas reciente de los balances incluidos. `last_sync_at`
indica el ultimo sync exitoso conocido por el tenant y puede ser `null` en una
instalacion sin nodos sincronizados.

Cuando se envia `branch_id` o `warehouse_id`, las cantidades principales corresponden
solo a la ubicacion solicitada. `has_availability` es falso si no hay cantidad
disponible, aunque exista stock reservado o danado. `alternatives` solo se llena con
otras sucursales autorizadas que tengan `available_quantity > 0`; nunca incluye la
sucursal solicitada ni `tiendas-arens`.

Ejemplos de estados para el CRM:

### Producto disponible en la sucursal seleccionada

```json
{
  "sku": "PHONE-A",
  "branch_id": 12,
  "available_quantity": 7,
  "has_availability": true,
  "is_stale": false,
  "alternatives": []
}
```

### Sin stock seleccionado, disponible en otra sucursal

```json
{
  "sku": "PHONE-B",
  "branch_id": 12,
  "available_quantity": 0,
  "has_availability": false,
  "alternatives": [
    {
      "branch_id": 18,
      "branch_code": "NORTE",
      "branch_name": "Sucursal Norte",
      "branch_slug": "sucursal-norte",
      "available_quantity": 6,
      "reserved_quantity": 1,
      "damaged_quantity": 0,
      "as_of": "2026-08-29T14:20:00+00:00",
      "is_stale": false
    }
  ]
}
```

### Sin disponibilidad en ninguna sucursal autorizada

```json
{
  "sku": "PHONE-C",
  "branch_id": 12,
  "available_quantity": 0,
  "reserved_quantity": 2,
  "damaged_quantity": 1,
  "has_availability": false,
  "alternatives": []
}
```

### Inventario desactualizado

```json
{
  "sku": "PHONE-D",
  "branch_id": 12,
  "available_quantity": 2,
  "has_availability": true,
  "as_of": "2026-08-29T10:00:00+00:00",
  "is_stale": true
}
```

## Errores

- `401`: token ausente, invalido, expirado o revocado.
- `403` con `error=insufficient_scope`: falta el scope o el recurso esta fuera del alcance.
- `404` con `error=not_found`: tenant indicado inexistente, sucursal/almacen no existente o SKU no encontrado.
- `422`: filtros o payload de administracion invalidos.
- `429`: se excedio el rate limit del token.

Ejemplo de scope insuficiente:

```json
{
  "message": "La credencial CRM no tiene el scope 'inventory.read'.",
  "error": "insufficient_scope",
  "required_scope": "inventory.read"
}
```

## Rendimiento y limites

- La paginacion por defecto es 25 y el maximo es 100 para productos, sucursales y almacenes.
- `product_ids` acepta como maximo 100 IDs por solicitud.
- Disponibilidad carga una pagina de productos y sus balances autorizados en lote; no hace una
  solicitud ni una consulta independiente por producto.
- La lista de almacenes y los datos de sucursal se obtienen con eager loading; no se permite un
  filtro SQL arbitrario.
- El objetivo operativo es responder en menos de 300 ms para una pagina de hasta 25 productos en
  condiciones normales. El CRM debe usar `as_of`/`is_stale` y no asumir frescura por latencia HTTP.
- El rate limit por defecto es 60 solicitudes por minuto por credencial. Ante `429`, respetar el
  header de retry si el proxy lo agrega y aplicar backoff.

## Reglas para el agente de IA

1. Usar solo `GET` y solo los endpoints `/api/v1/integrations/crm/*`.
2. No construir SQL, no solicitar credenciales de base de datos y no intentar usar endpoints internos.
3. Para responder disponibilidad, indicar siempre la sucursal/almacen y la fecha `as_of`.
4. No afirmar que un producto esta disponible si `available_quantity` es cero o si el balance esta desactualizado.
5. No exponer costos, margenes, ventas, pagos, clientes ni informacion de otros tenants.
6. Ante `401`, `403` o `429`, detener el reintento automatico y reportar el error al operador.
