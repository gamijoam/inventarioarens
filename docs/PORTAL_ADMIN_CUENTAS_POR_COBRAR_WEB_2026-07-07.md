# Portal administrativo: Cuentas por cobrar web

Fecha: 2026-07-07

## Objetivo

Agregar al portal administrativo una vista compacta para consultar saldos de clientes y registrar cobros parciales o totales sin entrar a la aplicacion de escritorio.

## Implementado

- Nueva seccion `Cuentas por cobrar` en el menu del portal administrativo.
- Tabla de cuentas con cliente, documento, estado, total, cobrado y saldo.
- Filtros por busqueda, estado, cliente y rango de vencimiento.
- Panel lateral de detalle para revisar saldo y cobros registrados.
- Formulario de cobro con moneda, monto, metodo, referencia y notas.
- Boton para llenar automaticamente el saldo pendiente en USD o VES.
- Endpoint `GET /api/accounts-receivable` ampliado con filtros para la vista web.
- Permisos integrados al gestor de perfiles: `accounts_receivable.view` y `accounts_receivable.collect`.

## Reglas operativas

- Las cuentas por cobrar nacen al confirmar ventas.
- La web no crea deuda manual en esta fase.
- Un cobro no puede superar el saldo pendiente.
- Una cuenta pagada no acepta nuevos cobros.
- Todo se mantiene aislado por empresa activa.

## Pendiente

- Reporte consolidado de antiguedad de saldos.
- Exportacion de cuentas por cobrar.
- Alertas por vencimiento desde el tablero gerencial.
- Enlace desde ventas confirmadas hacia la cuenta por cobrar relacionada.

## Pruebas objetivo

- `tests/Feature/AccountsReceivable/AccountsReceivableApiTest.php`
- `tests/Feature/AdminPortal/AdminPortalWebTest.php`
