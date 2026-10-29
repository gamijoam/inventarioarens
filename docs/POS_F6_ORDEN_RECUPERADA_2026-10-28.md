# F6 sobre una orden recuperada (28-10-2026)

En el POS 0.2.72, `buildHoldPayload()` ya enviaba `order_id` al recuperar un pendiente, pero el Motor 0.1.6 lo filtraba en `StorePosHoldRequest` y `holdOrder()` siempre creaba otra venta y otra orden. La vista clásica registraba además F6 tanto en `ClassicPosView` como en `PosTerminal`, con dos envíos simultáneos al primer guardado.

El endpoint de espera ahora valida la pertenencia de `order_id` a la empresa, verifica permiso de lectura, y solo permite modificar una orden abierta con venta borrador y sin pagos. Dentro de una transacción libera la reserva anterior, sustituye las líneas y promociones del **mismo** borrador, actualiza la **misma** orden y vuelve a reservar las cantidades nuevas. Un error de stock revierte toda la operación. Un pedido nuevo sigue devolviendo HTTP 201; una actualización devuelve HTTP 200.

El atajo global F6 queda a cargo de `PosTerminal`; el botón de espera en `ClassicPosView` conserva `onHoldSale`. Pruebas: `tests/Feature/POS/PosHoldOrderApiTest.php` cubre recuperación repetida, IDs, stock, cobro posterior, rollback por falta de stock, pago previo y aislamiento por empresa.

Esta PC usa un Motor 0.1.6 instalado con cambios de lista de precios adicionales a esta rama. El hotfix local se aplicó sobre esos archivos instalados, conservando sus cambios, y sobre el `app.asar` compilado 0.2.72. Respaldo de PHP, ASAR y SQLite: `C:\ProgramData\InventarioArens\backups\pos-hold-fix-20261028-205953`. Una reinstalación del Motor o del cliente debe incorporar el fix antes de reemplazar esos archivos.
