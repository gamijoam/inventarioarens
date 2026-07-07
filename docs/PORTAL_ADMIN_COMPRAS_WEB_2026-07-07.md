# Portal administrativo: compras web

## Objetivo

Se agrego una primera base operativa del modulo **Compras** dentro del portal administrativo web. La intencion es que administracion pueda registrar ordenes de compra, revisar compras pendientes y recibir mercancia para alimentar inventario desde la nube cuando aplique.

## Alcance implementado

- Nueva seccion **Compras** en el portal administrativo.
- Listado compacto de ordenes de compra con filtros por busqueda, estado y proveedor.
- Formulario lateral para crear compras en borrador.
- Agregado de items por producto, almacen, cantidad y costo.
- Recepcion completa de una compra pendiente, actualizando inventario desde el backend.
- Anulacion de compras en borrador.
- Respuesta visual compacta siguiendo la regla permanente de interfaz administrativa de alta densidad.

## APIs utilizadas

- `GET /api/purchases`: lista compras con filtros `search`, `status`, `supplier_id`, `date_from`, `date_to`, `limit` y `page`.
- `POST /api/purchases`: crea una compra en estado borrador.
- `GET /api/purchases/{id}`: obtiene el detalle de una compra.
- `PATCH /api/purchases/{id}/receive`: recibe una compra y genera movimiento de inventario.
- `PATCH /api/purchases/{id}/cancel`: anula una compra que aun no fue recibida.

## Flujo operativo

1. El usuario entra al portal web y selecciona la empresa.
2. Abre el modulo **Compras**.
3. Crea una compra con proveedor, documento, moneda e items.
4. La compra queda en borrador y no mueve inventario todavia.
5. Al presionar **Recibir compra**, el backend valida permisos, cantidades, almacen y producto.
6. Si todo es correcto, se genera el movimiento de inventario y la compra pasa a recibida o parcialmente recibida.

## Pendientes controlados

- La recepcion web de productos serializados/IMEI queda pendiente para una fase posterior. Por ahora, esos ingresos se deben manejar desde el flujo de escritorio o desde el modulo especializado de entradas con seriales.
- Se debe validar en una fase posterior si las compras web deben emitir eventos de sincronizacion mas granulares para escenarios web -> local con multiples sucursales activas.

## Pruebas especificas

- Se agrego cobertura del portal para verificar que la seccion de compras y sus controles existan.
- Se agrego cobertura del API para filtrar compras por busqueda, estado y proveedor.
