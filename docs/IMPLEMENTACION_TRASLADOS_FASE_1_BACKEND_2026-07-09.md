# Implementacion Traslados Fase 1 Backend

Fecha: 2026-07-09

## Resumen

Se inicio la Fase 1 del modulo de traslados logisticos. Esta fase deja preparada la base de datos y la API para soportar guias, cantidades solicitadas, preparadas, recibidas, diferencias y configuracion por empresa.

El flujo simple existente se mantiene funcionando igual: al crear un traslado interno, el stock sale del almacen origen, entra al almacen destino y el traslado queda completado. La diferencia es que ahora tambien se genera una guia logistica base para poder evolucionar luego a preparacion, despacho y recepcion con checklist.

## Cambios Realizados

- Se agrego la tabla `tenant_transfer_settings` para configurar por empresa el modo de validacion de traslados.
- Se ampliaron los traslados con:
  - `guide_number`
  - `validation_mode`
  - fechas de solicitud, preparacion, despacho y recepcion
  - usuarios preparador, despachador y receptor
- Se ampliaron los items de traslado con:
  - cantidad solicitada
  - cantidad preparada
  - cantidad recibida
  - cantidad de diferencia
  - motivo y notas de diferencia
  - seriales/IMEI preparados y recibidos
- Se agrego la tabla `inventory_transfer_guides`.
- Se agrego la tabla `inventory_transfer_checklists`.
- Se agrego la tabla `inventory_transfer_checklist_items`.
- Se crearon modelos para configuracion, guia y checklist.
- La API de traslados ahora devuelve:
  - numero de guia
  - modo de validacion
  - guia relacionada
  - cantidades logisticas de cada item
- Se agregaron permisos base para futuras acciones logisticas:
  - `inventory_transfers.prepare`
  - `inventory_transfers.dispatch`
  - `inventory_transfers.receive`
  - `inventory_transfers.cancel`
  - `inventory_transfers.resolve_differences`
  - `inventory_transfers.admin`

## Comportamiento Actual

Cuando se crea un traslado simple:

1. Se valida stock.
2. Se mueve el inventario de origen a destino.
3. Se marca el traslado como `completed`.
4. Se genera una guia `GUIA-000001`, `GUIA-000002`, etc.
5. Los items quedan con cantidad solicitada, preparada y recibida igual a la cantidad trasladada.
6. La diferencia queda en cero.

Esto prepara el terreno para que en las siguientes fases el traslado pueda detenerse en estados intermedios.

## Pruebas Ejecutadas

Se ejecuto la suite especifica:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Resultado:

- 6 pruebas pasadas.
- 37 aserciones.
- Base PostgreSQL local de pruebas.

## Siguiente Fase Recomendada

Continuar con la Fase 2 backend:

- Endpoint para crear traslado en modo logistico sin mover stock inmediatamente.
- Estado inicial `requested`.
- Guia generada en estado `generated`.
- Checklist de preparacion pendiente.
- Validacion de permisos para preparador y receptor.
- Tests de aislamiento por empresa.

Despues de eso se puede integrar la primera pantalla de escritorio para crear traslados y ver la guia.
