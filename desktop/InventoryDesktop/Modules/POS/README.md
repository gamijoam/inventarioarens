# Módulo POS de escritorio

Este módulo contiene la primera base visual y operativa del punto de venta en WPF.

## Objetivo de la fase actual

- Trabajar el POS como pantalla completa propia, separada del panel administrativo.
- Priorizar velocidad de venta: búsqueda arriba, catálogo amplio, carrito fijo y acciones rápidas abajo.
- Mostrar más productos por fila mediante tarjetas compactas.
- Mantener el carrito visible, pero con ancho controlado para no quitar espacio al catálogo.
- Preparar cotizaciones visibles en segundo plano para que agregar al carrito responda más rápido.
- Buscar productos reales desde el backend.
- Seleccionar lista de precio activa.
- Cotizar el producto con `GET /api/products/{product}/price`.
- Agregar productos por cantidad a un carrito local.
- Mostrar total en `USD` y equivalente en `VES` cuando la API devuelve tasa.
- Abrir ventana de cobro con métodos de pago activos.
- Permitir pagos en `USD`, `VES` o mixtos cuando la lista de precio lo permite.
- Confirmar checkout real contra Laravel usando `POST /api/pos/checkouts`.
- Seleccionar cliente registrado o mantener `Cliente mostrador` por defecto.

## Diseño actual

- No tiene menú lateral interno del POS.
- La búsqueda, lista de precio y botón `Buscar` viven en la barra superior.
- El contexto operativo del POS permite seleccionar almacén y caja abierta.
- El catálogo ocupa la mayor parte de la pantalla.
- Las tarjetas son compactas para permitir más columnas visibles.
- El carrito queda a la derecha con ancho reducido.
- La acción `Volver al panel`, mensajes de estado y atajos se muestran en la barra inferior.
- El botón `Pagar` abre una ventana separada para no quitar espacio al catálogo ni al carrito.

## Rendimiento del carrito

- El POS mantiene una caché temporal de cotizaciones por producto y lista de precio.
- Después de buscar productos, precarga hasta 18 cotizaciones visibles en segundo plano.
- Si el vendedor hace click mientras una cotización ya se está preparando, el click reutiliza esa misma consulta.
- Al cambiar la lista de precio o hacer una nueva búsqueda se limpia la caché para evitar precios mezclados.
- La caché es solo de la pantalla actual; el checkout real deberá volver a validar precios en backend.

## Contexto operativo

- El POS carga almacenes activos desde `GET /api/warehouses`.
- El POS carga cajas abiertas desde `GET /api/cash-register/sessions`.
- Cada línea agregada al carrito conserva el almacén seleccionado.
- Si no hay almacén seleccionado, no se permite agregar productos.
- La caja abierta es obligatoria para confirmar una venta.
- En escritorio solo se listan cajas abiertas asignadas al usuario conectado; así se evita intentar vender con una caja de otro cajero.
- El botón `Pagar` muestra un mensaje claro si falta caja propia abierta, en vez de quedar silencioso.
- El botón `Abrir mi caja` crea una caja abierta para el usuario conectado usando la sucursal del almacén seleccionado y monto inicial cero.

## Cobro y checkout

- El POS carga métodos de pago activos desde `GET /api/payment-methods?active_only=1`.
- Si la lista de precio tiene métodos restringidos, la ventana de cobro solo muestra esos métodos.
- Si la lista de precio está abierta, se muestran todos los métodos activos.
- Si no hay métodos configurados y la lista está abierta, la ventana ofrece métodos básicos compatibles con el backend: efectivo USD, pago móvil Bs y transferencia Bs.
- Un método `USD` solo permite pago en dólares.
- Un método `VES` solo permite pago en bolívares.
- Un método `flexible` permite escoger USD o bolívares.
- Si el método exige referencia, la ventana bloquea el pago sin referencia.
- La ventana permite agregar varios pagos antes de confirmar.
- El botón `Completar saldo` coloca el monto faltante según la moneda seleccionada.
- Al escribir el monto recibido, la ventana muestra una vista previa inmediata de pagado, faltante y vuelto estimado.
- Cada pago agregado muestra el monto recibido y su equivalente: USD a bolívares cuando hay tasa, o bolívares a USD cuando se paga en Bs.
- Cuando el cobro es en bolívares, WPF envía a Laravel el tipo de tasa usado para cotizar los productos del carrito.
- Si el carrito mezcla productos con tipos de tasa distintos, WPF bloquea el pago en bolívares y pide separar la venta o cobrar en dólares.
- El botón `Borrar monto` limpia solo el monto y la referencia del pago que se está preparando.
- El botón `Agregar pago` acepta el pago recibido y lo pasa a la tabla de pagos agregados.
- El botón `Eliminar pago` elimina el pago seleccionado en la tabla de pagos agregados.
- Cada pago puede marcarse como capturado o pendiente.
- Los pagos capturados cuentan para cerrar la venta.
- Los pagos pendientes permiten registrar una orden abierta sin cerrar la venta.
- La ventana muestra vuelto estimado cuando el pago capturado supera el total.
- El faltante se calcula en USD cuando la app conoce la tasa usada en la cotización.
- Al confirmar, Laravel vuelve a validar caja, stock, seriales, lista de precio, método de pago, moneda y referencia.
- Si el servidor aprueba, se limpia el carrito y se refresca el catálogo.

## Cliente en POS

- El POS usa `Cliente mostrador` por defecto para ventas rápidas.
- El botón `Buscar cliente` abre una ventana de búsqueda.
- La búsqueda consulta `GET /api/customers?search={texto}&active_only=1&limit=20`.
- Se puede buscar por nombre, cédula/RIF, teléfono o correo.
- Si el cliente no existe, el botón `+ Nuevo cliente` abre el registro rápido desde el POS.
- El registro rápido envía `POST /api/customers` con nombre, tipo de documento, documento, teléfono, correo y dirección fiscal opcional.
- Al crear un cliente desde el POS, queda seleccionado automáticamente para la venta actual.
- Al seleccionar un cliente, el POS muestra nombre y documento en la orden actual.
- Al confirmar checkout, WPF envía `customer_id` y `customer_name`.
- El botón `Venta mostrador` limpia el cliente seleccionado y vuelve a venta rápida sin cliente registrado.

## Productos serializados / IMEI

- Los productos serializados ya no se bloquean de forma genérica.
- Al seleccionar un producto serializado, se abre la ventana `Seleccionar IMEI/serial`.
- La ventana consulta `GET /api/inventory-center/products/{product}/serials?status=available&warehouse_id={warehouse_id}`.
- El carrito evita repetir el mismo IMEI/serial en la orden actual.
- Las líneas con IMEI no permiten aumentar cantidad con el botón `+`; para otra unidad se debe elegir otro serial.
- El item del carrito conserva `product_unit_ids` para el futuro checkout real.

## APIs usadas

- `GET /api/inventory-center/summary`: búsqueda de productos con stock.
- `GET /api/price-lists?active_only=1`: listas de precio disponibles.
- `GET /api/products/{product}/price?price_list_id={id}`: cotización del producto según lista.
- `GET /api/warehouses`: almacenes disponibles.
- `GET /api/cash-register/sessions`: sesiones de caja abiertas.
- `GET /api/inventory-center/products/{product}/serials`: seriales/IMEI disponibles por producto y almacén.
- `GET /api/payment-methods?active_only=1`: métodos de pago disponibles.
- `GET /api/customers?search={texto}&active_only=1&limit=20`: búsqueda rápida de clientes activos.
- `POST /api/pos/checkouts`: confirmación real de venta POS.

## Reglas actuales

- El POS de escritorio no se conecta directo a PostgreSQL.
- Si un producto no tiene stock disponible, no se agrega al carrito.
- Si el producto es serializado/IMEI, se exige seleccionar un serial disponible del almacén activo.
- Si se elige una lista de precio y el producto no tiene precio activo para esa lista, la API rechaza la cotización y la app muestra el error.
- El checkout real crea la orden POS, registra pagos, usa caja y confirma venta cuando el backend valida que el pago cubre el total.
- El botón `Volver al panel` regresa al panel administrativo sin cerrar sesión.

## Siguiente fase natural

- Mejorar selector visual de métodos de pago con botones rápidos.
- Agregar cálculo de vuelto/cambio.
- Agregar cliente en POS.
- Agregar impresión o vista previa de ticket.
- Integración visual con cierre de caja.
