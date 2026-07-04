# Módulo POS de escritorio

Este módulo contiene la primera base visual y operativa del punto de venta en WPF.

## Objetivo de la fase 1

- Buscar productos reales desde el backend.
- Seleccionar lista de precio activa.
- Cotizar el producto con `GET /api/products/{product}/price`.
- Agregar productos por cantidad a un carrito local.
- Mostrar total en `USD` y equivalente en `VES` cuando la API devuelve tasa.
- Usar una pantalla completa propia para POS, sin quedar limitado por el sidebar del panel administrativo.
- Mantener el botón de pago deshabilitado hasta implementar checkout, caja y métodos de pago.

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
- El botón `Salir` vuelve al panel administrativo sin cerrar sesión.

## Siguiente fase natural

- Selector o lectura de IMEI/serial para productos serializados.
- Validación de almacén/caja activa.
- Checkout contra `POST /api/pos/checkouts`.
- Métodos de pago en `USD`, `VES` y pagos mixtos.
- Integración con caja y cierre.
