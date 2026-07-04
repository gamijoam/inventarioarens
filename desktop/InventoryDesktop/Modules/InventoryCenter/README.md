# Módulo Centro de Inventario

Este módulo es la primera pantalla operativa después del login en la aplicación de escritorio WPF.

## Objetivo

- Consumir el backend Laravel como fuente única de datos.
- Mostrar métricas, listado de productos, detalle operativo y herramientas de consulta.
- Mantener permisos, tenant, auditoría y reglas de stock en el backend.

## Listas de precio

- Una lista de precio pertenece a una empresa y queda disponible para todos los productos de esa empresa.
- Crear una lista no copia el mismo monto a todos los productos.
- Cada producto debe tener su propio precio por lista. Ejemplo: `Precio al mayor`, `Precio detal` y `Precio técnico` pueden existir para todos, pero el Samsung A06 y un cargador tendrán montos distintos.
- La lista predeterminada se usará como referencia cuando el POS no reciba una lista específica.
- `Posición visual` solo ordena cómo se muestran las listas en pantalla. No afecta cálculos, stock ni ventas.
- Si no se asigna un precio específico a un producto en una lista, el backend conserva el precio base del producto como respaldo.
- En el detalle del producto, la pestaña `Precios` muestra qué precio usará el POS por cada lista.
- La pestaña permite copiar el precio base en una lista específica o en todas las listas vacías para acelerar la carga.

## Alertas operativas

- El resumen del Centro de Inventario incluye alertas operativas para detectar problemas antes de vender.
- Las alertas iniciales cubren `Stock bajo`, `Sin stock`, `Sin precio base`, `Sin garantía` y `Listas de precio incompletas`.
- Cada alerta incluye conteo, mensaje, acción recomendada y hasta tres productos de ejemplo.
- La app WPF mantiene las alertas fuera del flujo principal para no quitar espacio al listado.
- El Centro de Inventario muestra un botón compacto con el conteo de alertas y abre una ventana independiente para revisarlas.

## Exportación de inventario

- El Centro de Inventario permite exportar un CSV desde el backend con los filtros actuales.
- La exportación usa `GET /api/inventory-center/export`.
- El archivo incluye producto, SKU, tipo de control, moneda, precio base, disponible, reservado, dañado y estado de stock.
- La app WPF muestra un selector de ubicación para guardar el archivo localmente.
- La exportación no modifica datos; solo consulta productos activos del tenant actual.

## Implementado

- Vista WPF de Centro de Inventario conectada a `GET /api/inventory-center/summary`.
- Botón WPF de alertas operativas conectado al campo `alerts` del resumen.
- Ventana independiente de alertas operativas con conteo, productos afectados y acción recomendada.
- Botón `Exportar CSV` conectado a `GET /api/inventory-center/export` con los filtros actuales.
- Filtros por búsqueda, tipo de control y estado de stock.
- Listado de productos con SKU, precio, stock disponible, reservado, dañado y estado.
- Paginación básica con anterior y siguiente.
- Diseño compacto para aprovechar mejor el espacio y ver más productos sin hacer scroll.
- Métricas principales convertidas en chips dentro de la cabecera.
- Estado de carga, estado vacío y mensajes de error visibles en español.
- Ventana independiente de detalle de producto conectada a `GET /api/inventory-center/products/{product}`.
- Acceso al detalle por botón `Ver` o doble clic sobre una fila.
- El detalle usa pestañas para separar `Resumen`, `Stock`, `Seriales / IMEI`, `Movimientos` y `Auditoría`.
- La pestaña `Seriales / IMEI` consume `GET /api/inventory-center/products/{product}/serials` con búsqueda, filtro de estado y paginación.
- La pestaña `Movimientos` consume `GET /api/inventory-center/products/{product}/movements` con búsqueda, filtro de tipo, fechas y paginación.
- Las pestañas `Seriales / IMEI` y `Movimientos` permiten filtrar por almacén, limpiar filtros y desactivan la navegación cuando no hay página anterior o siguiente.
- La pestaña `Movimientos` valida fechas en formato `yyyy-mm-dd` antes de consultar la API.
- La pestaña `Auditoría` consume `GET /api/inventory-center/products/{product}/audits` con búsqueda por usuario/correo, filtro por acción y paginación.
- Si la base real aún no tiene `product_audits`, el detalle abre igual y muestra auditoría vacía.
- Acción `Ver Kardex` desde la ventana de detalle del producto.
- Ventana independiente de Kardex por producto conectada a `GET /api/kardex/products/{product}`.
- Filtros de Kardex por almacén, fecha desde y fecha hasta.
- Kardex con saldo inicial, saldo final, cantidad de movimientos y tabla cronológica con entradas, salidas, saldo y motivo.
- Acción `Registrar entrada` desde la ventana de detalle del producto.
- Ventana de entrada conectada a `POST /api/product-entries`.
- La entrada permite elegir almacén, cantidad, costo unitario, motivo, referencia, notas e IMEI/seriales uno por línea para productos serializados.
- La recepción de IMEI/seriales tiene contador visual, vista previa, validación de duplicados, líneas vacías, seriales cortos y coincidencia con cantidad.
- Acción `Registrar salida` desde la ventana de detalle del producto.
- Ventana de salida conectada a `POST /api/product-exits`.
- La salida permite elegir almacén, cantidad, motivo, referencia, notas y seleccionar IMEI/seriales disponibles en productos serializados.
- Al guardar una entrada o salida desde el detalle, el detalle del producto se recarga automáticamente y el Centro de Inventario actualiza métricas, disponibilidad y listado.
- Botón lateral `Entradas y salidas` habilitado en el shell principal.
- Pantalla operativa `Entradas y salidas` con búsqueda de productos y acciones rápidas `Entrada` / `Salida`.
- Botón lateral `Listas de precio` habilitado en el shell principal.
- El menú lateral principal usa scroll vertical para soportar más módulos sin cortar opciones.
- Pantalla operativa `Listas de precio` conectada a `GET/POST/PATCH/DELETE /api/price-lists`.
- La pantalla permite crear listas, editar nombre/código/descripción/orden, marcar predeterminada, activar/desactivar y desactivar sin borrar historia.
- El formulario de listas diferencia entre `Preparar nueva`, `Crear lista`, `Guardar cambios` y `Cancelar` para evitar confusión operativa.
- Botón `+ Nuevo producto` habilitado en el Centro de Inventario.
- Acción `Editar` por producto desde el listado principal.
- Acción `Editar` dentro de la ventana de detalle del producto.
- Ventana única de creación/edición conectada a `POST /api/products` y `PATCH /api/products/{product}`.
- El formulario permite nombre, SKU, tipo de control, moneda, precio base, tipo de tasa, política de garantía y estado activo.
- Pestaña `Precios` en el detalle del producto conectada a `GET /api/products/{product}/prices` y `PUT /api/products/{product}/prices`.
- La pestaña `Precios` permite asignar precios por lista, moneda `USD` o `VES`, tasa opcional y estado activo.
- La pestaña `Precios` indica si el POS usará un precio específico de lista o el respaldo del precio base.
- La pestaña `Precios` incluye acciones para copiar el precio base a una fila o a todas las listas vacías.
- Las opciones de tasas y garantías se cargan desde `GET /api/currency/rate-types` y `GET /api/warranty-policies`.
- Si un producto ya tiene unidades serializadas, el formulario bloquea el cambio de tipo de control siguiendo la regla del backend.
- Al editar desde el detalle, la ventana recarga la información comercial y marca la auditoría como pendiente de recarga.
- La app de escritorio muestra errores de validación del backend en español, incluyendo SKU duplicado, precio inválido, tasa ajena o garantía ajena.

## Regla de conexión

La aplicación de escritorio no se conecta directo a PostgreSQL. Todas las operaciones deben pasar por el backend Laravel para respetar permisos, tenant, auditoría y reglas de stock.
