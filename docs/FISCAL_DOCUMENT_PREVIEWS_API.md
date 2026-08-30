# Borradores Pre-Fiscales Internos

Fecha: 2026-08-29

Este módulo conserva una fotografía interna de una venta confirmada para revisión, impresión
comercial futura o integración posterior. No emite documentos fiscales.

## Endpoints

```text
POST /api/fiscal/documents/previews
GET  /api/fiscal/documents?sale_id=&status=&date_from=&date_to=&per_page=
GET  /api/fiscal/documents/{fiscalDocument}
```

El POST requiere pertenencia activa al tenant y uno de estos permisos:

```text
sales.view
reports.view
reports.sales.view
```

Body:

```json
{
    "sale_id": 123
}
```

La venta debe estar confirmada y pertenecer al tenant actual. Repetir el POST para la misma venta
devuelve el mismo preview, sin crear documentos ni líneas duplicadas.

El listado devuelve previews completos, incluyendo líneas, ordenados por `snapshot_at` descendente.
Acepta `sale_id`, `status=preview`, fechas inclusivas (`date_from`/`date_to`) y `per_page` entre 1 y
100. La paginación conserva los filtros enviados.

## Snapshots

El preview conserva:

- identidad legal/fiscal de la empresa;
- datos fiscales de la sucursal, cuando la venta proviene de POS;
- cliente, nombre fiscal, documento y dirección;
- totales USD/VES e impuestos de la venta;
- producto, almacén, descuentos y tratamiento fiscal de cada línea.

Los snapshots no cambian si posteriormente se edita la venta, el cliente, el producto o la tasa.

## Límites explícitos

- `document_mode` y `document_type` son `internal_preview`.
- `officially_issued` siempre es `false`.
- No existen series, número fiscal, número de control ni autorización en este módulo.
- El preview no es factura, nota de crédito, nota de débito, libro fiscal ni declaración.

En el frontend, el detalle de una venta confirmada muestra `Vista previa interna`. La acción consulta
el historial, reabre el snapshot existente o genera uno si todavía no existe. La ruta
`/fiscal/documents` ofrece la bandeja paginada y permite abrir cualquier preview. El diálogo permite
imprimir una vista comercial; no confirma, modifica ni emite la venta.

El flujo E2E está definido en `frontend/e2e/fiscal.api.spec.ts` y
`frontend/e2e/fiscal.ui.spec.ts`. Requiere `PLAYWRIGHT_E2E_EMAIL`, `PLAYWRIGHT_E2E_PASSWORD` y
`PLAYWRIGHT_E2E_TENANT`, además de una sesión de caja abierta y productos con stock para el fixture.
