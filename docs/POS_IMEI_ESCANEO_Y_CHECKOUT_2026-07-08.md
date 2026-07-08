# POS - IMEI en escaneo y checkout

## Objetivo

Cerrar el flujo operativo para productos serializados/IMEI dentro del POS de escritorio.

## Implementacion

- El POS ya exige seleccionar un IMEI disponible cuando el producto es serializado.
- El carrito evita repetir el mismo IMEI en la orden actual.
- Las lineas serializadas quedan con cantidad fija de 1; para vender otra unidad se debe escoger otro IMEI.
- El checkout envia `product_unit_ids` al backend.
- Laravel valida que el IMEI pertenezca al producto, al almacen y a la empresa actual.
- Laravel rechaza IMEIs no disponibles, reservados o vendidos por otra caja.
- En ventas pendientes, el IMEI queda reservado hasta completar el cobro.
- Al completar la venta, el IMEI cambia a vendido y queda asociado al `sale_item`.

## Ajuste agregado

Se mejoro el escaneo directo desde el buscador principal del POS:

- Si el usuario escribe o pistolea un IMEI y presiona Enter, el POS busca el producto.
- Si el resultado contiene un producto serializado con ese IMEI disponible, lo agrega directo al carrito.
- Si el IMEI no se puede resolver de forma unica, el POS mantiene el selector manual.

## Archivos modificados

- `desktop/InventoryDesktop/Modules/POS/PosViewModel.cs`
- `desktop/InventoryDesktop/Modules/POS/PosView.xaml.cs`
- `desktop/InventoryDesktop/Modules/POS/README.md`

## Pruebas requeridas

- Compilar la app WPF.
- Ejecutar pruebas especificas del POS en PostgreSQL.
- Probar manualmente:
  - Escanear un IMEI disponible.
  - Verificar que cae al carrito con su etiqueta IMEI.
  - Intentar agregar el mismo IMEI dos veces.
  - Confirmar venta y verificar que el backend marque el IMEI como vendido.
