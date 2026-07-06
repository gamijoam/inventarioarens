# Foto inicial de catalogo desde la nube

## Objetivo

Corregir el caso de una computadora local limpia donde la empresa se configuraba correctamente, pero no bajaban productos, almacenes, cajas, tasas, precios ni seriales.

Antes de este ajuste, la sincronizacion era incremental: si el catalogo ya existia en la nube antes de configurar el local, no habia eventos pendientes para ese nuevo nodo. Por eso el local podia quedar con empresas registradas, pero sin catalogo operativo.

## Comportamiento nuevo

Cuando el worker local detecta que la empresa no tiene catalogo base, solicita una foto inicial al registrar el nodo contra la nube.

La nube prepara eventos dirigidos solo a esa instalacion local. Esos eventos se descargan y aplican con el mismo canal normal de sincronizacion.

Esto no es un seed local. Los datos salen desde la base de datos de la nube.

## Datos incluidos

La foto inicial incluye:

- sucursales;
- almacenes;
- tipos de tasa;
- tasas;
- metodos de pago;
- listas de precio;
- productos;
- precios por producto;
- movimientos de stock;
- seriales e IMEI;
- cajas fisicas.

## Eventos generados

- `branch.created`
- `warehouse.created`
- `exchange_rate_type.created`
- `exchange_rate.created`
- `payment_method.created`
- `price_list.created`
- `product.created`
- `product_price.created`
- `stock_movement.created`
- `product_unit.created`
- `cash_register.created`

## Regla de stock

Los movimientos de stock que llegan por foto inicial se guardan localmente como `sync_snapshot`.

Esto permite reconstruir disponibilidad local sin confundir esos movimientos con entradas o salidas manuales hechas por un operador.

## Regla de recuperacion

Si una computadora estaba marcada como sincronizada, pero no tiene catalogo base, el worker vuelve a pedir la foto inicial.

Asi se evita que una base local quede en estado listo sin productos.

## Pruebas ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncApiTest.php tests\Feature\Sync\SyncEventApplierTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 18 pruebas pasadas;
- 119 aserciones.
