# API De Alícuotas Fiscales

Fecha: 2026-08-29

Esta API administra el catálogo de tratamientos fiscales por empresa. No emite documentos fiscales
certificados, pero sus alícuotas ya participan en los totales de `Sale`, `SaleItem` y `PosOrder`.

## Endpoints

```text
GET   /api/fiscal/tax-rates
POST  /api/fiscal/tax-rates
GET   /api/fiscal/tax-rates/{taxRate}
PATCH /api/fiscal/tax-rates/{taxRate}
```

La lectura requiere pertenencia activa al tenant. Crear o modificar alícuotas requiere
`settings.manage`. Los códigos son únicos dentro de cada empresa.

## Categorías

- `taxable`: base imponible más IVA.
- `exempt`: operación exenta.
- `exonerated`: operación exonerada.
- `non_taxable`: operación no sujeta/no gravada.

Las categorías no gravadas deben usar alícuota `0`. Una categoría `taxable` puede usar `0` para
representar una alícuota temporal configurada por el administrador, pero no implica por sí sola una
exención legal.

## Producto

Un producto puede recibir `fiscal_tax_rate_id` durante su creación o actualización. La referencia se
valida con `tenant_id`, y el recurso devuelve el snapshot descriptivo:

```json
{
    "fiscal_tax_rate_id": 1,
    "fiscal_tax_rate": {
        "id": 1,
        "code": "IVA16",
        "name": "IVA general",
        "rate": 16,
        "category": "taxable",
        "is_active": true
    }
}
```

La sincronización no copia IDs autoincrementales entre nodos: el applier resuelve la alícuota por
`fiscal_tax_rate_code` dentro del tenant destino y falla si el catálogo aún no está disponible.

## Clasificación masiva

Desde el Centro de Inventario se pueden seleccionar productos y usar la acción `Asignar tratamiento
fiscal`. La acción acepta cualquiera de las categorías del catálogo: `taxable`, `exempt`,
`exonerated` o `non_taxable`.

```text
POST /api/inventory-center/products/bulk-action
GET  /api/inventory-center/products/bulk-operations/{operation}
```

Para una selección de hasta 200 productos se envían sus IDs. Para aplicar la clasificación a todos
los resultados filtrados se envía `all_matching: true` junto con `filters`; el backend devuelve `202`
y procesa la operación en cola. El frontend muestra el progreso y el resultado de productos
actualizados o conservados.

```json
{
    "all_matching": true,
    "filters": {
        "active_status": "active",
        "search": ""
    },
    "action": "assign_fiscal_tax_rate",
    "payload": {
        "fiscal_tax_rate_id": 1,
        "overwrite_existing": false
    }
}
```

`overwrite_existing` es falso por defecto. Por seguridad, un producto que ya tenga tratamiento
fiscal explícito no se reemplaza, lo que protege clasificaciones exentas, exoneradas o no gravadas.
El administrador puede activar el interruptor para reemplazarlas conscientemente. Cada cambio crea
auditoría de producto y evento de sync.

La pantalla de configuración está disponible en `/settings/fiscal`, donde un usuario con
`settings.manage` puede crear o editar alícuotas. Las categorías exento, exonerado y no gravado
fuerzan tasa `0%` tanto en la interfaz como en el backend.

## Cálculo

`FiscalTaxCalculator` calcula por línea y documento base imponible, exentos, exonerados, no gravados,
IVA y total. Recibe explícitamente si los precios incluyen IVA (`pricesIncludeTax`); en este proyecto
los precios de venta son netos y el IVA se agrega al total.

Al crear un borrador se calcula un total provisional. Antes de cobrar o confirmar se recalcula con la
alícuota vigente y se congela el snapshot en `sales`, `sale_items` y `pos_orders`; una venta confirmada
no debe recalcularse con tasas posteriores.

Las promociones y combos aplican descuentos antes de calcular IVA. Los combos heredan por defecto la
clasificación de cada producto, pero pueden declarar:

```json
{
    "fiscal_tax_mode": "override",
    "fiscal_tax_rate_id": 2
}
```

El override solo está permitido para combos y se valida dentro del tenant. El sync replica el código
de la alícuota (`fiscal_tax_rate_code`), nunca el ID local.

## Devoluciones

Al solicitar una devolución se copia a cada línea el snapshot fiscal de la línea vendida, incluyendo
el tratamiento, la alícuota y los importes base/local. La nota de crédito, el saldo a favor, el
reembolso y la cuenta por cobrar usan esos importes originales, incluso si la alícuota del producto
cambia después. Las devoluciones sincronizadas transportan el mismo snapshot y conservan sus
timestamps.

La emisión fiscal certificada y la integración con impresora fiscal siguen fuera de esta fase; el
ticket actual continúa siendo comercial.

## Pruebas

- `tests/Feature/Fiscal/FiscalTaxRateApiTest.php`
- `tests/Feature/Products/ProductFiscalClassificationApiTest.php`
- `tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`
- `tests/Feature/Sync/FiscalTaxRateSyncTest.php`
- `tests/Feature/Promotions/PromotionApiTest.php`
- `tests/Feature/POS/PosPromotionCheckoutTest.php`
- `tests/Feature/Sales/FiscalSaleSnapshotTest.php`
- `tests/Feature/Sync/FiscalSaleSnapshotSyncTest.php`
- `tests/Feature/Sync/PromotionSyncTest.php`
- `tests/Unit/Fiscal/FiscalTaxCalculatorTest.php`
