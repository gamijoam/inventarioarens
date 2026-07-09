# Implementacion - Traslados Fase 3: preparacion logistica

## Resumen

Se agrego la preparacion operativa de traslados logisticos. Esta fase permite que el usuario encargado de cargar mercancia confirme cantidades o IMEIs, registre diferencias y deje el stock preparado como reservado para evitar ventas accidentales.

## Cambios realizados

- Se agrego el endpoint `POST /api/inventory-transfers/{inventoryTransfer}/prepare`.
- Se agrego el permiso `inventory_transfers.prepare` en la politica del modulo.
- Se agrego `PrepareInventoryTransferRequest` para validar payload de preparacion.
- Se agregaron estados de guia:
  - `prepared`
  - `prepared_with_differences`
- El servicio de transferencias ahora puede preparar un traslado logistico en una transaccion.
- Al preparar:
  - se exige que se envien todos los items de la guia;
  - se valida que el traslado este en modo `logistics` y estado `requested`;
  - se actualizan cantidades preparadas;
  - se registran motivos y notas de diferencia;
  - se actualizan items del checklist;
  - se reserva el stock preparado en el almacen origen;
  - se marcan IMEIs preparados como `reserved`.

## Reglas operativas

- La creacion del traslado logistico no mueve inventario.
- La preparacion separa lo cargado y lo deja reservado.
- Si todo coincide con la guia:
  - transferencia: `prepared`
  - guia: `prepared`
  - checklist: `completed`
- Si hay faltantes:
  - transferencia: `prepared_with_differences`
  - guia: `prepared_with_differences`
  - checklist: `completed_with_differences`
- Si se prepara menos de lo solicitado, el motivo de diferencia es obligatorio.
- Los productos serializados deben prepararse con IMEIs o seriales reales.
- Si la guia especificaba IMEIs, la preparacion no permite sustituirlos por otros.

## Pruebas realizadas

```txt
php artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Resultado:

```txt
10 pruebas aprobadas, 87 aserciones.
```

## Pendiente para fases siguientes

- Fase 4: despacho del traslado preparado.
- Fase 5: recepcion en destino con checklist de llegada.
- Fase 6: resolucion administrativa de diferencias.
- Fase 7: integracion visual en WPF y portal web.
