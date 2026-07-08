# Portal administrativo web: ventas POS

## Objetivo

Se agrego el modulo web de ventas POS para que el administrador pueda consultar ventas, ordenes pendientes, pagos y detalle de productos desde la nube, sin entrar al cliente de escritorio.

Esta pantalla sigue la regla visual de alta densidad del portal administrativo: mas informacion util visible, filtros compactos, tablas reducidas y acciones claras.

## Alcance implementado

- Vista web compacta `Ventas` dentro del portal administrativo.
- Filtros por fecha, sucursal, caja, cajero, estado y busqueda.
- Resumen de ordenes, ventas pagadas, pendientes, total facturado y total cobrado.
- Indicadores gerenciales compactos por sucursal, cajero, metodo de pago y productos mas vendidos.
- Tabla paginada de ordenes POS.
- Panel lateral con detalle de productos y pagos de la orden seleccionada.
- Exportacion CSV con los mismos filtros de la vista.
- Aislamiento por empresa usando el tenant activo.
- Permisos admitidos: `sales.view`, `reports.view` o `finance_reports.view`.

## APIs agregadas

- `GET /api/admin-portal/pos-sales`
- `GET /api/admin-portal/pos-sales/{posOrder}`

Parametros principales:

- `period`: `today`, `week` o `month`.
- `date_from` y `date_to`: rango personalizado.
- `branch_id`: sucursal.
- `cash_register_id`: caja fisica.
- `cashier_id`: cajero.
- `status`: `all`, `open`, `paid` o `cancelled`.
- `search`: orden, cliente, documento, cajero, producto o SKU.
- `page` y `limit`: paginacion.
- `export=csv`: descarga CSV.

## Reglas de seguridad

- Todas las consultas se filtran por `tenant_id`.
- El detalle devuelve `404` si la orden pertenece a otra empresa.
- Las uniones con cajas, sucursales, clientes, items y pagos validan la empresa activa.
- No se modifican ventas desde esta pantalla; por ahora es solo lectura y exportacion.

## Pruebas

Se agrego `tests/Feature/AdminPortal/AdminPosSalesApiTest.php` para validar:

- listado con resumen;
- busqueda por SKU;
- detalle con items y pagos;
- aislamiento entre empresas;
- exportacion CSV;
- bloqueo por permisos.
- indicadores por sucursal, cajero, metodo de pago y productos vendidos.

## Pendiente para futuras fases

- Anulacion controlada de ventas desde web.
- Historial avanzado por cliente.
- Comparativos avanzados por periodos y metas.
- Impresion o descarga de recibos desde el portal.
- Integracion con devoluciones y garantias desde una venta.

## Mejora gerencial agregada

La vista de ventas POS ahora muestra rankings administrativos en formato compacto:

- ventas por sucursal;
- ventas por cajero;
- cobros por metodo de pago;
- productos mas vendidos.

Estos bloques ayudan al administrador a revisar rapido donde se esta vendiendo, quien esta cobrando, que medios de pago se usan y que productos tienen mayor movimiento. Respetan los mismos filtros de fecha, sucursal, caja, cajero, estado y busqueda de la tabla principal.
