# Modulo Centro de Inventario

Este modulo sera la primera pantalla operativa despues del login.

## Objetivo inicial

- Consumir `GET /api/inventory-center/summary`.
- Mostrar metricas del inventario.
- Listar productos con busqueda y filtros.
- Abrir acciones rapidas: entrada, salida, kardex, seriales y edicion.

## Implementado

- Vista WPF solo lectura con metricas principales.
- Filtros por busqueda, tipo de control y estado de stock.
- Listado de productos con SKU, precio, stock disponible, reservado, danado y estado.
- Paginacion basica con anterior y siguiente.

## Regla de conexion

La aplicacion de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoria y reglas de stock.
