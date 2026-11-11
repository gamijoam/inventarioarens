# Auditoría de arqueo y cierre de caja (2026-11-11)

Revisión contable del módulo `CashRegister` con evidencia en datos reales de BalanzaPro y pruebas
de sondeo. Los hallazgos y las correcciones aplicadas están cubiertos por
`tests/Feature/CashRegister/CashRegisterAccountingIntegrityTest.php`.

## Hallazgos y correcciones

| # | Hallazgo | Corrección |
|---|---|---|
| 1 | El backend aceptaba cobros por encima del total. El vuelto en efectivo se sumaba como ingreso (faltante falso al cerrar) y los pagos electrónicos repetidos duplicaban la venta. | `PosCheckoutService::assertNotOverpaid` rechaza checkout y abonos cuyo total capturado supere la venta (+0,01 USD de tolerancia). El POS bloquea antes de enviar (`overpaymentIssue`) y explica que el vuelto se registra con "Recibido". |
| 2 | `difference_base_amount` comparaba el efectivo contado con el total de **todos** los métodos y revaluaba los Bs contados a la tasa del cierre: aparecían faltantes aunque la gaveta cuadrara. | `difference_base_amount` = diferencia de efectivo USD + diferencia en Bs valorada a la tasa del cierre. `counted_base_amount` = efectivo contado equivalente. `expected_base_amount` sigue siendo el total movido (todos los métodos). |
| 3 | El Reporte Z tomaba los pagos de las órdenes: un abono cobrado en el turno 1 y completado en el turno 2 desaparecía del Z 1 e inflaba el Z 2. | El Z construye `payments` (cobros), `refunds` (reembolsos) y `other_movements` desde `cash_register_movements` del propio turno. Expone `expected_cash_*`, `counted_cash_*`, `collected_*` y `non_cash_collected_base_amount`. |
| 4 | Reversar una venta con tarjeta, pago móvil o transferencia no descontaba nada del turno. | `SaleReversalService` registra una salida con el método original y la referencia del reembolso. No afecta el efectivo de la gaveta. |
| 5 | "Ajuste" solo podía sumar. | `type=adjustment` acepta monto negativo (distinto de 0). La UI ofrece "Ajuste sobrante (+)" y "Ajuste faltante (-)". |
| 6 | Se podía cerrar contando 0 con efectivo esperado, y aprobar sin nota un turno con diferencia. | El cierre exige `confirm_zero_count` o `confirm_open_orders` (si quedan órdenes pendientes con abonos). Aprobar un turno con diferencia de efectivo exige `review_notes`. La UI pide la confirmación con el mensaje del backend. |
| 7 | Los turnos podían quedar abiertos durante semanas sin aviso, y los movimientos de caja de un Motor Local no llegaban a la nube. | Aviso en el POS y en el Centro de Cajas cuando el turno supera 24 h. Nuevo evento de sync `cash.movement.recorded` (append-only, identidad `sync_source_node_code` + `sync_source_id`; la sesión viaja antes que sus movimientos). |

## Reglas vigentes

- El arqueo físico se cuadra **por moneda**: `difference_cash_usd` y `difference_cash_ves`. Los cobros
  electrónicos no se cuentan en la gaveta.
- Los históricos no se recalculan: los cierres anteriores conservan el `difference_base_amount` que
  tenían. Los reportes priorizan `difference_cash_usd`.
- Las órdenes 128, 130 y 131 de BalanzaPro (pago "ORO" duplicado) se dejaron sin corregir por
  decisión del usuario.
