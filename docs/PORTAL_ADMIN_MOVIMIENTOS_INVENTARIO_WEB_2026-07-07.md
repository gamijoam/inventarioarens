# Portal administrativo - Historial de movimientos de inventario

## Objetivo

Agregar al portal administrativo una vista compacta para revisar movimientos de inventario de toda la empresa activa. Esta pantalla permite auditar entradas, salidas, ventas, devoluciones, traslados, reservas y ajustes sin entrar producto por producto.

## Backend

Se agrego el endpoint:

```txt
GET /api/inventory-center/movements
```

Filtros disponibles:

- `search`: busca por producto, SKU, motivo o tipo de referencia.
- `type`: filtra por tipo de movimiento.
- `warehouse_id`: filtra por almacen.
- `date_from` y `date_to`: acotan el rango de fechas.
- `limit` y `page`: controlan paginacion.

La respuesta incluye producto, SKU, almacen, sucursal, motivo, referencia, usuario y fecha. El servicio precarga relaciones para evitar N+1 en el portal web.

## Frontend web

Se agrego la seccion **Movimientos** dentro del portal `/admin`.

La pantalla sigue la regla de alta densidad:

- filtros compactos en una sola fila;
- tabla compacta con fecha, producto, tipo, cantidad, almacen, motivo y usuario;
- paginacion simple;
- mensajes en español para carga, vacio y errores.

## Reglas

- La vista es solo lectura.
- No corrige stock directamente.
- Las correcciones deben seguir entrando por los modulos operativos: compras, ventas, entradas/salidas, traslados, devoluciones o ajustes.
- El historial respeta el tenant activo y no mezcla movimientos entre empresas.

## Pruebas agregadas

- `tests/Feature/InventoryCenter/InventoryCenterMovementsApiTest.php`
- `tests/Feature/AdminPortal/AdminPortalWebTest.php`

Estas pruebas cubren:

- filtros y paginacion del endpoint global;
- contexto operativo del movimiento;
- aislamiento por empresa;
- permiso requerido;
- presencia de la nueva pantalla en `/admin`.
