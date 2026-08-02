# Modalidades de envio interempresa

Las guias interempresa conservan el mismo flujo y permisos:

`Aceptada -> Guia borrador -> Preparada -> Despachada -> Entregada -> Recibida`

La preparacion de la guia ofrece dos modalidades:

## Envio simple

Es la modalidad predeterminada para operaciones internas o clientes que solo necesitan validar la
mercancia. La persona que prepara la guia debe confirmar cantidades, diferencias y los IMEI/seriales
cuando el producto sea serializado. Los datos del transportista son opcionales.

## Envio controlado

Se usa cuando se necesita trazabilidad del transporte. El nombre del transportista es obligatorio y
se pueden registrar usuario transportista, empresa, documento, telefono y placa.

La modalidad se guarda en `inventory_transfer_request_guides.transport_mode` y no cambia el control de
permisos. Preparar, despachar y recibir siguen requiriendo sus permisos respectivos. La modalidad
simple tampoco aplica stock por si sola: el stock se mueve durante la recepcion, igual que antes.

Los datos de transporte son auditables y pueden completarse desde la preparacion. No se eliminan las
notificaciones ni los pasos de despacho y recepcion.
