# Implementacion - Traslados fase 6: recepcion en escritorio

Fecha: 2026-07-09

## Objetivo

Permitir que la app de escritorio reciba traslados logisticos ya despachados, mostrando la guia pendiente y permitiendo confirmar recepcion completa o con diferencias.

## Cambios realizados

- Se amplio `GET /api/inventory-transfers` con filtros por `status` y `validation_mode`.
- El listado de traslados ahora carga items con producto para que el escritorio pueda mostrar lineas entendibles.
- Se agrego el modulo visual `InventoryTransfers` en WPF.
- El centro de modulos ahora muestra la tarjeta **Traslados** cuando el usuario tiene permisos de traslados.
- La pantalla de recepcion lista guias logisticas en estado `dispatched`.
- Cada linea permite ajustar cantidad recibida y motivo de diferencia.
- La accion **Recibir completo** copia las cantidades despachadas como recibidas.
- La accion **Confirmar recepcion** llama a `POST /api/inventory-transfers/{id}/receive`.

## Flujo operativo

1. El usuario entra al modulo **Traslados** desde el centro de modulos.
2. La app carga las guias logisticas despachadas.
3. El usuario selecciona una guia.
4. Revisa los productos enviados y sus cantidades.
5. Si todo llego correcto, usa **Recibir completo**.
6. Si hay diferencias, ajusta cantidad recibida y escribe el motivo.
7. Confirma la recepcion.
8. El backend registra entrada al almacen destino y cierra la guia como completada o completada con diferencias.

## Reglas importantes

- Solo se reciben traslados `validation_mode = logistics`.
- Solo se muestran traslados en estado `dispatched`.
- Si se recibe menos de lo despachado, el motivo de diferencia es obligatorio.
- La recepcion no crea una venta ni una salida; solo completa la entrada del traslado al almacen destino.

## Pruebas objetivo

- `InventoryTransferApiTest`: valida filtros para recepcion en escritorio.
- `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`: valida compilacion WPF.
