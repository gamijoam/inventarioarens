# Módulo Centro de Inventario

Este módulo es la primera pantalla operativa después del login en la aplicación de escritorio WPF.

## Objetivo

- Consumir el backend Laravel como fuente unica de datos.
- Mostrar metricas, listado de productos, detalle operativo y herramientas de consulta.
- Mantener permisos, tenant, auditoría y reglas de stock en el backend.

## Implementado

- Vista WPF de Centro de Inventario conectada a `GET /api/inventory-center/summary`.
- Filtros por búsqueda, tipo de control y estado de stock.
- Listado de productos con SKU, precio, stock disponible, reservado, dañado y estado.
- Paginación básica con anterior y siguiente.
- Diseño compacto para aprovechar mejor el espacio y ver más productos sin hacer scroll.
- Metricas principales convertidas en chips dentro de la cabecera.
- Estado de carga, estado vacío y mensajes de error visibles en español.
- Ventana independiente de detalle de producto conectada a `GET /api/inventory-center/products/{product}`.
- Acceso al detalle por boton `Ver` o doble clic sobre una fila.
- El detalle muestra información general, stock total, stock por almacén, seriales/IMEI, movimientos recientes y auditoría reciente.
- Si la base real aún no tiene `product_audits`, el detalle abre igual y muestra auditoría vacía.
- Acción `Ver Kardex` desde la ventana de detalle del producto.
- Ventana independiente de Kardex por producto conectada a `GET /api/kardex/products/{product}`.
- Filtros de Kardex por almacén, fecha desde y fecha hasta.
- Kardex con saldo inicial, saldo final, cantidad de movimientos y tabla cronológica con entradas, salidas, saldo y motivo.
- Errores de Kardex visibles dentro de la ventana, sin cerrar el panel ni ocultar el problema.

## Regla de conexión

La aplicación de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoría y reglas de stock.
