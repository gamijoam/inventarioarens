# Módulo Centro de Inventario

Este módulo será la primera pantalla operativa después del login.

## Objetivo inicial

- Consumir `GET /api/inventory-center/summary`.
- Mostrar métricas del inventario.
- Listar productos con búsqueda y filtros.
- Abrir acciones rápidas: entrada, salida, kardex, seriales y edición.

## Implementado

- Vista WPF solo lectura con metricas principales.
- Filtros por búsqueda, tipo de control y estado de stock.
- Listado de productos con SKU, precio, stock disponible, reservado, dañado y estado.
- Paginación básica con anterior y siguiente.
- Rediseño visual con cabecera operativa, métricas con intención visual y filtros agrupados.
- Distribución compacta para dar prioridad al listado y ver más productos sin hacer scroll.
- Métricas convertidas en chips pequeños dentro de la cabecera para no consumir una franja completa de pantalla.
- Estado de carga visible sobre el listado.
- Estado vacío con mensaje en español para filtros sin resultados o errores de carga.
- Mensajes de error en español con color diferenciado.
- Tipografía base ajustada a `Segoe UI Variable Text` con respaldo `Segoe UI`.
- Ventana independiente de detalle de producto conectada a `GET /api/inventory-center/products/{product}`.
- Acceso al detalle por botón `Ver` o doble clic sobre una fila.
- El detalle muestra información general, stock total, stock por almacén, seriales/IMEI, movimientos recientes y auditoría reciente.
- El listado principal mantiene todo su espacio; el detalle se abre aparte para no reducir el área de trabajo.

## Regla de conexión

La aplicación de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoría y reglas de stock.
