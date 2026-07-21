# Registro de implementación
## 2026-07-21 - Sprint POS performance y UX (QW1 a QW4)

### Implementado

- **QW1 (backend)** `ProductPriceService::quote()` acepta `price_source ∈ {base, list}` para eliminar la ambiguedad entre Precio base y lista default. `SaleService::createDraft` propaga el campo y `PosCheckoutService::priceListsForItems` ignora lineas en base al validar metodos de pago restringidos.
- **QW2 (sync)** `SyncEventApplier::applyCloudStockOut` + `applyCloudSerialSold` decrementan `stock_balances` y marcan `product_units.status='sold'` en la nube cuando el evento replica una venta con `sale_status=confirmed`. El outbox POS ahora serializa `product_serial_units` con identidad natural `(serial_type, serial_number)`. Fix colateral: `$salePayload` se separa del payload completo (antes se sobrescribia con el top-level `status`).
- **QW3 (bootstrap)** nuevo endpoint `GET /api/pos/bootstrap` que devuelve warehouses + cash_registers + payment_methods + price_lists + exchange_rate_types + exchange_rates + sesion abierta del cajero en una sola request.
- **QW4 (paginacion)** `PosOrderController::index` lee `per_page`, soporta filtros `cash_register_session_id`, `cashier_id`, `customer_id`, `date_from`, `date_to`, `search` y `summary=1` que retorna `PosOrderSummaryResource`. `WarehouseController` y `BranchController` aceptan `per_page` con fallback `limit`.

### Verificacion

- `phpunit --filter "PosCheckout|PosBootstrap|PosOrderStockSync|SyncEventApplier|InventoryTransferRequest"`: 51/51 verde.
- `vendor/bin/pint` aplicado a todos los archivos modificados.
- `frontend pnpm typecheck` limpio.

## 2026-07-20 - Bug critico solicitudes inter-empresa

### Implementado

- Se reescribio `InventoryTransferRequestService::processAcceptedItem` para que la empresa que acepta descuente unidades de SU almacen y entregue a la solicitante, corrigiendo el flujo inverso que rechazaba cualquier seleccion de IMEI.
- Nuevo helper `resolveRespondingUnits()` valida que la cantidad de IMEIs enviados coincida con `quantity`, que esten disponibles en el almacen elegido y rechaza duplicados.
- `SyncEventApplier::applyInventoryTransferRequestAccepted` y `applyTransferRequestItemAccepted` replican la nueva direccion en la nube; `createCloudProductExit` ahora recibe `serial_units` y resuelve las `product_unit_ids` correspondientes.
- Se renombraron `removeRespondingUnits`/`createRequesterUnits` para que el codigo se lea en la direccion real del movimiento.

### Verificacion

- `tests/Feature/InventoryTransferRequests/InventoryTransferRequestApiTest.php` (8/8 verde): el nuevo caso `test_requester_without_stock_can_receive_serialized_units_from_responding_company` cubre el bug original; el caso `test_accept_rejects_unavailable_imei_without_moving_stock` garantiza rollback transaccional.
- `tests/Feature/Sync/InventoryTransferRequestSyncTest.php` (4/4 verde): el evento `inventory_transfer_request.accepted` con IMEIs replica la salida en la empresa que responde y la entrada en la solicitante.
- `vendor/bin/pint` aplicado a los archivos modificados.

## 2026-07-05 - PostgreSQL local instalado en VPS

### Implementado

- Se verifico que el VPS no tenia PostgreSQL instalado en el host.
- Se instalo PostgreSQL 16 directamente en Ubuntu, fuera de Docker.
- Se habilito el servicio postgresql en systemd.
- Se crearon las bases inventory_arens e inventory_arens_testing.
- Se documento el entorno en docs/ENTORNO_VPS_POSTGRES_LOCAL_2026-07-05.md.

### Verificacion

- PostgreSQL local escucha en 127.0.0.1:5432.
- La conexion local con usuario postgres responde correctamente.
## 2026-07-05 - Servicio outbox inicial para POS y Caja

### Implementado

- Se creo SyncOutboxService para registrar eventos pendientes en sync_outbox.
- POS ahora registra pos.order.paid, pos.order.pending y pos.order.payment_added.
- Caja ahora registra cash.session.opened y cash.session.closed.
- Se documento el detalle de eventos y payloads en docs/SYNC_OUTBOX_EVENTOS_POS_CAJA_2026-07-05.md.
- Los eventos quedan en pending; todavia no existe worker de envio a nube.

### Pruebas

- Se ejecutaron SyncSchemaTest, PosCheckoutApiTest y CashRegisterApiTest.
## 2026-07-05 - Base inicial para sincronizacion local y nube

### Implementado

- Se documento la estrategia local-first con outbox bidireccional en docs/SINCRONIZACION_LOCAL_NUBE_2026-07-05.md.
- Se definieron fases para local -> nube, nube -> local, worker, conflictos, observabilidad y WebSocket opcional.
- Se crearon las tablas base sync_nodes, sync_outbox, sync_inbox y sync_states.
- Se agrego una prueba especifica para validar que la estructura guarda eventos por empresa, nodo y direccion.
- Esta fase no emite eventos desde POS, caja ni inventario. Solo prepara la base para hacerlo por modulo.

### Pruebas

- Se ejecuto SyncSchemaTest.

## 2026-07-05 - Compactacion visual final de Caja

### Implementado

- Se compacto la pantalla de Caja para ventanas pequenas.
- Se retiro el campo visible de notas de apertura y se conserva la nota automatica auditable.
- Se redujeron margenes y alturas de controles en apertura y cierre.
- Se redujo la altura de turnos abiertos y del resumen para que movimientos quede visible.
- El cierre y los movimientos del turno quedan accesibles sin depender de desplazamiento vertical.

### Pruebas

- Se compilo WPF en `desktop\InventoryDesktop\bin\CodexBuild`.
- Se ejecuto `CashRegisterApiTest`.## 2026-07-05 - Ajuste visual de apertura y cierre en Caja

### Implementado

- Se reemplazo la columna izquierda de Caja por pesta�as de **Abrir turno** y **Cerrar turno**.
- El cierre de caja queda visible sin depender de desplazamiento vertical.
- Los botones principales de abrir y cerrar quedan en el encabezado de cada pesta�a.
- Se compacto el formulario de cierre para mejorar uso en ventanas pequenas.

### Pruebas

- Se compilo WPF en `desktop\InventoryDesktop\bin\CodexBuild`.
- Se ejecuto `CashRegisterApiTest`.## 2026-07-05 - Cierre de caja con resumen de turno en WPF

### Implementado

- Al seleccionar un turno abierto, WPF carga el detalle real de la caja desde Laravel.
- Se agrego resumen visual de apertura, pagos POS, entradas, salidas y total esperado.
- Se agrego listado de movimientos del turno con fecha, tipo, metodo, recibido, base USD, local Bs, referencia y nota.
- El cierre de caja ahora se realiza con mayor contexto antes de confirmar el arqueo.
- La diferencia estimada usa el detalle actualizado del turno seleccionado.

### Pruebas

- Se compilo WPF en `desktop\InventoryDesktop\bin\CodexBuild`.
- Se ejecuto `CashRegisterApiTest`.## 2026-07-05 - Ajuste visual de Caja y administracion de cajas

### Implementado

- Se reubico el boton **Abrir turno** al encabezado del formulario para que siempre quede visible.
- Se compacto el formulario de apertura de turno.
- La ventana **Administrar cajas** dejo de usar scroll lateral para crear y editar.
- Se separo la administracion en pestanas: crear caja y editar caja seleccionada.
- Se mantuvo la creacion, edicion, activacion y desactivacion dentro del modulo Caja.

### Pruebas

- Se compila WPF.
- Se ejecuta `CashRegisterApiTest`.

## 2026-07-05 - Rediseño WPF del modulo Caja

### Implementado

- Se simplifico la pantalla principal de Caja para enfocarla en abrir turno, ver turnos abiertos y cerrar/arquear caja.
- Se retiro el formulario grande de creacion de caja de la parte izquierda.
- Se agrego una ventana independiente **Administrar cajas**.
- Desde esa ventana se pueden crear, editar, activar o desactivar cajas fisicas.
- La desactivacion funciona como borrado logico: la caja deja de aparecer para abrir turno, pero se conserva para historial y auditoria.
- La apertura de turno solo lista cajas activas.

### Pruebas

- Se compilo WPF en salida alternativa `desktop\InventoryDesktop\bin\CodexBuild`.
- Se ejecuto `CashRegisterApiTest`.

## 2026-07-05 - POS exige caja fisica abierta desde modulo Caja

### Implementado

- El backend POS ahora rechaza checkouts si la sesion de caja no tiene `cash_register_id`.
- La caja fisica asociada al turno debe estar activa.
- El escritorio WPF filtra las sesiones abiertas y solo usa la caja fisica del cajero actual en la sucursal del almacen seleccionado.
- Si el usuario intenta abrir POS sin caja fisica abierta, la aplicacion muestra una alerta y ofrece ir al modulo Caja.
- El POS muestra la caja fisica activa como nombre operativo, por ejemplo `Caja Mostrador 1 - Turno #12`.
- Se evito que POS abra cajas genericas; la apertura y cierre queda centralizada en el modulo Caja.

### Pruebas

- Se agrego una prueba para rechazar sesiones antiguas sin caja fisica en POS.
- Se actualizaron las pruebas POS para crear cajas fisicas reales antes de abrir sesiones.

## 2026-07-05 - Métricas internas de rendimiento backend para POS e Inventario

### Diagnóstico

- Las mediciones WPF mostraban que algunas APIs tardaban entre 2 y 5 segundos, pero no permitían saber qué paso interno causaba la espera.
- POS necesitaba separar validación de pagos, venta, caja, stock, cuentas por cobrar y carga de respuesta.
- Centro de Inventario necesitaba separar resumen, productos, métricas, alertas, detalle, seriales, movimientos, auditorías y acciones masivas.

### Implementado

- Se agregó `App\Support\Performance\PerformanceProbe` como medidor interno reutilizable.
- Las métricas backend se escriben en el log de Laravel con prefijo `PERF OK BACKEND` o `PERF LENTO BACKEND`.
- Se instrumentó el checkout POS completo y el flujo de completar órdenes pendientes.
- Se instrumentó el Centro de Inventario en resumen, exportación, detalle de producto, páginas de seriales, movimientos, auditorías, stock por almacén y acciones masivas.
- Se creó la documentación `docs/BACKEND_RENDIMIENTO_METRICAS_2026-07-05.md`.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php -l` sobre los archivos PHP modificados.
- Resultado: sin errores de sintaxis.
- Se ejecutó `docker compose run --rm app_test ./vendor/bin/pint` sobre los archivos PHP modificados.
- Resultado: formato aplicado correctamente.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 36 pruebas pasadas, 265 aserciones.

## 2026-07-05 - Mejora de medición y caja en cobro POS

### Diagnóstico

- El log `POS confirmar venta` incluía el tiempo que el recibo quedaba abierto en pantalla, por lo que podía aparentar una espera mayor a la real.
- El pago POS en caja recalculaba el total esperado leyendo todos los movimientos de la caja.
- Ese recalculo es correcto, pero en una caja con muchas ventas puede volverse cada vez más costoso.

### Implementado

- La medición `POS confirmar venta` ahora cubre solo la confirmación real contra backend.
- `POS preparar recibo` sigue midiendo la construcción del resumen visual.
- La lectura del recibo por parte del cajero ya no infla las métricas de rendimiento.
- `CashRegisterService::recordPosPayment` ahora actualiza el monto esperado de la caja sumando el pago POS capturado sobre la sesión bloqueada.
- Se eliminó la recarga innecesaria de movimientos de caja al registrar pagos POS desde checkout.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/CashRegister/CashRegisterApiTest.php`.
- Resultado: 22 pruebas pasadas, 151 aserciones.

## 2026-07-05 - Optimización del cobro POS

### Diagnóstico

- Al confirmar una venta, el POS se sentía lento porque después del checkout volvía a refrescar la búsqueda de productos antes de mostrar el resultado.
- La ventana de cobro se deshabilitaba completa durante el procesamiento, lo que podía sentirse como bloqueo visual.
- Algunas listas estáticas del POS podían volver a pedirse aunque ya estuvieran cargadas en la sesión.

### Implementado

- El checkout POS ya no ejecuta una búsqueda de productos al terminar la venta.
- Después de cobrar, WPF limpia el carrito y deja listo el POS para escanear o buscar otra venta sin bloquear el recibo.
- Las listas de precio, almacenes y métodos de pago quedan cacheados durante la sesión WPF.
- El botón de recargar contexto fuerza actualización de almacenes y cajas abiertas cuando el usuario lo necesite.
- La ventana de cobro muestra un mensaje de procesamiento mientras Laravel valida caja, stock, seriales y pagos.
- Durante el cobro se bloquean solo los botones de cancelar y confirmar.
- Se agregaron mediciones de rendimiento para `POS confirmar venta`, `POS checkout backend` y `POS preparar recibo`.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Último recibo y detalle operativo POS

### Diagnóstico

- Después de cerrar el recibo visual, el cajero no tenía una forma rápida de volver a verlo dentro del POS.
- El recibo necesitaba mostrar mejor los datos operativos de productos serializados y pagos.

### Implementado

- El POS conserva en memoria el último recibo pagado de la sesión actual.
- Se agregó la herramienta `F9 Último recibo` para reabrir ese recibo desde el POS.
- Se agregó atajo `F9` y se actualizó la barra inferior.
- El recibo ahora muestra SKU, tipo de control e IMEI/serial dentro del producto.
- La sección de pagos ahora incluye equivalente y referencia.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Alineación del selector de productos POS

### Diagnóstico

- En la ventana `Buscar producto`, los valores de SKU, precio, stock y control no quedaban alineados con sus encabezados.
- El contenido del `ListBox` no se estiraba completamente al ancho disponible, por eso las columnas se veían corridas.

### Implementado

- Se ajustó el selector para usar columnas compartidas entre encabezado y filas.
- Cada fila del resultado ahora ocupa todo el ancho disponible para mantener la lectura de tabla.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Bloqueo de productos sin stock en POS

### Diagnóstico

- El POS podía confundir al cajero mostrando productos sin stock como seleccionables.
- También era posible intentar agregar más unidades de un producto si el stock visible ya estaba consumido dentro del carrito actual.
- El recibo visual necesitaba una salida rápida para continuar vendiendo sin depender solo del botón.

### Implementado

- Se reforzó la validación del carrito para bloquear productos sin stock y productos cuyo disponible ya está dentro de la orden actual.
- La ventana de búsqueda de producto bloquea la acción antes de abrir seriales/IMEI o cotizar cuando no queda stock.
- El selector muestra `Sin stock` en rojo.
- El recibo POS ahora cierra con `Enter`, `Esc` o el botón `Cerrar`.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Recibo visual para ventas POS confirmadas

### Diagnóstico

- Al confirmar una venta pagada, el POS solo mostraba un mensaje simple.
- El cajero necesitaba una pantalla final clara para verificar productos, pagos, cliente, caja y totales antes de continuar.

### Implementado

- Se agregó una ventana `Venta confirmada` para mostrar el recibo visual de la venta POS.
- El recibo se abre solo cuando Laravel devuelve la orden como pagada.
- El recibo muestra orden POS, cliente, lista de precio, caja, productos vendidos, pagos recibidos, total USD, equivalente Bs, pagado y vuelto.
- Las órdenes pendientes siguen mostrando aviso de pendiente y no reciben recibo final.
- Se creó un modelo de captura del recibo para conservar productos y pagos antes de que el checkout limpie el carrito.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Aplicación de migración de descuentos en base local

### Diagnóstico

- Al confirmar una venta POS, PostgreSQL rechazaba el guardado porque la tabla `sale_items` no tenía la columna `discount_type`.
- La migración de descuentos ya existía en el proyecto, pero no estaba aplicada en la base local `inventory_arens` usada por la app de escritorio.

### Implementado

- Se ejecutó la migración pendiente `2026_07_05_120000_add_discounts_to_sale_items_table` sobre la base local de PostgreSQL.
- La tabla `sale_items` ahora tiene los campos necesarios para guardar descuentos por línea: tipo, valor, montos calculados y motivo.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 16 pruebas correctas, 120 aserciones.

## 2026-07-05 - Ajuste de búsqueda y precios activos en POS

### Diagnóstico

- La barra superior del POS tenía dos entradas para abrir el selector de productos: `Buscar` y `Selector (F2)`.
- El acceso a `Pendientes` estaba duplicado en la parte superior y en las herramientas laterales.
- Los botones principales seguían usando azul fuerte y no combinaban con el flujo más sobrio del POS.
- Al cambiar entre `Precio base` y una lista de precio, los productos que ya estaban en el carrito no actualizaban su precio.

### Implementado

- Se eliminó el botón superior `Selector (F2)`; ahora `Buscar` abre el selector manual usando el texto escrito.
- Se retiró el botón superior `Pendientes`; queda disponible en `Herramientas POS`.
- `Buscar` y `+ Buscar producto` usan estilo neutro, y `Cobrar (F12)` usa un estilo verde operativo.
- Al cambiar la lista de precio, WPF vuelve a cotizar cada línea del carrito y recalcula los totales.
- La barra inferior ahora indica `F2 buscar producto` para evitar confusión.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Limpieza operativa del POS y búsqueda robusta

### Diagnóstico

- El POS todavía mostraba controles de caja que pertenecen a un módulo propio de apertura/cierre.
- El bloque de cliente ocupaba demasiado espacio para una acción que debe abrirse bajo demanda.
- Si el cajero escribía búsquedas en minúsculas, PostgreSQL podía no devolver coincidencias esperadas como `Samsung`.

### Implementado

- El POS ya no muestra selector de caja ni botón `Abrir mi caja`.
- Al entrar al POS desde el centro de módulos, WPF valida primero que el usuario tenga caja abierta.
- Si no hay caja abierta, se muestra una alerta y el POS no se abre.
- El panel derecho del POS se simplificó: herramientas y total, sin cuadro redundante de contexto de caja.
- El cliente por defecto ahora se muestra como `Consumidor final`.
- La selección o creación de cliente se mantiene por ventana con `F8`.
- La búsqueda de inventario ahora usa `LOWER(...) LIKE` para ser insensible a mayúsculas/minúsculas en PostgreSQL.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=case_insensitive`.
- Resultado: 1 prueba correcta, 3 aserciones.

## 2026-07-05 - POS centrado en carrito y selector manual

### Diagnóstico

- El POS con tarjetas permanentes desperdiciaba espacio y hacía que la información de productos se cortara.
- Para caja real, el flujo principal debe ser escanear/agregar/cobrar, no navegar un catálogo grande.
- Cuando no hay código de barra, el cajero necesita un selector manual sin quitar espacio al carrito.

### Implementado

- Se rediseñó la pantalla principal del POS para que el carrito sea el área central.
- El panel derecho concentra cliente, contexto de caja, herramientas, pendientes y total.
- La búsqueda superior queda enfocada para escanear o escribir, y Enter intenta agregar coincidencia exacta.
- Se agregó la ventana `Buscar producto` para selección manual con nombre, SKU, precio, stock y tipo de control.
- El selector manual se abre con `F2` o con el botón `Selector (F2)`.
- Se agregaron accesos rápidos `F8` para cliente y `F12` para cobrar.
- Se mantuvieron los flujos existentes de IMEI, descuentos por línea, caja, cliente, pendientes y cobro.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Descuentos por línea en POS

### Diagnóstico

- El POS necesitaba permitir descuentos operativos sin alterar la lista de precio del producto.
- El descuento debía quedar asociado al producto vendido para poder auditar quién autorizó el ajuste.
- El total de la venta debía recalcularse antes del cobro y validarse nuevamente en backend.
- La primera versión visual dejaba el modal de descuento muy justo y el carrito demasiado angosto para mostrar acciones claras.

### Implementado

- Se agregaron campos de descuento en `sale_items`: tipo, valor, monto descontado, base USD, local VES y motivo.
- `POST /api/pos/checkouts` acepta descuentos por item con tipo `percent` o `fixed`.
- Laravel valida que el descuento no supere el total de la línea y descuenta los totales de la venta antes de confirmar.
- El recurso de venta devuelve los datos de descuento por línea.
- En WPF, cada producto del carrito tiene botón `Desc.` para abrir una ventana de descuento.
- La ventana exige motivo, valida porcentaje máximo de 100% y bloquea montos fijos superiores a la línea.
- El carrito recalcula totales y envía el descuento al backend durante el checkout.
- Se rediseñó el modal de descuento para mostrar campos, vista previa, error y botones completos.
- Se amplió el carrito del POS y se redujo una columna visual de productos para mejorar lectura y acciones.
- Las acciones del carrito ahora usan `Descuento` y `Quitar` con más espacio, evitando botones cortados.
- Se aumentó el alto del modal de descuento para evitar cortes con escalado de Windows.
- Se compactaron las tarjetas del catálogo, eliminando la franja superior vacía y acercando precio, SKU y tipo de control.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 16 pruebas correctas, 120 aserciones.

## 2026-07-05 - Búsqueda rápida y agregado exacto en POS

### Diagnóstico

- El POS exigía presionar `Buscar` para refrescar resultados.
- Para una venta rápida, el cajero necesita escribir o pistolear un código y resolver con pocas acciones.
- La búsqueda no debía consultar la API en cada tecla para no afectar rendimiento cuando el catálogo crezca.

### Implementado

- Se agregó búsqueda automática con pausa corta al escribir.
- Enter ejecuta búsqueda inmediata y revisa coincidencia exacta por SKU o nombre.
- Si existe una única coincidencia exacta por cantidad, el producto se agrega directamente al carrito.
- Si la coincidencia exacta es serializada, el POS intenta ubicar el IMEI exacto disponible.
- Si no se encuentra el IMEI exacto, se abre el selector de IMEI para que el cajero elija.
- Se mantiene el botón `Buscar` como acción manual.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-05 - Contexto operativo de caja en POS

### Diagnóstico

- El POS ya podía abrir y seleccionar cajas, pero el bloqueo visual no era suficientemente claro.
- El botón `Pagar` podía verse disponible con productos en carrito aunque faltara contexto operativo.
- Si el usuario cambiaba de almacén, podía quedar seleccionada una caja de otra sucursal hasta recargar contexto.

### Implementado

- `CanPay` ahora exige productos, almacén seleccionado, caja abierta y que la pantalla no esté ocupada.
- El encabezado del catálogo muestra estado operativo `ABIERTA` o `SIN CAJA`.
- Si se cambia el almacén y la caja pertenece a otra sucursal, el POS limpia la caja seleccionada y muestra un mensaje claro.
- Las cajas listadas se filtran por cajero conectado y por la sucursal del almacén seleccionado.
- `Abrir mi caja` pide confirmación antes de crear una caja con monto inicial `USD 0.00`.
- El flujo de pago valida almacén y caja antes de abrir la ventana de cobro.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Claridad visual de IMEI en carrito y cobro POS

### Diagnóstico

- El POS ya exigía seleccionar IMEI/serial para productos serializados.
- El carrito lo mostraba como una línea pequeña, pero no diferenciaba claramente una venta por cantidad de una venta serializada.
- En la ventana de cobro no había un resumen visible de los productos/IMEI que se iban a confirmar.

### Implementado

- Cada línea del carrito ahora muestra una etiqueta de control: `Por cantidad` o `Serializado / IMEI`.
- Los productos serializados muestran el IMEI en una etiqueta verde.
- Los botones `+` y `-` quedan desactivados para líneas con IMEI, porque otra unidad debe seleccionarse con otro serial.
- La ventana de cobro muestra un resumen compacto de los productos que se van a confirmar, incluyendo el IMEI cuando aplica.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Rendimiento POS al cambiar listas de precio

### Diagnóstico

- El POS ya actualizaba las tarjetas al cambiar entre `Precio base` y listas específicas.
- El flujo anterior podía disparar cotizaciones para todas las tarjetas cargadas, lo que no escala bien cuando el catálogo crezca a cientos o miles de productos.
- En productos serializados, el flujo de selección de IMEI podía requerir más clicks de los necesarios para una operación de caja.

### Implementado

- `Precio base` ahora muestra el precio normal del catálogo sin consultar una cotización por cada tarjeta.
- Al seleccionar una lista específica, el POS solo precotiza un grupo pequeño de productos disponibles.
- Los productos no precotizados muestran `Cotizar al tocar` y se cotizan cuando el cajero intenta agregarlos.
- Se redujo la concurrencia de precotización para no saturar la API.
- Al agregar un producto al carrito, la tarjeta actualiza su precio visual con la cotización usada.
- La ventana de IMEI enfoca automáticamente el buscador y permite confirmar con Enter si hay un único resultado.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Tarjetas POS recotizadas por lista de precio

### Diagnóstico

- El selector del POS ya permitía elegir `Precio base` o una lista específica.
- Las tarjetas del catálogo seguían mostrando el precio base/resumen, aunque el cajero cambiara a una lista distinta.
- Esto podía confundir porque el precio visual no siempre coincidía con el precio que Laravel usaría al agregar al carrito.

### Implementado

- Las tarjetas POS ahora tienen un precio visual propio que se actualiza al cambiar la lista seleccionada.
- Al seleccionar una lista específica, las tarjetas visibles muestran `Cotizando...` mientras consultan `GET /api/products/{product}/price?price_list_id={id}`.
- Si Laravel devuelve precio, la tarjeta muestra el precio de esa lista.
- Si el producto no tiene precio en la lista, la tarjeta muestra `Sin precio en lista` en color de error.
- Si se selecciona `Precio base`, la tarjeta vuelve a mostrar el precio normal del producto.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Opción Precio base en selector POS

### Diagnóstico

- El backend ya permitía cotizar con precio base cuando no se enviaba `price_list_id`.
- En WPF, el selector del POS solo mostraba listas de precio reales y seleccionaba una lista automáticamente.
- Eso impedía vender de forma clara con el precio base normal configurado en el producto.

### Implementado

- Se agregó la opción visual `Precio base` como primera opción del selector del POS.
- Cuando `Precio base` está seleccionado, WPF cotiza sin `price_list_id`.
- El checkout también envía `price_list_id` como `null`, por lo que Laravel usa el precio base del producto.
- Si el usuario selecciona una lista real, se mantiene la regla de exigir precio específico en esa lista.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Mensaje claro cuando falta precio en una lista seleccionada

### Diagnóstico

- En el POS se puede seleccionar una lista de precio para vender con precios especiales.
- Si el producto no tenía precio activo en esa lista, el backend devolvía un mensaje técnico y menos claro.
- La regla comercial deseada es no usar el precio base en silencio cuando el cajero seleccionó una lista específica.

### Implementado

- La cotización `GET /api/products/{product}/price?price_list_id={id}` ahora responde: `Este producto no tiene precio en esta lista.`
- El checkout POS hereda la misma validación; si intenta vender con una lista sin precio para ese producto, la venta se bloquea.
- Se mantuvo el comportamiento seguro: una lista seleccionada exige precio activo del producto en esa lista.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 32 pruebas pasaron, 213 assertions.

### Nota funcional

- La siguiente mejora visual será agregar en WPF una opción clara `Precio base` en el selector del POS para que el cajero pueda vender con el precio normal del producto sin elegir una lista especial.

## 2026-07-04 - Reservas de inventario para órdenes POS pendientes

### Diagnóstico

- Una orden POS pendiente dejaba la venta en borrador, pero el inventario seguía apareciendo como disponible.
- En productos de baja cantidad, esto podía permitir que otra caja vendiera una unidad que ya estaba comprometida por un pago parcial o pendiente.
- En productos serializados/IMEI, hacía falta que el serial quedara apartado mientras se completaba el cobro.

### Implementado

- El backend ahora reserva inventario cuando `POST /api/pos/checkouts` crea una orden POS abierta.
- La reserva mueve unidades de `quantity_available` a `quantity_reserved` y queda referenciada a la orden POS.
- Si el producto tiene serial/IMEI, el `product_unit` pasa a estado `reserved`.
- Cuando `POST /api/pos/orders/{order}/payments` completa el total capturado, Laravel libera la reserva dentro de la misma transacción y confirma la venta inmediatamente.
- Al confirmar, el inventario termina descontado como venta real y los seriales/IMEI pasan a estado `sold`.
- Las ventas pagadas completo desde el primer cobro no reservan; se confirman directo como antes.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 14 pruebas pasaron, 107 assertions.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryMovementServiceTest.php`.
- Resultado: 6 pruebas pasaron, 18 assertions.

### Notas de seguridad

- Si queda una sola unidad disponible y una orden pendiente la reserva, otra caja ya no puede venderla.
- Si el producto es serializado/IMEI, el mismo IMEI no puede reutilizarse mientras esté reservado.
- La protección queda en backend, no depende de que el frontend refresque rápido.

## 2026-07-04 - Reglas claras para pendientes POS por caja

### Diagnostico

- La ventana de pendientes mostraba ordenes abiertas de la empresa completa, incluyendo ordenes creadas por otros cajeros.
- Al intentar completar una orden de otro cajero, Laravel respondia correctamente que la caja pertenecia a otro cajero.
- En el cobro inicial, WPF no permitia confirmar una venta parcial si todos los pagos agregados estaban marcados como capturados, aunque el backend puede registrarla como orden pendiente.

### Implementado

- WPF ahora filtra las ordenes pendientes por `cashier_id` del usuario conectado.
- La ventana de pendientes muestra mensajes orientados a `tu caja`.
- El cobro inicial permite confirmar una orden pendiente cuando los pagos capturados no cubren el total, previa advertencia al cajero.
- Se actualizo la documentacion del modulo POS en espanol.

### Pruebas

- Se ejecuto `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilacion correcta, 0 advertencias, 0 errores.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php --filter=pending`.
- Resultado: 2 pruebas pasaron, 21 assertions.

### Notas de seguridad

- Backend sigue rechazando completar cobros de cajas ajenas.
- El filtro visual evita confusion, pero la seguridad real sigue estando en Laravel.

## 2026-07-04 - Claridad visual al completar orden POS pendiente

### Diagnostico

- La ventana de ordenes pendientes era confusa porque mostraba el selector de estado del pago aunque el uso principal era cerrar la orden con un pago capturado.
- Al registrar el pago, la ventana se deshabilitaba completa durante la llamada al backend y el mensaje de resultado podia no ser evidente.
- El usuario no tenia una indicacion clara de que, al cubrir el faltante, la orden debia salir de la lista de pendientes.

### Implementado

- Se renombro el boton principal a `Cobrar faltante y cerrar`.
- La ventana ahora registra el pago como capturado en este flujo, evitando confundir con pagos pendientes.
- Se dejo visible el mensaje de resultado dentro de la misma ventana.
- Durante el procesamiento se deshabilita solo el boton principal y se muestra `Procesando...`.
- Si ya no quedan ordenes pendientes, la ventana muestra un mensaje verde indicando que la orden quedo cerrada.
- Se actualizo la documentacion del modulo POS en espanol.

### Pruebas

- Se ejecuto `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilacion correcta, 0 advertencias, 0 errores.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php --filter=pending`.
- Resultado: 2 pruebas pasaron, 21 assertions.

### Notas de seguridad

- El backend sigue validando si el pago realmente cubre el total antes de cerrar la orden.
- La interfaz solo aclara la accion principal del cajero: cobrar el faltante para cerrar.

## 2026-07-04 - Ordenes POS pendientes y completar cobro

### Diagnostico

- Las ordenes POS abiertas ya existian en backend cuando un pago quedaba pendiente o no cubria el total.
- Faltaba una operacion segura para agregar pagos a una orden abierta sin crear una venta nueva.
- Faltaba una herramienta visual en WPF para que el cajero pudiera ver pendientes y completar el cobro.

### Implementado

- Se agrego `POST /api/pos/orders/{posOrder}/payments` para registrar pagos adicionales sobre una orden POS abierta.
- El backend valida que la orden este `open`, que la venta asociada siga en borrador y que la caja pertenezca al cajero conectado.
- Al agregar pagos capturados, el backend registra movimiento de caja y recalcula lo pagado.
- Si los pagos capturados cubren el total, el backend confirma la venta, descuenta inventario, crea la cuenta por cobrar pagada y marca la orden como `paid`.
- Se agrego filtro `status` en `GET /api/pos/orders` para consultar `status=open`.
- Se agrego la ventana WPF `Ordenes POS pendientes` con listado de ordenes abiertas, total, pagado, faltante y registro de pago.
- Se agrego boton `Pendientes` dentro del POS de escritorio.
- Se actualizo el README del modulo POS en espanol.

### Pruebas

- Se ejecuto `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilacion correcta, 0 advertencias, 0 errores.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php --filter=pending`.
- Resultado: 2 pruebas pasaron, 21 assertions.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 12 pruebas pasaron, 86 assertions.

### Notas de seguridad

- Completar una orden pendiente no crea una venta adicional.
- Solo se pueden completar ordenes de la empresa actual y con permisos `pos.checkout`.
- La confirmacion final sigue pasando por el servicio de ventas, por lo que conserva validacion de stock, IMEI/seriales y concurrencia.

## 2026-07-04 - Corrección de estado pagado en cobro POS WPF

### Diagnóstico

- Laravel marca una orden POS completamente pagada con estado `paid`.
- La ventana WPF de cobro estaba considerando venta confirmada solo cuando el estado era `closed`.
- Como `closed` no es el estado real del modelo `PosOrder`, WPF mostraba una venta pagada como `Orden pendiente`.

### Implementado

- Se agregó una validación centralizada en WPF para tratar `paid`, `closed` y `payment_status = paid` como venta confirmada.
- Se ajustó el título y el icono del mensaje final para que una orden `paid` muestre `Venta confirmada`.
- Se compactó la ventana de cobro para que entre mejor en pantalla y la tabla de pagos sea más legible.
- Se actualizó la documentación del módulo POS en español.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 11 pruebas pasaron, 75 assertions.

### Notas de seguridad

- El backend sigue siendo quien decide si una orden queda pagada o pendiente.
- WPF solo corrige la interpretación visual del estado devuelto por Laravel.

## 2026-07-04 - Tasa correcta al cobrar POS en bolívares

### Diagnóstico

- Una orden podía quedar pendiente aunque el monto en bolívares pareciera cubrir el total mostrado en WPF.
- La causa probable era una diferencia entre la tasa usada para cotizar el producto en pantalla y la tasa predeterminada que Laravel usaba al procesar el pago.
- El backend ya soportaba `payments.*.exchange_rate_type_id`, pero WPF no lo enviaba.

### Implementado

- Se agregó `exchange_rate_type_id` al DTO de cotización POS en WPF.
- Cada ítem del carrito conserva el tipo de tasa usado por su cotización.
- Al cobrar en bolívares, WPF envía el `exchange_rate_type_id` del carrito en cada pago.
- Si el carrito mezcla productos con tipos de tasa distintos, WPF bloquea el pago en bolívares y muestra un mensaje claro.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 11 pruebas pasaron, 75 assertions.

## 2026-07-04 - Migración local pendiente para métodos de pago

### Diagnóstico

- Durante una venta POS, Laravel mostró `relation "payment_methods" does not exist`.
- La causa fue que la base local `inventory_arens` no tenía aplicada la migración `2026_07_04_235500_create_payment_methods_tables`.
- El código y las pruebas ya tenían la migración, pero la base de desarrollo estaba atrasada.

### Acción realizada

- Se ejecutó `docker compose run --rm app php artisan migrate`.
- Se aplicó correctamente `2026_07_04_235500_create_payment_methods_tables`.
- Se verificó con `docker compose run --rm app php artisan migrate:status`.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/PaymentMethods/PaymentMethodApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 14 pruebas pasaron, 89 assertions.

### Nota operativa

- Si una estación local muestra errores de tablas faltantes después de actualizar el código, se debe ejecutar `docker compose run --rm app php artisan migrate` contra la base de desarrollo correspondiente.

## 2026-07-04 - Apertura de caja propia desde POS

### Implementado

- Se agregó lectura de `branch_id` en el DTO de almacenes del escritorio.
- Se agregó botón `Abrir mi caja` en el encabezado del POS.
- El botón abre una caja propia usando la sucursal del almacén seleccionado.
- La apertura se realiza con monto inicial `0 USD` y nota `Apertura desde POS de escritorio`.
- Después de abrir caja, el POS recarga el contexto y selecciona la caja del usuario conectado.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/CashRegister/CashRegisterApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 17 pruebas pasaron, 106 assertions.

### Notas

- El backend mantiene la regla de una sola caja abierta por cajero.
- Si el usuario no tiene permiso `cash_register.open`, Laravel rechazará la apertura y WPF mostrará el mensaje del servidor.

## 2026-07-04 - Mensaje claro al pagar sin caja propia

### Implementado

- Se ajustó el botón `Pagar` para que responda cuando hay productos en el carrito aunque falte caja seleccionada.
- Al presionar `Pagar` sin caja propia abierta, WPF recarga cajas abiertas del usuario y muestra un mensaje claro si no encuentra ninguna.
- Se agregó `PayHint` al POS para mostrar debajo del botón si falta producto, caja o si ya puede abrir cobro.
- Se mantiene la protección backend: la venta solo se confirma si la caja pertenece al cajero autenticado.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

## 2026-07-04 - Corrección de caja y equivalentes en cobro POS

### Implementado

- Se corrigió la relación `PriceList -> paymentMethods` indicando explícitamente las llaves del pivote `price_list_payment_method`.
- Se corrigió la relación inversa `PaymentMethod -> priceLists` con las llaves correctas del pivote.
- Se agregó prueba para listar listas de precio con métodos de pago asociados.
- El POS de escritorio ahora filtra cajas abiertas por el usuario conectado.
- Si el usuario no tiene caja abierta propia, el POS muestra un mensaje claro antes de intentar vender.
- La ventana de cobro se amplió para dar más espacio a los pagos agregados.
- La tabla de pagos ahora muestra método, monto recibido, equivalente, estado y referencia.
- Cuando el pago es en USD, se muestra equivalente en Bs si la tasa está disponible.
- Cuando el pago es en Bs, se muestra equivalente en USD.
- Se agregó mensaje más claro si el backend rechaza la caja por pertenecer a otro cajero o por estar cerrada.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/PaymentMethods/PaymentMethodApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 14 pruebas pasaron, 89 assertions.

### Notas

- La validación final de caja sigue en Laravel.
- El filtrado WPF evita seleccionar cajas ajenas, pero el backend conserva la protección definitiva.

## 2026-07-04 - Registro rápido de cliente y cobro visible en POS

### Implementado

- Se agregó botón `+ Nuevo cliente` en la ventana de selección de cliente del POS.
- Se agregó ventana WPF `Nuevo cliente` para registrar nombre, tipo de documento, documento, teléfono, correo y dirección fiscal.
- El registro rápido consume `POST /api/customers`.
- Al crear el cliente desde POS, queda seleccionado automáticamente para la venta actual.
- Se reorganizó la ventana de cobro para que `Agregar pago` esté visible junto al monto recibido.
- Se eliminó el botón duplicado de agregar pago que quedaba oculto debajo del formulario.
- Se amplió ligeramente la ventana de cobro para que referencia y estado del pago tengan mejor espacio.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Customers/CustomerApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 16 pruebas pasaron, 101 assertions.

### Notas

- No se cambió la lógica de negocio del backend; se usa la API existente de clientes.
- `Agregar pago` solo agrega el pago a la tabla; `Confirmar venta` sigue siendo la acción final validada por Laravel.

## 2026-07-04 - Ajustes UX de cliente y cobro POS

### Implementado

- Se reorganizó el bloque `Orden actual` para separar el resumen del carrito, el cliente de la venta y las acciones disponibles.
- Se cambió `Limpiar` por `Limpiar carrito` para dejar claro que borra la orden completa.
- Se cambió `Cliente` por `Buscar cliente` y `Mostrador` por `Venta mostrador`.
- Se cambió `Usar faltante` por `Completar saldo` en la ventana de cobro.
- Se cambió `Limpiar` por `Borrar monto` dentro del formulario de nuevo pago.
- Se cambió `Quitar seleccionado` por `Eliminar pago`.
- Se agregó vista previa automática al escribir el monto recibido: pagado estimado, faltante estimado y vuelto estimado.
- La vista previa distingue pagos capturados de pagos pendientes para evitar confusión antes de confirmar.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas

- No se cambió lógica de negocio del backend.
- El servidor seguirá validando caja, stock, seriales, moneda, método de pago y referencia al confirmar la venta.

## 2026-07-04 - Cliente en POS de escritorio

### Implementado

- Se agregó búsqueda backend de clientes por `search` en `GET /api/customers`.
- La búsqueda de clientes filtra por nombre, documento, teléfono o correo.
- Se agregó `active_only=1` para consultar solo clientes activos.
- Se agregó `limit` configurable entre 1 y 100 registros.
- Se agregó ventana WPF `Seleccionar cliente` en el módulo POS.
- El POS mantiene `Cliente mostrador` por defecto para ventas rápidas.
- El cajero puede buscar clientes activos por nombre, documento, teléfono o correo.
- El cajero puede seleccionar un cliente real o volver a `Cliente mostrador`.
- La orden actual muestra nombre, documento y datos básicos del cliente seleccionado.
- El checkout de escritorio envía `customer_id` cuando hay cliente seleccionado.
- Si no hay cliente seleccionado, el checkout conserva `customer_name = Cliente mostrador`.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Customers/CustomerApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 16 pruebas pasaron, 101 assertions.

### Notas de seguridad

- El frontend solo selecciona el cliente; backend valida que `customer_id` pertenezca a la empresa actual.
- La búsqueda no mezcla clientes entre empresas.
- La creación rápida de cliente desde POS queda para una fase posterior.

## 2026-07-04 - Mejoras de cobro en POS de escritorio

### Implementado

- Se agregó botón `Usar faltante` en la ventana de cobro para completar rápidamente el monto pendiente.
- Se agregó botón `Limpiar` para reiniciar el formulario de pago sin tocar los pagos ya agregados.
- Se agregó selector de estado del pago: capturado o pendiente.
- Los pagos capturados cuentan para cerrar la venta.
- Los pagos pendientes quedan visibles en la lista, pero no cubren el total capturado.
- Si se confirma con pagos pendientes y el total capturado no cubre la venta, la app advierte que la orden quedará pendiente.
- Se agregó cálculo de vuelto estimado cuando los pagos capturados superan el total.
- El resumen de pagos muestra cuántos pagos están pendientes.
- El mensaje final diferencia entre venta cerrada y orden pendiente.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/PaymentMethods/PaymentMethodApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 13 pruebas pasaron, 86 assertions.

### Notas de seguridad

- El cálculo de vuelto es estimado y se basa en pagos capturados conocidos.
- Backend sigue siendo quien valida si la venta se cierra o queda pendiente.
- Los pagos pendientes están pensados para financiadoras, transferencias por validar u otros cobros que no cierran caja inmediatamente.

## 2026-07-04 - Centro de módulos después del login

### Implementado

- Se cambió la primera vista del panel de escritorio para mostrar un `Centro de módulos` después del login.
- Se agregaron tarjetas grandes para abrir POS, Centro de Inventario, Entradas y salidas y Listas de precio.
- Se dejaron tarjetas preparadas para Caja, Reportes y Configuración como módulos próximos.
- Se eliminó el menú lateral para dejar la navegación principal solo por tarjetas de módulos.
- Las pantallas internas usan un botón superior `Módulos` para volver al selector principal.
- POS se mantiene como pantalla completa y vuelve al `Centro de módulos` con su acción inferior.
- El POS vuelve al `Centro de módulos` cuando el usuario usa `Volver al panel`.
- Se agregó base de permisos en `DesktopSession` con `HasPermission` y `HasAnyPermission`.
- Las tarjetas y botones principales ya pueden habilitarse o deshabilitarse según permisos.
- Los módulos no implementados quedan visibles como próximos módulos, pero deshabilitados para evitar confusión operativa.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- La pantalla de módulos solo controla navegación visual.
- Los permisos reales siguen validándose en backend.
- Esta base permitirá ocultar o bloquear módulos por rol sin reordenar todo el menú lateral.

## 2026-07-04 - Ventana de cobro POS en escritorio

### Implementado

- Se habilitó el botón `Pagar` en el POS de escritorio cuando existe carrito y caja abierta.
- Se creó la ventana `Cobrar venta` como pantalla separada para no reducir el espacio del catálogo ni del carrito.
- La ventana carga métodos de pago activos desde backend.
- Si la lista de precio tiene métodos restringidos, solo muestra esos métodos.
- Si la lista está abierta y no hay métodos configurados, ofrece métodos básicos compatibles con backend.
- Se permite agregar varios pagos antes de confirmar la venta.
- Se valida en escritorio que los métodos `USD`, `VES` y `flexible` usen monedas compatibles.
- Se exige referencia en escritorio cuando el método de pago la requiere.
- Se calcula pagado y faltante estimado en USD cuando la cotización tiene tasa.
- Se conecta el checkout real contra `POST /api/pos/checkouts`.
- Al confirmar correctamente, se limpia el carrito y se refresca el catálogo.
- Se documentó el nuevo flujo en el README del módulo POS.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/PaymentMethods/PaymentMethodApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 13 pruebas pasaron, 86 assertions.

### Notas de seguridad

- WPF solo guía la experiencia; Laravel vuelve a validar caja, stock, seriales, lista de precio, método de pago, moneda y referencia.
- El cálculo de faltante en bolívares es estimado; backend recalcula con la tasa activa al confirmar.
- La impresión de ticket, vuelto/cambio y cliente en POS quedan para una siguiente fase.

## 2026-07-04 - Métodos de pago configurables y reglas por lista de precio

### Implementado

- Se creó el módulo backend `PaymentMethods` para administrar métodos de pago por empresa.
- Se agregó la tabla `payment_methods` con nombre, código, tipo operativo, modo de moneda, referencia obligatoria, estado y orden.
- Se agregó la tabla pivote `price_list_payment_method` para asociar listas de precio con métodos de pago permitidos.
- Se agregó `payment_method_id` en `pos_payments` para conservar históricamente el método configurado usado en una venta POS.
- Las listas de precio ahora pueden recibir y devolver `payment_method_ids` y `payment_methods`.
- El checkout POS ahora acepta `items.*.price_list_id` para saber qué lista se está vendiendo.
- El checkout POS ahora acepta `payments.*.payment_method_id`.
- Si una lista de precio tiene métodos asociados, el POS solo permite cobrar con esos métodos.
- Si el método está configurado como `USD`, solo acepta pagos en dólares.
- Si el método está configurado como `VES`, solo acepta pagos en bolívares.
- Si el método está configurado como `flexible`, acepta pagos en dólares o bolívares.
- Si el método exige referencia, el POS rechaza el pago cuando no se envía `reference`.
- Se mantuvo compatibilidad con el checkout existente: si la lista de precio no tiene métodos restringidos, el POS puede seguir usando `method` y `currency` como antes.
- Se agregaron permisos `payment_methods.view` y `payment_methods.update`.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/PaymentMethods/PaymentMethodApiTest.php tests/Feature/POS/PosCheckoutApiTest.php`.
- Resultado: 13 pruebas pasaron, 86 assertions.

### Notas de seguridad

- La validación final vive en backend; el frontend o WPF solo deben mostrar opciones disponibles.
- Las reglas de pago se aplican por empresa y no se mezclan entre tenants.
- Las financiadoras externas quedan representadas como tipo operativo `external_financing`, pero sus datos específicos se implementarán en una fase futura.
- Las listas de precio pueden quedar sin métodos asociados para operar como listas abiertas.

## 2026-07-04 - Contexto operativo e IMEI en POS

### Implementado

- Se agregó selector de almacén activo en la cabecera del POS de escritorio.
- Se agregó selector de caja abierta consultando sesiones de caja existentes.
- El POS ahora impide agregar productos si no hay almacén seleccionado.
- Cada línea del carrito conserva el almacén de salida.
- Se creó la ventana `Seleccionar IMEI/serial` para productos serializados.
- La ventana consulta seriales disponibles filtrados por producto, estado disponible y almacén seleccionado.
- El carrito evita repetir el mismo IMEI/serial dentro de la orden actual.
- Las líneas con IMEI no permiten aumentar cantidad con `+`; otra unidad requiere escoger otro serial.
- Se dejó preparado `product_unit_ids` en el item del carrito para el futuro checkout real.
- Se actualizó el README del módulo POS en español.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter="serials|checkout"`.
- Resultado: 10 pruebas pasaron, 89 assertions.

### Notas de seguridad

- No se modificó backend.
- No se habilitó checkout ni pagos.
- No se descuenta inventario.
- El checkout real deberá revalidar caja, almacén, stock, seriales y precios en backend.

## 2026-07-04 - Optimización de cotizaciones en POS

### Implementado

- Se agregó caché temporal de cotizaciones en el POS de escritorio.
- El POS precarga cotizaciones de productos visibles en segundo plano después de cada búsqueda.
- Si el usuario hace click en un producto cuya cotización ya está preparada, el agregado al carrito evita una nueva llamada a la API.
- Si la precarga y el click coinciden, ambos comparten la misma consulta para no duplicar llamadas.
- Al cambiar lista de precio o ejecutar una nueva búsqueda se limpia la caché para evitar mezclar precios.
- Se documentó que el checkout real debe volver a validar precios contra backend antes de vender.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se modificó backend.
- No se habilitó checkout ni pagos.
- No se descuenta inventario.
- La caché solo acelera la experiencia visual del POS; no reemplaza validación final del servidor.

## 2026-07-04 - POS compacto con mayor espacio para catálogo

### Implementado

- Se retiró la barra lateral interna del POS para que el catálogo gane ancho real.
- Se movieron la salida, el estado y los atajos rápidos a una barra inferior.
- Se redujo el ancho del carrito derecho para equilibrar orden actual y catálogo.
- Se compactaron las tarjetas de productos para permitir más columnas visibles.
- Se reubicó la búsqueda junto a la lista de precio en la barra superior.
- Se corrigieron textos del POS para mostrar acentos correctamente en español.
- Se actualizó el README del módulo POS en español.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se modificó backend.
- No se habilitó checkout ni pagos.
- No se descuenta inventario.

## 2026-07-04 - POS en pantalla completa

### Implementado

- Se rediseñó `PosView` para ocupar toda la ventana de trabajo.
- El POS ahora se muestra como capa completa sobre el shell, sin sidebar ni cabecera administrativa.
- Se agregó una barra lateral mínima propia del POS con botón `Salir`.
- El catálogo de productos usa más espacio horizontal y tarjetas más estables.
- El carrito permanece fijo a la derecha con totales visibles.
- Se actualizó el README del módulo POS en español.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se modificó backend.
- No se habilitó checkout ni pagos.
- No se descuenta inventario.

## 2026-07-04 - Base visual y operativa del POS en WPF

### Implementado

- Se creó el módulo de escritorio `desktop/InventoryDesktop/Modules/POS`.
- Se agregó `PosView` con diseño base inspirado en POS moderno: búsqueda superior, catálogo de productos y carrito fijo a la derecha.
- Se agregó `PosViewModel` para buscar productos reales desde `GET /api/inventory-center/summary`.
- Se cargan listas de precio activas desde `GET /api/price-lists?active_only=1`.
- Al agregar un producto se cotiza con `GET /api/products/{product}/price`, respetando la lista seleccionada.
- El carrito local muestra cantidad, precio unitario, lista usada, tasa usada, total `USD` y equivalente `VES` cuando aplica.
- El menú lateral del shell ahora habilita el botón `POS`.
- El botón `Pagar` queda deshabilitado porque checkout, caja y métodos de pago se implementarán en la siguiente fase.
- Se bloquea el agregado directo de productos serializados/IMEI hasta integrar selección o lectura de serial.
- Se agregó README del módulo POS de escritorio y se actualizó el README general de escritorio.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan migrate:fresh` para restaurar la base de pruebas después de un intento paralelo fallido.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter="filters_by_search|paginates"`.
- Resultado: 5 pruebas pasaron, 44 assertions.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php --filter=price`.
- Resultado: 6 pruebas pasaron, 48 assertions.

### Notas de seguridad

- Esta fase no crea ventas ni pagos.
- No descuenta inventario.
- No registra movimientos de caja.
- La cotización y la búsqueda siguen pasando por el backend con token, tenant y permisos.
- El checkout real debe usar el módulo backend `POS` y no descontar inventario directamente.

## 2026-07-04 - Historial de precios en ventana independiente

### Implementado

- Se retiró el historial embebido de la parte inferior de la pestaña `Precios`.
- Se agregó el botón `Ver historial` en la cabecera de la pestaña `Precios`.
- Se creó la ventana WPF `Historial de precios` para mostrar fecha, lista, precio anterior, precio nuevo y usuario.
- La ventana permite recargar el historial y muestra errores en español si falla la API.
- La pestaña `Precios` vuelve a priorizar la edición de precios sin ocupar espacio con historial.
- Se actualizó el README del módulo en español.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se modificó backend ni reglas de negocio.
- El historial sigue consultando `GET /api/products/{product}/price-history`.

## 2026-07-04 - Historial de precios por producto

### Implementado

- Se agregó `GET /api/products/{product}/price-history` para consultar historial estructurado de cambios de precio por lista.
- `PUT /api/products/{product}/prices` ahora registra auditoría con precio anterior y precio nuevo cuando se crea o actualiza un precio por lista.
- El historial reutiliza `product_audits` y muestra cambios manuales y cambios masivos.
- La pestaña WPF `Precios` del detalle de producto ahora muestra un bloque de `Historial reciente de precios`.
- El historial muestra fecha, lista, precio anterior, precio nuevo y usuario responsable.
- Se actualizó la documentación de API y el README del módulo en español.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php --filter=price`.
- Resultado: 6 pruebas pasaron, 48 assertions.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=price_list`.
- Resultado: 2 pruebas pasaron, 11 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- El historial requiere permiso `products.view`.
- La edición de precios sigue requiriendo `products.update`.
- No se modifican stock, seriales, movimientos ni ventas.
- El POS futuro podrá apoyarse en este historial para auditoría de cambios de precios antes de vender.

## 2026-07-04 - Actualización masiva de precios por lista

### Implementado

- Se agregó la acción masiva `update_price_list` en `POST /api/inventory-center/products/bulk-action`.
- La acción `fill_missing_price_list` se mantiene para completar únicamente precios faltantes sin tocar precios existentes.
- La nueva acción `update_price_list` crea el precio si no existe y lo actualiza si ya existe.
- La app WPF ahora muestra dos opciones separadas: `Completar precios faltantes` y `Actualizar precios por lista`.
- El panel de acciones masivas usa los mismos campos de lista, estrategia, moneda, monto fijo o porcentaje para ambas acciones.
- Se actualizaron la documentación de API y el README del módulo en español para aclarar cuándo usar cada opción.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=price_list`.
- Resultado: 2 pruebas pasaron, 11 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- Ambas acciones requieren token, tenant y permiso `products.update`.
- La lista de precio seleccionada debe pertenecer a la empresa actual.
- No se modifica stock, seriales, movimientos de inventario ni ventas.
- Para cambiar precios existentes debe usarse explícitamente `Actualizar precios por lista`.

## 2026-07-04 - Ajuste visual del formulario de acciones masivas

### Implementado

- Se amplió la ventana WPF `Acciones masivas` para dar más espacio al formulario.
- Se ajustó la distribución entre la lista de productos seleccionados y el panel de acción.
- El panel derecho ahora tiene desplazamiento vertical interno para que las acciones con más campos, como `Completar precios por lista`, no queden cortadas.
- Los botones `Cancelar` y `Aplicar acción` permanecen visibles en la parte inferior de la ventana.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se modificó lógica de negocio ni APIs.
- El cambio afecta únicamente la presentación visual de la ventana WPF.

## 2026-07-04 - Completar precios faltantes por lista en acciones masivas

### Implementado

- Se agregó la acción masiva `fill_missing_price_list` en `POST /api/inventory-center/products/bulk-action`.
- La acción permite completar precios faltantes de una lista usando tres estrategias: copiar precio base, usar monto fijo o calcular porcentaje sobre el precio base.
- El backend crea registros en `product_prices` solo cuando el producto no tiene precio en la lista seleccionada.
- Si un producto ya tiene precio en esa lista, se omite y se devuelve el motivo visible.
- Si la estrategia necesita precio base y el producto no lo tiene, se omite sin modificarlo.
- Cada precio creado queda auditado en `product_audits`.
- La ventana WPF de `Acciones masivas` ahora incluye la opción `Completar precios por lista`, selector de lista, estrategia, moneda y campos dinámicos según la estrategia.
- Se actualizó la documentación de API y el README del módulo en español.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=bulk_action`.
- Resultado: 3 pruebas pasaron, 20 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 18 pruebas pasaron, 137 assertions.

### Notas de seguridad

- La acción requiere token, tenant y permiso `products.update`.
- La lista de precio seleccionada debe pertenecer a la empresa actual.
- No sobrescribe precios existentes.
- No modifica stock, seriales, movimientos de inventario ni ventas.

## 2026-07-04 - Corrección de acceso a acciones masivas WPF

### Implementado

- Se corrigió el botón `Acciones masivas` para que siempre responda al clic.
- Si no hay productos seleccionados, la app muestra un mensaje claro en español.
- Si hay una fila activa, la acción masiva usa esa fila aunque no haya una selección múltiple explícita.
- La selección de la tabla ahora sincroniza la fila activa con el ViewModel del Centro de Inventario.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- No se cambió la API ni las reglas de negocio.
- Las acciones masivas siguen pasando por `POST /api/inventory-center/products/bulk-action` con token, tenant y permisos.

## 2026-07-04 - Acciones masivas del Centro de Inventario

### Implementado

- Se agregó `POST /api/inventory-center/products/bulk-action` para ejecutar acciones masivas sobre productos.
- Las acciones iniciales son `activate`, `deactivate`, `assign_warranty_policy` y `assign_exchange_rate_type`.
- El backend valida tenant, permiso `products.update`, productos seleccionados y que garantía o tasa pertenezcan a la empresa actual.
- Cada producto modificado genera auditoría en `product_audits`.
- Las acciones se ejecutan en transacción y bloquean los productos seleccionados durante la actualización.
- Se agregó selección múltiple en la tabla WPF del Centro de Inventario.
- Se creó la ventana WPF `Acciones masivas` con confirmación, lista de productos seleccionados y campos dinámicos según la acción.
- Se actualizó la documentación de API y el README del módulo.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=bulk_action`.
- Resultado: 2 pruebas pasaron, 14 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 17 pruebas pasaron, 131 assertions.

### Notas de seguridad

- No se permite modificar productos de otra empresa.
- La ventana WPF solo consume APIs; no toca PostgreSQL directamente.
- Las acciones no modifican stock ni movimientos de inventario.

## 2026-07-04 - Exportación CSV del Centro de Inventario

### Implementado

- Se agregó `GET /api/inventory-center/export` para exportar inventario filtrado en CSV.
- La exportación reutiliza los filtros del resumen: búsqueda, tipo de control, estado de stock y umbral de stock bajo.
- El CSV incluye producto, SKU, tipo de control, moneda, precio base, disponible, reservado, dañado y estado de stock.
- Se agregó el botón `Exportar CSV` en la cabecera WPF del Centro de Inventario.
- La app de escritorio permite elegir ubicación local y muestra errores de API o guardado en español.
- Se actualizó la documentación de API y el README del módulo.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=exports_filtered_inventory_as_csv`.
- Resultado: 1 prueba pasó, 6 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 15 pruebas pasaron, 117 assertions.

### Notas de seguridad

- La exportación requiere token, tenant y permiso `products.view` o `inventory.view`.
- No modifica datos ni genera movimientos de inventario.

## 2026-07-04 - Alertas operativas en ventana WPF

### Implementado

- Se retiró la visualización fija de alertas operativas entre filtros y tabla del Centro de Inventario.
- Se agregó un botón compacto en la cabecera del Centro de Inventario con el conteo de alertas.
- Se creó una ventana independiente `Alertas operativas` para revisar conteo, severidad, productos afectados y acción recomendada.
- La tabla principal vuelve a ocupar el espacio central inmediatamente después de los filtros.
- No se cambió la API ni la lógica de negocio del backend.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- Las alertas siguen llegando desde `GET /api/inventory-center/summary` y respetan tenant, token y permisos existentes.
- La nueva ventana es solo lectura; no modifica productos, stock ni listas de precio.

## 2026-07-04 - Alertas operativas del Centro de Inventario

### Implementado

- Se agregó el bloque `alerts` a `GET /api/inventory-center/summary`.
- Las alertas iniciales detectan `Stock bajo`, `Sin stock`, `Sin precio base`, `Sin garantía` y `Listas de precio incompletas`.
- Cada alerta devuelve tipo, severidad, título, conteo, mensaje, acción recomendada y productos de ejemplo.
- Se conectó la app WPF para mostrar las alertas entre filtros y tabla de productos.
- Se mantuvo el cálculo en consultas agregadas por tenant para evitar lecturas N+1.
- Se actualizó la documentación de API y el README del módulo.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php --filter=operational_alerts`.
- Resultado: 1 prueba pasó, 11 assertions.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 14 pruebas pasaron, 111 assertions.

### Notas de seguridad

- Las alertas respetan tenant y permisos existentes de `inventory-center/summary`.
- No modifican datos; son solo lectura operativa.

## 2026-07-04 - Mejora de precios por lista en detalle de producto WPF

### Implementado

- Se mejoró la pestaña `Precios` dentro del detalle del producto.
- Cada fila ahora muestra qué precio usará el POS: precio específico de la lista o respaldo del precio base.
- Se agregó la acción `Copiar base a vacíos` para completar listas sin precio específico.
- Se agregó la acción por fila `Copiar base` para cargar el precio base en una lista puntual.
- La vista recalcula el precio efectivo cuando se cambia precio, moneda o estado activo.
- Se documentó el flujo de listas de precio dentro del módulo Centro de Inventario.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php --filter=price_lists`.
- Resultado: 1 prueba pasó, 14 assertions.

### Notas de seguridad

- No se cambió la API ni las reglas de negocio del backend.
- La pantalla sigue usando `GET /api/products/{product}/prices` y `PUT /api/products/{product}/prices` con token y tenant.

## 2026-07-04 - Corrección UX definitiva de listas de precio WPF

### Implementado

- Se corrigió el botón principal del formulario de listas para que no quede recortado como una franja morada.
- El botón `Crear lista` / `Guardar cambios` ahora aparece antes de `Opciones avanzadas`, con mayor altura, texto centrado y contraste visible.
- Se redujo la altura del campo `Descripción` para mejorar el espacio vertical del formulario.
- Se corrigieron textos visibles con tildes en la vista XAML de listas de precio.
- Se eliminó el botón confuso `+ Preparar nueva` de la cabecera de listas de precio.
- La cabecera ahora solo permite `Actualizar` y `Limpiar formulario`.
- La acción real de guardado queda dentro del formulario con un botón principal visible: `Crear lista` o `Guardar cambios`.
- Se agregó un estado vacío claro: si no hay listas, la pantalla indica que se debe completar el formulario y presionar `Crear lista`.
- El campo `Orden` se cambió a `Posición visual` y se movió a `Opciones avanzadas`.
- La posición visual ahora se calcula automáticamente al crear una lista nueva.
- Se corrigieron textos visibles con tildes en la pantalla WPF de listas de precio.
- Se aclaró en pantalla que crear una lista la deja disponible para todos los productos, pero cada producto debe tener su precio específico por lista.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php --filter=price_lists`.
- Resultado: 1 prueba pasó, 14 assertions.

### Notas de seguridad

- No se cambió la regla de negocio del backend.
- Las listas siguen protegidas por token, empresa actual y permiso `products.update`.

## 2026-07-04 - Mejora UX de formulario de listas de precio

### Implementado

- Se cambió el botón superior de `+ Nueva lista` a `+ Preparar nueva` para evitar confundirlo con la acción de guardar.
- El botón principal del formulario ahora cambia entre `Crear lista` y `Guardar cambios`.
- El subtítulo del formulario explica la acción esperada según el modo actual.
- Después de crear o editar una lista, la pantalla recarga y selecciona automáticamente la lista guardada.
- Se corrigió que la recarga posterior al guardado no ejecutaba porque `IsBusy` seguía activo.
- Se mantiene `Cancelar` para limpiar el formulario y volver al modo nueva lista.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- El cambio es visual y conserva las mismas APIs protegidas del backend.

## 2026-07-04 - Migración local de listas de precio y menú lateral con scroll

### Implementado

- Se aplicaron las migraciones pendientes en la base local `inventory_arens`.
- Quedaron creadas las tablas `price_lists`, `product_prices` y `product_audits` en la base usada por la app WPF.
- Se corrigió el origen del error visual `relation "price_lists" does not exist`.
- Se ajustó el menú lateral WPF para usar `ScrollViewer`.
- La cabecera del negocio queda fija y las opciones de módulos ahora pueden crecer con scroll vertical.

### Pruebas

- Se ejecutó `docker compose exec app php artisan migrate --force`.
- Se ejecutó `docker compose exec app php artisan migrate:status`.
- Resultado: `2026_07_04_141000_create_price_lists_and_product_prices_tables` aparece como ejecutada.
- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.

### Notas de seguridad

- El cambio de menú es solo visual.
- La base local quedó alineada con las migraciones versionadas del backend.

## 2026-07-04 - Listas de precio en WPF y asignación por producto

### Implementado

- Se agregó soporte `PUT` y `DELETE` al cliente API de escritorio.
- Se agregaron DTOs WPF para listas de precio y precios de producto por lista.
- Se agregó el botón lateral `Listas de precio` en el shell principal.
- Se creó la pantalla WPF `PriceListsView` para listar, crear, editar y desactivar listas de precio.
- La pantalla permite editar nombre, código, descripción, orden, estado activo y si la lista es predeterminada.
- Se agregó la pestaña `Precios` en la ventana de detalle del producto.
- La pestaña `Precios` carga listas activas, precios existentes del producto y tipos de tasa activos.
- La pestaña permite guardar precios por lista con moneda `USD` o `VES`, tasa opcional y estado activo.
- Se actualizó el README del módulo de Centro de Inventario.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 16 pruebas pasaron, 89 assertions.

### Notas de seguridad

- La app WPF no toca PostgreSQL directamente; todas las operaciones pasan por Laravel.
- La creación, edición, desactivación y asignación de precios conserva permisos, tenant y validaciones del backend.

## 2026-07-04 - Listas de precio por producto para POS

### Implementado

- Se creó la tabla `price_lists` para manejar listas de precio por empresa, por ejemplo `MAYOR`, `DETAL` o `TECNICO`.
- Se creó la tabla `product_prices` para asignar precios específicos de un producto en cada lista.
- Se agregó soporte para precio en `USD` o `VES` por lista.
- Se agregó `exchange_rate_type_id` opcional por precio de lista para permitir tasas distintas por producto/lista.
- Se agregaron endpoints `GET/POST/PATCH/DELETE /api/price-lists`.
- Se agregaron endpoints `GET /api/products/{product}/prices` y `PUT /api/products/{product}/prices`.
- `GET /api/products/{product}/price` ahora acepta `price_list_id`.
- Si no se envía `price_list_id`, el servicio usa la lista predeterminada si el producto tiene precio activo en esa lista; si no, mantiene el `base_price` del producto.
- El servicio de ventas ahora acepta `items.*.price_list_id` y copia `price_list_id` y `price_list_name` en `sale_items`.
- La respuesta de ventas expone la lista de precio usada por cada item.
- Se actualizó `docs/API.md` con los contratos de listas de precio y precios por producto.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/Sales/SalesApiTest.php`.
- Resultado: 25 pruebas pasaron, 141 assertions.

### Notas de seguridad

- Las listas y precios quedan aislados por tenant.
- No se permite asignar listas, tasas ni productos de otra empresa.
- El POS futuro debe copiar `price_list_id`, nombre de lista, moneda, precio, tasa y valor de tasa al momento de vender para mantener historia.

## 2026-07-04 - Edición completa de productos en Centro de Inventario WPF

### Implementado

- Se habilitó la acción `Editar` dentro de la ventana de detalle del producto.
- La edición reutiliza la ventana única de producto conectada a `PATCH /api/products/{product}`.
- Al guardar desde el detalle, se recarga la información comercial del producto y se notifica al Centro de Inventario para refrescar métricas/listado.
- La pestaña `Auditoría` queda marcada como pendiente de recarga después de editar para evitar mostrar datos viejos.
- El cliente WPF ahora lee los errores de validación de Laravel desde `errors` y los muestra en español.
- Se corrigió el mensaje local cuando la API responde sin datos válidos.
- Se normalizaron los mensajes de validación de edición de producto en backend para precio numérico y política de garantía.
- Se actualizó la documentación del módulo WPF y la sección de productos en `docs/API.md`.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 14 pruebas pasaron, 72 assertions.

### Notas de seguridad

- La edición sigue pasando únicamente por Laravel, con autenticación, tenant, policy `products.update`, SKU único por empresa, tasa/garantía del tenant actual y auditoría de cambios.

## 2026-07-04 - Auditoría paginada en Centro de Inventario

### Implementado

- Se agregó `GET /api/inventory-center/products/{product}/audits` para consultar auditoría paginada de producto.
- La API permite filtrar por acción `created`, `updated` y `deactivated`.
- La API permite buscar por nombre o correo del usuario que hizo el cambio.
- Si la tabla `product_audits` no existe, la API responde lista vacía para no romper el detalle del producto.
- Se conectó la pestaña `Auditoría` de WPF al nuevo endpoint.
- La pestaña `Auditoría` ahora tiene búsqueda, filtro por acción, botón `Limpiar` y paginación.
- Se muestra fecha, acción, usuario, correo y resumen JSON de cambios.
- Se actualizó `docs/API.md` y el README del módulo.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 13 pruebas pasaron, 100 assertions.

### Notas de seguridad

- La auditoría se consulta solo mediante API protegida con autenticación, tenant y policy de producto.
- La búsqueda de auditoría no permite ver usuarios ni cambios de productos de otra empresa.

## 2026-07-04 - Filtros avanzados en detalle WPF de inventario

### Implementado

- Se agregó filtro por almacén en la pestaña `Seriales / IMEI`.
- Se agregó filtro por almacén en la pestaña `Movimientos`.
- Se agregaron botones `Limpiar` para reiniciar búsqueda, estado, tipo, fechas y almacén.
- Se desactivan los botones `Anterior` y `Siguiente` cuando no existe página disponible.
- Se agregó validación local de fechas en formato `yyyy-mm-dd` antes de consultar movimientos.
- Los filtros de almacén se reconstruyen cuando el detalle se refresca después de una entrada o salida.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 11 pruebas pasaron, 86 assertions.

### Notas de seguridad

- Los filtros visuales solo agregan parámetros a las APIs protegidas; el backend conserva la validación de tenant, permisos y producto.

## 2026-07-04 - Detalle de producto WPF por pestañas

### Implementado

- Se rediseñó la ventana WPF de detalle de producto para separar la información por pestañas.
- Se agregaron pestañas `Resumen`, `Stock`, `Seriales / IMEI`, `Movimientos` y `Auditoría`.
- La pestaña `Seriales / IMEI` ahora consume `GET /api/inventory-center/products/{product}/serials` con búsqueda, filtro de estado y paginación.
- La pestaña `Movimientos` ahora consume `GET /api/inventory-center/products/{product}/movements` con búsqueda, filtro de tipo, rango de fechas y paginación.
- Se agregaron mensajes de carga y error en español por pestaña.
- Se mantienen visibles las acciones `Registrar entrada`, `Registrar salida` y `Ver Kardex`.
- Al registrar una entrada o salida, el detalle sigue recargando el producto y reinicia las pestañas paginadas para evitar datos viejos.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 11 pruebas pasaron, 86 assertions.

### Notas de seguridad

- La ventana WPF solo consume APIs protegidas del backend; no accede directo a PostgreSQL.
- Las pestañas paginadas respetan autenticación, tenant y permisos definidos en Laravel.

## 2026-07-04 - Endpoints paginados para detalle del Centro de Inventario

### Implementado

- Se agregaron endpoints backend dedicados para detalle escalable de producto en `InventoryCenter`.
- Se agregó `GET /api/inventory-center/products/{product}/serials` con búsqueda, filtro por estado, filtro por almacén y paginación.
- Se agregó `GET /api/inventory-center/products/{product}/movements` con búsqueda, filtro por tipo, filtro por almacén, rango de fechas y paginación.
- Se agregó `GET /api/inventory-center/products/{product}/stock-by-warehouse` para consultar saldos por almacén desde `stock_balances`.
- Se crearon requests de validación específicos para seriales y movimientos.
- Se reutilizó `InventoryCenterProductDetailService` para mantener la lectura del módulo centralizada y evitar lógica duplicada.
- Se actualizó el catálogo `docs/API.md` con las APIs nuevas y sus reglas.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 11 pruebas pasaron, 86 assertions.

### Notas de seguridad

- Las nuevas APIs requieren autenticación, tenant activo y permiso `products.view`.
- Cada endpoint autoriza la policy del producto antes de responder.
- Las consultas siguen usando modelos tenant-scoped para impedir mezcla de seriales, movimientos o saldos entre empresas.

## 2026-07-04 - Refresco automático del Centro de Inventario WPF

### Implementado

- Se agregó aviso de guardado en las ventanas WPF de `Registrar entrada` y `Registrar salida`.
- Cuando una entrada o salida se guarda correctamente, la ventana de detalle del producto se recarga desde `GET /api/inventory-center/products/{product}`.
- El Centro de Inventario se actualiza automáticamente para refrescar métricas, disponibilidad, estados de stock y listado.
- Si el movimiento se guarda pero falla la recarga del detalle, la aplicación muestra un mensaje visible en español sin cerrar la ventana.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`.
- Resultado: 13 pruebas pasaron, 69 assertions.

### Notas de seguridad

- El refresco automático solo vuelve a consultar la API; no accede directo a PostgreSQL ni omite reglas de permisos, tenant o stock del backend.

## 2026-07-04 - Mejora de selección IMEI en salidas WPF

### Implementado

- Se mejoró la ventana `Registrar salida` para productos serializados en la aplicación WPF.
- Se agregó búsqueda de IMEI/serial por número, almacén o estado.
- Se agregó contador visible de seriales seleccionados contra la cantidad requerida.
- Se agregó botón para limpiar la selección de seriales.
- Se agregó botón para usar la cantidad de seriales seleccionados como cantidad de salida.
- Se conservó el bloqueo antes de guardar cuando la cantidad no coincide con los IMEI/seriales seleccionados.
- Se mantuvieron mensajes visibles en español dentro de la ventana.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductExits/ProductExitApiTest.php`.
- Resultado: 7 pruebas pasaron, 33 assertions.

### Notas de seguridad

- La selección visual no reemplaza las reglas del backend: `POST /api/product-exits` sigue validando tenant, stock disponible y unidades serializadas antes de registrar la salida.

## 2026-07-04 - Entradas y salidas desde WPF

### Implementado

- Se agregó la acción `Registrar entrada` desde la ventana de detalle del producto.
- Se creó una ventana WPF de entrada conectada a `POST /api/product-entries`.
- La entrada permite elegir almacén, cantidad, costo unitario, motivo, referencia, notas e IMEI/seriales uno por línea para productos serializados.
- Se agregó la acción `Registrar salida` desde la ventana de detalle del producto.
- Se creó una ventana WPF de salida conectada a `POST /api/product-exits`.
- La salida permite elegir almacén, cantidad, motivo, referencia, notas y seleccionar IMEI/seriales disponibles en productos serializados.
- Las ventanas cargan almacenes desde `GET /api/warehouses` y conservan fallback desde el detalle cuando la API no puede listar almacenes.
- Se agregaron validaciones en español para cantidad, almacén, motivo y coincidencia exacta de IMEI/seriales en productos serializados.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`.
- Resultado: 13 pruebas pasaron, 69 aserciones.

### Notas de seguridad

- La app de escritorio solo envía solicitudes al backend Laravel; PostgreSQL no se consulta directamente.
- Los permisos, tenant, stock insuficiente y seriales inválidos siguen siendo validados por Laravel.

## 2026-07-04 - Activación del botón lateral Entradas y salidas

### Implementado

- Se habilitó el botón lateral `Entradas y salidas`, que estaba desactivado en el shell principal.
- Se agregó una pantalla operativa WPF para buscar productos y abrir acciones rápidas de entrada o salida.
- La pantalla reutiliza el cliente API autenticado y carga datos reales desde `GET /api/inventory-center/summary`.
- Las acciones rápidas cargan el detalle del producto y abren las ventanas ya conectadas a `POST /api/product-entries` y `POST /api/product-exits`.
- Se agregaron mensajes visibles si alguna ventana no puede abrirse, para evitar fallos silenciosos.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`.
- Resultado: 21 pruebas pasaron, 129 aserciones.

### Notas de seguridad

- La pantalla no escribe directo en base de datos; delega las reglas de negocio, permisos y tenant al backend Laravel.

## 2026-07-04 - Recepción avanzada de IMEI en WPF

### Implementado

- Se rediseñó la sección de IMEI/seriales en la ventana `Registrar entrada`.
- Se agregó contador visual de IMEI/seriales válidos detectados.
- Se agregó vista previa en tabla con número de línea, serial y estado.
- Se validan líneas vacías, duplicados, seriales demasiado cortos y diferencia entre cantidad e IMEI detectados.
- Se agregó botón `Usar conteo` para colocar automáticamente la cantidad según los IMEI válidos.
- Se agregó botón `Limpiar duplicados` para dejar una sola ocurrencia de cada serial.
- Se bloquea el guardado localmente si la recepción serializada no está consistente.
- Se corrigieron textos visibles en español dentro de la ventana de entrada.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php`.
- Resultado: 6 pruebas pasaron, 36 aserciones.

### Notas de seguridad

- La validación WPF reduce errores humanos, pero Laravel sigue siendo la autoridad final para duplicados, permisos, tenant y cantidad exacta.

## 2026-07-04 - Tolerancia a auditoría faltante al guardar productos

### Implementado

- Se corrigió el error al crear productos cuando la tabla `product_audits` no existe en la base real.
- `ProductController::recordAudit()` ahora verifica `Schema::hasTable('product_audits')` antes de escribir auditoría.
- Si la tabla falta, el producto se crea/edita/desactiva normalmente y se omite solo la auditoría.
- Se agregó prueba específica que elimina `product_audits` y crea un producto por API.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 14 pruebas pasaron, 72 aserciones.

### Notas de seguridad

- Esto evita caída operativa en bases locales desactualizadas.
- La auditoría seguirá guardándose automáticamente en bases que sí tengan la migración aplicada.

## 2026-07-04 - Crear y editar productos desde WPF

### Implementado

- Se habilitó el botón `+ Nuevo producto` en el Centro de Inventario.
- Se agregó acción `Editar` por producto en el listado principal.
- Se creó una ventana única de creación/edición de productos en WPF.
- La ventana consume `POST /api/products` para crear productos.
- La ventana consume `PATCH /api/products/{product}` para editar productos.
- Se agregó soporte `PATCH` al cliente API de escritorio.
- El formulario permite configurar nombre, SKU, tipo de control, precio base, moneda de venta, tipo de tasa, política de garantía y estado activo.
- Se cargan tipos de tasa desde `GET /api/currency/rate-types`.
- Se cargan políticas de garantía desde `GET /api/warranty-policies`.
- Si el backend indica que el tipo de control no puede cambiarse, el formulario bloquea ese campo.
- Al guardar correctamente, el listado del Centro de Inventario se refresca automáticamente.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/Currency/CurrencyApiTest.php tests/Feature/Warranties/WarrantyPolicyApiTest.php`.
- Resultado: 35 pruebas pasaron, 197 aserciones.

### Notas de seguridad

- La app no escribe directo en base de datos; creación y edición pasan por Laravel.
- Laravel conserva permisos, validación de SKU por tenant, validación de tasa/garantía por tenant y auditoría de producto.

## 2026-07-04 - Corrección de recurso visual en navegación WPF

### Implementado

- Se corrigió la navegación entre `Centro de Inventario` y `Entradas y salidas`.
- Se eliminó el uso del recurso inexistente `AccentSoftBrush`.
- Se reemplazó el cambio de estado visual de los botones por colores explícitos y seguros.
- Se evita la excepción `ResourceReferenceKeyNotFoundException` y el cast inválido al alternar secciones.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 8 pruebas pasaron, 60 aserciones.

### Notas de seguridad

- El cambio es visual y de navegación; no modifica reglas de inventario ni endpoints backend.

## 2026-07-04 - Kardex por producto en WPF

### Implementado

- Se agregó la acción `Ver Kardex` en la ventana de detalle del producto del Centro de Inventario.
- Se creó una ventana independiente de Kardex por producto en WPF.
- La ventana consume `GET /api/kardex/products/{product}` usando el token y tenant de la sesión activa.
- Se agregaron filtros por almacén, fecha desde y fecha hasta.
- Se muestran saldo inicial, saldo final, cantidad de movimientos y tabla cronológica con entradas, salidas, saldo y motivo.
- Se agregaron mensajes visibles en español para errores de API, errores de conexión, timeout y filtros de fecha inválidos.
- Se mantuvo la regla de que la app de escritorio no consulta PostgreSQL directamente.

### Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Kardex/KardexApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 12 pruebas pasaron, 85 aserciones.

### Notas de seguridad

- El Kardex sigue validando permisos y tenant desde Laravel.
- La ventana usa el cliente API autenticado existente, por lo que no duplica login ni pierde contexto de empresa.

## 2026-07-04 - Correccion de binding en ventana de detalle WPF

### Implementado

- Se corrigieron bindings `Run.Text` en `InventoryProductDetailWindow` para usar `Mode=OneWay`.
- Se evita el error WPF: `A TwoWay or OneWayToSource binding cannot work on the read-only property 'TypeLabel'`.
- Se reforzaron columnas calculadas del listado con `Mode=OneWay`.
- La ventana de detalle puede leer propiedades calculadas como `TypeLabel`, `Quantity`, `Available`, `Reserved` y `Damaged` sin intentar escribir sobre ellas.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 8 pruebas pasadas, 60 aserciones.

## 2026-07-04 - Tolerancia a auditoria faltante en detalle de inventario

### Implementado

- Se corrigió `InventoryCenterProductDetailService` para que el detalle de producto no falle si la base real aún no tiene la tabla `product_audits`.
- Cuando la tabla de auditoría no existe, el endpoint devuelve `recent_audits` vacío y mantiene el resto del detalle operativo.
- Esto permite abrir la ventana independiente de detalle aunque falte ejecutar la migración de auditoría en una base existente.
- Se agregó prueba específica para el caso de `product_audits` ausente.
- Se actualizó `desktop/InventoryDesktop/Modules/InventoryCenter/README.md`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 8 pruebas pasadas, 60 aserciones.

## 2026-07-04 - Ventana independiente de detalle en Centro de Inventario WPF

### Implementado

- Se reemplazó el detalle embebido/lateral por una ventana independiente `InventoryProductDetailWindow`.
- El listado principal conserva todo su ancho y no pierde espacio al consultar un producto.
- La ventana de detalle muestra información comercial, stock total, stock por almacén, seriales/IMEI, movimientos recientes y auditoría reciente.
- El botón `Ver` y el doble clic ahora cargan el detalle desde la API y abren la nueva ventana.
- Si falla la carga del detalle, el error queda visible en el mensaje inferior del Centro de Inventario.
- Se actualizó `desktop/InventoryDesktop/Modules/InventoryCenter/README.md`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 7 pruebas pasadas, 57 aserciones.

## 2026-07-04 - Detalle lateral de producto en Centro de Inventario WPF

### Implementado

- Se agregó detalle lateral de producto dentro del módulo WPF `InventoryCenter`.
- Se consume `GET /api/inventory-center/products/{product}` desde el cliente de escritorio.
- Se agregaron DTOs WPF para producto, tasa, garantía, stock por almacén, seriales/IMEI, movimientos y auditorías.
- Se agregó acción `Ver` por fila y apertura por doble clic sobre el producto.
- El detalle muestra información general, precio, tasa, garantía, stock total, stock por almacén, seriales/IMEI, movimientos recientes y auditoría reciente.
- El panel lateral puede cerrarse y no ocupa espacio cuando está cerrado.
- Se agregaron mensajes de carga/error propios del detalle, separados del listado principal.
- Se actualizó `desktop/InventoryDesktop/Modules/InventoryCenter/README.md`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 7 pruebas pasadas, 57 aserciones.

## 2026-07-04 - Compactacion visual del Centro de Inventario WPF

### Implementado

- Se compactó la pantalla del Centro de Inventario para priorizar el espacio útil del listado.
- Se eliminaron las tarjetas grandes de métricas como franja independiente.
- Las métricas ahora se muestran como chips pequeños dentro de la cabecera.
- Se redujo el alto de cabecera, filtros, botones y filas de tabla para mostrar más productos visibles.
- Se conservan estados de carga, estado vacío, mensajes en español y textos con acentos.
- Se documentó el ajuste en `desktop/InventoryDesktop/Modules/InventoryCenter/README.md`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 7 pruebas pasadas, 57 aserciones.

## 2026-07-04 - Mejora visual y UX del Centro de Inventario WPF

### Implementado

- Se rediseño `InventoryCenterView` con cabecera operativa, filtros agrupados y tabla más legible.
- Se agregaron métricas con colores por intención: disponible, reservado, dañado, stock bajo y sin stock.
- Se agregó estado de carga visible sobre el listado mientras se consulta la API.
- Se agregó estado vacío con mensajes en español cuando no hay productos o cuando ocurre un error.
- Se diferenciaron los mensajes de error con color propio mediante `StatusBrush`.
- Se corrigieron textos visibles con acentos en el Centro de Inventario y el shell lateral.
- Se ajustó la tipografía base a `Segoe UI Variable Text` con respaldo `Segoe UI`.
- Se actualizó `desktop/InventoryDesktop/Modules/InventoryCenter/README.md` en español con el nuevo estándar visual y de mensajes.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 7 pruebas pasadas, 57 aserciones.

## 2026-07-04 - Correccion de reconfiguracion HTTP en cliente WPF

### Implementado

- Se corrigio `ApiClient` para no modificar `HttpClient.BaseAddress` ni `DefaultRequestHeaders` despues de haber enviado solicitudes.
- `ApiClient` ahora guarda la URL base, token Bearer y empresa activa como estado propio y los aplica por cada solicitud HTTP.
- Se evita el error de .NET: `This instance has already started one or more requests. Properties can only be modified before sending the first request`.
- Se cambio el cliente WPF a salida de consola (`OutputType=Exe`) para que `dotnet run` mantenga una consola visible.
- `AppLogger` ahora escribe tanto en `%LOCALAPPDATA%\SistemaInventario\desktop.log` como en la consola.
- Se cerro el proceso WPF anterior que mantenia bloqueado `InventoryDesktop.exe` durante la compilacion.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Diagnostico robusto del cierre WPF despues del login

### Implementado

- Se agrego `AppLogger` para registrar el flujo WPF en `%LOCALAPPDATA%\SistemaInventario\desktop.log`.
- Se registran eventos de inicio de aplicacion, creacion del login, login exitoso, apertura de `ShellWindow`, carga del panel y errores no controlados.
- Se agregaron manejadores globales para excepciones de Dispatcher, dominio de aplicacion y tareas no observadas.
- `ShellWindow` ahora se crea primero con una vista visible de carga antes de construir `ShellView`.
- Si falla la carga de `ShellView`, la ventana del panel queda abierta mostrando el error y la ruta del log.
- Se elimino la relacion `Owner` entre login y panel para evitar cierres visuales dependientes de la ventana duena.
- Se dejo `ShutdownMode` en `OnExplicitShutdown`; el proceso solo se apaga al cerrar `MainWindow`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Apertura visible del panel WPF sin cerrar login

### Implementado

- Se reintrodujo `ShellWindow` como ventana host del panel principal para diagnosticar el flujo visual despues del login.
- `MainWindow` permanece abierta con el login despues de autenticar.
- Al iniciar sesion correctamente se crea y muestra `ShellWindow`, que carga `ShellView` y el Centro de Inventario.
- Si el panel ya esta abierto, el login muestra un mensaje y activa la ventana existente.
- Se cambio `ShutdownMode` a `OnMainWindowClose` para evitar procesos WPF vivos despues de cerrar el login.
- Se actualizo `desktop/InventoryDesktop/README.md` con el flujo actual de login abierto mas panel en ventana separada.

### Pruebas

- Se ejecuto `dotnet clean` para eliminar el binario WPF anterior y confirmar que no se use una compilacion vieja.
- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente despues de restaurar: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Migracion WPF a ventana unica

### Implementado

- Se convirtio `MainWindow` en el contenedor unico de la aplicacion WPF.
- Se separo el login en `LoginView`, reutilizando el flujo de autenticacion existente.
- Se convirtio el contenido del antiguo `ShellWindow` en `ShellView`.
- Al iniciar sesion correctamente ya no se abre una segunda ventana, no se oculta el login y no se cierra `MainWindow`.
- La misma ventana reemplaza su contenido interno de `LoginView` a `ShellView`.
- Se eliminaron `ShellWindow.xaml` y `ShellWindow.xaml.cs` para evitar ciclos de vida duplicados.
- El panel principal conserva el Centro de Inventario conectado a datos reales.
- Se actualizo `desktop/InventoryDesktop/README.md` para documentar `MainWindow`, `LoginView` y `ShellView`.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php` contra PostgreSQL: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Auditoria del flujo login WPF a shell

### Implementado

- Se elimino el boton secundario `Buscar empresas` para dejar un solo flujo de ingreso.
- El boton `Ingresar` queda encargado de buscar empresas cuando sea necesario y de iniciar sesion cuando ya exista empresa seleccionada.
- Se dejo de cerrar la ventana de login inmediatamente despues de autenticar; ahora se oculta y permanece viva mientras se abre el panel principal.
- Se agrego manejo de error visible si `ShellWindow` no puede abrirse, evitando cierres silenciosos.
- Se mantiene el apagado explicito de la aplicacion solo al cerrar el panel principal.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Cierre explicito de la aplicacion WPF

### Implementado

- Se configuro WPF con `ShutdownMode="OnExplicitShutdown"` para evitar que la app se cierre al cerrar el login.
- Despues de autenticar, el login se oculta, se abre `ShellWindow`, se registra como ventana principal y la aplicacion solo se apaga cuando el shell se cierra.
- Esto corrige el caso donde el login se cerraba despues de iniciar sesion y no quedaba ninguna ventana visible.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Correccion de apertura del panel WPF despues del login

### Implementado

- Se corrigio el flujo posterior al login WPF para registrar `ShellWindow` como ventana principal antes de cerrar el login.
- Esto evita que la aplicacion se cierre al autenticar cuando `MainWindow` deja de existir.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 15 pruebas pasadas, 94 aserciones.

## 2026-07-03 - Ajuste profesional del login WPF

### Implementado

- Se rediseño el login WPF para eliminar referencias tecnicas visibles como `Laravel`, `WPF`, `API` y `BD`.
- Se reemplazo el bloque lateral por mensajes de producto orientados a inventario, ventas, caja, reportes, permisos y empresa.
- Se oculto la URL del servidor dentro de `Configuracion de conexion` para que no sea protagonista del login.
- Se corrigio la jerarquia de botones: `Ingresar` queda como accion principal visible y `Buscar empresas` como accion secundaria.
- Se mejoro el flujo de ingreso: si el usuario presiona `Ingresar` sin empresa seleccionada, la app busca empresas primero y entra automaticamente cuando solo hay una disponible.
- Se mantuvo la seleccion manual de empresa cuando el usuario pertenece a varias empresas.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 7 pruebas pasadas, 57 aserciones.

## 2026-07-03 - Shell WPF y Centro de Inventario solo lectura

### Implementado

- Se agrego `ShellWindow` como pantalla principal posterior al login.
- El login WPF ahora abre el shell con la sesion autenticada y mantiene el `ApiClient` configurado con token Bearer y `X-Tenant`.
- Se creo el modulo WPF `InventoryCenter` con DTOs, ViewModel y vista propia.
- El Centro de Inventario consume `GET /api/inventory-center/summary` usando datos reales del backend Laravel.
- Se muestran metricas principales: productos, disponible, reservado, danado, stock bajo y sin stock.
- Se agrego listado de productos con SKU, tipo de control, precio, cantidades y estado.
- Se agregaron filtros iniciales por busqueda, tipo de control y estado de stock.
- Se agrego paginacion basica con botones `Anterior` y `Siguiente`.
- Se dejo preparado el shell visual con sidebar modular para los siguientes modulos.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 7 pruebas pasadas, 57 aserciones.

## 2026-07-03 - Base inicial del cliente de escritorio WPF

### Implementado

- Se creo la base de la aplicacion de escritorio en `desktop/InventoryDesktop`.
- Se definio WPF como cliente principal de escritorio para consumir el backend Laravel.
- Se agrego una solucion `.slnx` para abrir el proyecto desde Visual Studio.
- Se creo la pantalla inicial de login con URL de API, correo, contrasena, seleccion de empresa e ingreso.
- Se agrego `ApiClient` para centralizar llamadas HTTP, token Bearer y header `X-Tenant`.
- Se agrego `TokenVault` para guardar el token protegido por usuario de Windows.
- Se agregaron DTOs de autenticacion ajustados al contrato real de Laravel, donde las respuestas vienen envueltas en `data`.
- Se creo la estructura modular inicial para `Auth` y `InventoryCenter`.
- Se actualizo `.gitignore` para excluir `bin/` y `obj/` de proyectos .NET.
- Se documento la arquitectura WPF + Laravel API + PostgreSQL.

### Pruebas

- Se compilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se recompilo `desktop/InventoryDesktop/InventoryDesktop.csproj` con .NET correctamente: 0 errores, 0 advertencias.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.

## 2026-07-03 - Migración del rediseño de entradas y salidas a Tailwind CSS

### Implementado

- Se confirmó que Tailwind CSS 4 ya estaba instalado y conectado con Vite mediante `@tailwindcss/vite`.
- Se migró el bloque visual del módulo `Entradas y salidas` a componentes Tailwind usando `@apply`.
- Se agregaron utilidades Tailwind explícitas en la vista Blade para los contenedores principales del módulo.
- Se conservaron las clases funcionales usadas por JavaScript, como `operation-tab`, `operation-form`, `operation-card` y `operation-layout`.
- Se mantuvo el buscador de producto como primer paso visible en entradas y salidas.
- Se dejó el CSS manual solo para detalles puntuales que Tailwind no cubre directamente en este flujo, como el icono visual del buscador y la animación de panel.

### Pruebas

- Se compiló el frontend con `pnpm run build` correctamente.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 13 pruebas pasadas, 69 aserciones.

## 2026-07-03 - Rediseño visual de entradas y salidas

### Implementado

- Se rediseñó el módulo `Entradas y salidas` tomando como referencia las capturas de estilo revisadas.
- Se reorganizaron los formularios para que la selección del producto sea el primer paso visible en entradas y salidas.
- Se mejoraron las pestañas `Entrada`, `Salida` e `Historial` con un diseño segmentado, más claro y con transición visual.
- Se agregó un panel de trabajo más compacto con tarjetas blancas, bordes suaves, énfasis violeta y sombras ligeras.
- Se mejoró el foco visual del buscador de productos para operaciones con muchos productos.
- Se dejó el resumen de operación como panel lateral en escritorio y como bloque apilado en pantallas medianas o pequeñas.
- Se agregaron acciones fijas al pie del formulario para que `Limpiar` y `Registrar` estén disponibles sin depender del scroll.
- No se instaló una librería nueva porque el rediseño se resolvió con CSS propio, evitando peso adicional y manteniendo el frontend simple.

### Pruebas

- Se compiló el frontend con `pnpm run build` correctamente.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 13 pruebas pasadas, 69 aserciones.

## 2026-07-03 - Cierre de fase del centro de inventario

### Implementado

- Se agregó paginación visible al historial de `Entradas y salidas`, separada para entradas y salidas.
- Cada columna del historial muestra resumen de registros y botones `Anterior` / `Siguiente`.
- Los filtros del historial reinician la paginación para evitar resultados vacíos al cambiar búsqueda, almacén o fechas.
- Se creó la tabla `product_audits` para registrar auditoría de productos por empresa.
- Se registran auditorías al crear, actualizar y desactivar productos.
- El detalle del producto en el centro de inventario ahora incluye una pestaña `Auditoría` con cambios recientes del catálogo.
- Se agregaron mensajes de validación en español para creación y edición de productos.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 33 pruebas pasadas, 194 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Filtros del historial de entradas y salidas

### Implementado

- Se agregaron filtros al historial del módulo `Entradas y salidas`.
- La API `GET /api/product-entries` ahora acepta `search`, `warehouse_id`, `date_from`, `date_to` y `limit`.
- La API `GET /api/product-exits` ahora acepta `search`, `warehouse_id`, `date_from`, `date_to` y `limit`.
- La búsqueda cubre documento, motivo, referencia, notas, producto, SKU e IMEI/serial.
- En salidas serializadas se puede buscar por IMEI real resolviendo las unidades relacionadas.
- El frontend ahora muestra filtros compactos por búsqueda, almacén y rango de fechas, con acciones para aplicar o limpiar.
- El historial se recarga desde la API con filtros activos para evitar depender de listas grandes en el navegador.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 13 pruebas pasadas, 69 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Detalle completo de movimientos de inventario

### Implementado

- Se mejoró el detalle del historial dentro del módulo `Entradas y salidas`.
- El detalle ahora muestra documento, fecha, estado, motivo, referencia, responsable, cantidad total, cantidad de items, cantidad de IMEIs/seriales y notas internas.
- Cada producto del movimiento muestra SKU, tipo de control, almacén, cantidad, costo unitario cuando aplica, subtotal estimado e IMEIs asociados.
- La API de entradas y salidas ahora incluye el usuario creador cargado como `created_by_user`.
- La API de salidas serializadas ahora devuelve los IMEIs reales en `items.*.serial_units` y evita consultas repetidas cargándolos en lote.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 12 pruebas pasadas, 62 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Asistente visual para recepción masiva de IMEIs

### Implementado

- Se mejoró la carga masiva de IMEIs en el formulario de `Entrada`.
- Se agregó contador visual de IMEIs escritos, estado de lista y vista previa en chips.
- Se detectan duplicados dentro del pegado y se muestran las líneas afectadas antes de enviar.
- Se marcan líneas con formato sospechoso, como espacios internos, símbolos no permitidos o IMEIs numéricos fuera de 14 a 18 dígitos.
- Se agregó acción `Limpiar lista` para normalizar la carga y quitar repetidos manteniendo el primer valor.
- La validación del frontend bloquea el registro si hay duplicados o líneas por revisar, mientras el backend sigue siendo la autoridad final.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php`: 5 pruebas pasadas, 27 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Pestañas visuales para entradas y salidas

### Implementado

- Se reorganizó el módulo `Entradas y salidas` en pestañas: `Entrada`, `Salida` e `Historial`.
- Cada flujo muestra solo su formulario y resumen, evitando una pantalla larga que obligaba a bajar para encontrar opciones.
- Las pestañas mantienen estado visual activo, accesibilidad básica con `role="tab"` y navegación por teclado con flechas, inicio y fin.
- Se ajustó el diseño responsive para que las pestañas funcionen en escritorio y móvil sin romper el formulario.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 12 pruebas pasadas, 55 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Formularios compactos y buscador de productos en operaciones

### Implementado

- Se reemplazaron los selectores largos de producto en entradas y salidas por buscadores por nombre o SKU.
- La API `GET /api/products` ahora acepta `search` y `limit` para no depender de listas gigantes cuando existan cientos o miles de productos.
- La búsqueda de productos es insensible a mayúsculas/minúsculas en PostgreSQL.
- Se compactaron los formularios de entradas y salidas con campos más bajos, grillas más densas y resultados con scroll interno.
- Se evitó que presionar Enter dentro del buscador envíe el formulario accidentalmente.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 25 pruebas pasadas, 120 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Historial operativo de entradas y salidas

### Implementado

- Se agregó una sección de `Actividad reciente` dentro del módulo `Entradas y salidas`.
- El historial muestra entradas recientes y salidas recientes con documento, motivo, referencia, fecha, cantidad e items.
- Se agregó detalle rápido para revisar productos, almacenes, cantidades, costos y seriales/IMEIs asociados.
- Después de registrar una entrada o salida, el historial se refresca automáticamente.
- Los endpoints de entrada y salida ahora cargan items con producto y almacén para evitar consultas extra desde el frontend.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/ProductExits/ProductExitApiTest.php`: 12 pruebas pasadas, 55 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Frontend inicial para salida de productos

### Implementado

- Se agregó el formulario de `Salida` dentro del módulo `Entradas y salidas`.
- La salida permite seleccionar almacén origen, producto, motivo, referencia, cantidad y notas.
- Para productos serializados se cargan los IMEIs disponibles del producto en el almacén seleccionado.
- La cantidad de salida serializada se calcula según los IMEIs seleccionados.
- Se conecta con `POST /api/product-exits` para registrar salidas trazables.
- Al registrar una salida se actualiza el Centro de Inventario para reflejar el nuevo stock.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductExits/ProductExitApiTest.php`: 7 pruebas pasadas, 26 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Acceso trazable para recibir stock desde Centro de Inventario

### Implementado

- Se agregó el botón `Recibir stock` en tarjetas y lista del Centro de Inventario.
- El botón no modifica cantidades directamente; abre el módulo `Entradas y salidas` con el producto seleccionado.
- Se mantiene la regla operativa: el catálogo permite consultar y editar datos del producto, pero el stock se mueve mediante entradas/salidas trazables.
- Se optimizó la carga de opciones del formulario de entrada para evitar llamadas duplicadas al cambiar de módulo.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php`: 5 pruebas pasadas, 25 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Frontend inicial para entrada de productos

### Implementado

- Se agregó el panel `Entradas y salidas` como módulo independiente dentro del frontend.
- Se implementó el formulario de recepción de productos conectado a `POST /api/product-entries`.
- El formulario carga productos y almacenes reales de la empresa activa.
- Para productos por cantidad permite indicar cantidad y costo unitario.
- Para productos serializados/IMEI habilita una caja de carga múltiple, un serial por línea, y calcula la cantidad automáticamente.
- Se agregó un resumen lateral con producto, almacén, unidades a recibir y tipo de control.
- Al registrar una entrada se actualiza el Centro de Inventario para reflejar el nuevo stock.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php`: 5 pruebas pasadas, 25 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Edición robusta de producto en Centro de Inventario

### Implementado

- Se reforzó la edición de productos desde el Centro de Inventario usando la API real `PATCH /api/products/{product}`.
- El backend ahora devuelve `can_change_tracking_type` y `units_count` al consultar o actualizar un producto.
- El formulario desactiva el selector de tipo de control cuando el producto ya tiene unidades/IMEIs asociados.
- Se agregó ayuda visual para explicar cuándo un producto no puede cambiar entre control por cantidad y serializado.
- Al guardar una edición, el frontend enfoca el producto editado en el listado para comprobar el cambio de inmediato.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`: 12 pruebas pasadas, 61 aserciones.
- Se compiló el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Rediseño compacto del modal de detalle de producto

### Implementado

- Se ajusto el modal de detalle para que tenga altura maxima y scroll interno, evitando que ocupe toda la pantalla.
- Se agrego una navegacion superior compacta por secciones: Resumen, Almacenes, Seriales y Movimientos.
- Se redujo el peso visual de tarjetas, tablas y estadisticas para mejorar lectura en escritorio.
- Se mejoro el texto de cabecera para explicar que el detalle permite consultar stock por almacen, seriales y movimientos recientes.

### Pruebas

- Se compilo el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Detalle de producto en Centro de Inventario

### Implementado

- Se agrego `GET /api/inventory-center/products/{product}` para consultar el detalle operativo de un producto.
- El detalle devuelve datos generales, tasa, garantia, stock total, stock por almacen, seriales/IMEIs y movimientos recientes.
- Se creo `InventoryCenterProductDetailService` para mantener separada la lectura agregada del Centro de Inventario.
- Se agrego un modal de detalle en el frontend con botones `Detalle` en tarjetas y lista.
- El modal muestra resumen de stock, datos generales, stock por almacen, seriales/IMEIs y movimientos recientes.

### Pruebas

- Se agregaron pruebas para validar stock por almacen, seriales y movimientos recientes en el detalle del producto.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php tests/Feature/Products/ProductApiTest.php`: 18 pruebas pasadas, 105 aserciones.
- Se compilo el frontend con `pnpm run build` correctamente.

### Notas tecnicas

- El detalle limita seriales a 50 registros y movimientos a 10 para evitar cargas grandes.
- La ruta usa la policy de `Product`, por lo que respeta tenant y permiso `products.view`.

## 2026-07-03 - Auditoria de producto creado no visible en Centro de Inventario

### Diagnostico

- Se verifico en PostgreSQL que el producto creado desde frontend (`PRUEBA`, SKU `pru`) existe, esta activo y pertenece a `Demo Caracas`.
- Se verifico por API real que `GET /api/inventory-center/summary?search=pru&stock_status=all` devuelve el producto con stock `0` y estado `out`.
- El problema no era de persistencia ni de tenant; el producto quedaba oculto cuando la pantalla conservaba filtros previos como `Disponibles`, tipo de control o busqueda/paginacion.

### Implementado

- Al crear un producto desde el Centro de Inventario, el frontend ahora limpia filtros de stock y tipo, vuelve a la pagina 1 y coloca el SKU creado en el buscador.
- Esto fuerza a que el listado muestre inmediatamente el producto recien creado, aunque no tenga stock inicial.
- Al editar productos existentes se conservan filtros y pagina actual.

### Pruebas

- Se agrego prueba para confirmar que el Centro de Inventario muestra productos activos nuevos sin stock con estado `out`.
- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php tests/Feature/Products/ProductApiTest.php`: 17 pruebas pasadas, 94 aserciones.
- Se compilo el frontend con `pnpm run build` correctamente.
- Se verifico la API real local buscando `pru`, devolviendo el producto `PRUEBA`.

## 2026-07-03 - Correccion de envio JSON en login con tenant

### Implementado

- Se corrigio el helper `api()` del frontend para mezclar headers personalizados sin perder `Accept` ni `Content-Type: application/json`.
- El fallo aparecia al segundo paso del login, cuando se agregaba `X-Tenant`; el backend recibia la solicitud sin cuerpo JSON parseable y devolvia `The email field is required`.

### Verificacion

- Se compilo el frontend con `pnpm run build` correctamente.
- Se verifico `POST /api/auth/login` en `http://localhost:8000` con `X-Tenant: demo-caracas`, devolviendo token real.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.

## 2026-07-03 - Login local apuntando a PostgreSQL y permisos de catalogo

### Implementado

- Se corrigio `.env` local para usar PostgreSQL (`pgsql`) en lugar de SQLite durante el desarrollo.
- Se reinicio el servicio web para que `localhost:8000` use la misma base PostgreSQL que las pruebas y seeders.
- Se agregaron permisos `products.create` y `products.update` al rol base `Gerente`.
- Se reejecuto el seeder local para aplicar permisos y datos demo.

### Verificacion

- `POST /api/auth/tenants` en `http://localhost:8000` responde correctamente para `gerente.caracas@demo.test` con clave `password`.
- `POST /api/auth/login` devuelve token real para `Demo Caracas`.
- El token del gerente devuelve `products.view`, `products.create` y `products.update`.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/Products/ProductApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 20 pruebas pasadas, 146 aserciones.

## 2026-07-03 - Limpieza de tenants demo antiguos para login

### Implementado

- Se ajusto `DemoDataSeeder` para detectar tenants demo antiguos con slug `arens-demo-caracas` y `arens-demo-valencia`.
- Esos tenants se renombran como `Demo Legado ...` y sus usuarios se marcan como `inactive` en `tenant_user`.
- Esto evita que aparezcan empresas antiguas en el selector de login y elimina referencias visibles a la marca anterior.
- Se documento la clave demo `password` en `docs/DEMO_DATA.md`.

### Pruebas

- Se verifico en la base local que `gerente.caracas@demo.test` valida con clave `password`.
- Se verifico que el login demo ahora devuelve solo `Demo Caracas`.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Seeders/DemoDataSeederTest.php tests/Feature/Auth/AuthApiTest.php`: 9 pruebas pasadas, 95 aserciones.

## 2026-07-03 - Cierre de sesion visible en el panel principal

### Implementado

- Se agrego un boton global `Cerrar sesion` en la barra superior del panel principal.
- Se reutilizo la misma accion de cierre de sesion para el boton del Resumen y para la barra superior.
- Al cerrar sesion se limpia la seleccion previa de empresa para permitir entrar con un usuario real despues de usar el modo demo local.

### Pruebas

- Se compilo el frontend con `pnpm run build` correctamente.

## 2026-07-03 - Creacion y edicion de productos desde Centro de Inventario

### Implementado

- Se agrego un formulario modal en el frontend para crear y editar productos desde el Centro de Inventario.
- El formulario permite gestionar nombre, SKU, tipo de control, precio base, moneda de venta, tipo de tasa, politica de garantia y estado activo.
- Se agregaron acciones de edicion en vista de tarjetas y vista de lista, visibles solo con permiso `products.update`.
- Se conecto el formulario con `POST /api/products` y `PATCH /api/products/{product}` usando el token y tenant de la sesion actual.
- Se ampliaron las respuestas de productos para incluir `sale_exchange_rate_type` cargado junto con `warranty_policy`, evitando consultas extra despues de guardar o abrir un producto.
- El frontend carga tipos de tasa y politicas de garantia para completar los selectores cuando el usuario tiene permisos de lectura sobre esos modulos.

### Pruebas

- Se actualizaron pruebas de productos para validar que la API devuelve la tasa asociada al crear y editar productos.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 16 pruebas pasadas, 87 aserciones.
- Se compilo el frontend con `pnpm run build` correctamente.

### Notas de uso

- El Centro de Inventario mantiene su API agregada solo para lectura; las escrituras del catalogo se hacen por el modulo `Products`.
- Si un producto ya tiene IMEIs/unidades serializadas, el backend sigue bloqueando cambios peligrosos de `tracking_type`.

## 2026-07-03 - Catalogo demo ampliado para Centro de Inventario

### Implementado

- Se amplio `DemoDataSeeder` con 10 productos adicionales por empresa.
- Se agregaron productos por cantidad, productos serializados, productos sin stock, productos con stock bajo, stock reservado y stock danado.
- Se agregaron IMEIs adicionales para telefonos serializados del catalogo demo.
- Se mantuvo el seeder idempotente para poder ejecutarlo varias veces sin duplicar datos principales.
- Se actualizo `docs/DEMO_DATA.md`.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Seeders/DemoDataSeederTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 6 pruebas pasadas, 94 aserciones.

### Notas de uso

- Estos datos sirven para probar paginacion, filtros, vista lista, tarjetas y futuros flujos de creacion/edicion de productos.

## 2026-07-03 - Centro de Inventario con lista, paginacion y filtros

### Implementado

- Se agrego paginacion real a `GET /api/inventory-center/summary` con `page`, `limit` y bloque `pagination`.
- Se mantuvo el stock agregado por producto en base de datos.
- Se agrego filtro frontend/backend por tipo de control: todos, por cantidad y serializados.
- Se agrego alternancia visual entre tarjetas y lista.
- Se agrego tabla de productos con columnas de SKU, tipo, precio, disponible, reservado, danado y estado.
- Se agregaron controles de pagina anterior/siguiente en el Centro de Inventario.

### Buenas practicas de consulta

- La API cuenta resultados filtrados en base de datos antes de paginar.
- La paginacion evita cargar catalogos completos cuando existan muchos productos.
- La vista lista usa el mismo endpoint agregado y no dispara consultas extra por producto.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se ejecuto prueba especifica en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 5 pruebas pasadas, 36 aserciones.

### Notas de seguridad

- El endpoint mantiene `api.auth`, `tenant` y permisos `products.view` o `inventory.view`.
- Las pruebas siguen validando aislamiento entre empresas.

## 2026-07-03 - Limpieza visual del frontend y textos en español

### Implementado

- Se cambio la tipografia principal del frontend a `Plus Jakarta Sans` con fallbacks seguros.
- Se corrigieron textos visibles con tildes y `ñ` en login, shell principal y Centro de Inventario.
- Se reemplazaron simbolos dañados de la barra superior por SVGs inline.
- Se reemplazo la navegacion lateral para usar claves de icono y SVGs internos, evitando caracteres corruptos.
- Se corrigieron textos dinamicos del frontend: ordenes POS, busqueda, danado, almacen demo y accesos rapidos.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta y sin warnings.
- Se ejecuto prueba especifica en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 4 pruebas pasadas, 23 aserciones.

### Notas de seguridad

- El cambio no modifica reglas de permisos ni endpoints.
- Los textos de productos renderizados desde API siguen escapandose antes de insertarse en HTML.

## 2026-07-03 - Centro de Inventario conectado a base de datos

### Implementado

- Se agrego el modulo `InventoryCenter`.
- Se expuso `GET /api/inventory-center/summary`.
- Se agrego `InventoryCenterSummaryService` para metricas y productos con stock agregado.
- Se conecto el frontend del Centro de Inventario al endpoint real cuando la sesion tiene token valido.
- Se agregaron metricas de productos, serializados, disponible, reservado, danado, bajo stock y sin stock.
- Se agregaron buscador y filtros rapidos por estado de stock.
- El modo demo local conserva datos simulados para revisar la pantalla sin credenciales.
- Se documento API, arquitectura, mapa modular y bitacora.

### Buenas practicas de consulta

- El frontend consume una API agregada en lugar de disparar muchas consultas pequenas.
- El stock se suma en base de datos desde `stock_balances` agrupado por producto.
- El listado queda limitado a 50 productos maximo por llamada.
- La API no carga almacenes producto por producto, reduciendo riesgo de N+1.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se ejecuto prueba especifica en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`: 4 pruebas pasadas, 23 aserciones.

### Notas de seguridad

- El endpoint requiere `api.auth` y `tenant`.
- La autorizacion acepta `products.view` o `inventory.view`.
- Las pruebas cubren varias empresas para confirmar que no se mezclan productos ni saldos.

## 2026-07-03 - Dashboard real con API agregada

### Implementado

- Se agrego el modulo `Dashboard`.
- Se expuso `GET /api/dashboard/summary`.
- Se agrego `DashboardSummaryService` con consultas agregadas para ventas, POS, caja, stock bajo y finanzas.
- Se conecto el frontend del panel principal a la API real cuando la sesion tiene token valido.
- El modo demo local conserva datos simulados para revisar el frontend sin credenciales.
- Se agrego estado de carga/error en el panel de atencion.
- Se documento API, arquitectura, mapa modular y bitacora.

### Buenas practicas de consulta

- La portada usa una API agregada para evitar multiples llamadas pequeñas desde frontend.
- Las metricas usan `sum` y `count` en base de datos.
- La lista de stock bajo usa `limit(5)` y carga solo relaciones necesarias.
- No se cargan colecciones completas para calcular tarjetas.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Dashboard/DashboardSummaryApiTest.php`: 3 pruebas pasadas, 16 aserciones.

### Notas de seguridad

- El dashboard requiere `api.auth` y `tenant`.
- La autorizacion acepta permisos de lectura operativos existentes.
- El frontend sigue siendo cliente; las APIs protegidas siguen validando permisos reales.

## 2026-07-03 - Acceso demo local para revisar frontend

### Implementado

- Se agrego `FRONTEND_DEV_BYPASS_LOGIN` para habilitar un acceso directo solo en ambiente local.
- Se agrego el boton `Entrar en modo demo local` cuando `APP_ENV=local` y `FRONTEND_DEV_BYPASS_LOGIN=true`.
- El modo demo local crea una sesion frontend con empresa, usuario, rol y permisos amplios para revisar el shell visual.
- El valor por defecto en `.env.example` queda desactivado para evitar habilitarlo por accidente.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se reinicio la app local para cargar `FRONTEND_DEV_BYPASS_LOGIN=true`.
- Se verifico `http://localhost:8000`: el HTML incluye `Entrar en modo demo local`, `data-dev-bypass-login="true"` y `Sistema de Inventario`.

### Notas de seguridad

- Este bypass no llama APIs protegidas ni reemplaza seguridad real.
- Solo debe usarse para revisar pantallas frontend locales.
- En produccion debe permanecer desactivado.

## 2026-07-03 - Base del panel principal con navegación por permisos

### Implementado

- Se agrego el shell principal del sistema despues del login.
- Se agrego barra lateral con modulos agrupados por Operacion, Inventario, Finanzas y Administracion.
- Se agrego barra superior con tasa referencial, estado de caja, acceso POS, reportes, ayuda y usuario.
- Se agrego resumen inicial del negocio con metricas base, alertas y accesos rapidos.
- La navegacion y los accesos visibles se calculan desde los permisos devueltos por `POST /api/auth/login`.
- Se mantiene el login existente y, al iniciar sesion, se cambia a la experiencia de panel completo.
- Se agrego soporte responsive para mostrar/ocultar menu lateral en pantallas pequenas.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se ejecuto prueba especifica en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.
- Se verifico en navegador local `http://localhost:8000` que el login carga con `APP_NAME`, el shell existe oculto antes de iniciar sesion y no hay errores de consola.

### Notas de seguridad

- El frontend solo oculta opciones por permisos para mejorar la experiencia.
- Las APIs siguen siendo la autoridad real y deben validar `api.auth`, tenant, roles, permisos y policies en cada accion.

## 2026-07-03 - Rediseño profesional del login

### Implementado

- Se cambio la marca visible para usar `APP_NAME` desde `.env`, con valor por defecto `Sistema de Inventario`.
- Se removieron referencias visibles a nombres propios anteriores en el login y datos demo.
- Se cambio la llave de sesion del navegador a `inventory_system_session`.
- Se elimino el panel visual izquierdo del login inicial.
- Se dejo una pantalla unica de acceso centrada.
- Se mantuvo la identidad morada/azul en una composicion mas sobria y empresarial.
- Se conservaron correo, contrasena, selector de empresa y sesion activa.
- Se mantuvo el consumo de `POST /api/auth/tenants`, `POST /api/auth/login` y `POST /api/auth/logout`.
- Se actualizo la documentacion de arquitectura, modulos y bitacora.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se verifico la pantalla en `http://localhost:8000`: login central visible, marca visible y panel lateral anterior removido.
- Se ejecuto prueba especifica del seeder demo en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Seeders/DemoDataSeederTest.php`: 1 prueba pasada, 50 aserciones.
- Se ejecuto prueba especifica en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.
- Suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 198 pruebas pasadas, 959 aserciones.

### Notas de seguridad

- El rediseño no cambia el modelo de autenticacion.
- El backend sigue validando token, tenant, roles, permisos y policies.

## 2026-07-03 - Frontend inicial de login

### Implementado

- Se reemplazo la pantalla inicial de Laravel por un login visual de Sistema de Inventario.
- Se mantuvo una paleta morada/azul similar a la referencia del usuario, con un diseno propio.
- Se agrego un panel oscuro operativo con marca, mensajes y tarjetas de capacidades.
- Se agrego una tarjeta de acceso clara con correo, contrasena y selector de empresa.
- El frontend consume `POST /api/auth/tenants` para resolver empresas activas del usuario.
- El frontend consume `POST /api/auth/login` con `X-Tenant` para iniciar sesion.
- Si el usuario pertenece a varias empresas, se muestra selector antes de entrar.
- Si la sesion queda guardada, se muestra un panel base de sesion activa.
- Se agrego cierre de sesion contra `POST /api/auth/logout`.
- Se documento arquitectura y mapa modular del frontend inicial.

### Pruebas

- Se ejecuto build frontend con `pnpm run build`: compilacion correcta.
- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.
- Suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 198 pruebas pasadas, 959 aserciones.

### Notas de seguridad

- El frontend solo consume APIs; no decide permisos criticos.
- El backend sigue validando token, tenant, roles, permisos y policies.
- El token se conserva en navegador solo como primera fase para avanzar el panel; se podra endurecer mas adelante.

## 2026-07-03 - Modulo Auth login y tokens por empresa

### Implementado

- Se agrego el modulo `Auth`.
- Se agrego la tabla `auth_tokens`.
- Se agrego el modelo `AuthToken`.
- Se agrego `AuthenticateApiToken` como middleware `api.auth`.
- Se expuso `POST /api/auth/tenants` para listar empresas activas disponibles antes de iniciar sesion.
- Se expuso `POST /api/auth/login` para iniciar sesion con `X-Tenant`.
- Se expuso `GET /api/auth/me` para consultar usuario, empresa, roles y permisos efectivos.
- Se expuso `POST /api/auth/logout` para revocar el token actual.
- Se expuso `POST /api/auth/logout-all` para revocar sesiones del usuario en la empresa actual.
- Las rutas protegidas cambiaron de `auth + tenant` a `api.auth + tenant`.
- Los tokens se guardan hasheados y se asocian a `tenant_id` y `user_id`.
- `ResolveTenant` valida que el token usado pertenezca al tenant solicitado.
- Se documento la API, arquitectura y mapa modular.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Auth/AuthApiTest.php`: 8 pruebas pasadas, 37 aserciones.
- Suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 198 pruebas pasadas, 959 aserciones.

### Notas de seguridad

- Un token emitido para una empresa no puede usarse con otra empresa.
- El token plano solo se entrega una vez al hacer login.
- El frontend sera cliente de las APIs; la seguridad real queda en backend con token, tenant, roles, permisos y policies.

## 2026-07-03 - Reembolso financiero de garantias

### Implementado

- Se agregaron campos de reembolso en `warranty_claims`.
- `PATCH /api/warranty-claims/{warrantyClaim}/resolve` ahora soporta `resolution_type = refund`.
- El reembolso puede salir por caja abierta con movimiento `cash_register_movements.type = outflow`.
- El reembolso puede aplicarse contra saldo pendiente creando un `FinancialAdjustment` sobre `AccountsReceivable`.
- Se evita doble efecto financiero: no se permite caja y rebaja de saldo en la misma resolucion.
- Se guarda snapshot de moneda, monto, tasa, metodo, referencia y monto base/local del reembolso.
- Si el producto serializado se reembolsa, el IMEI recibido por garantia queda `damaged`.
- Se documento la API, arquitectura, mapa modular y datos demo.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Warranties/WarrantyPolicyApiTest.php`: 16 pruebas pasadas, 92 aserciones.
- Suite completa: `docker compose run --rm app_test php artisan test` (190 pruebas, 922 aserciones).

### Notas de seguridad

- El caso debe estar aprobado con `resolution_type = refund`.
- El monto base del reembolso no puede superar el monto vendido para el item.
- Una garantia solo puede resolverse una vez.

## 2026-07-03 - Resoluciones de garantia por reemplazo y rechazo

### Implementado

- Se agregaron campos de ejecucion de resolucion en `warranty_claims`.
- Se agrego `ResolveWarrantyClaimRequest`.
- Se expuso `PATCH /api/warranty-claims/{warrantyClaim}/resolve`.
- `replacement` ejecuta reemplazo de garantia sin crear venta ni cobro.
- En reemplazos serializados, el IMEI defectuoso queda `damaged`.
- En reemplazos serializados, el IMEI entregado queda `sold`.
- El reemplazo genera movimiento de inventario `adjustment_out` referenciado al caso de garantia.
- `rejected` cierra el caso y devuelve el IMEI original a `sold` si estaba en `warranty_hold`.
- Se audita la accion `warranty.claim.resolved`.
- Se documento la API, arquitectura, mapa modular y datos demo.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Warranties/WarrantyPolicyApiTest.php`: 13 pruebas pasadas, 73 aserciones.
- Suite completa: `docker compose run --rm app_test php artisan test` (187 pruebas, 903 aserciones).

### Notas de seguridad

- Solo se puede resolver una garantia una vez.
- El reemplazo requiere que el caso este aprobado con resolucion `replacement`.
- El IMEI de reemplazo debe estar disponible y pertenecer al mismo producto.
- `refund` queda pendiente para una fase financiera separada.

## 2026-07-03 - Ventas con IMEI exacto en sale_items

### Implementado

- Se agrego `product_unit_ids` a `sale_items`.
- `POST /api/sales` acepta `items.*.product_unit_ids`.
- `POST /api/pos/checkouts` acepta `items.*.product_unit_ids` y lo delega a `Sales`.
- Al confirmar una venta serializada se valida un IMEI o serial disponible por cada unidad.
- La confirmacion bloquea las unidades serializadas y las marca como `sold`.
- La respuesta de venta muestra `product_unit_ids` y `serial_units`.
- `SalesReturns` solo permite devolver IMEIs registrados en el `sale_item` vendido.
- `Warranties` solo permite abrir garantia de un IMEI registrado en el `sale_item` vendido.
- La demo asocia un IMEI a la venta POS pagada y usa ese mismo IMEI en la devolucion.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Sales/SalesApiTest.php tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/SalesReturns/SalesReturnApiTest.php tests/Feature/Warranties/WarrantyPolicyApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 33 pruebas pasadas, 232 aserciones.
- Suite completa: `docker compose run --rm app_test php artisan test` (184 pruebas, 884 aserciones).

### Notas de seguridad

- Crear una venta no reserva el IMEI; la disponibilidad se valida al confirmar.
- Si dos cajas intentan vender el mismo IMEI, solo la primera confirmacion puede marcarlo como vendido.
- Las devoluciones y garantias ya no aceptan IMEIs del mismo producto si no salieron en ese item.

## 2026-07-03 - Modulo Warranties casos de garantia

### Implementado

- Se agrego la tabla `warranty_claims`.
- Se agrego el modelo `WarrantyClaim`.
- Se agrego `WarrantyClaimService`.
- Se expuso `GET /api/warranty-claims`.
- Se expuso `POST /api/warranty-claims`.
- Se expuso `GET /api/warranty-claims/{warrantyClaim}`.
- Se expuso `PATCH /api/warranty-claims/{warrantyClaim}/review`.
- Se expuso `PATCH /api/warranty-claims/{warrantyClaim}/deliver`.
- Crear caso valida venta confirmada, snapshot de garantia y vigencia.
- Los productos serializados pueden asociar IMEI/serial mediante `product_unit_id`.
- Las unidades serializadas recibidas por garantia quedan en estado `warranty_hold`.
- Se auditan recepcion, revision y entrega de casos.
- La demo crea un caso de garantia recibido por empresa.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Warranties/WarrantyPolicyApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 10 pruebas pasadas, 101 aserciones.
- Suite completa: `docker compose run --rm app_test php artisan test` (179 pruebas, 861 aserciones).

### Notas de seguridad

- No se puede crear garantia si la venta no esta confirmada.
- No se puede crear garantia si el item no tiene garantia o ya vencio.
- No se permite abrir dos casos activos para la misma unidad serializada.
- Esta fase no mueve dinero ni hace reemplazos; solo registra recepcion, revision y entrega.

## 2026-07-03 - Modulo Warranties politicas y snapshot

### Implementado

- Se agrego el modulo `Warranties`.
- Se agrego la tabla `warranty_policies`.
- Se agrego `warranty_policy_id` en `products`.
- Se agrego snapshot de garantia en `sale_items`.
- Se agregaron permisos `warranty_policies.view`, `warranty_policies.manage`, `warranties.view`, `warranties.create`, `warranties.review`, `warranties.resolve` y `warranties.deliver`.
- Se expuso `GET /api/warranty-policies`.
- Se expuso `POST /api/warranty-policies`.
- Se expuso `GET /api/warranty-policies/{warrantyPolicy}`.
- Se expuso `PATCH /api/warranty-policies/{warrantyPolicy}`.
- Se expuso `DELETE /api/warranty-policies/{warrantyPolicy}` como desactivacion.
- Productos ahora aceptan `warranty_policy_id`.
- Las ventas copian la garantia del producto en cada item.
- Al confirmar venta se asigna inicio y vencimiento de garantia.
- La demo crea politicas `Android 30 dias` y `Accesorios 7 dias`.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Warranties/WarrantyPolicyApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 81 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 175 pruebas pasadas, 841 aserciones.

### Notas de seguridad

- Una empresa no puede usar politicas de garantia de otra empresa.
- Las ventas guardan snapshot para no depender de cambios futuros en la politica del producto.
- Esta fase deja lista la base para casos de garantia, revision, reemplazo y reembolso.

## 2026-07-03 - Modulo AccessControl fase 2 auditoria y proteccion

### Implementado

- Se conecto `AccessControlService` con `AuditLogger`.
- Se audita creacion o vinculacion de usuarios a empresas.
- Se audita actualizacion de nombre de usuario.
- Se audita cambio de estado del usuario dentro de la empresa.
- Se audita cambio de roles del usuario dentro de la empresa.
- Se audita creacion, actualizacion, cambio de permisos y eliminacion de roles.
- Se agrego proteccion para no inactivar el ultimo `Owner` o `Administrador` activo de la empresa.
- Se agrego proteccion para no quitar el ultimo rol `Owner` o `Administrador` activo de la empresa.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/AccessControl/AccessControlApiTest.php`: 10 pruebas pasadas, 47 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 170 pruebas pasadas, 803 aserciones.

### Notas de seguridad

- Toda accion sensible de accesos queda trazada con usuario actor, tenant, IP y user agent.
- Una empresa no puede quedar sin administrador activo por error operativo.
- La proteccion respeta tenants: solo cuenta administradores activos de la empresa actual.

## 2026-07-03 - Modulo AccessControl fase 1

### Implementado

- Se agrego el modulo `AccessControl`.
- Se agregaron permisos `roles.view`, `roles.create`, `roles.update` y `roles.delete`.
- Se expuso `GET /api/users`.
- Se expuso `POST /api/users`.
- Se expuso `GET /api/users/{user}`.
- Se expuso `PATCH /api/users/{user}`.
- Se expuso `PATCH /api/users/{user}/status`.
- Se expuso `PATCH /api/users/{user}/roles`.
- Se expuso `GET /api/users/{user}/permissions`.
- Se expuso `GET /api/roles`.
- Se expuso `POST /api/roles`.
- Se expuso `GET /api/roles/{role}`.
- Se expuso `PATCH /api/roles/{role}`.
- Se expuso `PATCH /api/roles/{role}/permissions`.
- Se expuso `DELETE /api/roles/{role}`.
- Se expuso `GET /api/permissions`.
- El mismo usuario puede pertenecer a varias empresas con roles y estado independientes.
- Los roles base quedan protegidos contra eliminacion.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/AccessControl/AccessControlApiTest.php`: 7 pruebas pasadas, 30 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 167 pruebas pasadas, 786 aserciones.

### Notas de seguridad

- Los usuarios listados siempre pertenecen al tenant actual.
- Los roles consultados y asignados pertenecen al tenant actual.
- Un cambio de estado en una empresa no afecta el acceso del mismo usuario en otra empresa.
- El catalogo de permisos queda centralizado para evitar permisos inventados fuera de `BasePermissions`.

## 2026-07-02 - Mejoras Purchases recepcion parcial

### Implementado

- Se agregaron campos `issued_at`, `due_date`, `received_base_amount` y `received_local_amount` en `purchase_orders`.
- Se agrego `received_quantity` en `purchase_items`.
- Se agrego `ReceivePurchaseOrderRequest`.
- `PATCH /api/purchases/{purchaseOrder}/receive` ahora puede recibir todo lo pendiente o cantidades parciales por item.
- Se agrego estado `partially_received`.
- Las cuentas por pagar se crean o actualizan con el monto recibido real.
- La demo de compras ahora guarda fecha de emision y vencimiento.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Purchases/PurchaseOrderApiTest.php tests/Feature/AccountsPayable/AccountsPayableApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 15 pruebas pasadas, 113 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 160 pruebas pasadas, 756 aserciones.

### Notas de seguridad

- No se puede recibir mas cantidad que la pendiente.
- La recepcion parcial no mueve inventario por lo no recibido.
- La cuenta por pagar no aumenta por mercancia pendiente sin recibir.

## 2026-07-02 - Modulo InventoryTransferRequests

### Implementado

- Se agrego el modulo `InventoryTransferRequests`.
- Se agregaron tablas `inventory_transfer_requests` y `inventory_transfer_request_items`.
- Se agrego modelo, policy, controller, requests, resources y service del modulo.
- Se agregaron permisos `inventory_transfer_requests.view`, `inventory_transfer_requests.create`, `inventory_transfer_requests.respond` y `inventory_transfer_requests.cancel`.
- Se expuso `GET /api/inventory-transfer-requests`.
- Se expuso `POST /api/inventory-transfer-requests`.
- Se expuso `GET /api/inventory-transfer-requests/{inventoryTransferRequest}`.
- Se expuso `POST /api/inventory-transfer-requests/{inventoryTransferRequest}/accept`.
- Se expuso `POST /api/inventory-transfer-requests/{inventoryTransferRequest}/reject`.
- Se expuso `POST /api/inventory-transfer-requests/{inventoryTransferRequest}/cancel`.
- La empresa destino puede buscarse por slug o por correo de usuario activo.
- Crear solicitud no mueve inventario.
- Aceptar descuenta inventario de origen y crea entrada en destino.
- En serializados, el IMEI queda removido en origen y disponible en destino.
- Se actualizo el seeder demo para crear una solicitud interempresa completada.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/InventoryTransferRequests/InventoryTransferRequestApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 69 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 159 pruebas pasadas, 742 aserciones.

### Notas de seguridad

- Una tercera empresa no puede ver ni responder solicitudes ajenas.
- Solo la empresa destino puede aceptar o rechazar.
- Solo la empresa origen puede cancelar.
- La aceptacion falla si ya no hay stock o IMEIs disponibles en origen.
- El producto destino debe tener el mismo tipo de control que el producto origen.

## 2026-07-02 - Modulo InventoryTransfers

### Implementado

- Se agrego el modulo `InventoryTransfers`.
- Se agregaron tablas `inventory_transfers` y `inventory_transfer_items`.
- Se agrego modelo, policy, controller, request, resources y service del modulo.
- Se agregaron permisos `inventory_transfers.view` y `inventory_transfers.create`.
- Se expuso `GET /api/inventory-transfers`.
- Se expuso `POST /api/inventory-transfers`.
- Se expuso `GET /api/inventory-transfers/{inventoryTransfer}`.
- Las transferencias pueden contener uno o varios productos.
- Cada item genera movimiento `transfer_out` en origen y `transfer_in` en destino.
- Los productos serializados requieren unidades disponibles especificas del almacen origen.
- Los IMEIs trasladados quedan disponibles y cambian de almacen.
- Se actualizo el seeder demo para crear una transferencia interna por empresa.
- Se documento que los traslados entre empresas se implementaran como solicitudes interempresa con aceptacion o rechazo.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 7 pruebas pasadas, 70 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 154 pruebas pasadas, 715 aserciones.

### Notas de seguridad

- El modulo requiere permisos y respeta tenant.
- No permite trasladar a almacenes de otra empresa.
- Usa los bloqueos existentes de `InventoryMovementService` para evitar stock negativo en competencia.
- Las transferencias interempresa no moveran inventario directo sin aceptacion de la empresa destino.

## 2026-07-02 - Modulo ProductExits

### Implementado

- Se agrego el modulo `ProductExits`.
- Se agregaron tablas `product_exits` y `product_exit_items`.
- Se agrego modelo, policy, controller, request, resources y service del modulo.
- Se agregaron permisos `product_exits.view` y `product_exits.create`.
- Se expuso `GET /api/product-exits`.
- Se expuso `POST /api/product-exits`.
- Se expuso `GET /api/product-exits/{productExit}`.
- Las salidas pueden contener uno o varios productos.
- El motivo `damaged` genera movimiento `damaged` y mueve stock a danado.
- Los demas motivos generan movimiento `adjustment_out` y reducen disponible.
- Los productos serializados requieren unidades disponibles especificas del mismo producto y almacen.
- Se actualizo el seeder demo para crear una salida por garantia de un IMEI por empresa.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/ProductExits/ProductExitApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 8 pruebas pasadas, 65 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 148 pruebas pasadas, 684 aserciones.

### Notas de seguridad

- El modulo requiere permisos y respeta tenant.
- No permite sacar IMEIs vendidos, removidos, danados o de otro almacen.
- No reemplaza ventas, POS ni devoluciones a proveedor.

## 2026-07-02 - Modulo ProductEntries

### Implementado

- Se agrego el modulo `ProductEntries`.
- Se agregaron tablas `product_entries` y `product_entry_items`.
- Se agrego modelo, policy, controller, request, resources y service del modulo.
- Se agregaron permisos `product_entries.view` y `product_entries.create`.
- Se expuso `GET /api/product-entries`.
- Se expuso `POST /api/product-entries`.
- Se expuso `GET /api/product-entries/{productEntry}`.
- Las entradas pueden contener uno o varios productos.
- Cada item genera movimiento `purchase` usando `InventoryMovementService`.
- Los productos serializados requieren un IMEI o serial por cada unidad.
- Los seriales se validan contra duplicados dentro de la entrada y contra seriales existentes del tenant.
- Se actualizo el seeder demo para crear entradas de 30 IMEIs por empresa.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/ProductEntries/ProductEntryApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 61 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 141 pruebas pasadas, 655 aserciones.

### Notas de seguridad

- El modulo requiere permisos y respeta tenant.
- Las entradas operativas no crean cuenta por pagar ni proveedor.
- Para compra formal con proveedor se debe usar `Purchases`.

## 2026-07-02 - Modulo FinancialAdjustments

### Implementado

- Se agrego el modulo `FinancialAdjustments`.
- Se agrego la tabla `financial_adjustments`.
- Se agregaron columnas `adjusted_base_amount` y `adjusted_local_amount` a cuentas por cobrar y cuentas por pagar.
- Se agrego modelo, policy, controller, request, resource y service del modulo.
- Se agregaron permisos `financial_adjustments.view` y `financial_adjustments.create`.
- Se expuso `GET /api/financial-adjustments`.
- Se expuso `POST /api/financial-adjustments`.
- Se expuso `GET /api/financial-adjustments/{financialAdjustment}`.
- Los ajustes pueden aplicarse a cuentas por cobrar o cuentas por pagar.
- Los ajustes reducen saldo pendiente sin mover inventario.
- Los ajustes en `VES` guardan snapshot de tipo de tasa, codigo y valor usado.
- Se actualizo el seeder demo para crear ajustes financieros visibles.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/FinancialAdjustments/FinancialAdjustmentApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 53 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 136 pruebas pasadas, 629 aserciones.

### Notas de seguridad

- El modulo requiere permisos y respeta tenant.
- El ajuste no puede superar el saldo pendiente.
- El ajuste no crea comprobante de pago porque no representa dinero recibido o entregado.
- Las devoluciones fisicas siguen perteneciendo a `SalesReturns` y `PurchaseReturns`.

## 2026-07-02 - Modulo PaymentReceipts

### Implementado

- Se agrego el modulo `PaymentReceipts`.
- Se agrego la tabla `payment_receipts`.
- Se agrego modelo, policy, controller, request, resource y service del modulo.
- Se agregaron permisos `payment_receipts.view` y `payment_receipts.void`.
- Se expuso `GET /api/payment-receipts`.
- Se expuso `GET /api/payment-receipts/{paymentReceipt}`.
- Se expuso `PATCH /api/payment-receipts/{paymentReceipt}/void`.
- Se emiten comprobantes automaticamente al registrar cobros de clientes en `AccountsReceivable`.
- Se emiten comprobantes automaticamente al registrar pagos a proveedores en `AccountsPayable`.
- Los pagos POS capturados quedan cubiertos porque POS sincroniza esos pagos como cobros de cliente.
- Cada comprobante guarda snapshot de tercero, moneda, monto, metodo, referencia, tipo de tasa y tasa usada.
- El correlativo `receipt_number` es independiente por tenant.
- Se actualizo el seeder demo para emitir comprobantes sobre pagos y cobros existentes.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/PaymentReceipts/PaymentReceiptApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 50 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 131 pruebas pasadas, 610 aserciones.

### Notas de seguridad

- El modulo es tenant-scoped y requiere permisos.
- La anulacion del comprobante no revierte el pago, la cuenta, caja ni inventario.
- La emision es idempotente por origen para evitar comprobantes duplicados si un flujo se reintenta.

## 2026-07-02 - Integracion POS con AccountsReceivable

### Implementado

- Se integro `POS` con `AccountsReceivable`.
- Los pagos POS con estado `captured` se registran automaticamente como cobros de la cuenta por cobrar creada al confirmar la venta.
- Los cobros automaticos usan referencia idempotente `POS-PAYMENT-{id}`.
- Se guarda metodo `pos_{method}` para distinguir cobros generados desde POS.
- Los pagos POS en `VES` conservan snapshot de tipo de tasa, codigo y valor usado.
- Los pagos POS con estado `pending` no crean cobros automaticos y mantienen la venta en borrador.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`: 7 pruebas pasadas, 59 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 126 pruebas pasadas, 591 aserciones.

### Notas de seguridad

- La sincronizacion ocurre dentro de la transaccion de checkout.
- Si la venta no se confirma, no se crea cuenta por cobrar ni cobro.
- Solo pagos capturados se reflejan como cobros.
- La referencia idempotente evita duplicar cobros si el flujo se reintenta internamente.

## 2026-07-02 - Modulo FinanceReports

### Implementado

- Se agrego el modulo `FinanceReports`.
- Se agrego `FinanceReportController`.
- Se agrego `FinanceReportRequest`.
- Se agrego `FinanceReportService`.
- Se agrego archivo de rutas `app/Modules/FinanceReports/routes.php`.
- Se agrego permiso `finance_reports.view`.
- Se expuso `GET /api/finance-reports/summary`.
- Se expuso `GET /api/finance-reports/receivables`.
- Se expuso `GET /api/finance-reports/payables`.
- El resumen muestra cuentas por cobrar, cuentas por pagar, cobros, pagos y balance neto en `USD`.
- Los listados permiten filtrar por estado, cliente, proveedor y fechas.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/FinanceReports/FinanceReportApiTest.php`: 4 pruebas pasadas, 23 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 126 pruebas pasadas, 586 aserciones.

### Notas de seguridad

- Los reportes financieros son solo lectura.
- Los filtros de cliente y proveedor se validan contra el tenant actual.
- El modulo no mezcla datos entre empresas.
- El modulo requiere permiso `finance_reports.view`.

## 2026-07-02 - Modulo AccountsReceivable

### Implementado

- Se agrego el modulo `AccountsReceivable`.
- Se agregaron tablas `accounts_receivables` y `accounts_receivable_payments`.
- Se agregaron modelos `AccountsReceivable` y `AccountsReceivablePayment`.
- Se agrego `AccountsReceivablePolicy`.
- Se agrego `AccountsReceivableService`.
- Se agrego `AccountsReceivableController`.
- Se agregaron recursos y request de cobro de cliente.
- Se agregaron endpoints para listar, ver y cobrar cuentas por cobrar.
- Se agregaron permisos `accounts_receivable.view` y `accounts_receivable.collect`.
- Se integro `Sales` para crear cuenta por cobrar automaticamente al confirmar una venta.
- Se integro `SalesReturns` para reducir el saldo pendiente cuando hay devolucion de venta.
- Se soportan cobros en `USD` y `VES`.
- Se guarda snapshot de tipo de tasa, codigo y valor cuando el cobro usa bolivares.
- Se valida que un cobro no supere el saldo pendiente.
- Se actualizo el seeder demo para crear ventas a credito, cuentas por cobrar y abonos visibles en la BD local.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/AccountsReceivable/AccountsReceivableApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 8 pruebas pasadas, 57 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 122 pruebas pasadas, 563 aserciones.

### Notas de seguridad

- Las cuentas por cobrar son tenant-scoped.
- Una cuenta por cobrar nace desde una venta confirmada, no desde un endpoint manual.
- Los cobros rechazan cuentas de otra empresa mediante policy.
- Los cobros en bolivares guardan la tasa usada y no recalculan historia.
- Las devoluciones de venta rebajan saldo sin borrar ventas ni movimientos historicos.

## 2026-07-02 - Modulo AccountsPayable

### Implementado

- Se agrego el modulo `AccountsPayable`.
- Se agregaron tablas `accounts_payables` y `accounts_payable_payments`.
- Se agregaron modelos `AccountsPayable` y `AccountsPayablePayment`.
- Se agrego `AccountsPayablePolicy`.
- Se agrego `AccountsPayableService`.
- Se agrego `AccountsPayableController`.
- Se agregaron recursos y request de pago a proveedor.
- Se agregaron endpoints para listar, ver y pagar cuentas por pagar.
- Se agregaron permisos `accounts_payable.view` y `accounts_payable.pay`.
- Se integro `Purchases` para crear cuenta por pagar automaticamente al recibir una compra.
- Se integro `PurchaseReturns` para reducir el saldo pendiente cuando hay devolucion a proveedor.
- Se soportan pagos en `USD` y `VES`.
- Se guarda snapshot de tipo de tasa, codigo y valor cuando el pago usa bolivares.
- Se valida que un pago no supere el saldo pendiente.
- Se actualizo el seeder demo para crear cuentas por pagar y abonos visibles en la BD local.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/AccountsPayable/AccountsPayableApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 8 pruebas pasadas, 55 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 115 pruebas pasadas, 535 aserciones.

### Notas de seguridad

- Las cuentas por pagar son tenant-scoped.
- Una cuenta por pagar nace desde una compra recibida, no desde un endpoint manual.
- Los pagos rechazan cuentas de otra empresa mediante policy.
- Los pagos en bolivares guardan la tasa usada y no recalculan historia.
- Las devoluciones a proveedor rebajan saldo sin borrar compras ni movimientos historicos.

## 2026-07-02 - Modulo PurchaseReturns

### Implementado

- Se agrego el modulo `PurchaseReturns`.
- Se agregaron tablas `purchase_returns` y `purchase_return_items`.
- Se agregaron modelos `PurchaseReturn` y `PurchaseReturnItem`.
- Se agrego `PurchaseReturnPolicy`.
- Se agrego `PurchaseReturnService`.
- Se agrego `PurchaseReturnController`.
- Se agregaron recursos y request de devolucion a proveedor.
- Se agregaron endpoints para listar, crear y ver devoluciones a proveedor.
- Se agregaron permisos `purchase_returns.view` y `purchase_returns.create`.
- Se agrego movimiento de inventario `purchase_return` en `InventoryMovementService`.
- Se agrego `purchase_return` a los tipos oficiales de `StockMovement` y a Kardex como salida.
- Se valida que solo se devuelvan compras recibidas.
- Se valida que no se devuelva mas cantidad que la comprada menos devoluciones previas.
- Se soportan devoluciones de productos serializados indicando unidades especificas.
- Se actualizo el seeder demo para crear devoluciones a proveedor visibles en la BD local.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/PurchaseReturns/PurchaseReturnApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 43 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 108 pruebas pasadas, 507 aserciones.

### Notas de seguridad

- Las devoluciones a proveedor son tenant-scoped.
- Una devolucion a proveedor no borra ni cancela la compra original.
- Las devoluciones rechazan compras e items de otra empresa.
- Los productos serializados requieren unidad especifica por cada cantidad devuelta.
- Las unidades serializadas devueltas quedan como `removed`.
- El inventario se mueve mediante `InventoryMovementService`, no desde el controlador.

## 2026-07-02 - Modulo Kardex

### Implementado

- Se agrego el modulo `Kardex`.
- Se agrego `KardexController`.
- Se agrego `KardexProductRequest`.
- Se agrego `KardexService`.
- Se agrego `app/Modules/Kardex/routes.php`.
- Se agrego el permiso `kardex.view`.
- Se agrego `sale_return` a los tipos oficiales de `StockMovement`.
- Se expuso `GET /api/kardex/products/{product}` con filtros por almacen y fechas.
- Kardex calcula saldo inicial, saldo final, entradas, salidas y saldo corrido desde `stock_movements`.
- Se actualizo la documentacion de API, modulos y arquitectura.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Kardex/KardexApiTest.php`: 4 pruebas pasadas, 25 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 103 pruebas pasadas, 489 aserciones.

### Notas de seguridad

- Kardex es solo lectura.
- Kardex no duplica datos ni crea tablas paralelas.
- Kardex respeta tenant por producto, almacen y movimientos.
- Kardex rechaza filtros de almacenes de otra empresa.

## 2026-07-02 - Modulo SalesReturns

### Implementado

- Se agrego el modulo `SalesReturns`.
- Se agregaron tablas `sales_returns` y `sales_return_items`.
- Se agregaron modelos `SalesReturn` y `SalesReturnItem`.
- Se agrego `SalesReturnPolicy`.
- Se agrego `SalesReturnService`.
- Se agrego `SalesReturnController`.
- Se agregaron recursos y request de devolucion.
- Se agregaron endpoints para listar, crear y ver devoluciones de venta.
- Se agregaron permisos `sales_returns.view` y `sales_returns.create`.
- Se agrego movimiento de inventario `sale_return` en `InventoryMovementService`.
- Se valida que solo se devuelvan ventas confirmadas.
- Se valida que no se devuelva mas cantidad que la vendida menos devoluciones previas.
- Se soportan devoluciones de productos serializados indicando unidades especificas.
- Se actualizo el seeder demo para crear devoluciones visibles en la BD local.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/SalesReturns/SalesReturnApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 6 pruebas pasadas, 42 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 99 pruebas pasadas, 464 aserciones.

### Notas de seguridad

- Las devoluciones son tenant-scoped.
- Una devolucion no borra ni cancela la venta original.
- Las devoluciones rechazan ventas e items de otra empresa.
- Los productos serializados requieren unidad especifica por cada cantidad devuelta.
- El inventario se mueve mediante `InventoryMovementService`, no desde el controlador.

## 2026-07-02 - Modulos Suppliers y Purchases

### Implementado

- Se agrego el modulo `Suppliers`.
- Se agrego el modulo `Purchases`.
- Se agregaron tablas `suppliers`, `purchase_orders` y `purchase_items`.
- Se agregaron modelo, policy, requests, resources, controller y rutas para proveedores.
- Se agregaron modelo, policy, request, resources, service, controller y rutas para compras.
- Se agregaron permisos `suppliers.view`, `suppliers.create`, `suppliers.update` y `suppliers.delete`.
- Se mantuvieron permisos de compras `purchases.view`, `purchases.create` y `purchases.approve`.
- Crear una compra la deja en `draft` y no mueve inventario.
- Recibir una compra genera movimientos `purchase` mediante `InventoryMovementService`.
- Las compras pueden registrar costos en `USD` o `VES` y guardar snapshot de tasa.
- Las compras de productos serializados pueden recibir IMEIs o seriales y crear unidades en `product_units`.
- Se actualizo el seeder demo para crear proveedores y compras recibidas visibles en la BD local.
- Se actualizo la documentacion de API, modulos, arquitectura y datos demo.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Suppliers/SupplierApiTest.php tests/Feature/Purchases/PurchaseOrderApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 11 pruebas pasadas, 70 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 94 pruebas pasadas, 445 aserciones.

### Notas de seguridad

- Los proveedores y compras son tenant-scoped.
- El mismo documento de proveedor puede existir en empresas distintas, pero no duplicado dentro de la misma empresa.
- Compras rechaza proveedores, almacenes, productos y tipos de tasa de otra empresa.
- Las compras recibidas no se cancelan directamente en esta fase.
- La entrada de stock queda centralizada en `InventoryMovementService`, no en el controlador.

## 2026-07-02 - Modulo Customers y asociacion con ventas/POS

### Implementado

- Se agrego el modulo `Customers`.
- Se agrego la tabla `customers` con datos fiscales basicos, telefono, correo, direccion, cliente generico y estado activo.
- Se agrego `customer_id` opcional a `sales` y `pos_orders`.
- Se agregaron modelo, policy, requests, resource, controller y rutas para clientes.
- Se agregaron permisos `customers.view`, `customers.create`, `customers.update` y `customers.delete`.
- Se integro `Customers` con `Sales` para asociar una venta a un cliente del tenant actual.
- Se integro `Customers` con `POS` para asociar una orden POS y su venta interna al mismo cliente.
- Se actualizo el seeder demo para crear clientes por empresa y enlazarlos a las ventas POS demo.
- Se actualizo la documentacion de API, modulos, arquitectura y datos demo.

### Pruebas

- Se ejecutaron pruebas especificas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Customers/CustomerApiTest.php tests/Feature/Sales/SalesApiTest.php tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php`: 18 pruebas pasadas, 126 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 84 pruebas pasadas, 396 aserciones.

### Notas de seguridad

- Los clientes son tenant-scoped.
- El mismo documento puede existir en empresas distintas, pero no duplicado dentro de la misma empresa.
- Ventas y POS rechazan `customer_id` de otra empresa.
- Desactivar un cliente no borra ventas historicas.
- `customer_id` es opcional para permitir ventas rapidas, cliente generico o flujo POS sin datos completos.

## 2026-07-02 - Seeder demo para datos visibles

### Implementado

- Se agrego `DemoDataSeeder`.
- Se ajusto `DatabaseSeeder` para no duplicar el usuario base `test@example.com`.
- El seeder demo crea dos empresas de ejemplo.
- El seeder demo crea usuarios cajero y gerente por empresa.
- El seeder demo crea sucursales, almacenes, tasas `BCV` y `PARALELO`.
- El seeder demo crea productos por cantidad y productos serializados con IMEIs.
- El seeder demo carga stock inicial mediante el servicio de inventario.
- El seeder demo abre cajas y crea ventas POS pagadas y ventas POS con financiamiento pendiente.
- Se agrego una prueba para validar que el seeder crea datos de negocio visibles y es idempotente.

### Pruebas

- Se ejecutaron pruebas especificas del seeder en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Seeders/DemoDataSeederTest.php`: 1 prueba pasada, 17 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 80 pruebas pasadas, 362 aserciones.

### Notas de uso

- Para llenar la BD local visible desde HeidiSQL se debe ejecutar `docker compose run --rm app php artisan db:seed --class=DemoDataSeeder`.
- El seeder esta pensado para ambiente local/demo, no para datos reales de produccion.
- Los tests siguen limpiando su propia base; este seeder sirve para datos persistentes en `inventory_arens`.
- Se ejecutaron migraciones y el seeder demo en la BD local `inventory_arens`.
- Verificacion local: 2 empresas, 4 productos, 16 unidades serializadas, 2 cajas, 4 ventas POS, 4 pagos POS y 6 movimientos de inventario.

## 2026-07-02 - Integracion POS con Caja

### Implementado

- Se agrego `cash_register_session_id` a `pos_orders`.
- Se actualizo el checkout POS para exigir una caja abierta.
- Se valido que la caja pertenezca al cajero autenticado.
- Se valido que no se pueda vender con una caja cerrada.
- Cada pago POS con estado `captured` crea un movimiento de caja `pos_payment`.
- Los pagos pendientes no crean movimiento de caja ni confirman la venta.
- Se probaron multiples cajas abiertas vendiendo el mismo producto con stock limitado.

### Pruebas

- Se ejecutaron pruebas especificas de POS en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`: 7 pruebas pasadas, 48 aserciones.
- Se ejecutaron pruebas especificas de caja en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/CashRegister/CashRegisterApiTest.php`: 6 pruebas pasadas, 31 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 79 pruebas pasadas, 345 aserciones.

### Notas de seguridad

- Varias cajas pueden estar abiertas al mismo tiempo, pero cada una pertenece a un cajero.
- POS no permite vender desde una caja cerrada.
- POS no permite vender desde una caja de otro cajero.
- Si dos cajas intentan vender la ultima unidad, la primera confirmacion descuenta stock y la segunda falla por stock insuficiente.
- El inventario no debe quedar negativo y los movimientos de caja del intento fallido se revierten con la transaccion.

## 2026-07-02 - Caja base

### Implementado

- Se agrego el modulo `CashRegister`.
- Se agrego la tabla `cash_register_sessions`.
- Se agrego la tabla `cash_register_movements`.
- Se agregaron modelos `CashRegisterSession` y `CashRegisterMovement`.
- Se agrego `CashRegisterSessionPolicy`.
- Se agrego `CashRegisterService`.
- Se agrego `CashRegisterSessionController`.
- Se agregaron endpoints para listar sesiones, abrir caja, ver una sesion, registrar movimientos y cerrar caja.
- La caja maneja montos en `USD` o `VES` con snapshot de tasa cuando aplica.
- El cierre guarda monto esperado, monto contado y diferencia.
- Se evita que un cajero tenga dos cajas abiertas al mismo tiempo.

### Pruebas

- Se ejecutaron pruebas especificas de caja en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/CashRegister/CashRegisterApiTest.php`: 6 pruebas pasadas, 31 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 77 pruebas pasadas, 325 aserciones.

### Notas de seguridad

- Caja es tenant-scoped.
- Las sesiones solo aceptan sucursales de la empresa actual.
- Los movimientos no pueden agregarse a una caja cerrada.
- POS seguira siendo el modulo de venta; caja sera el modulo de apertura, movimientos, arqueo y cierre.

## 2026-07-02 - POS base

### Implementado

- Se agrego el modulo `POS`.
- Se agrego la tabla `pos_orders`.
- Se agrego la tabla `pos_payments`.
- Se agregaron modelos `PosOrder` y `PosPayment`.
- Se agrego `PosOrderPolicy`.
- Se agrego `PosCheckoutService`.
- Se agrego `PosOrderController`.
- Se agregaron endpoints para listar ordenes POS, crear checkouts y ver una orden POS.
- El POS crea una venta usando `Sales`, registra pagos y confirma la venta solo si los pagos capturados cubren el total.
- Los pagos pueden estar en `USD` o `VES`.
- Los pagos en `VES` guardan tipo de tasa, codigo y valor exacto usado.
- Los pagos con estado `pending`, como financiadoras externas futuras, no cierran la venta ni descuentan inventario.

### Pruebas

- Se ejecutaron pruebas especificas de POS en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`: 5 pruebas pasadas, 28 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 71 pruebas pasadas, 294 aserciones.

### Notas de seguridad

- POS es tenant-scoped.
- POS no mueve inventario directamente; delega la confirmacion a `Sales`.
- Los items solo aceptan productos y almacenes de la empresa actual.
- Las ordenes POS solo son visibles dentro de la empresa actual.
- Los pagos quedan modelados desde el inicio para metodos futuros como pago movil, tarjeta, transferencia, Zelle y financiadoras externas.

## 2026-07-02 - Ventas base

### Implementado

- Se agrego la tabla `sales`.
- Se agrego la tabla `sale_items`.
- Se agregaron modelos `Sale` y `SaleItem`.
- Se agrego `SalePolicy`.
- Se agrego `SaleService`.
- Se agrego `SaleController`.
- Se agregaron endpoints para listar, crear, ver, confirmar y cancelar ventas.
- Crear una venta genera un borrador y copia precio/tasa historica.
- Confirmar una venta descuenta inventario y enlaza movimientos.
- Cancelar solo aplica a ventas en borrador en esta fase.

### Pruebas

- Se ejecutaron pruebas especificas de ventas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Sales/SalesApiTest.php`: 6 pruebas pasadas, 27 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 66 pruebas pasadas, 266 aserciones.

### Notas de seguridad

- Las ventas son tenant-scoped.
- Los items solo aceptan productos y almacenes de la empresa actual.
- La venta confirmada guarda historia de precio y tasa.
- POS futuro debe usar ventas, no mover inventario directamente.

## 2026-07-02 - Precios de productos con tasas

### Implementado

- Se agrego `base_price` a productos como precio base interno en `USD`.
- Se agrego `sale_currency` para indicar si el producto se cotiza en `USD` o `VES`.
- Se agrego `sale_exchange_rate_type_id` para asignar tipos de tasa como `BCV` o `PARALELO`.
- Se agrego `ProductPriceService`.
- Se agrego `ProductPriceResource`.
- Se agrego `GET /api/products/{product}/price`.
- Se valido que el tipo de tasa asignado al producto pertenezca al tenant actual.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 11 pruebas pasaron, 47 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 60 pruebas pasaron, 239 assertions.

### Notas de seguridad

- La cotizacion de precio no mueve inventario ni crea ventas.
- Si un producto vende en `VES`, debe existir una tasa activa.
- Las ventas futuras deben copiar precio, moneda, tipo de tasa y valor exacto usado.

## 2026-07-02 - Cierre de APIs de tasas

### Implementado

- Se confirmo que `POST /api/currency/rates` es la API para crear una nueva tasa.
- Se agrego `PATCH /api/currency/rates/{rate}/deactivate`.
- Se documento la diferencia entre crear tasa, activar tasa y desactivar tasa.
- La desactivacion de una tasa individual conserva el historial.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Currency/CurrencyApiTest.php`.
- Resultado: 6 pruebas pasaron, 37 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 55 pruebas pasaron, 215 assertions.

### Notas de seguridad

- Desactivar una tasa requiere `currency.manage`.
- La tasa no se elimina fisicamente porque las ventas futuras deben conservar historia monetaria.

## 2026-07-02 - Modulo Currency con tasas BCV y paralelo

### Implementado

- Se agrego la tabla `exchange_rate_types`.
- Se agrego la tabla `exchange_rates`.
- Se agregaron modelos `ExchangeRateType` y `ExchangeRate`.
- Se agregaron policies para tipos de tasa y tasas.
- Se agregaron permisos `currency.view` y `currency.manage`.
- Se agrego `ExchangeRateActivationService`.
- Se agregaron APIs para tipos de tasa.
- Se agregaron APIs para historial, tasas actuales y activacion de tasas.
- Se documento que una empresa puede tener `BCV` y `PARALELO` activos al mismo tiempo.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Currency/CurrencyApiTest.php`.
- Resultado: 5 pruebas pasaron, 32 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 54 pruebas pasaron, 210 assertions.

### Notas de seguridad

- Los tipos de tasa y tasas son tenant-scoped.
- Una tasa no puede apuntar a un tipo de tasa de otra empresa.
- Activar una tasa solo reemplaza tasas activas del mismo tipo y par de monedas.
- Las ventas futuras deben guardar el valor exacto de la tasa usada.

## 2026-07-02 - API de sucursales y almacenes

### Implementado

- Se agrego `BranchController`.
- Se agregaron requests y resource para sucursales.
- Se agrego `app/Modules/Branches/routes.php`.
- Se agrego `WarehouseController`.
- Se agregaron requests y resource para almacenes.
- Se agrego `app/Modules/Warehouses/routes.php`.
- Se agregaron `BranchPolicy` y `WarehousePolicy`.
- Se agregaron permisos `branches.*` y `warehouses.*`.
- Se expusieron endpoints para listar, crear, ver, actualizar y desactivar sucursales y almacenes.
- Se valido `code` unico por tenant en sucursales y almacenes.
- Se valido que `branch_id` de almacenes pertenezca al tenant actual.
- La eliminacion por API desactiva usando `status = inactive`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Locations/BranchWarehouseApiTest.php`.
- Resultado: 5 pruebas pasaron, 33 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 49 pruebas pasaron, 178 assertions.

### Notas de seguridad

- Todos los endpoints usan `auth` y `tenant`.
- Las APIs usan policies para validar permisos y pertenencia al tenant actual.
- Los listados no mezclan datos entre empresas.
- Un almacen no puede apuntar a una sucursal de otra empresa.

## 2026-07-02 - API de productos

### Implementado

- Se agrego `ProductController`.
- Se agregaron requests para crear y actualizar productos.
- Se agrego `ProductResource`.
- Se agrego `app/Modules/Products/routes.php`.
- Se expusieron endpoints para listar, crear, ver, actualizar y desactivar productos.
- Se valido `sku` unico por tenant.
- Se valido `tracking_type` con soporte para `quantity` y `serialized`.
- Se bloqueo el cambio de `tracking_type` cuando el producto ya tiene unidades serializadas.
- La eliminacion por API desactiva el producto con `is_active = false`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 6 pruebas pasaron, 23 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 44 pruebas pasaron, 145 assertions.

### Notas de seguridad

- Todos los endpoints usan `auth` y `tenant`.
- La API usa `ProductPolicy` para validar permisos y pertenencia al tenant actual.
- El listado de productos no mezcla datos entre empresas.
- Los productos serializados quedan preparados para asociar IMEIs o seriales en `product_units`.
- No se permite perder trazabilidad cambiando a cantidad un producto que ya tiene IMEIs o seriales registrados.

## 2026-07-02 - Base para productos serializados e IMEI

### Implementado

- Se agrego `tracking_type` a productos para distinguir productos por cantidad y productos serializados.
- Se agrego la tabla `product_units` para IMEI, seriales u otros identificadores unicos por unidad fisica.
- Se agrego el modelo `ProductUnit`.
- Se agrego relacion `Product::units()`.
- Se agrego una clave unica compuesta `tenant_id + id` en `stock_movements` para permitir referencias seguras desde unidades serializadas.
- Se documento que `Samsung A06` es el producto y cada IMEI es una unidad asociada.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/SerializedProductUnitTest.php`.
- Resultado: 4 pruebas pasaron, 8 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 38 pruebas pasaron, 122 assertions.

### Notas de seguridad

- Los seriales son unicos por tenant y tipo de serial.
- Las unidades serializadas usan `tenant_id` y no pueden apuntar a productos o almacenes de otra empresa.
- Las unidades serializadas tampoco pueden apuntar a movimientos de stock de otra empresa.
- Esta base aplica a telefonos con IMEI y a otros productos con serial unico.

## 2026-07-02 - Organizacion modular y catalogo de APIs

### Implementado

- Se agrego `docs/MODULES.md` como mapa modular del proyecto.
- Se agrego `docs/API.md` como catalogo de APIs actuales, clasificado por seccion.
- Se movieron las rutas de inventario a `app/Modules/Inventory/routes.php`.
- Se movieron las rutas de reportes a `app/Modules/Reports/routes.php`.
- `routes/api.php` quedo como cargador de rutas modulares con middleware `auth` y `tenant`.
- Se actualizo `docs/ARCHITECTURE.md` para apuntar a la estructura modular actual.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryApiTest.php tests/Feature/Reports/InventoryReportApiTest.php`.
- Resultado: 8 pruebas pasaron, 33 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 34 pruebas pasaron, 114 assertions.

### Notas de seguridad

- Separar rutas por modulo ayuda a ubicar fallos o mejoras sin mezclar responsabilidades.
- Los middleware `auth` y `tenant` siguen aplicandose desde `routes/api.php`.
- Las APIs futuras, como POS, deben tener su propio archivo `app/Modules/POS/routes.php`.

## 2026-07-02 - Reportes iniciales de inventario

### Implementado

- Se agrego `InventoryReportController`.
- Se agregaron requests de reportes de stock y movimientos.
- Se agregaron resources para respuestas de stock y movimientos.
- Se agregaron endpoints de stock actual, bajo stock y movimientos.
- Se agregaron filtros por almacen, producto, tipo de movimiento y fechas.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Reports/InventoryReportApiTest.php`.
- Resultado: 4 pruebas pasaron, 18 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 34 pruebas pasaron, 114 assertions.

### Notas de seguridad

- Los reportes requieren `reports.view`.
- Se probaron varias empresas para confirmar que los reportes no mezclan stock ni movimientos.
- Los filtros de producto y almacen se validan contra el tenant actual.
- Los reportes consultan modelos tenant-scoped.

## 2026-07-02 - Auditoria de movimientos de inventario

### Implementado

- Se agrego la tabla `audit_logs`.
- Se agrego el modelo `AuditLog` con aislamiento por tenant.
- Se agrego `AuditLogger`.
- Se integro auditoria en `InventoryMovementService`.
- Cada movimiento de inventario crea un audit log con accion `inventory.movement.created`.
- Los movimientos creados por API registran usuario, IP y user agent cuando existen.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Audit/InventoryAuditTest.php`.
- Resultado: 2 pruebas pasaron, 20 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 30 pruebas pasaron, 96 assertions.

### Notas de seguridad

- Los audit logs tienen `tenant_id` y usan el mismo aislamiento que el resto de datos de negocio.
- Se probaron varias empresas para confirmar que productos, balances y logs no se mezclan.
- La auditoria se registra desde el servicio de inventario, no desde el controlador, para cubrir API y futuros jobs/IA.

## 2026-07-02 - Decision de moneda para Venezuela

### Implementado

- Se documento que el inventario usara `USD` como moneda base interna.
- Se documento que los productos podran venderse en `USD` o `VES`.
- Se dejo definido que las operaciones monetarias futuras deben guardar moneda original y tasa usada.

### Pruebas

- No aplica ejecucion de tests automatizados porque este cambio solo documenta una decision de arquitectura.

### Notas de seguridad

- No se deben recalcular costos historicos usando la tasa nueva del dia.
- Cada compra, venta o movimiento monetario debe conservar la tasa usada en el momento de la operacion.
- La tasa del dia se usara para equivalencias y reportes, no para modificar la historia.

## 2026-07-02 - API inicial de inventario

### Implementado

- Se agrego `routes/api.php`.
- Se registro el archivo API en `bootstrap/app.php`.
- Se agrego `InventoryMovementController`.
- Se agregaron requests para movimientos y transferencias de inventario.
- Se agrego `StockMovementResource`.
- Se expusieron endpoints para compras, ventas, ajustes, reservas, liberaciones, danados y transferencias.
- Todos los endpoints usan `auth`, `tenant` y `AuthorizedInventoryMovementService`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryApiTest.php`.
- Resultado: 4 pruebas pasaron, 15 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 28 pruebas pasaron, 76 assertions.

### Notas de seguridad

- Los endpoints no llaman directamente a `InventoryMovementService`.
- Los recursos enviados en la peticion se validan contra el tenant actual.
- Si un producto o almacen pertenece a otro tenant, la validacion responde `422`.
- Si el usuario no tiene permisos, la respuesta es `403`.

## 2026-07-02 - Autorizacion de operaciones de inventario

### Implementado

- Se agrego `InventoryPolicy` para validar permisos y pertenencia al tenant en operaciones de inventario.
- Se registraron Gates internos para operaciones de inventario.
- Se agrego `AuthorizedInventoryMovementService` para que controladores, jobs e IA autoricen antes de mover inventario.
- Se separaron los nombres de abilities internos de los nombres de permisos Spatie usando el sufijo `-operation`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryAuthorizationTest.php`.
- Resultado: 5 pruebas pasaron, 16 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 24 pruebas pasaron, 61 assertions.

### Notas de seguridad

- No se deben usar directamente abilities con el mismo nombre que permisos Spatie cuando tambien hay que validar modelos o tenant.
- `inventory.adjust-operation` revisa el permiso `inventory.adjust`, pero ademas valida almacen/producto del tenant actual.
- `inventory.transfer-operation` revisa el permiso `inventory.transfer`, pero ademas valida almacen origen, almacen destino y producto.
- La IA y los endpoints futuros deben usar `AuthorizedInventoryMovementService`, no llamar directamente a `InventoryMovementService`.

## 2026-07-02 - Fase 1: base del sistema

### Implementado

- Se creó la base del proyecto Laravel 13.
- Se agregó soporte Docker para la aplicación Laravel y PostgreSQL.
- Se agregó la estructura modular base bajo `app/Modules`.
- Se agregó el módulo `Tenancy` con modelo de tenant, middleware y provider.
- Se agregó `TenantManager` como servicio scoped para guardar el tenant actual durante la petición.
- Se agregaron `BelongsToTenant` y `TenantScope` para automatizar el filtrado por tenant y la asignación de `tenant_id`.
- Se agregaron las migraciones `tenants` y `tenant_user`.
- Se agregó una tabla inicial `products` tenant-scoped para validar el patrón antes de construir el inventario completo.
- Se instaló Spatie Laravel Permission.
- Se configuró Spatie con teams usando `tenant_id` como clave de tenant.
- Se agregaron permisos y roles base.
- Se agregaron pruebas de aislamiento multitenant.

### Pruebas

- Se ejecutó `php artisan test`.
- Resultado: 5 pruebas pasaron.

### Notas de seguridad

- Todo dato de negocio tenant-owned debe usar `BelongsToTenant`.
- Los registros tenant-owned deben fallar rápido si se crean sin tenant actual.
- La unicidad de negocio debe estar limitada por tenant, por ejemplo `tenant_id + sku`.
- La IA debe permanecer fuera del core de inventario y no puede saltarse permisos, validaciones, policies ni auditoría.

## 2026-07-02 - Policies tenant-aware para productos

### Implementado

- Se agregó `ProductPolicy` como primer patrón de policy tenant-aware.
- Se registró la policy de productos en `AppServiceProvider`.
- Se agregó `User::belongsToTenant()` para centralizar la validación de membresía activa por tenant.
- Se reforzó que el acceso a productos requiere permiso granular y pertenencia al tenant actual.

### Pruebas

- Se ejecutó `php artisan test tests/Feature/Permissions/ProductPolicyTest.php`.
- Resultado: 4 pruebas pasaron, 9 assertions.

### Notas de seguridad

- Un rol o permiso válido en un tenant nunca debe otorgar acceso en otro tenant.
- Las policies deben proteger incluso si un recurso fue cargado sin global scopes o ya existe en memoria.
- El backend sigue siendo la autoridad de permisos; las futuras acciones de IA deben pasar por las mismas policies.

## 2026-07-02 - Regla de documentación en español

### Implementado

- Se tradujo la documentación existente a español.
- Se dejó establecido que toda documentación futura debe escribirse en español.
- Se corrigió el árbol de carpetas modular para usar caracteres ASCII legibles.

### Pruebas

- No aplica ejecución de tests automatizados porque este cambio solo afecta documentación.

### Notas de seguridad

- Mantener la documentación en un solo idioma reduce errores de interpretación en decisiones de arquitectura, permisos y multitenancy.

## 2026-07-02 - Base de inventario por movimientos

### Implementado

- Se agregaron las migraciones `branches`, `warehouses`, `stock_movements` y `stock_balances`.
- Se agregaron los modelos `Branch`, `Warehouse`, `StockMovement` y `StockBalance`.
- Se aplicó `BelongsToTenant` a todos los modelos nuevos de negocio.
- Se agregó una clave única compuesta `tenant_id + id` en `products` para permitir referencias seguras desde inventario.
- Se agregaron claves foráneas compuestas para impedir referencias cruzadas entre tenants.
- Se mantuvo el principio de inventario basado en movimientos: `stock_movements` es la verdad histórica y `stock_balances` es una lectura rápida.

### Pruebas

- Se ejecutó `php artisan test tests/Feature/Inventory/InventorySchemaIsolationTest.php`.
- Resultado: 4 pruebas pasaron, 12 assertions.

### Notas de seguridad

- Un almacén no puede apuntar a una sucursal de otro tenant.
- Un movimiento o balance de stock no puede apuntar a productos o almacenes de otro tenant.
- Los códigos de sucursal y almacén son únicos por tenant, no globales.
- El stock no se guarda en productos; eso evita inconsistencias futuras cuando existan varios almacenes.

## 2026-07-02 - Pruebas con PostgreSQL

### Implementado

- Se cambió `phpunit.xml` para que PHPUnit use PostgreSQL en lugar de SQLite.
- Se agregó el servicio `postgres_test` en `docker-compose.yml`.
- Se agregó el servicio `app_test` para ejecutar PHPUnit contra `postgres_test`.
- Se configuró la base `inventory_arens_testing` para pruebas automatizadas.
- Se agregaron healthchecks a PostgreSQL para que los servicios esperen a que la base esté lista.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test`.
- Resultado: 13 pruebas pasaron, 27 assertions.

### Notas de seguridad

- SQLite no debe usarse como fuente principal de confianza para este proyecto.
- PostgreSQL es obligatorio para validar claves foráneas compuestas, decimales e integridad multitenant como se comportarán en producción.

## 2026-07-02 - Servicio de movimientos de inventario

### Implementado

- Se agregó `InventoryMovementService` para centralizar operaciones de inventario.
- Se implementaron entradas por compra, ventas, ajustes positivos, ajustes negativos, reservas, liberaciones, dañados y transferencias.
- Se agregaron excepciones específicas para cantidad inválida, stock insuficiente y referencias cruzadas entre tenants.
- Cada operación crea registros en `stock_movements`.
- Cada operación actualiza `stock_balances` dentro de una transacción.
- Las transferencias crean dos movimientos: `transfer_out` y `transfer_in`.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryMovementServiceTest.php`.
- Resultado: 6 pruebas pasaron, 18 assertions.
- Se ejecutó la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 19 pruebas pasaron, 45 assertions.

### Notas de seguridad

- El servicio rechaza modelos que no pertenezcan al tenant actual antes de escribir en base de datos.
- Las salidas no pueden dejar stock disponible negativo.
- Las liberaciones no pueden dejar stock reservado negativo.
- Las operaciones críticas de inventario quedan preparadas para integrarse con permisos, policies y auditoría.
## 2026-07-05 - Worker continuo de sincronizacion

### Implementado

- Se agrego el comando `php artisan sync:daemon` para ejecutar sincronizacion local-nube por ciclos.
- El comando soporta `--interval`, `--cycles` y `--once` para operacion continua o pruebas controladas.
- Se mantuvieron las mismas opciones de `sync:run`: nodo, nombre, URL nube, token, limite, push-only, pull-only y no-apply.
- Se actualizo la documentacion del modulo Sync y el asistente smoke local-nube.

### Pruebas

- Se agrego prueba especifica para `sync:daemon --once`.
- Se valida que un ciclo controlado registre nodo, suba eventos locales y marque el outbox como procesado.
## 2026-07-05 - Controlador Windows para worker Sync

### Implementado

- Se agrego `scripts/sync-worker.ps1` para iniciar, detener y consultar el worker continuo de sincronizacion.
- Se agrego `scripts/sync-worker.cmd` para ejecutar el controlador sin depender de la politica global de PowerShell.
- El controlador guarda PID en `storage/app/sync-worker`, logs en `storage/logs/sync-worker.log` y evita abrir workers duplicados.
- Se agrego opcion `-Cycles` para pruebas controladas y ejecuciones temporales.
- El token de nube se entrega por `.env`, parametro o variable temporal del proceso, sin guardarlo en el `.cmd` generado.
- Se documento la operacion en `docs/SYNC_WORKER_WINDOWS_2026-07-05.md`.

### Pruebas

- Se valido la sintaxis de `scripts/sync-worker.ps1`.
- Se ejecuto `scripts/sync-worker.cmd status`.
- Se ejecuto `php artisan list sync`.
- Se ejecuto `tests/Feature/Sync` con PostgreSQL local.
## 2026-07-05 - Control visual WPF para Sync

Contexto:

- El worker local-nube ya podia ejecutarse por consola, pero necesitaba una forma visual para iniciar, detener y consultar estado desde la aplicacion de escritorio.
- El flujo debe seguir organizado por modulos, igual que POS, Caja y Centro de Inventario.

Implementacion:

- Se agrego el modulo visual `Sincronizacion` en el centro de modulos WPF.
- Se creo `SyncWorkerView` para mostrar configuracion local, estado del worker y ultimos eventos del log.
- Se creo `SyncWorkerViewModel` para ejecutar `scripts/sync-worker.cmd start|stop|status` desde la app.
- La pantalla permite configurar empresa, nodo, nombre, URL nube, token e intervalo.
- El estado y los errores quedan visibles en la pantalla sin cerrar la aplicacion.

Documentacion:

- Se creo `docs/SYNC_CONTROL_WPF_2026-07-05.md`.
- Se actualizo `docs/MODULES.md` con los archivos y comandos del control visual Sync.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Se ejecuto `artisan test tests/Feature/Sync` con PostgreSQL local.
- Se ejecuto `scripts/sync-worker.cmd status`.
## 2026-07-06 - Correccion visual y ejecucion del modulo Sync WPF

Contexto:

- La pantalla de Sincronizacion mostraba el error completo de consola porque WPF invocaba `sync-worker.cmd` con una forma que `cmd.exe` interpretaba mal.
- La configuracion del worker ocupaba demasiada altura y algunos controles quedaban poco claros en ventanas normales.

Implementacion:

- Se corrigio la ejecucion de `scripts/sync-worker.cmd` desde WPF usando `cmd.exe /d /c` con comillas compatibles con rutas de Windows.
- Se agregaron mensajes amigables para errores comunes de ejecucion, Laravel o red.
- Se compacto `SyncWorkerView`: botones de inicio/detencion siempre visibles, opciones avanzadas plegadas y log con mejor espacio.
- Se mantiene el log tecnico disponible sin llenar la pantalla principal con texto crudo.

Pruebas:

- Se valido el comando real `sync-worker.cmd status` con tenant `demo-valencia`.
- Se ejecuto `artisan test tests/Feature/Sync` con PostgreSQL local.
- Se compilo WPF en salida temporal porque el ejecutable principal estaba abierto y bloqueado por Windows.
## 2026-07-06 - Outbox de sincronizacion para inventario y precios

Contexto:

- La sincronizacion local-nube ya tenia transporte, inbox, outbox y worker.
- Faltaba que las operaciones reales de catalogo e inventario generaran eventos automaticamente para ser subidas a la nube.

Implementacion:

- Se creo `SyncCatalogOutboxService` para centralizar los payloads de sincronizacion del catalogo.
- La creacion, edicion y desactivacion de productos ahora registra eventos `product.created` y `product.updated`.
- La creacion, edicion y desactivacion de listas de precio ahora registra eventos `price_list.created` y `price_list.updated`.
- La creacion y edicion de precios por producto/lista ahora registra eventos `product_price.created` y `product_price.updated`.
- Las entradas y salidas de inventario ahora registran eventos `product_entry.created` y `product_exit.created`.
- Los eventos usan claves de negocio como `sku`, `price_list_code` y `warehouse_code` para no depender de IDs locales.

Documentacion:

- Se creo `docs/SYNC_OUTBOX_INVENTARIO_PRECIOS_2026-07-06.md`.
- Se actualizo `docs/MODULES.md` con el nuevo servicio y reglas de eventos.

Pruebas:

- Se ejecuto la suite especifica de productos, entradas, salidas y sync con PostgreSQL local.
- Resultado: 41 pruebas pasadas y 244 aserciones.
## 2026-07-06 - Sincronizacion manual desde WPF

Contexto:

- El modulo de Sincronizacion permitia iniciar, detener y consultar el worker, pero faltaba una accion directa para ejecutar un ciclo puntual y ver el resumen sin depender de consola.

Implementacion:

- Se agrego la accion `run` a `scripts/sync-worker.ps1` y `scripts/sync-worker.cmd` para ejecutar un ciclo inmediato con `sync:run`.
- Se agrego el boton `Sincronizar ahora` en la pantalla WPF de Sincronizacion.
- WPF resume el resultado del ciclo manual mostrando eventos subidos, bajados, aplicados, ignorados y fallos.
- Se limpiaron los errores del script para mostrar mensajes simples en espa�ol cuando falte `SYNC_CLOUD_URL` o `SYNC_CLOUD_TOKEN`.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Se ejecuto `scripts/sync-worker.cmd status`.
- Se valido `scripts/sync-worker.cmd run` sin configuracion nube y ahora devuelve un error limpio de configuracion.
- Se ejecuto `artisan test tests/Feature/Sync` con PostgreSQL local.
## 2026-07-06 - Estado local de eventos Sync en WPF

Contexto:

- El modulo visual de Sincronizacion mostraba el estado del worker y el log, pero faltaba una lectura directa del backend para saber cuantos eventos habia realmente en `sync_outbox` y `sync_inbox`.

Implementacion:

- Se extendio `GET /api/sync/status` para incluir `latest_events` con los ultimos eventos locales de salida y entrada.
- Se agrego en WPF el panel `Estado local de eventos` con nodos registrados, conteos de outbox/inbox y ultimos eventos.
- El panel se actualiza al consultar estado, iniciar, detener o ejecutar `Sincronizar ahora`.
- Se mantiene el log tecnico separado para no mezclar diagnostico operativo con salida de consola.

Documentacion:

- Se actualizo `docs/SYNC_CONTROL_WPF_2026-07-05.md`.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Se ejecuto `artisan test tests/Feature/Sync`.
- Resultado: 12 pruebas pasadas y 69 aserciones.
## 2026-07-06 - Sincronizacion operativa por empresa en el centro de modulos

Contexto:

- La sincronizacion estaba visible como un modulo tecnico, pero para el usuario final debe funcionar como estado operativo por empresa.
- La misma computadora puede abrir varias empresas o sedes, por lo que el estado debe entenderse como instalacion local + empresa seleccionada.

Implementacion:

- Se oculto la tarjeta principal de Sincronizacion del centro de modulos.
- Se agrego un semaforo de sincronizacion en el encabezado del centro de modulos.
- Se agrego el boton `Sincronizar ahora` para ejecutar un ciclo manual sin entrar al modulo tecnico.
- El estado visible se resume como sincronizado, activo, detenido, error, no configurado o sin consultar.
- La pantalla tecnica de Sync queda disponible internamente para soporte, pero ya no es el flujo principal del operador.

Documentacion:

- Se creo `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md` con fases, criterios y pruebas.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Se ejecuto `artisan test tests/Feature/Sync`.
- Resultado: 12 pruebas pasadas y 69 aserciones.
## 2026-07-06 - Sincronizacion por instalacion local y empresa

Contexto:

- La sincronizacion no debe bloquear globalmente a una empresa ni depender solo del tenant.
- Una misma empresa puede abrirse en varias computadoras y una misma computadora puede abrir varias empresas.
- El operador debe ver un estado simple de sincronizacion, no detalles tecnicos de outbox, inbox o nodos.

Implementacion:

- Se creo la tabla `sync_tenant_readiness` para guardar el estado por empresa e instalacion local.
- Se agrego `SyncReadinessService` para marcar estados `pending`, `syncing`, `ready`, `warning` y `error`.
- Se agregaron las APIs `GET /api/sync/local-readiness` y `POST /api/sync/local-readiness`.
- El worker `sync:run` y `sync:daemon` ahora aceptan `--installation` y actualizan readiness al iniciar, completar o fallar.
- El script `scripts/sync-worker.ps1` propaga `InstallationCode` hacia Artisan.
- WPF genera un codigo estable por computadora y empresa activa, consulta readiness y muestra el semaforo operativo.
- El semaforo reconoce estados pendientes, sincronizando, advertencia, error y sincronizado.

Documentacion:

- Se actualizo `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`.
- Se actualizo `docs/MODULES.md`.

Pruebas:

- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 11 pruebas pasadas y 80 aserciones.
- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilacion correcta, 0 errores y 0 advertencias.
## 2026-07-06 - Sincronizacion inicial guiada en centro de modulos

Contexto:

- El usuario final no debe entrar a una pantalla tecnica para entender si la empresa esta lista en su computadora.
- La sincronizacion debe ser por empresa e instalacion local, sin bloquear otras computadoras que usen la misma empresa.

Implementacion:

- Se agrego un aviso operativo en el centro de modulos cuando la empresa esta pendiente, sincronizando, con advertencia o con error en esta instalacion.
- El aviso incluye la accion `Sincronizar empresa`, que ejecuta el ciclo manual del worker para la empresa activa.
- Cuando el estado queda `ready`, el aviso se oculta y solo queda el semaforo de sincronizacion.
- Los mensajes aclaran que la preparacion ocurre en esta computadora, no de forma global para toda la empresa.

Documentacion:

- Se actualizo `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`.

Pruebas:

- Pendiente de compilar WPF y ejecutar pruebas especificas de Sync despues del ajuste visual.
## 2026-07-06 - Confirmacion automatica de sincronizacion inicial

Contexto:

- La app ya mostraba un aviso en el centro de modulos cuando una empresa no estaba lista en una computadora, pero el usuario tenia que decidir manualmente cuando sincronizar.

Implementacion:

- Al abrir el panel principal, WPF consulta el estado de sincronizacion de la empresa en esta instalacion local.
- Si el estado esta pendiente, sin consultar, no configurado o en error, se muestra una confirmacion para sincronizar ahora.
- Si el usuario acepta, se ejecuta el ciclo manual del worker para esa empresa.
- Si el usuario no acepta, el aviso operativo queda visible y puede sincronizar despues.
- El flujo sigue siendo por empresa e instalacion local; no bloquea otras computadoras.

Documentacion:

- Se actualizo `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilacion correcta, 0 errores y 0 advertencias.
- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 11 pruebas pasadas y 80 aserciones.
## 2026-07-06 - Ventana de progreso y reinicio seguro de readiness local

Contexto:

- Para probar la primera apertura local era necesario limpiar el estado `sync_tenant_readiness` sin tocar productos, ventas, cajas ni eventos reales.
- La sincronizacion inicial necesitaba una ventana de progreso mas clara para el operador.

Implementacion:

- Se agrego `SyncProgressWindow` en WPF para mostrar progreso mientras se prepara la empresa en esta computadora.
- El boton `Sincronizar empresa` y la confirmacion inicial abren esta ventana durante el ciclo manual del worker.
- Se agrego el comando `php artisan sync:reset-readiness` para reiniciar solo el estado local de preparacion.
- El comando permite limpiar por empresa, por instalacion o todo con `--all`.
- El comando tolera que la tabla aun no exista y muestra un mensaje claro en espanol.
- Se aplico la migracion local `2026_07_06_130000_create_sync_tenant_readiness_table`.
- Se ejecuto `sync:reset-readiness --all`; no habia registros previos, por eso elimino 0.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilacion correcta, 0 errores y 0 advertencias.
- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 12 pruebas pasadas y 85 aserciones.
## 2026-07-06 - Correccion de falso positivo en ventana de sincronizacion

Contexto:

- Al presionar `Sincronizar empresa`, la ventana de progreso podia decir `Sincronizacion completada` aunque el backend todavia devolviera la empresa como pendiente en esta computadora.
- Esto confundia porque el centro de modulos seguia mostrando `Sincronizacion pendiente`, que era el estado real.

Implementacion:

- WPF ahora valida el estado real despues de ejecutar el worker y refrescar `sync/local-readiness`.
- La ventana solo muestra `Sincronizacion completada` cuando el estado queda `Sincronizado` o `Activo`.
- Si el estado queda pendiente, error, advertencia o sin consultar, la ventana muestra `Sincronizacion pendiente` con el mensaje del worker o el detalle del backend.
- Se evito el falso positivo visual sin cambiar la logica de sincronizacion ni los datos.

Pruebas:

- Se compilo WPF con `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilacion correcta, 0 errores y 0 advertencias.
- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 12 pruebas pasadas y 85 aserciones.
## 2026-07-06 - Preparacion operativa de API nube para Sync

Contexto:

- El worker local necesita una API Laravel nube para subir y bajar eventos de sincronizacion.
- Se comprobo que el VPS responde con una aplicacion Express existente, por lo que `/api/sync/status` no pertenece a Laravel en ese servidor.
- No se pudo abrir una sesion root interactiva desde Codex por seguridad; se preparo una alternativa repetible con scripts para ejecutar directamente en el VPS y en la PC local.

Implementacion:

- Se agrego `php artisan sync:issue-token` para emitir tokens Bearer de sincronizacion por empresa y usuario.
- Se agrego `scripts/cloud-api-bootstrap-vps.sh` para preparar la API Laravel nube en el VPS usando PostgreSQL local y puerto 8010.
- Se agrego `scripts/configure-sync-cloud-local.ps1` para guardar `SYNC_CLOUD_URL` y `SYNC_CLOUD_TOKEN` en el `.env` local y ejecutar una prueba opcional.
- Se documento que el worker no debe escribir directo contra PostgreSQL remoto; debe comunicarse con la API Laravel nube.

Documentacion:

- Se creo `docs/SYNC_CONFIGURACION_NUBE_OPERATIVA_2026-07-06.md`.

Pruebas:

- Pendiente de ejecutar suite especifica despues de agregar el comando y scripts.
Verificacion posterior:

- `php artisan list sync` muestra `sync:issue-token` junto a los comandos existentes.
- `scripts/configure-sync-cloud-local.ps1` paso validacion sintactica de PowerShell.
- `scripts/cloud-api-bootstrap-vps.sh` no pudo validarse localmente con `bash -n` porque Windows/WSL no tiene distribucion instalada en esta maquina.
- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 13 pruebas pasadas y 90 aserciones.
## 2026-07-06 - Correccion de seeder de permisos en nube limpia

Contexto:

- Al ejecutar `scripts/cloud-api-bootstrap-vps.sh` en el VPS, Laravel instalo dependencias, genero `APP_KEY`, ejecuto migraciones y fallo durante `db:seed`.
- El error fue `There is no permission named sales_returns.view for guard web`.
- La causa era que el seeder de roles asignaba permisos por nombre dentro del contexto multiempresa; en una base nube limpia el catalogo podia no estar resuelto aun para Spatie Permissions.

Implementacion:

- Se robustecio `RolesAndPermissionsSeeder` para garantizar cada permiso antes de sincronizar cada rol.
- La asignacion de roles ahora usa modelos `Permission` consultados desde la base de datos, evitando fallos por resolucion de nombres en contexto de tenant.
- No se cambiaron los nombres de permisos ni los roles existentes.

Pruebas:

- Se reinicio solo la base local de pruebas con PostgreSQL porque habia quedado inconsistente por ejecuciones paralelas.
- Se ejecuto `artisan test tests/Feature/AccessControl/AccessControlApiTest.php --filter=roles_and_permissions_seeder`.
- Resultado: 1 prueba pasada y 3 aserciones.
- Se ejecuto `artisan test tests/Feature/Sync/SyncSchemaTest.php tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 13 pruebas pasadas y 90 aserciones.

## 2026-07-06 - Carga demo opcional para API nube

Contexto:

- En una base de nube limpia, `php artisan sync:issue-token demo-valencia gerente.valencia@demo.test` puede responder `Empresa no encontrada`.
- La razon es que `DatabaseSeeder` solo prepara datos base del sistema, pero no carga empresas demo, productos, almacenes ni cajas de prueba.
- Para produccion no conviene cargar datos demo automaticamente, pero para pruebas operativas si debe existir una forma clara de hacerlo.

Implementacion:

- `scripts/cloud-api-bootstrap-vps.sh` ahora acepta `CLOUD_SEED_DEMO=1`.
- Cuando esa bandera esta activa, el bootstrap ejecuta `DemoDataSeeder` y `MultiCompanyLoginDemoSeeder` despues del seeder base.
- La guia operativa de nube documenta como instalar sin demos, como instalar con demos y como cargar solo los datos demo si la nube ya estaba migrada.
- La documentacion ahora incluye ejemplos de token para `demo-valencia`, `demo-valencia-centro` y `demo-valencia-norte`.

Pruebas:

- Pendiente de ejecutar pruebas especificas despues de actualizar el bootstrap y la documentacion.

Verificacion posterior de carga demo opcional:

- Se ajusto `DemoDataSeeder` para crear/reusar una caja fisica demo por sucursal antes de abrir el turno POS.
- Esto evita que el POS demo falle con la regla actual: la venta requiere caja fisica abierta desde el modulo Caja.
- Se ejecuto `artisan test tests/Feature/Seeders/DemoDataSeederTest.php tests/Feature/Seeders/MultiCompanyLoginDemoSeederTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 8 pruebas pasadas y 132 aserciones.

## 2026-07-06 - Aplicacion inmediata de eventos locales en la nube

Contexto:

- Se detecto que un cambio de precio hecho correctamente desde el sistema local podia quedar como enviado por el worker, pero no reflejarse todavia en la tabla `products` del PostgreSQL del VPS.
- La causa era que la API nube guardaba los eventos entrantes en `sync_inbox`, pero no los aplicaba en ese mismo ciclo.
- Tambien se aclaro que los cambios hechos directamente en HeidiSQL no pasan por Laravel y por tanto no generan eventos `sync_outbox`.

Implementacion:

- `SyncTransportService::pushEvents` ahora aplica inmediatamente los eventos recibidos usando `SyncEventApplier`.
- Cada evento local recibido se replica en el `sync_outbox` de la nube con `origin_node_id`, para que otras computadoras puedan bajarlo sin reenviarlo al nodo que lo origino.
- La respuesta de `POST /api/sync/events/push` ahora incluye contadores `applied`, `ignored` y `failed`.
- Se documento que los seeders son solo para pruebas; el arranque real de una base local vacia debe resolverse con descarga inicial desde nube.

Pruebas:

- Se ejecuto `artisan test tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 12 pruebas pasadas y 85 aserciones.
- Se ejecuto `artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 17 pruebas pasadas y 107 aserciones.
- Ajuste posterior: los eventos recibidos solo se retransmiten desde la nube si quedaron `applied` o `ignored`; si fallan, no se publican a otros nodos.
- Verificacion adicional: `artisan test tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncWorkerCommandTest.php tests/Feature/Products/ProductApiTest.php`.
- Resultado: 29 pruebas pasadas y 192 aserciones.

## 2026-07-06 - Correccion de cola recibida en nube para cambios de precio

Contexto:

- Se valido que el cambio local de `Adaptador Bluetooth` a USD 2000 si generaba evento `product.updated` y quedaba como `processed` en el `sync_outbox` local.
- En la nube el evento llegaba a `sync_inbox`, pero quedaba en estado `received` y por eso el precio no cambiaba en PostgreSQL del VPS.
- La causa era que la nube intentaba aplicar los primeros eventos pendientes de `sync_inbox` por orden de llegada. Si habia eventos antiguos no aplicados por delante, el evento nuevo de precio podia quedar esperando aunque el worker local ya lo hubiera enviado correctamente.

Implementacion:

- `SyncEventApplier` ahora permite aplicar eventos recibidos por `event_uuid`.
- `SyncTransportService::pushEvents` ya no aplica "los primeros N pendientes"; ahora aplica exactamente los UUID que acaba de recibir en ese `push`.
- Se agrego el comando `sync:apply-inbox` para procesar eventos antiguos que ya estaban recibidos en una base antes de esta correccion.

Comando operativo para el VPS:

```bash
php artisan sync:apply-inbox demo-valencia --limit=200
```

Este comando es util despues de hacer `pull` en el VPS cuando ya existian eventos `received` sin aplicar.

Pruebas:

- Se ejecuto `artisan test tests/Feature/Sync/SyncApiTest.php tests/Feature/Sync/SyncApplyInboxCommandTest.php tests/Feature/Sync/SyncWorkerCommandTest.php`.
- Resultado: 14 pruebas pasadas y 100 aserciones.

## 2026-07-06 - Configuracion local por empresa para sincronizacion automatica

Contexto:

- La sincronizacion automatica ya existia con `sync:daemon`, pero dependia de un token global en `.env` o de pasarlo manualmente.
- Al abrir otra empresa, podia aparecer `Falta Token` porque esa empresa no tenia credencial propia configurada.
- El worker tambien usaba un PID global, por lo que una empresa podia bloquear o confundir el estado de otra.

Implementacion:

- La pantalla de sincronizacion de escritorio ahora guarda configuracion local por empresa en `storage/app/sync-worker/sync-config.json`.
- Cada empresa puede tener URL nube, token, nodo, instalacion e intervalo propio.
- `Iniciar automatico` guarda la configuracion y arranca el worker continuo.
- `Sincronizar ahora` usa la configuracion guardada si el usuario no escribe token en pantalla.
- El script `scripts/sync-worker.ps1` separa PID, comando temporal y log por empresa.

Operacion:

- El intervalo automatico por defecto es de 30 segundos.
- El tecnico debe configurar una vez cada empresa en esa computadora.
- Si una empresa no tiene token propio, puede usar el token global del `.env` como respaldo, pero no es lo recomendado para multiempresa.

Pruebas:

- Se ejecuto `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore`.
- Resultado: compilacion correcta, 0 errores, 0 advertencias.
- Se ejecuto `scripts\\sync-worker.cmd status -TenantSlug demo-valencia`.
- Resultado: el controlador responde por empresa y apunta a `storage/logs/sync-worker-demo-valencia.log`.

## 2026-07-06 - Asistente tecnico para tokens de sincronizacion

Problema:

- La sincronizacion por empresa ya funcionaba, pero el token de nube se generaba desde el VPS con `php artisan sync:issue-token`.
- Ese flujo no es adecuado para clientes o tecnicos no programadores, porque obliga a entrar al servidor, copiar tokens manualmente y configurar archivos.

Implementacion:

- Se creo `SyncTokenService` para centralizar la emision de tokens de sincronizacion.
- El comando `sync:issue-token` ahora reutiliza ese servicio y queda como herramienta administrativa avanzada.
- Se agrego la API `POST /api/sync/tokens`, protegida por login y por empresa activa.
- La API devuelve el token una sola vez y guarda en base de datos solo el hash.
- Se agrego la ventana WPF `SyncSetupWizardWindow` como asistente tecnico.
- El asistente permite escribir URL nube, buscar empresas por correo, iniciar sesion, generar token y guardar URL, token, nodo e intervalo en la configuracion local por empresa.
- La pantalla de Sincronizacion ahora conserva acciones claras: asistente tecnico, sincronizar ahora, iniciar automatico y detener.

Documentacion:

- `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`
- `docs/SYNC_API_TRANSPORTE_2026-07-05.md`
- `docs/MODULES.md`

Pruebas:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncTokenApiTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 7 pruebas pasadas;
- 39 aserciones.

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

## 2026-07-06 - Redisenio visual del modulo Sincronizacion

Problema:

- La sincronizacion ya funcionaba, pero la pantalla seguia pareciendo una consola tecnica.
- Los campos de URL, token, nodo y logs tenian demasiado protagonismo para un usuario no programador.

Implementacion:

- Se reorganizo `SyncWorkerView` para mostrar primero el estado operativo con semaforo.
- Se agruparon las acciones rapidas: sincronizar ahora, iniciar automatico, detener y asistente tecnico.
- La configuracion manual quedo como seccion avanzada para soporte.
- Los conteos de eventos y el log tecnico quedaron como paneles de diagnostico.
- Se redisenio `SyncSetupWizardWindow` como asistente de instalacion: credenciales, empresa, equipo local y boton principal de configuracion.
- Se elimino el enfoque de copiar token manualmente para instalaciones normales.

Documentacion:

- `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`

Pruebas:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncTokenApiTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 7 pruebas pasadas;
- 39 aserciones.

## 2026-07-06 - Instalador externo de sincronizacion inicial

Problema:

- El asistente de sincronizacion dentro del programa principal no resuelve una instalacion totalmente vacia.
- Si la BD local no tiene empresa ni usuario, el usuario no puede iniciar sesion para abrir el modulo de sincronizacion.

Implementacion:

- Se agrego el comando `php artisan sync:prepare-local`.
- El comando crea o actualiza la empresa local, el usuario local y un rol `Administrador local` con permisos base.
- Se creo el ejecutable WPF independiente `desktop/InventorySyncInstaller`.
- El instalador externo consulta empresas en la nube, inicia sesion, genera token, corre migraciones, prepara empresa/usuario local, guarda la configuracion, ejecuta una primera sincronizacion e inicia el worker automatico.

Documentacion:

- `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`

Pruebas:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## 2026-07-06 - Redise�o del configurador externo de sincronizacion

Implementacion:

- Se redise�o `desktop/InventorySyncInstaller` como herramienta guiada para tecnicos.
- Se simplifico el lenguaje visible: servidor, correo administrador, empresa, computadora y frecuencia.
- Se agregaron pasos de progreso visibles y detalles tecnicos ocultos.
- Se agrego un boton final para abrir el Sistema de Inventario cuando la configuracion termina correctamente.

Pruebas pendientes de esta entrada:

- Compilacion WPF del instalador y del sistema de escritorio.
- Pruebas backend especificas de sincronizacion.

Verificacion posterior del redise�o:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop.slnx --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## 2026-07-06 - Mensajes claros de conexion en configurador externo

Implementacion:

- Se tradujeron los errores de conexion del instalador externo.
- Si el servidor de nube no responde, ahora se indica revisar URL, Laravel encendido y puerto abierto.
- Los detalles internos quedan disponibles solo en `Ver detalles tecnicos`.

Motivo:

- El error generico de Windows/.NET `An error occurred while sending the request` no era entendible para tecnicos ni usuarios finales.

Verificacion del ajuste de mensajes:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj --no-restore -o .build\inventory-sync-installer-build-check
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## 2026-07-06 - Correccion de bloqueo de log en sincronizacion inicial

Problema:

- Al reconfigurar una empresa, la sincronizacion inicial podia fallar si el archivo de log del worker estaba siendo usado por otro proceso.

Implementacion:

- El configurador externo detiene el worker anterior antes de ejecutar la primera sincronizacion.
- El script `scripts/sync-worker.ps1` ahora escribe el log de forma tolerante y no aborta la sincronizacion si el log esta bloqueado.

Pruebas:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj --no-restore -o .build\inventory-sync-installer-build-check
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## 2026-07-06 - Validacion de credenciales y worker de sincronizacion

Problemas detectados:

- El configurador consultaba empresas por correo antes de validar la contrasena. Por eso, si se escribia una clave incorrecta, aun se mostraban empresas candidatas.
- Despues de limpiar la base local, quedo evidencia de un worker anterior intentando sincronizar con un `tenant_id` viejo. Eso generaba errores de llave foranea y evitaba descargar/aplicar datos.
- La configuracion local solo tenia registrada la empresa `demo-valencia`; las demas empresas deben configurarse de forma individual o se debe ampliar el instalador a modo multiseleccion en una fase posterior.

Implementacion:

- El configurador ahora exige contrasena para buscar empresas.
- Antes de mostrar empresas, valida correo y contrasena intentando login contra cada empresa candidata.
- Si la clave es incorrecta, no muestra empresas y devuelve un mensaje claro.
- El worker automatico ahora se inicia apuntando el PID al proceso real de PHP, no a un `cmd` intermedio. Esto evita que queden procesos hijos fuera de control cuando se detiene la sincronizacion.

Notas operativas:

- En una base local limpia, primero debe existir la empresa local preparada por el configurador.
- Si el servidor de nube no esta activo en la URL configurada, la sincronizacion no puede bajar productos aunque la base local este correcta.

Verificacion:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj --no-restore -o .build\inventory-sync-installer-build-check
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## 2026-07-06 - Foto inicial de catalogo para instalaciones locales

Problema:

- Al limpiar la base local y configurar una empresa desde la nube, se creaban las identidades de empresa, pero no bajaban productos, almacenes, cajas, tasas, precios ni seriales.
- La sincronizacion existente era incremental: solo bajaba eventos creados despues. Si la nube ya tenia catalogo antes de configurar el local, ese catalogo no tenia eventos pendientes para el nuevo nodo.

Implementacion:

- Se agrego `SyncInitialSnapshotService` para preparar eventos iniciales dirigidos a un nodo local especifico.
- Al registrar un nodo, la nube puede recibir `metadata.initial_snapshot=true` y genera la foto inicial del catalogo para esa instalacion.
- El worker local ahora solicita esa foto si detecta que la empresa local no tiene catalogo base o si la sincronizacion inicial no esta completa.
- El aplicador de eventos ahora puede crear/actualizar sucursales, almacenes, movimientos de stock, seriales/IMEI y cajas fisicas ademas de productos, precios y tasas.
- Los movimientos de stock de la foto inicial quedan con `reference_type=sync_snapshot` para diferenciar sincronizacion inicial de operaciones manuales.
- Se creo `docs/SYNC_FOTO_INICIAL_CATALOGO_2026-07-06.md`.

Pruebas:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncApiTest.php tests\Feature\Sync\SyncEventApplierTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 18 pruebas pasadas;
- 119 aserciones.

## 2026-07-07 - Dominio HTTPS para API nube

Objetivo:

- Usar `https://app.miinventariofacil.com/api` como URL operativa de la API nube.
- Dejar de depender de IP y puerto visible para configuracion local y sincronizacion.

Implementacion:

- Se configuro Nginx en el VPS `217.216.80.158` para servir Laravel desde `/opt/inventarioarens-cloud/public`.
- Se activo HTTPS con Let's Encrypt para `app.miinventariofacil.com`.
- Se agrego `scripts/vps_configure_app_domain.py` para repetir la configuracion del dominio en el VPS si hace falta.
- Se actualizaron los valores sugeridos del configurador, asistente de sincronizacion y scripts locales para usar `https://app.miinventariofacil.com/api`.
- Se documento el procedimiento en `docs/DOMINIO_APP_MIINVENTARIOFACIL_VPS_2026-07-07.md`.

Validacion:

- `app.miinventariofacil.com` resuelve a `217.216.80.158`.
- HTTPS quedo activo.
- La API Laravel responde por el dominio. Una ruta protegida devolvio `401`, que es correcto cuando no se envia token.

## 2026-07-07 - API nube permanente y worker por dominio

Objetivo:

- Confirmar que la nube opere por `https://app.miinventariofacil.com/api`.
- Validar que el worker local pueda sincronizar contra el dominio HTTPS.
- Evitar avisos de log en uso cuando el panel lee el log del worker.

Implementacion:

- Se verifico que `nginx`, `php8.4-fpm` y `postgresql` estan activos y habilitados en el VPS.
- Se actualizo `APP_URL` de la nube a `https://app.miinventariofacil.com` y se refresco la cache de Laravel.
- Se reforzo `scripts/vps_configure_app_domain.py` para actualizar `APP_URL` al dominio y reiniciar PHP-FPM.
- Se ajusto `scripts/sync-worker.ps1` para escribir logs con lectura/escritura compartida.
- Se adapto `scripts/sync-smoke-test.ps1` para aceptar `-CloudApiUrl` y probar contra una API externa como el dominio productivo.
- Se documento en `docs/API_NUBE_PERMANENTE_Y_PRUEBA_DOMINIO_2026-07-07.md`.

Validacion:

- `Test-NetConnection app.miinventariofacil.com -Port 443` respondio correctamente con red real.
- La API respondio `401` en una ruta protegida, esperado sin token.
- El worker local ejecuto sincronizacion contra `https://app.miinventariofacil.com/api` con 0 fallos.

Nota:

- La prueba profunda que valida PostgreSQL nube directo depende de permisos/red hacia el puerto 5432. La operacion real del sistema debe pasar por API HTTPS.

## 2026-07-07 - API base del portal administrativo web

Objetivo:

- Comenzar la base backend del futuro portal web administrativo.
- Permitir que la web consulte metricas por API, sin conectarse directo a PostgreSQL.
- Preparar un resumen gerencial por empresa con ventas, POS, caja, inventario y sincronizacion.

Implementacion:

- Se agrego el modulo `AdminPortal`.
- Se agrego la ruta `GET /api/admin-portal/dashboard`.
- La API devuelve metricas agregadas del tenant activo y alertas operativas.
- La autorizacion acepta permisos de lectura gerencial u operativa: `reports.view`, `finance_reports.view`, `sales.view`, `products.view` o `cash_register.view`.
- Las consultas usan agregados SQL y filtros por tenant para evitar N+1 y mezcla entre empresas.
- Se documento el contrato en `docs/API.md`, `docs/MODULES.md` y `docs/PORTAL_ADMIN_BACKEND_DASHBOARD_2026-07-07.md`.

Pruebas:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\AdminPortal\AdminDashboardApiTest.php
```

Resultado:

- 3 pruebas pasadas;
- 27 aserciones.

## 2026-07-07 - Regla permanente de UI de alta densidad para portal administrativo

Objetivo:

- Fijar como estandar del portal administrativo una interfaz compacta, productiva y orientada a sistemas de gestion.
- Evitar que futuras pantallas web vuelvan a un estilo amplio, con mucho espacio vacio o textos demasiado grandes.

Implementacion:

- Se amplio `docs/GUIA_UI_ALTA_DENSIDAD_PORTAL_ADMIN_2026-07-07.md` como regla permanente para `/admin`.
- Se enlazo la regla desde `docs/MODULES.md` dentro del modulo `AdminPortal`.
- Se agrego la decision en `docs/ARCHITECTURE.md` para que aplique a futuras pantallas administrativas.

Regla:

- Texto general entre 12px y 14px.
- Tablas densas y controles compactos.
- Menos padding, menos margen y menos scroll.
- El portal debe sentirse como herramienta de trabajo, no como pagina comercial.
## 2026-07-07 - Compras en portal administrativo web

### Implementado

- Se agrego la seccion **Compras** al portal administrativo web con estilo de alta densidad.
- Se agregaron filtros por busqueda, estado y proveedor.
- Se agrego formulario compacto para crear compras en borrador desde la web.
- Se agrego recepcion completa de compras para mover inventario desde el backend.
- Se agrego anulacion de compras en borrador.
- Se amplio `GET /api/purchases` con filtros, paginacion y conteo de items.
- Se documento el avance en `docs/PORTAL_ADMIN_COMPRAS_WEB_2026-07-07.md`, `docs/API.md` y `docs/MODULES.md`.

### Pruebas

- Pendiente de ejecutar pruebas especificas del modulo Compras y del portal administrativo.

### Notas

- La recepcion web de productos serializados/IMEI queda pendiente para una fase posterior; por ahora se bloquea desde la interfaz web para evitar ingresos incompletos.## 2026-07-07 - Verificacion de compras web

### Pruebas ejecutadas

- `PurchaseOrderApiTest` y `AdminPortalWebTest` pasaron correctamente.
- `pnpm build` compilo los assets del portal administrativo sin errores.
## 2026-07-07 - Portal web: Cuentas por pagar

- Se agrego la seccion web compacta de Cuentas por pagar para listar saldos, filtrar por proveedor/estado/vencimiento y registrar pagos.
- Se ampliaron filtros del endpoint GET /api/accounts-payable para uso administrativo web.
- Se documentaron API, modulo y nota especifica en docs/PORTAL_ADMIN_CUENTAS_POR_PAGAR_WEB_2026-07-07.md.
- Pruebas objetivo: AccountsPayableApiTest y AdminPortalWebTest.


## 2026-07-07 - Portal web: Cuentas por cobrar

- Se agrego la seccion web compacta de Cuentas por cobrar para listar saldos de clientes, filtrar por cliente/estado/vencimiento y registrar cobros.
- Se amplio GET /api/accounts-receivable con filtros para uso administrativo web.
- Se integraron permisos accounts_receivable.view y accounts_receivable.collect en la gestion visual de perfiles.
- Se documento el avance en docs/PORTAL_ADMIN_CUENTAS_POR_COBRAR_WEB_2026-07-07.md, docs/API.md y docs/MODULES.md.
- Pruebas objetivo: AccountsReceivableApiTest y AdminPortalWebTest.

## 2026-07-09 - Traslados: recepcion logistica en escritorio

- Se agrego la pantalla WPF de recepcion logistica para guias despachadas.
- Se amplio el listado GET /api/inventory-transfers con filtros por estado y modo de validacion.
- La app de escritorio ahora permite recibir completo o registrar diferencias justificadas por linea.
- Se documento el avance en docs/IMPLEMENTACION_TRASLADOS_FASE_6_ESCRITORIO_RECEPCION_2026-07-09.md, docs/API.md y docs/MODULES.md.
- Pruebas objetivo: InventoryTransferApiTest y compilacion WPF.
