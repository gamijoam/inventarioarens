# F6 sobre una orden recuperada (28-10-2026)

En el POS 0.2.72, `buildHoldPayload()` ya enviaba `order_id` al recuperar un pendiente, pero el Motor 0.1.6 lo filtraba en `StorePosHoldRequest` y `holdOrder()` siempre creaba otra venta y otra orden. La vista clásica registraba además F6 tanto en `ClassicPosView` como en `PosTerminal`, con dos envíos simultáneos al primer guardado.

El endpoint de espera ahora valida la pertenencia de `order_id` a la empresa, verifica permiso de lectura, y solo permite modificar una orden abierta con venta borrador y sin pagos. Dentro de una transacción libera la reserva anterior, sustituye las líneas y promociones del **mismo** borrador, actualiza la **misma** orden y vuelve a reservar las cantidades nuevas. Un error de stock revierte toda la operación. Un pedido nuevo sigue devolviendo HTTP 201; una actualización devuelve HTTP 200.

El atajo global F6 queda a cargo de `PosTerminal`; el botón de espera en `ClassicPosView` conserva `onHoldSale`. Pruebas: `tests/Feature/POS/PosHoldOrderApiTest.php` cubre recuperación repetida, IDs, stock, cobro posterior, rollback por falta de stock, pago previo y aislamiento por empresa.

Esta PC usa un Motor 0.1.6 instalado con cambios de lista de precios adicionales a esta rama. El hotfix local se aplicó sobre esos archivos instalados, conservando sus cambios, y sobre el `app.asar` compilado 0.2.72. Respaldo de PHP, ASAR y SQLite: `C:\ProgramData\InventarioArens\backups\pos-hold-fix-20261028-205953`. Una reinstalación del Motor o del cliente debe incorporar el fix antes de reemplazar esos archivos.
# Seguimiento: stock unitario al recuperar y volver a guardar

El Motor conserva la reserva de la orden al volver a guardarla (prueba API con una sola unidad disponible). El cliente POS recuperaba la unidad reservada, pero `refreshCartProducts` volvía a tomar exclusivamente `product.available_stock`, que es cero porque la unidad pertenece a esa misma orden. Ahora la línea recuperada guarda `reserved_stock` como snapshot de la cantidad original y calcula `available_stock = stock libre + reserva propia` tanto al recuperarla como al refrescarla. Aumentar la cantidad por encima de la reserva continúa requiriendo stock libre adicional.

Pruebas: `PosHoldOrderApiTest.php` 17/17; pruebas frontend POS 186/186; `tsc --noEmit` correcto. La copia local del cliente 0.2.72 recibió un hotfix del bundle y se verificó que `http://127.0.0.1:8791/assets/pos-C7Z31xSX.js` entrega la nueva lógica. Se guardaron `app.asar` y una copia SQLite en `%ProgramData%\InventarioArens\backups\pos-pending-stock-20261028-211821`. Para conservarlo al reinstalar hace falta empaquetar un release nuevo del POS.
