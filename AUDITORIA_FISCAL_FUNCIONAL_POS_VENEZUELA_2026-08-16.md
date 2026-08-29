# Auditoría Fiscal Y Funcional POS Venezuela

Fecha: 2026-08-16
Última revisión de implementación: 2026-08-29
Repositorio: `INVENTARIOARENS`
Alcance: backend Laravel, frontend React/Electron, API, base de datos, impresión, sync, tests y documentación.
Modo: solo lectura. No se modificó código ni el prompt original.

## 1. Veredicto Ejecutivo

El sistema tiene una base sólida como **SaaS comercial de inventario, POS, caja, CxC/CxP, promociones, sincronización y tickets no fiscales**.

No está preparado actualmente para comercializarse como sistema de **facturación fiscal venezolana**. El bloqueo principal no es la homologación de software: faltan `FiscalDocument`, factura fiscal, series, numeración fiscal, número de control, notas fiscales, medio autorizado de emisión, máquina fiscal y facturación digital. El catálogo y cálculo interno de IVA ya están implementados como preparación.

El ticket actual se identifica correctamente como `Documento no fiscal`, pero el producto debe mantener una separación explícita entre:

- `Sale`/`PosOrder`: operación comercial interna.
- Ticket/PDF: documento comercial no fiscal.
- `FiscalDocument`: entidad pendiente para una factura fiscal.
- Forma libre: medio fiscal externo/preimpreso pendiente.
- Máquina fiscal: adaptador fiscal pendiente.
- Facturación digital: integración con imprenta digital/proveedor autorizado pendiente.

### Estado estimado

Los porcentajes representan cobertura funcional aproximada, no cumplimiento legal ni certificación.

| Área                          | Preparación | Justificación                                                                                                                                          |
| ----------------------------- | ----------: | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POS general                   |         80% | Checkout, caja, inventario, pagos, promociones, órdenes pendientes y tests amplios. Persisten riesgos de concurrencia, reservas y servicios sin stock. |
| Facturación comercial         |         65% | Ventas, tickets, PDFs, recibos internos y devoluciones comerciales. No existe factura fiscal.                                                          |
| Cumplimiento fiscal Venezuela |         10% | El ticket no fiscal existe y hay moneda/tasa, pero faltan IVA, documentos, numeración, control y emisor fiscal.                                        |
| Seguridad                     |         72% | Auth, tenant, RBAC, CSRF y varias policies existen; hay riesgos de settings, idempotencia, scopes y auditoría financiera.                              |
| Integridad de datos           |         62% | Muchos decimales, FKs tenant y transacciones; faltan constraints de variantes, cascadas seguras, versiones y concurrencia real.                        |
| Facturación digital           |          0% | No hay proveedor, autorización, número de control, respuesta fiscal, reintentos ni consulta por diez años.                                             |
| Máquina fiscal                |          0% | Existe ESC/POS genérico, no protocolo fiscal, SDK, estado, X/Z ni adaptadores por marca.                                                               |
| Multimoneda                   |         65% | USD/VES, tasas y pagos mixtos funcionan; monedas ISO configurables y algunas precisiones históricas faltan.                                            |
| Auditoría                     |         45% | `AuditLogger` existe y cubre algunas áreas, pero no pagos, CxC/CxP, devoluciones, ajustes y emisión fiscal.                                            |

### Revisión De Línea Base 2026-08-29

La rama `programa-fiscal` fue sincronizada con `main` y la Etapa 1 operativa de este documento ya
está incorporada y validada: idempotencia tenant-scoped, aprobaciones atómicas de inventario,
créditos a favor, ACK seguro de sync, reconciliación de stock y expiración de reservas.

La identidad fiscal base de empresa y sucursal también quedó implementada:

- `GET/PATCH /api/fiscal/identity` administra la identidad de la empresa.
- `GET/PATCH /api/fiscal/identity/branches/{branch}` administra datos fiscales de sucursal.
- La empresa reutiliza `tenant_settings.settings.company` para conservar compatibilidad con tickets
  comerciales y sync existente.
- Las sucursales conservan dirección, ciudad, estado, contacto y condición IVA en columnas
  tenant-scoped y los replican por eventos de branch.
- Las escrituras exigen `settings.manage`; la lectura y el acceso a sucursales respetan el tenant.

La revisión del 2026-08-29 agregó clasificación fiscal de productos, snapshots de IVA en ventas y
devoluciones, además de un reporte interno tenant-scoped por período. También se agregó el nombre
fiscal separado del nombre comercial del cliente y su propagación por sync.

Esto no cambia el veredicto fiscal: todavía no existe `FiscalDocument`, numeración fiscal, número de
control ni medio autorizado de emisión. El cálculo y reporte de IVA actuales son preparatorios e
internos, no una emisión fiscal certificada.

Validación posterior al merge:

- Backend modular SQLite: `1.523` tests ejecutados, `1.519` pasados y `4` skips existentes.
- Frontend: `148` archivos de test, `854` pasados y `1` skip.
- TypeScript, ESLint, Prettier y Pint: correctos.

## 2. Marco Normativo Consultado

La fuente oficial `seniat.gob.ve` no fue accesible desde este entorno. Se contrastaron copias y referencias secundarias de las providencias, por lo que esta sección no sustituye revisión de un asesor tributario venezolano ni la consulta de la Gaceta Oficial original.

Fuentes consultadas:

- IVECOFI, `SNAT/2011/00071`: https://tributos.ivecofi.net/informacion/legislacion/providencias/pa-2011-71
- IVECOFI, `SNAT/2024/000102`: https://tributos.ivecofi.net/informacion/legislacion/providencias/pa-2024-102
- Finanzas Digital, `SNAT/2026/00084`: https://finanzasdigital.com/seniat-deroga-norma-sobre-proveedores-de-sistemas-fiscales/

### Implicaciones relevantes

La PA `SNAT/2011/00071` contempla factura, notas de débito/crédito, guías y medios como formatos de imprenta autorizada, formas libres y máquinas fiscales. Sus requisitos incluyen denominación, numeración consecutiva/única, número de control, emisor, RIF, fecha, cliente, descripción, descuentos, bases por alícuota, IVA, exentos/exonerados, total y moneda/tasa cuando aplique.

La PA `SNAT/2024/000102` regula medios digitales, pero no convierte automáticamente cualquier PDF local en factura fiscal. La copia consultada exige autorización, imprenta digital autorizada, integridad/autenticidad/trazabilidad, respaldo, acceso permanente, contingencia, emisión de número de control y entrega/consulta del comprobante.

La PA `SNAT/2026/00084`, publicada según las fuentes consultadas en Gaceta Oficial 43.435 del 12 de agosto de 2026, derogaría la PA `SNAT/2024/000121` sobre proveedores de sistemas homologados. Esto elimina ese requisito específico según las fuentes, pero no elimina los requisitos del documento fiscal, IVA, numeración, control, autorización o medio de emisión que resulten aplicables.

## 3. Hallazgos Prioritarios

### 🚨 P0 — Bloqueadores críticos

1. **No existe identidad fiscal del emisor.** `Tenant` y `Branch` no tienen RIF, razón social, domicilio fiscal, régimen, condición IVA ni datos fiscales. Evidencia: `app/Modules/Tenancy/Models/Tenant.php:16-22`, `app/Modules/Branches/Models/Branch.php:12-20`, `database/migrations/2026_07_02_170000_create_tenants_table.php:11-18`.
2. **No existe modelo ni cálculo de IVA.** No hay alícuotas, impuestos por línea, base imponible, exento, exonerado ni total de IVA. Evidencia: `app/Modules/Products/Models/Product.php:17-44`, `app/Modules/Sales/Models/SaleItem.php:16-57`, `app/Modules/POS/Models/PosOrder.php:15-51`.
3. **No existe `FiscalDocument`, factura fiscal, factura item, serie fiscal, secuencia fiscal ni número de control.** Evidencia: rutas cargadas en `routes/api.php:17-128` y migraciones de `sales`/`pos_orders`.
4. **No existe Fiscal/Billing Engine.** `PosCheckoutService` coordina directamente venta, inventario, caja, CxC, promociones, comisiones y sync. Evidencia: `app/Modules/POS/Services/PosCheckoutService.php:5-51`, servicio de aproximadamente 1.433 líneas.
5. **La carrera de `Idempotency-Key` puede ejecutar dos veces la operación.** Si la segunda inserción choca con unique, el middleware continúa hacia `$next()`. Evidencia: `app/Http/Middleware/IdempotencyKey.php:68-109,133-150`.
6. **Checkout, pagos, inventario, entradas/salidas y CxC no tienen una protección de idempotencia uniforme.** Evidencia: `app/Modules/POS/routes.php:13-23` frente a `app/Modules/Inventory/routes.php:8-15`, `app/Modules/ProductEntries/routes.php:6-8`, `app/Modules/ProductExits/routes.php:6-8`, `app/Modules/AccountsReceivable/routes.php:10`.
7. **Dos aprobaciones concurrentes de un movimiento manual pueden aplicar stock dos veces.** Evidencia: `app/Modules/Inventory/Controllers/InventoryManualMovementController.php:108-166`.
8. **El crédito a favor puede gastarse dos veces bajo concurrencia.** Evidencia: `app/Modules/Customers/Services/CustomerCreditService.php:40-71` y `app/Modules/SalesReturns/Services/SalesReturnService.php:243-267`.
9. **El worker puede marcar eventos sync como procesados aunque la aplicación remota haya fallado.** Evidencia: `app/Modules/Sync/Services/SyncWorkerService.php:167-188`, `app/Modules/Sync/Services/SyncTransportService.php:96-117`.
10. **`stock_balances` permite múltiples filas para una combinación con variante nula después de eliminar el índice parcial.** Evidencia: `database/migrations/2026_07_10_000000_drop_legacy_stock_balances_partial_unique.php:27-29`, `database/migrations/2026_07_30_120100_add_product_variant_id_to_inventory_tables.php:32-33`.
11. **Una venta fiscal no puede reconstruirse.** Los resources y tickets no contienen IVA, base por alícuota, exentos/exonerados, número fiscal ni control. Evidencia: `app/Modules/Sales/Resources/SaleResource.php:17-29`, `resources/views/printing/pos-ticket.blade.php:68-96`.

### 🔴 P1/P2 — Faltantes y riesgos principales

- No existe nota de débito fiscal; el ajuste `AJF-*` no es una nota fiscal. Evidencia: `app/Modules/FinancialAdjustments/Services/FinancialAdjustmentService.php:115-136`.
- La devolución crea ajuste financiero/saldo a favor, pero no documento fiscal ni evento sync monetario completo. Evidencia: `app/Modules/SalesReturns/Services/SalesReturnService.php:598-612`, `app/Modules/Sync/Services/SyncEventApplier.php:2998-3136`.
- El audit log no cubre de forma central pagos, devoluciones, créditos, CxC/CxP, ajustes y recibos. `AuditLogger` existe en `app/Modules/Audit/Services/AuditLogger.php:16-42`, pero no está invocado por esos servicios.
- `track_stock=false` se entiende en frontend como servicio, pero Sales/Inventory pueden seguir intentando mover stock. Evidencia: `app/Modules/Sales/Services/SaleService.php:196-223`, `app/Modules/Inventory/Services/InventoryMovementService.php:565-587`.
- La validación POS no demuestra que almacén y caja pertenezcan a la misma sucursal. Evidencia: `app/Modules/POS/Requests/StorePosCheckoutRequest.php:68-72`.
- Variante enviada no se valida siempre contra el producto. Evidencia: `app/Modules/POS/Requests/StorePosCheckoutRequest.php:86-90`.
- La búsqueda de órdenes consulta `pos_orders.document_number`, columna no encontrada en la migración. Evidencia: `app/Modules/POS/Controllers/PosOrderController.php:56-62`, `database/migrations/2026_07_02_203000_create_pos_orders_table.php:14-25`.
- Las secuencias `MAX()+1` bloquean la última fila, no un contador estable. Evidencia: `app/Modules/ProductEntries/Services/ProductEntryService.php:151-159`, `app/Modules/ProductExits/Services/ProductExitService.php:165-173`, `app/Modules/FinancialAdjustments/Services/FinancialAdjustmentService.php:115-136`, `app/Modules/PaymentReceipts/Services/PaymentReceiptService.php:122-134`.
- Las reservas POS no tienen TTL, expiración ni sweeper. Evidencia: `app/Modules/POS/Services/PosCheckoutService.php:223-230,1002-1059`.
- La impresión puede duplicarse al reintentar después de perder la respuesta. Evidencia: `app/Modules/Printing/Services/PosTicketPrintService.php:17-43`, `app/Modules/Printing/routes.php:25`.
- La API no tiene `/api/v1`, OpenAPI/Swagger ni catálogo estable de códigos de error. Evidencia: `routes/api.php:17-128`, `composer.json:8-15`, `frontend/src/types/api.ts:7-11`.
- No existe rate limit global específico para checkout, pagos, importaciones y mutaciones sensibles. Evidencia: `app/Providers/AppServiceProvider.php:181-204`.
- Cualquier miembro activo puede modificar `tenant_settings` aunque la documentación sugiera Owner/Administrador. Evidencia: `app/Modules/Tenancy/Controllers/TenantSettingController.php:128-147`, `tests/Feature/Tenancy/TenantSettingApiTest.php:33-56`.
- Los scopes de almacén/reportes no se aplican uniformemente. Evidencia: `app/Modules/Reports/Controllers/InventoryReportController.php:18-66` frente a `app/Modules/Sales/Controllers/SaleController.php:85-92`.
- `PosCheckoutService` mezcla demasiadas responsabilidades; Payments está repartido entre POS, CashRegister, CxC, recibos y sync.

## 4. Auditoría Por Área

| Área                          | Estado                                     | Prioridad | Evidencia                                                      | Acción                                                                      |
| ----------------------------- | ------------------------------------------ | --------: | -------------------------------------------------------------- | --------------------------------------------------------------------------- |
| 1. Empresa, sucursal y fiscal | 🚨 ERROR CRÍTICO                           |        P0 | `Tenant.php`, `Branch.php`, migraciones de tenants/branches    | Crear configuración fiscal tipada por empresa/sucursal                      |
| 2. Productos y servicios      | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | `Product.php`, `ProductResource.php`, `ProductService`         | Agregar tipo servicio y fiscalidad; respetar `track_stock=false` en backend |
| 3. Clientes                   | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | `Customer.php`, requests de customers, POS customer_name       | Normalizar RIF/cédula y formalizar consumidor final                         |
| 4. Proceso de venta           | 🟡 IMPLEMENTADO PARCIALMENTE               |        P0 | `PosCheckoutService`, `SaleService`, `PosOrder`                | Agregar snapshot fiscal completo                                            |
| 5. IVA                        | 🔴 NO IMPLEMENTADO                         |        P0 | Ausencia de tax model/migrations/fields                        | Tax engine, alícuotas y cálculo por línea/documento                         |
| 6. Documentos fiscales        | 🔴 NO IMPLEMENTADO                         |        P0 | No existe módulo Billing/Fiscal/Invoice                        | Separar venta comercial de documento fiscal                                 |
| 7. Factura                    | 🚨 ERROR CRÍTICO                           |        P0 | `pos-ticket.blade.php` dice Documento no fiscal                | Implementar factura fiscal con todos los campos normativos                  |
| 8. Numeración                 | ⚠️ IMPLEMENTADO PERO CON RIESGO            |        P1 | servicios `MAX()+1`; no hay secuencia fiscal                   | Contadores transaccionales por empresa/sucursal/serie                       |
| 9. Número de control          | 🔴 NO IMPLEMENTADO                         |        P0 | No hay tabla, campo ni endpoint                                | Recibirlo de imprenta/proveedor; nunca inventarlo                           |
| 10. Modos de facturación      | 🔴 NO IMPLEMENTADO                         |        P0 | `PrinterStation` solo define salida térmica/digital            | Crear adaptadores fiscal/no fiscal                                          |
| 11. Forma libre               | 🔴 NO IMPLEMENTADO                         |        P0 | `PrintProfile` solo visual                                     | Rangos, imprenta, control, consumo y contingencia                           |
| 12. Máquina fiscal            | 🔴 NO IMPLEMENTADO                         |        P0 | ESC/POS genérico no fiscal                                     | `FiscalPrinterAdapter` por marca y protocolo                                |
| 13. Facturación digital       | 🔴 NO IMPLEMENTADO                         |        P0 | Dompdf/PrintServer local solamente                             | Proveedor, autorización, control, estados, reintentos y consulta            |
| 14. Nota de crédito           | 🟡 IMPLEMENTADO PARCIALMENTE               |        P0 | `FinancialAdjustment`/`customer_credit`                        | Separar nota fiscal de ajuste financiero                                    |
| 15. Nota de débito            | 🔴 NO IMPLEMENTADO                         |        P1 | No hay entidad/ruta/estado                                     | Implementar documento enlazado a original                                   |
| 16. No borrado                | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | API no expone DELETE; cascadas existen                         | Restrict/nullOnDelete y guardas ORM                                         |
| 17. Estados                   | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | SalesReturns, POS orders, financial adjustments                | Máquina de estados fiscal y transiciones atómicas                           |
| 18. Audit log                 | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | `AuditLogger` parcial                                          | Auditar pagos, emisión, devoluciones, ajustes y anulaciones                 |
| 19. Multimoneda               | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | USD/VES, tasas, snapshots numéricos                            | Catálogo ISO, tasa ID/fecha y precisión uniforme                            |
| 20. Formas de pago            | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | POS payments y CashRegister                                    | Corregir vuelto mixto y crédito concurrente                                 |
| 21. No fiscales               | ✅ IMPLEMENTADO CORRECTAMENTE              |        P2 | Ticket, cotización/orden y guía operativa                      | Rotular siempre no fiscal; separar nombres “invoice promotion”              |
| 22. Sucursales                | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | branches/warehouses/cash sessions                              | Configuración fiscal y validación cross-branch                              |
| 23. Cajas/terminales          | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | CashRegisterService/PosTerminal                                | Evitar doble sesión y habilitar arqueo dual desde POS                       |
| 24. Inventario                | ✅ IMPLEMENTADO CORRECTAMENTE, con riesgos |        P1 | InventoryMovementService, POS, transfers                       | Reconciliación, TTL reservas y concurrencia real                            |
| 25. Seguridad                 | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | Auth, RBAC, policies, tenant middleware                        | Idempotencia, scopes, settings y auditoría                                  |
| 26. Idempotencia              | 🚨 ERROR CRÍTICO                           |        P0 | `IdempotencyKey`, rutas sin middleware                         | Reserva atómica y claves desde frontend                                     |
| 27. Transacciones             | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | checkout transaccional; transiciones y secuencias no uniformes | Atomicidad por operación y outbox                                           |
| 28. Concurrencia              | ⚠️ IMPLEMENTADO PERO CON RIESGO            |        P1 | secuencias, crédito, sesiones, stock balance                   | Pruebas reales en dos procesos/conexiones                                   |
| 29. Contingencia              | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | local-first/sync/print; sin contingencia fiscal                | Estado durable, retries, TTL, fiscal offline                                |
| 30. Reportes                  | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | Reports/FinanceReports/Operational                             | Corregir confirmadas, IVA y export completo                                 |
| 31. Base de datos             | 🟡 IMPLEMENTADO PARCIALMENTE               |        P0 | muchas entidades operativas; faltan fiscales                   | Crear tax/invoice/fiscal/control entities                                   |
| 32. Integridad                | ⚠️ IMPLEMENTADO PERO CON RIESGO            |        P1 | decimals correctos; FKs/cascades/variants incompletos          | Índices, FKs compuestas, restrict y versionado                              |
| 33. Frontend                  | 🟡 IMPLEMENTADO PARCIALMENTE               |        P0 | POS muestra total/tasa, no IVA/document type                   | UI fiscal y confirmaciones críticas                                         |
| 34. Backend                   | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | FormRequests/services/policies; orquestador grande             | Desacoplar Billing/Fiscal/Accounting                                        |
| 35. API                       | 🟡 IMPLEMENTADO PARCIALMENTE               |        P1 | auth/resources/errors/request ID                               | versionado, OpenAPI, error codes y rate limits                              |
| 36. Arquitectura              | 🟡 IMPLEMENTADO PARCIALMENTE               |        P0 | monolito modular sin Fiscal/Billing/Accounting                 | Crear bounded contexts y adaptadores                                        |

## 5. Evidencia De Funcionalidades Bien Implementadas

- Multi-tenancy con `tenant_id`, global scopes, middleware `X-Tenant` y tests cross-tenant.
- POS transaccional con reservas, inventario, caja, pagos, CxC, promociones y comisiones.
- Auth por Bearer/cookie, CSRF para cookies, roles y policies de backend.
- Catálogo comercial amplio: SKU, barcode, categorías, tags, variantes, precios y tasas.
- Snapshots de precios/tasas en operaciones comerciales.
- Pagos mixtos USD/VES con persistencia base/local y métodos configurables.
- IMEI/seriales, traslados, devoluciones, garantías y stock histórico.
- Sync local/nube con outbox/inbox, hashes, snapshots e idempotencia de varios writers.
- Impresión térmica genérica Windows/Linux/red, PDF/HTML y perfiles por estación.
- Tickets explícitamente identificados como no fiscales.
- Tests frontend: suite actual `728 passed, 1 skipped`.
- Tests focalizados backend Sales/Printing ejecutados: `22 passed, 131 assertions`.

## 6. Limitaciones De Verificación

- La suite backend completa con `php vendor/bin/phpunit --process-isolation` excedió 20 minutos y fue terminada por timeout. No se debe presentar como suite completa verde.
- La suite frontend completa sí terminó: `124 test files passed`, `728 passed`, `1 skipped`.
- La auditoría de PostgreSQL paralelo no es concluyente por deadlocks/tablas compartidas y migraciones pendientes; debe ejecutarse en una base PostgreSQL de auditoría aislada.
- No se probó físicamente una máquina fiscal ni una impresora fiscal.
- No se obtuvo respuesta directa del portal oficial SENIAT desde este entorno; las providencias se contrastaron con copias secundarias y Gaceta referenciada.
- La auditoría técnica no constituye opinión legal ni certificación fiscal.

## 7. Problemas Críticos

### 🚨 Problemas críticos

1. Ausencia del núcleo de emisión fiscal: documentos, numeración, control y medio autorizado.
2. Idempotencia concurrente defectuosa y no activada uniformemente en frontend/mutaciones.
3. Posible doble aplicación de stock en aprobación concurrente de movimientos.
4. Posible doble uso de crédito a favor.
5. Eventos sync marcados procesados aunque su aplicación remota falle.
6. Índice único defectuoso para `stock_balances` con variantes nulas.
7. Cascadas capaces de destruir históricos financieros/inventario si se ejecuta borrado físico.

### 🔴 Funcionalidades faltantes

- Factura fiscal, nota de débito fiscal y nota de crédito fiscal.
- Reporte interno de IVA y clasificación por alícuota, exento/exonerado/no gravado.
- FiscalDocument/Billing/Fiscal Engine/Accounting ledger.
- Número de control, imprenta autorizada/digital, series y secuencias fiscales.
- Máquina fiscal y adaptadores de marcas.
- Facturación digital, autorización, API externa, reintentos y contingencia fiscal.
- Configuración fiscal empresa/sucursal.
- OpenAPI, versionado `/api/v1` y catálogo de códigos de error.

### 🟡 Funcionalidades incompletas

- Consumidor final y validación de RIF/cédula.
- Servicios sin stock en backend.
- EUR/otras monedas configurables.
- Auditoría de pagos y finanzas.
- Reportes IVA, notas, anulaciones y agrupaciones fiscales.
- Scopes de inventario/reportes.
- TTL/reconciliación de reservas y pruebas concurrentes.

### ✅ Funcionalidades bien implementadas

- Núcleo POS comercial, inventario y caja.
- Multiempresa, autenticación, permisos y aislamiento.
- Precio/tasa snapshots, pagos USD/VES y promociones comerciales.
- Sync local/nube y operaciones de inventario/IMEI.
- Ticket comercial no fiscal e impresión genérica.

## 8. Plan De Remediación

### FASE 1 — Bloqueadores críticos

| Tarea                               | Módulos/archivos                         | Dificultad | Dependencias           | Prioridad | Riesgo                      | Pruebas                       |
| ----------------------------------- | ---------------------------------------- | ---------: | ---------------------- | --------- | --------------------------- | ----------------------------- |
| Corregir carrera de idempotencia    | `IdempotencyKey`, migrations, POS routes |       Alta | DB target              | P0        | Duplicación de ventas/pagos | Dos procesos con misma clave  |
| Activar idempotency key en frontend | `api/client`, `pos/api`                  |      Media | Contrato middleware    | P0        | Retry duplicado             | Timeout/retry checkout/pago   |
| Corregir crédito concurrente        | `CustomerCreditService`, returns/POS     |       Alta | Lock de cliente/ledger | P0        | Saldo negativo              | Dos consumos simultáneos      |
| Corregir stock balance nullable     | migraciones/inventory service            |      Media | SQLite/PostgreSQL      | P0        | Stock inconsistente         | Primer movimiento concurrente |
| Bloquear aprobación manual          | controller/service                       |      Media | transacción/lock       | P0        | Stock doble                 | Dos approves simultáneos      |
| Corregir sync failed ACK            | Sync worker/applier                      |       Alta | contrato outbox        | P0        | Datos divergentes           | Evento failed y retry         |

### FASE 2 — Núcleo fiscal

| Tarea                            | Módulos/archivos            | Dificultad | Dependencias               | Prioridad | Riesgo                    | Pruebas                         |
| -------------------------------- | --------------------------- | ---------: | -------------------------- | --------- | ------------------------- | ------------------------------- |
| Identidad fiscal tenant/sucursal | Tenancy, Branches, settings |      Media | asesoría tributaria        | P0        | Emisor inválido           | RIF/domicilio/config incompleta |
| Tax catalog y Tax Engine         | nuevo `Tax/Fiscal`          |       Alta | reglas IVA vigentes        | P0        | IVA incorrecto            | alícuotas, exentos, descuentos  |
| FiscalDocument y líneas          | nuevo `Billing/Fiscal`      |       Alta | tax engine                 | P0        | documento irreconstruible | invoice/credit/debit            |
| Secuencias/series/control        | nuevo módulo fiscal         |       Alta | imprenta/máquina/proveedor | P0        | duplicados                | concurrencia, rollback          |
| Document mode no fiscal/fiscal   | POS/Printing/Fiscal         |      Media | FiscalDocument             | P0        | confusión cliente         | UI y payloads                   |

### FASE 3 — Facturación digital

| Tarea                      | Módulos/archivos        | Dificultad | Dependencias               | Prioridad | Riesgo                | Pruebas                  |
| -------------------------- | ----------------------- | ---------: | -------------------------- | --------- | --------------------- | ------------------------ |
| Adaptador imprenta digital | nuevo `Fiscal/Adapters` |       Alta | proveedor autorizado       | P0        | emisión inválida      | contract tests, sandbox  |
| Estados y reintentos       | jobs/outbox/fiscal docs |       Alta | idempotency                | P0        | duplicación           | timeout, retry, rejected |
| Consulta/retención         | storage, FiscalDocument |      Media | requisitos de conservación | P1        | pérdida de evidencia  | diez años, exportación   |
| Envío al cliente           | mail/notifications      |      Media | documento aceptado         | P2        | entrega no demostrada | correo, reintento        |

### FASE 4 — Máquina fiscal

| Tarea                  | Módulos/archivos       | Dificultad | Dependencias           | Prioridad | Riesgo               | Pruebas               |
| ---------------------- | ---------------------- | ---------: | ---------------------- | --------- | -------------------- | --------------------- |
| `FiscalPrinterAdapter` | Printing/Fiscal        |       Alta | marca/SDK              | P0        | hardware fiscal      | mock y equipo real    |
| Estados X/Z/cierre     | CashRegister/Fiscal    |       Alta | protocolo fabricante   | P1        | cierre incorrecto    | reportes y recovery   |
| Contingencia           | POS/Fiscal/Local Motor |       Alta | forma libre autorizada | P1        | ventas sin documento | caída internet/equipo |

### FASE 5 — Seguridad y auditoría

| Tarea                            | Módulos/archivos             | Dificultad | Dependencias     | Prioridad | Riesgo                      | Pruebas                  |
| -------------------------------- | ---------------------------- | ---------: | ---------------- | --------- | --------------------------- | ------------------------ |
| Audit log financiero append-only | Audit, POS, CxC/CxP, returns |      Media | catálogo eventos | P1        | trazabilidad insuficiente   | actor/IP/request/old-new |
| Restringir tenant settings       | Tenancy settings/policies    |       Baja | permisos         | P1        | configuración alterada      | miembro sin permiso      |
| Scopes uniformes                 | Reports/Inventory            |      Media | ScopeResolver    | P1        | fuga de datos               | cross-branch/warehouse   |
| Errores/request ID/OpenAPI       | API/frontend/docs            |      Media | contrato v1      | P2        | soporte y clientes frágiles | 4xx/5xx/OpenAPI          |

### FASE 6 — Mejoras

- Separar `PosCheckoutService` en casos de uso más pequeños.
- Agregar EUR y catálogo de monedas ISO si el negocio lo requiere.
- TTL y limpieza de reservas pendientes.
- Reconciliación `stock_movements` vs `stock_balances`.
- Reportes fiscales, IVA, anulaciones y documentos originales.
- Confirmaciones UI para cobro, cancelación y acciones irreversibles.
- Actualizar README y documentación histórica de auditoría.

## 9. Conclusión Comercial

El sistema puede comercializarse hoy como **plataforma de inventario y POS comercial no fiscal**, siempre que el material comercial y los tickets indiquen claramente ese alcance.

No debe venderse todavía como:

- sistema de facturación fiscal venezolano;
- proveedor de facturación digital;
- sistema homologado/certificado;
- integración con máquina fiscal;
- emisor de notas de crédito/débito fiscales.

La derogación reportada de la PA `SNAT/2024/000121` no cambia esta conclusión: elimina una obligación regulatoria específica según las fuentes consultadas, pero el repositorio todavía no contiene el documento fiscal ni los mecanismos técnicos que exige el marco de emisión aplicable.
