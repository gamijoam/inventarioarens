# Módulo POS de escritorio

Este módulo contiene la primera base visual y operativa del punto de venta en WPF.

## Objetivo de la fase actual

- Trabajar el POS como pantalla completa propia, separada del panel administrativo.
- Priorizar velocidad de venta: búsqueda arriba, catálogo amplio, carrito fijo y acciones rápidas abajo.
- Mostrar más productos por fila mediante tarjetas compactas.
- Mantener el carrito visible, pero con ancho controlado para no quitar espacio al catálogo.
- Buscar productos reales desde el backend.
- Seleccionar lista de precio activa.
- Cotizar el producto con `GET /api/products/{product}/price`.
- Agregar productos por cantidad a un carrito local.
- Mostrar total en `USD` y equivalente en `VES` cuando la API devuelve tasa.
- Mantener el botón de pago deshabilitado hasta implementar checkout, caja y métodos de pago.

## Diseño actual

- No tiene menú lateral interno del POS.
- La búsqueda, lista de precio y botón `Buscar` viven en la barra superior.
- El catálogo ocupa la mayor parte de la pantalla.
- Las tarjetas son compactas para permitir más columnas visibles.
- El carrito queda a la derecha con ancho reducido.
- La acción `Volver al panel`, mensajes de estado y atajos se muestran en la barra inferior.

## APIs usadas

- `GET /api/inventory-center/summary`: búsqueda de productos con stock.
- `GET /api/price-lists?active_only=1`: listas de precio disponibles.
- `GET /api/products/{product}/price?price_list_id={id}`: cotización del producto según lista.

## Reglas actuales

- El POS de escritorio no se conecta directo a PostgreSQL.
- Si un producto no tiene stock disponible, no se agrega al carrito.
- Si el producto es serializado/IMEI, se bloquea el agregado directo hasta integrar selección de seriales.
- Si se elige una lista de precio y el producto no tiene precio activo para esa lista, la API rechaza la cotización y la app muestra el error.
- Esta fase no crea ventas, no descuenta inventario, no registra pagos y no toca caja.
- El botón `Volver al panel` regresa al panel administrativo sin cerrar sesión.

## Siguiente fase natural

- Selector o lectura de IMEI/serial para productos serializados.
- Validación de almacén/caja activa.
- Checkout contra `POST /api/pos/checkouts`.
- Métodos de pago en `USD`, `VES` y pagos mixtos.
- Integración con caja y cierre.
