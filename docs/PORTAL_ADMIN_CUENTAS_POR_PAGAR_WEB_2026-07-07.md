# Portal administrativo - Cuentas por pagar web

Fecha: 2026-07-07

## Objetivo

Agregar al portal administrativo una pantalla compacta para revisar saldos de proveedores y registrar pagos parciales o totales sin entrar al sistema de escritorio.

## Alcance implementado

- Nueva seccion `Cuentas por pagar` dentro del portal administrativo.
- Filtros por busqueda, estado, proveedor y fecha de vencimiento.
- Tabla compacta de cuentas con total, pagado y saldo.
- Panel lateral para ver detalle de una cuenta seleccionada.
- Historial de pagos registrados contra la cuenta.
- Formulario de pago con moneda, monto, metodo, referencia y notas.
- Boton para completar automaticamente el saldo pendiente.
- Paginacion de cuentas por pagar.

## Permisos

- `accounts_payable.view`: permite listar y ver cuentas.
- `accounts_payable.pay`: permite registrar pagos a proveedor.

## Reglas funcionales

- La pantalla no crea deuda manual.
- Las cuentas nacen cuando una compra queda recibida.
- El pago no puede superar el saldo pendiente.
- Los pagos quedan asociados al tenant actual.
- Los pagos en `VES` siguen usando la validacion de tasa del backend.

## APIs usadas

- `GET /api/accounts-payable`
- `GET /api/accounts-payable/{accountsPayable}`
- `POST /api/accounts-payable/{accountsPayable}/payments`
- `GET /api/suppliers?active_status=active&limit=100`

## Pruebas especificas

- `tests/Feature/AccountsPayable/AccountsPayableApiTest.php`
- `tests/Feature/AdminPortal/AdminPortalWebTest.php`

## Pendiente natural

- Mejorar dashboard financiero web con totales de cuentas por pagar vencidas y proximas a vencer.
- Agregar reporte exportable de pagos a proveedores.
- Evaluar conciliacion por metodo de pago cuando se cierre el modulo financiero completo.
