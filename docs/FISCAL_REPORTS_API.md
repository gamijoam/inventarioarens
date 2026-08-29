# Reporte Interno De IVA

Fecha: 2026-08-29

Este reporte sirve para control interno y preparación de información para el contador. No emite
facturas fiscales, no genera número de control y no sustituye un libro fiscal ni una declaración.

## Endpoint

```text
GET /api/reports/fiscal/iva
```

Requiere pertenencia activa al tenant y uno de estos permisos:

```text
reports.view
reports.sales.view
finance_reports.view
```

Filtros disponibles:

- `date=2026-08-29`
- `date_from=2026-08-01&date_to=2026-08-31`
- `branch_id`
- `customer_id`
- `product_id`

El informe solo agrupa ventas confirmadas del tenant actual y utiliza los snapshots fiscales
guardados en sus líneas. Las categorías son `taxable`, `exempt`, `exonerated`, `non_taxable` y
`unclassified` para operaciones históricas sin clasificación fiscal.

La respuesta contiene:

- `period`: período consultado.
- `summary`: cantidad de ventas, bases por tratamiento, IVA y totales USD/VES.
- `rows`: desglose por código, categoría y alícuota histórica.
- `generated_at`: momento de generación.

Los importes confirmados no se recalculan usando alícuotas actuales. Para una venta confirmada,
el snapshot de la operación es la fuente de verdad.
