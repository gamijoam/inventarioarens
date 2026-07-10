# Correccion de Fallas Preexistentes

Fecha: 2026-07-10

## Resumen

Se corrigieron dos fallas preexistentes en la suite de tests que no estaban relacionadas con el modulo de traslados pero que detectamos al correr la suite completa despues de cerrar la Fase 7 de cancelacion. Las dos fallas llevaban varias iteraciones en la rama principal sin tocarse y se aprovecharon antes de iniciar el siguiente pendiente.

## Falla 1 - Sync idempotente de eventos POS con payload minimo

### Diagnostico

El test `Tests\Feature\Sync\SyncApiTest::test_it_receives_pushed_events_idempotently` empuja dos veces el mismo evento `pos.order.paid` con un payload reducido `{order_id: 10, total_base_amount: '20.0000'}` y esperaba que el primer push quedara como `ignored` en `sync_inbox` y el segundo como duplicado.

El handler `App\Modules\Sync\Services\SyncEventApplier::applyPosOrder` exigia en sus primeras lineas que el payload incluyera `sale.id` o `sale.sale_id`. Cuando esos identificadores no estaban presentes, el handler lanzaba `RuntimeException` y `applyEvents` lo marcaba como `failed` en lugar de `ignored`.

Para un sistema de sincronizacion idempotente, un evento sin estructura minima para ser procesado de forma confiable debe ser `ignored` y no `failed`. Un `failed` sugiere que un reintento podria tener exito, pero un payload reducido va a seguir fallando igual.

### Cambio

En `app/Modules/Sync/Services/SyncEventApplier.php` el handler `applyPosOrder` ahora retorna `'ignored'` cuando `$sourceOrderId <= 0` o `$sourceSaleId <= 0`. Esto cubre el caso de eventos viejos, snapshots parciales o pruebas de smoke que no traen la estructura completa.

El resto del handler sigue exigiendo la estructura completa cuando los identificadores si estan presentes, de modo que los tests que envian payloads reales (`SyncEventApplierTest` y `test_pushed_product_update_is_applied_even_when_older_inbox_events_are_pending`) siguen comportandose igual.

## Falla 2 - Aislamiento operacional sin caja fisica asociada

### Diagnostico

El test `Tests\Feature\Tenancy\OperationalTenantIsolationTest::test_users_products_cash_registers_and_pos_sales_are_isolated_by_company` crea dos empresas con su `Branch`, `Warehouse`, `Product`, `StockBalance` y `CashRegisterSession`. La sesion de caja no tenia un `CashRegister` (caja fisica) asociado.

El backend POS evoluciono para exigir la caja fisica: `PosCheckoutService::assertCashRegisterCanSell` rechaza el checkout con el mensaje "La venta requiere una caja fisica abierta desde el modulo Caja" cuando `cash_register_id` es null. Esto quedo documentado en `docs/IMPLEMENTATION_LOG.md` y `docs/POS_PAGOS_RAPIDOS_ESCRITORIO_2026-07-08.md` como una decision para evitar que POS abra cajas genericas.

El test preexistente quedo desincronizado con esa regla: la primera parte del test seguia pasando porque solo consulta inventario, pero la parte de ventas POS fallaba con 422 antes de poder llegar al assert esperado.

### Cambio

En `tests/Feature/Tenancy/OperationalTenantIsolationTest.php`:

- Se agrega el import `App\Modules\CashRegister\Models\CashRegister`.
- El helper privado `operationalCompany` ahora crea una `CashRegister` activa por empresa y la asigna a la sesion mediante un update despues del create.

El codigo generado en cada empresa es unico (`CR-MAIN-{tenant_id}`) para que las dos cajas de las dos empresas no choquen si la BD de testing se reutiliza entre ejecuciones de test.

## Pruebas Ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test
```

Resultado:

- 339 pruebas pasadas.
- 2187 aserciones.
- 0 pruebas fallidas.
- 0 errores.

## Archivos Tocados

- `app/Modules/Sync/Services/SyncEventApplier.php`
- `tests/Feature/Tenancy/OperationalTenantIsolationTest.php`
