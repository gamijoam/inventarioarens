# Implementacion de traslados con preparacion, despacho y sincronizacion

## Objetivo

Cerrar la base operativa del modulo de traslados para escritorio, separando el flujo en tres bandejas:

- Preparar guia.
- Despachar guia.
- Recibir guia.

La intencion es que una empresa pueda solicitar un traslado, preparar fisicamente lo que se carga, despacharlo y luego recibirlo con validacion de diferencias.

## Cambios en escritorio

- Se unifico la vista de traslados en una pantalla operativa por etapas.
- La bandeja de preparacion muestra traslados solicitados.
- La bandeja de despacho muestra guias preparadas o preparadas con diferencias.
- La bandeja de recepcion muestra guias despachadas.
- El boton `Completo` solo queda disponible cuando aplica: preparacion o recepcion.
- El usuario puede completar cantidades esperadas, indicar diferencias y confirmar la etapa correspondiente.

## Cambios en backend

- Al preparar un traslado logistico se reserva inventario y se registra el movimiento `reserved`.
- Al despachar se descuenta de inventario reservado y se registra `transfer_out`.
- Al recibir se incrementa el inventario destino y se registra `transfer_in`.
- En productos serializados/IMEI, cada cambio de estado o almacen de la unidad queda registrado para sincronizacion.

## Sincronizacion

Se agregaron eventos para que los traslados no queden solo en la base local:

- `stock_movement.created`: movimientos de reserva, salida y entrada.
- `product_unit.updated`: cambios de estado, almacen y movimiento asociado de seriales/IMEI.

Esto permite que un traslado realizado en local suba sus efectos a la nube, y que otra instalacion pueda recibir esos cambios mediante el worker.

## Validaciones

- No se puede preparar mas cantidad de la solicitada.
- Si se prepara o recibe menos, debe indicarse motivo.
- Los IMEI/seriales no pueden repetirse dentro del mismo traslado.
- Solo se pueden preparar IMEI/seriales disponibles en el almacen origen.
- Solo se pueden recibir IMEI/seriales previamente despachados.

## Pruebas realizadas

- Compilacion del proyecto WPF.
- Prueba de API de traslados.
- Verificacion de eventos de sincronizacion para movimientos de inventario.
- Verificacion de eventos de sincronizacion para seriales/IMEI.

## Pendientes futuros

- Crear la version web administrativa de seguimiento de traslados.
- Agregar impresion o exportacion de guia.
- Crear permisos finos por etapa: solicitar, preparar, despachar, recibir y auditar.
- Agregar panel de diferencias por traslado para administradores.
