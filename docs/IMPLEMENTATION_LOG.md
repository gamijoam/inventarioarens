# Registro de implementación

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
