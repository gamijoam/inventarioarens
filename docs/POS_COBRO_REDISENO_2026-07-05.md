# POS - Rediseno de ventana de cobro

## Objetivo

Simplificar la ventana **Cobrar venta** para que el cajero tenga mas espacio util al registrar pagos.

La pantalla anterior repetia informacion:

- Resumen de productos en el encabezado.
- Total y equivalente en la parte superior derecha.

Esa informacion ya esta representada por el flujo del POS y por el panel de pagos, por eso se retiro del encabezado.

## Cambios aplicados

- Se elimino visualmente el resumen de productos del encabezado.
- Se elimino visualmente el total superior redundante.
- Se mantuvo el contexto de lista de precio y caja.
- Se amplio el area operativa de **Nuevo pago**.
- Se agrandaron los campos de metodo de pago, moneda, monto recibido, referencia y estado.
- Se agrandaron los botones **Completar saldo**, **Borrar monto** y **Agregar pago**.
- Se amplio la ventana para dar mas aire al formulario y a la tabla de pagos agregados.

## Comportamiento esperado

La ventana de cobro debe enfocarse en:

1. Seleccionar metodo de pago.
2. Registrar moneda y monto recibido.
3. Agregar uno o varios pagos.
4. Revisar pagado, faltante y vuelto.
5. Confirmar la venta.

## Pruebas ejecutadas

Compilacion WPF:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- Compilacion correcta.
- 0 errores.

Pruebas backend del flujo POS:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/Auth/AuthApiTest.php
```

Resultado:

- 24 pruebas pasadas.
- 157 aserciones.
