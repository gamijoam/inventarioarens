# Sync Outbox para inventario y precios

Fecha: 2026-07-06

## Objetivo

Conectar las operaciones principales de catalogo, precios y movimientos de inventario con `sync_outbox`, para que el worker local pueda subir esos cambios a la nube y dejarlos disponibles para otras instalaciones.

## Eventos generados

### Productos

- `product.created`: se registra al crear un producto desde la API.
- `product.updated`: se registra al editar datos comerciales, moneda, tasa, garantia, tipo de control o al desactivar el producto.

Payload principal:

- `sku`
- `name`
- `tracking_type`
- `base_price`
- `sale_currency`
- `sale_exchange_rate_type_code`
- `warranty_policy_id`
- `warranty_policy_name`
- `is_active`

### Listas de precio

- `price_list.created`: se registra al crear una lista.
- `price_list.updated`: se registra al editar o desactivar una lista.

Payload principal:

- `code`
- `name`
- `description`
- `is_default`
- `is_active`
- `sort_order`
- `payment_method_codes`

### Precios por producto

- `product_price.created`: se registra cuando un producto recibe precio en una lista por primera vez.
- `product_price.updated`: se registra cuando cambia precio, moneda, tasa o estado de ese precio.

Payload principal:

- `sku`
- `price_list_code`
- `price`
- `currency`
- `exchange_rate_type_code`
- `is_active`

### Entradas y salidas

- `product_entry.created`: se registra al procesar una entrada de inventario.
- `product_exit.created`: se registra al procesar una salida de inventario.

Payload principal:

- `document_number`
- `reason`
- `reference`
- `notes`
- `processed_at`
- `items` con `sku`, `warehouse_code`, `quantity` y seriales/unidades cuando aplique.

## Aplicacion automatica actual

El aplicador de eventos (`SyncEventApplier`) ya aplica automaticamente:

- productos creados o actualizados;
- listas de precio creadas o actualizadas;
- precios por producto creados o actualizados;
- tasas, tipos de tasa y metodos de pago.

Los eventos de entradas y salidas ya se suben como eventos operativos, pero su aplicacion automatica en otro nodo queda para una fase posterior. Esa fase debe definir reglas contra duplicacion de stock, origen de autoridad y conflictos entre sucursales.

## Regla de diseno

Los eventos usan claves de negocio como `sku`, `price_list_code` y `warehouse_code` para no depender de IDs locales, porque cada base de datos puede tener IDs diferentes.

## Pruebas ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Products\ProductApiTest.php tests\Feature\ProductEntries\ProductEntryApiTest.php tests\Feature\ProductExits\ProductExitApiTest.php tests\Feature\Sync
```

Resultado:

- 41 pruebas pasadas.
- 244 aserciones.
