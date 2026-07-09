# Implementacion traslados - Fase 4: despacho logistico

Fecha: 2026-07-09

## Resumen

Se agrego la fase de despacho para traslados logisticos. Esta fase ocurre despues de la preparacion/checklist y antes de la recepcion en destino.

El objetivo es que la mercancia preparada salga formalmente del almacen origen, sin entrar todavia al almacen destino. Esto permite representar el estado real de una guia en transito.

## Cambios principales

- Nuevo endpoint para despachar traslados logisticos:

```txt
POST /api/inventory-transfers/{inventoryTransfer}/dispatch
```

- Nuevo request:

```txt
app/Modules/InventoryTransfers/Requests/DispatchInventoryTransferRequest.php
```

- Nuevo permiso usado por policy:

```txt
inventory_transfers.dispatch
```

- Nuevo estado de guia:

```txt
dispatched
```

- Nuevo flujo de stock:

```txt
requested -> prepared/prepared_with_differences -> dispatched
```

## Reglas de negocio

- Solo se pueden despachar traslados `validation_mode = logistics`.
- Solo se pueden despachar traslados preparados.
- Se aceptan los estados:
  - `prepared`
  - `prepared_with_differences`
- Al despachar:
  - se consume el stock reservado del almacen origen;
  - se registra un movimiento `transfer_out`;
  - se guarda usuario y fecha de despacho;
  - la guia queda en `dispatched`;
  - la transferencia queda en `dispatched`.
- El almacen destino no recibe stock todavia.
- Los IMEIs o seriales despachados quedan reservados logicamente hasta la fase de recepcion.

## Por que se maneja asi

La preparacion solo confirma que el personal cargo la mercancia y la reserva para que no pueda venderse. El despacho confirma que esa mercancia ya salio del origen.

La recepcion sera la encargada de:

- validar lo que llego;
- registrar diferencias al recibir;
- mover el stock al almacen destino;
- liberar los IMEIs o seriales en destino.

## Pruebas agregadas

Archivo:

```txt
tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Casos cubiertos:

- despacho de traslado logistico por cantidad desde stock reservado;
- bloqueo si se intenta despachar una transferencia no preparada;
- despacho de traslado logistico serializado/IMEI.

## Siguiente fase recomendada

Fase 5: recepcion logistica.

Debe incluir:

- checklist de recepcion;
- cantidades recibidas;
- IMEIs/seriales recibidos;
- motivos por faltante o sobrante;
- movimiento `transfer_in` al almacen destino;
- cambio de IMEIs al almacen destino;
- cierre de guia como recibida o recibida con diferencias.
