# Implementacion traslados - Fase 5: recepcion logistica

## Objetivo

Cerrar el ciclo logistico de traslados internos con una recepcion controlada por checklist, permitiendo confirmar lo recibido en destino y registrar diferencias cuando llegue menos de lo despachado.

## Cambios realizados

- Se agrego el endpoint `POST /api/inventory-transfers/{inventoryTransfer}/receive`.
- Se agrego el permiso `inventory_transfers.receive` a la politica del modulo.
- Se creo `ReceiveInventoryTransferRequest` para validar cantidades, IMEIs recibidos, motivos y notas de diferencia.
- El despacho ahora prepara el checklist de recepcion pendiente.
- La recepcion mueve stock al almacen destino con movimiento `transfer_in`.
- Los IMEIs recibidos cambian al almacen destino y quedan disponibles.
- La guia puede cerrar como `completed` o `completed_with_differences`.
- El checklist de recepcion puede cerrar como `completed` o `completed_with_differences`.

## Flujo operativo

1. Se crea el traslado logistico.
2. El origen prepara la mercancia y reserva stock.
3. El origen despacha y descuenta el stock reservado.
4. El destino recibe la guia.
5. Si todo coincide, el traslado queda completado.
6. Si hay faltantes, se exige motivo y el traslado queda completado con diferencias.

## Reglas importantes

- Solo se puede recibir un traslado en estado `dispatched`.
- No se puede recibir mas de lo despachado.
- Si se recibe menos, se debe indicar `difference_reason`.
- En productos serializados, solo se aceptan IMEIs o seriales despachados en la guia.
- Los IMEIs no recibidos quedan pendientes de resolucion futura.

## Pruebas ejecutadas

```txt
php artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Resultado:

```txt
17 tests, 153 assertions
```

## Pendiente recomendado

- Crear una fase de resolucion de diferencias para decidir si un faltante queda en investigacion, vuelve al origen, se marca como perdido o se ajusta administrativamente.
- Integrar la recepcion logistica en la app de escritorio con una ventana de checklist para el receptor.
- Llevar el monitoreo de diferencias al portal web administrativo.
