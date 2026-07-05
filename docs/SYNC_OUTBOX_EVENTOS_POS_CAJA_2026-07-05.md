# Eventos iniciales de sincronizacion para POS y Caja

## Objetivo

Dejar registrado que POS y Caja ya pueden escribir eventos operativos en `sync_outbox`. Estos eventos aun no se envian a la nube; quedan pendientes para que el futuro worker los suba.

## Servicio creado

Se creo `App\Modules\Sync\Services\SyncOutboxService`.

Responsabilidades:

- Registrar eventos del tenant actual.
- Guardar `event_uuid`, `event_type`, `aggregate_type`, `aggregate_id`, `payload`, `status` e `idempotency_key`.
- Mantener los eventos en estado `pending`.
- Evitar duplicados por `idempotency_key`.

## Eventos de POS

### pos.order.paid

Se registra cuando una orden POS queda completamente pagada.

Casos:

- Venta pagada desde el checkout inicial.
- Orden pendiente que luego recibe pagos suficientes y se cierra.

Aggregate:

- `aggregate_type`: `pos_order`
- `aggregate_id`: id de la orden POS.

Payload:

- `order_id`
- `sale_id`
- `sale_status`
- `cash_register_session_id`
- `customer_id`
- `customer_name`
- `status`
- `cashier_id`
- `total_base_amount`
- `total_local_amount`
- `paid_base_amount`
- `paid_local_amount`
- `payments_count`
- `opened_at`
- `paid_at`
- `closed_at`

### pos.order.pending

Se registra cuando una orden POS queda abierta porque el pago no cubre el total.

Uso futuro:

- Subir a nube la reserva de inventario.
- Permitir auditoria de ventas separadas/apartadas por saldo pendiente.
- Mostrar en panel central que hay ordenes abiertas.

### pos.order.payment_added

Se registra cuando se agrega un pago a una orden pendiente, pero todavia no cubre el total.

Uso futuro:

- Sincronizar avances de cobro.
- Auditar pagos parciales.

## Eventos de Caja

### cash.session.opened

Se registra cuando un cajero abre turno/caja.

Aggregate:

- `aggregate_type`: `cash_register_session`
- `aggregate_id`: id del turno de caja.

Payload:

- `session_id`
- `branch_id`
- `cash_register_id`
- `cashier_id`
- `opened_by`
- `closed_by`
- `status`
- `opening_base_amount`
- `opening_local_amount`
- `expected_base_amount`
- `expected_local_amount`
- `counted_base_amount`
- `counted_local_amount`
- `difference_base_amount`
- `difference_local_amount`
- `opened_at`
- `closed_at`

### cash.session.closed

Se registra cuando el cajero cierra turno/caja.

Uso futuro:

- Subir resumen de cierre a nube.
- Auditar diferencia entre esperado y contado.
- Preparar reportes centrales por caja, cajero y sucursal.

## Reglas actuales

- Todos los eventos se guardan con `status = pending`.
- Todavia no se marcan como enviados ni aplicados.
- Todavia no existe worker local/nube.
- Todavia no se emiten eventos de inventario, productos, precios ni clientes.
- POS y Caja no dependen de internet para registrar estos eventos.

## Pruebas ejecutadas

- `SyncSchemaTest`
- `PosCheckoutApiTest`
- `CashRegisterApiTest`

