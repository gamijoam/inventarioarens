# POS - Rendimiento y medición

## Objetivo

Detectar y reducir esperas visibles en el POS, especialmente al abrir el módulo y al agregar productos al carrito.

## Implementado

- Se creó una herramienta interna `PerformanceTrace` para medir operaciones lentas en la app de escritorio.
- Cada llamada a la API registra su duración en el log local de escritorio.
- El log queda en:
  `C:\Users\<usuario>\AppData\Local\SistemaInventario\desktop.log`
- Al abrir el POS, la pantalla se muestra primero y luego se carga el contexto operativo.
- La carga inicial del POS ahora ejecuta en paralelo datos independientes:
  - listas de precio,
  - almacenes.
- Luego se cargan cajas abiertas.
- El POS ya no carga productos al abrir la pantalla.
- Una búsqueda vacía tampoco trae catálogo completo.
- Los productos se consultan bajo demanda cuando el cajero escribe al menos 2 caracteres, escanea un código o abre el selector con texto.
- Los métodos de pago se cargan cuando se abre la ventana de cobro o cuando una pantalla de cobro pendiente los necesita.
- Si el POS ya fue inicializado antes, al volver a entrar refresca la caja abierta sin recargar todo el contexto estático ni el catálogo.
- Al agregar un producto con `Precio base` y moneda `USD`, WPF usa el precio que ya viene en el catálogo y evita pedir una cotización adicional.
- El checkout final sigue validando precios, stock, caja, seriales y pagos en Laravel.

## Qué revisar si algo sigue lento

- Entradas `PERF LENTO` en `desktop.log`.
- Duración de `API GET inventory-center/summary`.
- Duración de `API GET products/{id}/price`.
- Duración de `POS agregar producto`.
- Duración de `Abrir módulo POS`.

## Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se volvió a ejecutar la compilación después de cambiar el POS a búsqueda bajo demanda.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## Ajuste de cobro rápido

- Las listas de precio, almacenes y métodos de pago quedan cacheados durante la sesión WPF.
- Si el usuario presiona recargar contexto, se fuerza la actualización de almacenes y cajas abiertas.
- Al confirmar una venta, WPF ya no refresca la búsqueda de productos antes de mostrar el recibo.
- Primero se registra el checkout en Laravel, se limpia el carrito y se muestra el resumen de venta.
- La ventana de cobro muestra un mensaje visible de procesamiento mientras Laravel valida caja, stock, seriales y pagos.
- Se bloquearon solo los botones de cancelar y confirmar durante el procesamiento, evitando que la ventana parezca cerrada o congelada.
- El log ahora mide `POS confirmar venta`, `POS checkout backend` y `POS preparar recibo`.

## Pruebas de cobro rápido

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
