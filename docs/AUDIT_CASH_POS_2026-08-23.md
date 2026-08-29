# Auditoria Caja, POS, Cierres y Reportes

Fecha: 2026-08-23

## Alcance

- Apertura de caja con USD y VES.
- Movimientos de entrada, salida y ajustes.
- Checkout POS, pagos mixtos, IMEI, idempotencia y stock.
- Cierre normal, cierre ciego, diferencias y Reporte Z.
- Ventas, devoluciones, reembolsos, credito de cliente y canjes.
- Reportes operativos, financieros y Reports V2.
- Sincronizacion de caja/POS.
- Frontend POS y pruebas Playwright con navegador.
- Rendimiento de bootstrap POS y reportes.

## Evidencia Ejecutada

### Backend

```text
CashRegister + POS + ReportsV2 relacionados: 126 tests, 126 passed, 0 failed.
POS backend previo: 159 tests, 159 passed, 0 failed.
```

Se validaron apertura, cierre USD/VES, diferencias, conteo ciego, movimientos, Reporte Z,
checkout con IMEI, pagos mixtos, snapshots de tasa, idempotencia, listas de precio, reportes
y aislamiento multi-tenant.

### Frontend

```text
Vitest: 827 passed, 1 skipped, 0 failed.
TypeScript: passed.
POS build: passed.
Playwright API contra snapshot: 8 passed.
Playwright UI POS caja + checkout USD/VES + IMEI: 2 passed.
```

El lint global del frontend sigue fallando por deuda preexistente: 195 errores y 4 warnings.
`format:check` reporta 274 archivos fuera de formato. No se mezclo ese saneamiento global con
esta auditoria.

### Snapshot Oscarcell

Se utilizo exclusivamente la base aislada `inventory_arens_oscarcell_vps`, restaurada desde
`storage/app/oscarcell-vps.dump` con `scripts/reset-oscarcell-vps-snapshot.sh`. No se modifico
la base productiva publica.

El dump necesitaba ejecutar las migraciones actuales antes de probar: `/api/pos/bootstrap`
devolvia 500 porque faltaba `warehouses.is_default`. El procedimiento correcto es:

```bash
PGPASSWORD='<local-password>' ./scripts/reset-oscarcell-vps-snapshot.sh
DB_DATABASE=inventory_arens_oscarcell_vps DB_CONNECTION=pgsql php artisan migrate --force
```

### Benchmarks locales

Con PostgreSQL local y servidor Laravel de prueba:

| Endpoint | Requests | Concurrencia | Errores | p50 | p95 | RPS |
|---|---:|---:|---:|---:|---:|---:|
| `/api/pos/bootstrap` | 100 | 4 | 0 | 130 ms | 135 ms | 30.67 |
| `/api/reports/v2/sales_overview?scope=tenant` | 50 | 4 | 0 | 938 ms | 1001 ms | 4.22 |

`k6` no esta instalado en el entorno; por eso no se ejecuto el laboratorio k6 de carga.

## Correcciones Aplicadas

### F1. Checkout POS rechazaba pagos normales con 403

El frontend envia arrays vacios `combo_applications` y `product_offer_applications`. El
controller usaba `filled()`, que consideraba esos arrays presentes y exigia
`pos.promotions.apply` aunque no hubiese promocion.

Correccion:

- `app/Modules/POS/Controllers/PosOrderController.php`
- Solo se exige permiso de promociones cuando existe una aplicacion no vacia o `promotion_id`.
- Test TDD: `PosPromotionCheckoutTest::test_checkout_without_promotion_permission_accepts_empty_promotion_arrays`.

Resultado: el test fallo primero con 403 y paso despues con 201.

### F2. Reports V2 sumaba pagos `pending` y `failed`

Las consultas de `sales_overview`, `sales_by_company` y `sales_by_payment_method` agregaban todos
los `pos_payments`, aunque la orden estuviera pagada.

Correccion:

- `app/Modules/ReportsV2/ReportRegistry.php`
- Las agregaciones filtran `pp.status = 'captured'`.
- Test TDD: `ReportV2ApiTest::test_reports_ignore_pending_and_failed_pos_payments`.

### F3. Consecutivo Z vulnerable a carrera

`MAX(z_number) + 1` no bloqueaba la caja y la migracion solo tenia un indice no unico.

Correccion:

- `app/Modules/CashRegister/Services/ReportZService.php`
- Lock de la fila de `cash_registers` cuando existe caja fisica.
- Lock de `tenants` para sesiones virtuales sin `cash_register_id`.
- Migracion `2026_08_23_180000_make_z_number_unique_per_cash_register.php`.
- Unicidad por `(tenant_id, cash_register_id, z_number)`.

Antes de desplegar la migracion se debe verificar que no existan duplicados historicos de Z en
la base objetivo.

### F4. Arnes Playwright POS desalineado

El helper de login siempre esperaba `/dashboard`, aunque el cliente POS redirige correctamente a
`/pos`. Tambien habia selectores de producto/IMEI dependientes de clicks fragiles.

Correccion:

- `frontend/e2e/support/auth.ts`: destino configurable mediante `PLAYWRIGHT_APP_MODE=pos`.
- `frontend/e2e/pos.checkout.ui.spec.ts`: seleccion estricta del producto y activacion por teclado.
- El checkout ahora verifica el status HTTP 201 real antes de validar el recibo.

### F5. Anulación y reversión de venta POS pagada

Implementado el contrato append-only para ventas POS pagadas:

- `POST /api/pos/orders/{posOrder}/reverse` con permiso `sales.reverse`.
- `void` solo para ventas del día; ventas anteriores requieren `reversal`.
- Tabla `sale_reversals` con unicidad por tenant/orden, motivo, usuario, snapshots monetarios y timestamps.
- Reintegro de stock y seriales mediante `sale_reversal`.
- Reembolso en efectivo como egreso de caja con snapshot de tasa.
- Restauración de saldo a favor para pagos `customer_credit`.
- Reversión append-only del ledger de comisiones.
- Reporte Z y Reports V2 conservan el bruto original y exponen el ajuste/neto.
- Outbox `pos.order.reversed` y applier remoto idempotente con restauración de stock/seriales.
- Pagos externos no se marcan como reembolsados automáticamente: la operación devuelve `422` hasta
  existir un flujo de conciliación externa.

Tests TDD:

- `tests/Feature/POS/PosSaleReversalApiTest.php`: 9 tests, incluyendo permisos, doble reversión,
  fecha anterior, crédito de cliente, reporte, cross-tenant y rollback atómico.
- `tests/Feature/Sync/PosOrderStockSyncTest.php`: restauración de stock y orden remota anulada.

### F6. Canje legacy e idempotencia de cobros

- El endpoint legacy `POST /sales-returns/{id}/exchange` rechaza un segundo canje antes de crear otra venta.
- El checkout POS real reutiliza `Idempotency-Key` cuando se reintenta el mismo payload.
- Los endpoints de cobro CxC, pago CxP y ejecución de solicitudes CxP usan el middleware de idempotencia.
- Los hooks frontend de cobro CxC y pago/ejecución CxP envían `Idempotency-Key`.
- Tests: Sales Returns `12/12`, CxC/CxP cubren duplicación por clave, Vitest POS `33/33` y CxC `3/3`.

### F7. Scopes de caja y POS

- Apertura de caja valida el scope de sucursal del usuario.
- Bootstrap POS filtra sucursales, almacenes y cajas físicas por scopes de sucursal/almacén.
- Checkout y órdenes pendientes rechazan almacenes fuera del alcance del cajero/vendedor.
- La sesión de caja usada para vender también debe pertenecer al alcance de sucursal del cajero.
- Tests de regresión cubren apertura, bootstrap y checkout fuera de scope.

## Hallazgos Pendientes

### Criticos

1. **Resuelto parcialmente en F5:** existe anulacion/reversion append-only de venta POS pagada para
   efectivo y saldo a favor. Los pagos externos siguen requiriendo un flujo separado de conciliacion.
2. **Resuelto en F6:** el endpoint legacy de canje valida `exchange_sale_id` con lock transaccional.
3. **Resuelto en F6:** checkout POS, cobros CxC y pagos CxP envían idempotencia desde los hooks reales.

### Altos

1. **Resuelto en F7:** apertura, bootstrap POS y checkout aplican scopes de sucursal/almacén.
2. Sync replica sesiones abiertas/cerradas, pero no todos los movimientos manuales, conteos por
   denominacion, revisiones ni el vinculo de una orden POS a la sesion remota.
3. El cliente puede declarar directamente un pago externo como `captured`; debe definirse si la
   confirmacion manual es intencional o si requiere una autorizacion externa.
4. **Resuelto parcialmente en F6:** CxC/CxP tienen idempotencia HTTP cuando el cliente envía la clave;
   todavía falta una clave de negocio persistida para integraciones que no usen el middleware.
5. Cancelar una devolucion y procesarla pueden competir sin transicion atomica suficiente.
6. El resumen financiero no aplica de forma consistente filtros de cliente/proveedor a cobros y
   pagos.
7. Reportes y devoluciones no tienen un recorrido integrado suficiente desde checkout real hasta
   cierre y reporte.

### Medios

1. Ajustes manuales de caja siempre parecen incrementar el esperado; falta direccion explicita
   para ajustes negativos.
2. Conteos duplicados por moneda/denominacion llegan a la restriccion de base de datos y pueden
   terminar como 500 en lugar de 422.
3. Reporte Z muestra pagos POS, pero no ofrece desglose completo de movimientos manuales, CxC,
   CxP y reembolsos que impactan el esperado.
4. Devoluciones multi-linea/multi-tasa usan el snapshot de la primera linea para el total.
5. Listados financieros de detalle no estan paginados.
6. El frontend de devoluciones solo consulta la primera pagina y filtra estados localmente.
7. El cierre ciego POS no ofrece captura visual de denominaciones VES, aunque el backend la acepta.
8. Movimientos manuales POS y modulo Caja fijan USD en la UI aunque el backend acepta VES.
9. Reporte Z y centro de caja muestran principalmente diferencias USD y omiten parte del detalle VES.
10. Exportacion CSV de Reports V2 no escapa todos los valores.

## Prioridad Recomendada

### P0

1. Implementar anulacion de venta pagada con reverso append-only y permisos separados.
2. Eliminar/proteger el endpoint legacy de doble canje.
3. Enviar idempotencia desde el hook POS real y cubrir timeout/retry en navegador.
4. Completar prueba integrada: apertura USD/VES -> checkout USD/VES -> devolucion/reembolso ->
   cierre -> Reporte Z -> Reports V2.

### P1

1. Aplicar scopes a apertura, bootstrap y checkout.
2. Completar sync de movimientos/conteos/revisiones y cierre Z.
3. Agregar idempotencia a CxC/CxP y movimientos manuales.
4. Validar pagos `captured` por modalidad de pago externa.
5. Corregir filtros financieros y paginacion.

### P2

1. Completar UI VES de movimientos y denominaciones.
2. Añadir Z VES y detalle de movimientos a frontend.
3. Endurecer CSV y schemas Zod de mutaciones.
4. Incorporar `k6` al entorno de auditoria/CI y medir cierres, reportes y anulaciones.

## Comandos de Reproduccion

```bash
php vendor/bin/phpunit -c phpunit.sqlite.xml --process-isolation \
  tests/Feature/CashRegister \
  tests/Feature/POS/PosPromotionCheckoutTest.php \
  tests/Feature/POS/PosCheckoutApiTest.php \
  tests/Feature/ReportsV2/ReportV2ApiTest.php

cd frontend
pnpm test
pnpm typecheck
pnpm build:pos
```

Para navegador contra snapshot migrado:

```bash
PLAYWRIGHT_APP_MODE=pos \
PLAYWRIGHT_E2E_EMAIL='oscarcellmaster@gmail.com' \
PLAYWRIGHT_E2E_PASSWORD='<snapshot-password>' \
PLAYWRIGHT_E2E_TENANT='oscarcell-yaracall' \
PLAYWRIGHT_FRONTEND_URL='http://127.0.0.1:5173' \
pnpm exec playwright test e2e/pos.cash.ui.spec.ts e2e/pos.checkout.ui.spec.ts --project=ui
```
