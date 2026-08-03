# POS: tasa de cobro por lista de precio

## Objetivo

Cada lista de precio puede indicar la tasa USD/VES que debe usar el POS para
convertir pagos en bolivares. El cajero no selecciona la tasa: el sistema la
resuelve automaticamente desde la lista aplicada al ticket.

Ejemplo:

- Lista: `MAYOR`.
- Total: USD 50.
- Tasa asociada: `DIVISA RECIBIDA` a Bs 850 por USD.
- Primer pago: USD 40.
- Saldo: USD 10.
- Segundo pago sugerido: Bs 8.500.

La tasa BCV puede seguir activa para otras listas y operaciones. Asociar una
tasa a `MAYOR` no modifica precios ni movimientos historicos.

## Configuracion

1. En `Inventario > Tipos de tasa`, crear un tipo, por ejemplo `DIVISA RECIBIDA`.
2. Registrar y activar su valor USD/VES vigente.
3. En `Inventario > Administracion > Listas de precio`, editar la lista.
4. Seleccionar la tasa en `Tasa para cobros en bolivares`.
5. Asignar a la lista los metodos de pago permitidos para USD y/o VES.

Dejar la tasa vacia conserva el comportamiento general de la empresa: se usa
la tasa predeterminada activa.

## Reglas operativas

- Un ticket usa una sola lista y una sola tasa de cobro asociada.
- Los pagos USD y VES conservan el snapshot de esa tasa para auditoria.
- Los pagos mixtos calculan cada nuevo pago sobre el saldo restante.
- Si la lista tiene una tasa configurada pero no existe un valor activo, el POS
  bloquea el cobro y pide actualizar esa tasa.
- El backend rechaza una tasa enviada manualmente si no coincide con la lista.
- Una tasa de otra empresa nunca puede asociarse a la lista.
- La sincronizacion comparte el codigo del tipo de tasa, no sus IDs internos.

## Alcance contable

La deuda, el total y el saldo base continúan expresados en USD. El valor VES es
la conversion operativa para cobrar. Los pagos ya registrados no se recalculan
cuando la tasa cambia posteriormente.
