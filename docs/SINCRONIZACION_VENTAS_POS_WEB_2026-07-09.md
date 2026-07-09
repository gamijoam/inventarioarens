# Sincronizacion de ventas POS hacia el portal web

## Objetivo

Garantizar que una venta realizada en la aplicacion de escritorio quede visible en el portal administrativo web, especialmente en el apartado de Ventas POS, metricas del dia, detalle de productos vendidos y pagos capturados.

## Problema detectado

El POS local generaba eventos de sincronizacion como `pos.order.paid`, pero el receptor de sincronizacion no convertia esos eventos en registros reales de:

- `sales`
- `pos_orders`
- `sale_items`
- `pos_payments`

Por eso la sincronizacion podia decir que habia procesado eventos, pero la pagina web seguia mostrando cero ventas. El panel web consulta tablas operativas, no el historial de eventos.

## Implementacion realizada

Se agrego identidad de origen para evitar choques entre computadoras locales:

- `sync_source_node_code`
- `sync_source_id`

Estas columnas permiten saber que una venta viene, por ejemplo, de `LOCAL-VAL-01` con orden local `99`, sin reutilizar ese numero como ID global en la nube.

Tablas actualizadas:

- `sales`
- `pos_orders`
- `sale_items`
- `pos_payments`

Tambien se guardan datos de referencia de la venta sincronizada:

- sucursal
- caja
- cajero
- documento del cliente

Esto permite que el reporte web muestre informacion clara aunque la sesion de caja local no exista como la misma fila en la nube.

## Eventos POS soportados

El aplicador de sincronizacion ahora procesa:

- `pos.order.pending`
- `pos.order.payment_added`
- `pos.order.paid`
- `pos.order.cancelled`

Los eventos viejos con solo resumen de orden tambien pueden reprocesarse para que al menos aparezca la venta. Los eventos nuevos incluyen productos y pagos.

## Pagos mixtos y tasas

La venta guarda los precios por item. Cada pago guarda su propia moneda y tasa.

Ejemplo operativo:

- Producto vendido por `USD 50`.
- Cliente paga `USD 40` en efectivo.
- Cliente paga el equivalente de `USD 10` en bolivares usando tasa `PARALELO`, no necesariamente BCV.

En ese caso:

- El item mantiene su precio de venta.
- El pago en USD se registra como `USD`.
- El pago en Bs se registra como `VES`, con su `exchange_rate_type_code` y `exchange_rate`.
- El sistema calcula el equivalente base en USD para saber cuanto falta, cuanto se pago y si hay vuelto.

Esto permite mezclar efectivo USD, pago movil, transferencia, tarjeta u otros metodos sin perder la tasa usada en cada parte del cobro.

## Pruebas realizadas

Se ejecutaron pruebas especificas en PostgreSQL local:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Sync/SyncEventApplierTest.php tests/Feature/AdminPortal/AdminPosSalesApiTest.php tests/Feature/POS/PosCheckoutApiTest.php
```

Resultado:

- 34 pruebas aprobadas
- 239 aserciones aprobadas

## Pasos para aplicar en local y nube

Luego de hacer pull en cada ambiente:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan migrate
```

En el VPS:

```bash
cd /opt/inventarioarens-cloud
php artisan migrate
```

Despues de migrar, cualquier venta POS nueva deberia sincronizarse y aparecer en el portal web dentro del periodo seleccionado.
