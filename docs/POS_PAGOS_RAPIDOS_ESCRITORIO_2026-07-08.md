# POS escritorio: pagos rápidos

## Objetivo

Reducir pasos en el cobro del POS para ventas comunes. El cajero debe poder completar una venta con menos clics, sin perder validaciones de backend.

## Implementación

Se agregó una sección **Pagos rápidos** en la ventana `Cobrar venta`.

La ventana toma los métodos activos permitidos para la lista de precio seleccionada y crea botones compactos para los primeros métodos disponibles.

Al presionar un botón de pago rápido:

1. Se selecciona el método de pago.
2. Se coloca el estado como `Capturado`.
3. Se completa automáticamente el faltante según la moneda del método.
4. Si el método no exige referencia, el pago se agrega de inmediato.
5. Si el método exige referencia, el monto queda listo y el cursor pasa al campo de referencia.

## Reglas

- No se omite ninguna validación del backend.
- El checkout final sigue validando caja, stock, seriales, método de pago, moneda, referencia y lista de precio.
- Si el método exige referencia, el pago no se registra automáticamente.
- Si no se puede calcular el faltante en bolívares por falta de tasa, se muestra el error operativo existente.

## Archivos modificados

- `desktop/InventoryDesktop/Modules/POS/PosPaymentWindow.xaml`
- `desktop/InventoryDesktop/Modules/POS/PosPaymentWindow.xaml.cs`
- `desktop/InventoryDesktop/Modules/POS/README.md`

## Prueba realizada

Se compiló la aplicación de escritorio:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore -o .build\inventory-desktop-build-check
```

Resultado: compilación correcta, sin errores ni advertencias.
