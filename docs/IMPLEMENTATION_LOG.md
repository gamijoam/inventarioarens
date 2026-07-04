# Registro de implementación

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
