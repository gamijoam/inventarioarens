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
- Acción `Registrar entrada` desde la ventana de detalle del producto.
- Ventana de entrada conectada a `POST /api/product-entries`.
- La entrada permite elegir almacén, cantidad, costo unitario, motivo, referencia, notas e IMEI/seriales uno por línea para productos serializados.
- La recepción de IMEI/seriales tiene contador visual, vista previa, validación de duplicados, líneas vacías, seriales cortos y coincidencia con cantidad.
- La ventana de entrada permite usar el conteo detectado como cantidad y limpiar duplicados antes de guardar.
- Acción `Registrar salida` desde la ventana de detalle del producto.
- Ventana de salida conectada a `POST /api/product-exits`.
- La salida permite elegir almacén, cantidad, motivo, referencia, notas y seleccionar IMEI/seriales disponibles en productos serializados.
- La salida serializada incluye buscador de IMEI/serial, almacén o estado, contador de selección contra cantidad requerida, botón para limpiar selección y opción para usar la selección como cantidad.
- Las entradas y salidas validan mensajes en español antes de llamar a la API.
- Botón lateral `Entradas y salidas` habilitado en el shell principal.
- Pantalla operativa `Entradas y salidas` con búsqueda de productos y acciones rápidas `Entrada` / `Salida`.
- Si una ventana de movimiento no puede abrirse, la app muestra un mensaje visible en español.
- Botón `+ Nuevo producto` habilitado en el Centro de Inventario.
- Acción `Editar` por producto desde el listado principal.
- Ventana única de creación/edición conectada a `POST /api/products` y `PATCH /api/products/{product}`.
- El formulario permite nombre, SKU, tipo de control, moneda, precio base, tipo de tasa, política de garantía y estado activo.
- Las opciones de tasas y garantías se cargan desde `GET /api/currency/rate-types` y `GET /api/warranty-policies`.
- Si un producto ya tiene unidades serializadas, el formulario bloquea el cambio de tipo de control siguiendo la regla del backend.

## Regla de conexión

La aplicación de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoría y reglas de stock.
