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
- El selector de precios incluye `Precio base` como primera opción; esa opción no envía `price_list_id` y usa el precio normal del producto.
- Al cambiar de `Precio base` a una lista específica, las tarjetas visibles se recotizan en segundo plano y muestran el precio de esa lista.
- Si un producto visible no tiene precio en la lista seleccionada, la tarjeta muestra `Sin precio en lista`.
- Si se selecciona una lista de precio y el producto no tiene precio en esa lista, Laravel bloquea la cotización con el mensaje `Este producto no tiene precio en esta lista.`
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
- El botón `Pendientes` abre una ventana separada para completar cobros de órdenes POS abiertas.

## Rendimiento del carrito

- El POS mantiene una caché temporal de cotizaciones por producto y lista de precio.
- Después de buscar productos, precarga solo un grupo pequeño de cotizaciones visibles en segundo plano para no saturar la API.
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
- Si el almacén seleccionado cambia y la caja pertenece a otra sucursal, el POS limpia la caja seleccionada y pide abrir o seleccionar una caja correcta.
- El botón `Pagar` queda bloqueado si falta almacén o caja abierta, con mensaje claro en pantalla.
- El encabezado del catálogo muestra el estado operativo `ABIERTA` o `SIN CAJA` para que el cajero vea el bloqueo antes de cobrar.
- El botón `Abrir mi caja` pide confirmación antes de crear una caja abierta para el usuario conectado usando la sucursal del almacén seleccionado y monto inicial cero.

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
- Si el pago capturado es menor al total, WPF permite confirmar la operacion como orden pendiente con advertencia previa.
- Cuando una orden queda pendiente, Laravel reserva el inventario de sus productos para que otra caja no pueda vender esas mismas unidades.
- En productos por cantidad, la reserva mueve stock de disponible a reservado.
- En productos serializados/IMEI, el serial queda en estado `reserved` hasta completar el cobro.
- Las órdenes pendientes se consultan con `GET /api/pos/orders?status=open`.
- WPF filtra las ordenes pendientes por cajero conectado para evitar intentar cobrar ordenes de otra caja.
- Desde la ventana `Ordenes POS pendientes` se puede agregar un pago a una orden abierta.
- La ventana de pendientes esta enfocada en cobrar el faltante como pago capturado para cerrar la venta.
- El boton principal se muestra como `Cobrar faltante y cerrar` para dejar claro que, si el monto cubre el total, la orden sale de pendientes.
- Al agregar pagos capturados a una orden pendiente, WPF consume `POST /api/pos/orders/{order}/payments`.
- Si los pagos capturados cubren el total, Laravel libera la reserva en la misma transaccion, confirma la venta, descuenta inventario y marca la orden como `paid`.
- La ventana muestra vuelto estimado cuando el pago capturado supera el total.
- El faltante se calcula en USD cuando la app conoce la tasa usada en la cotización.
- Al confirmar, Laravel vuelve a validar caja, stock, seriales, lista de precio, método de pago, moneda y referencia.
- Si la lista seleccionada no tiene precio para un producto del carrito, Laravel rechaza el checkout para evitar vender con un precio equivocado.
- Laravel devuelve `paid` cuando la orden queda pagada; WPF lo interpreta como venta confirmada y no como pendiente.
- Si el servidor aprueba, se limpia el carrito y se refresca el catálogo.

## Rendimiento de listas de precio

- La opción `Precio base` no consulta precio por cada tarjeta; muestra el precio normal que ya viene en el catálogo.
- Al cambiar a una lista de precio específica, el POS no cotiza todo el catálogo.
- Solo se precotizan los primeros productos disponibles para dar respuesta visual rápida.
- El resto de productos muestra `Cotizar al tocar` y se cotiza justo cuando el cajero lo agrega al carrito.
- Este flujo evita bloqueos cuando existan cientos o miles de productos y mantiene la validación final en Laravel.
- Si el producto no tiene precio en la lista seleccionada, la tarjeta muestra `Sin precio en lista` y el backend bloquea la venta.

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
- La ventana enfoca automáticamente el buscador para pistolear o escribir el IMEI de inmediato.
- Si la búsqueda deja un único IMEI/serial disponible, Enter lo selecciona sin usar el mouse.
- La ventana consulta `GET /api/inventory-center/products/{product}/serials?status=available&warehouse_id={warehouse_id}`.
- El carrito evita repetir el mismo IMEI/serial en la orden actual.
- Las líneas con IMEI no permiten aumentar cantidad con el botón `+`; para otra unidad se debe elegir otro serial.
- En el carrito, cada línea muestra si el producto se vende `Por cantidad` o como `Serializado / IMEI`.
- Las líneas serializadas muestran el IMEI en una etiqueta verde y desactivan los botones `+` y `-`.
- La ventana de cobro muestra un resumen compacto de los productos a confirmar, incluyendo el IMEI cuando aplica.
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
- `GET /api/pos/orders?status=open`: listado de órdenes POS pendientes.
- `POST /api/pos/orders/{order}/payments`: completar cobro de una orden POS pendiente.

## Reglas actuales

- El POS de escritorio no se conecta directo a PostgreSQL.
- Si un producto no tiene stock disponible, no se agrega al carrito.
- Si el producto es serializado/IMEI, se exige seleccionar un serial disponible del almacén activo.
- Si se elige una lista de precio y el producto no tiene precio activo para esa lista, la API rechaza la cotización y la app muestra el error.
- El checkout real crea la orden POS, registra pagos, usa caja y confirma venta cuando el backend valida que el pago cubre el total.
- Una orden pendiente conserva la venta en borrador hasta que se agreguen pagos capturados suficientes.
- Completar una orden pendiente no crea otra venta; agrega pagos a la orden existente.
- El botón `Volver al panel` regresa al panel administrativo sin cerrar sesión.

## Siguiente fase natural

- Mejorar selector visual de métodos de pago con botones rápidos.
- Agregar cálculo de vuelto/cambio.
- Agregar cliente en POS.
- Agregar impresión o vista previa de ticket.
- Integración visual con cierre de caja.
