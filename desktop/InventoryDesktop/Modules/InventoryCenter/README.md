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
- Estado de carga visible sobre el listado.
- Estado vacío con mensaje en español para filtros sin resultados o errores de carga.
- Mensajes de error en español con color diferenciado.
- Tipografía base ajustada a `Segoe UI Variable Text` con respaldo `Segoe UI`.

## Regla de conexión

La aplicación de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoría y reglas de stock.
