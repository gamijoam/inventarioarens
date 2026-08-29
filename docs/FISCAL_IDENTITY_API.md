# API De Identidad Fiscal

Fecha: 2026-08-29

Esta primera subfase prepara los datos del emisor sin convertir el ticket comercial en una factura
fiscal. `Sale` y `PosOrder` siguen representando la operación comercial interna.

## Endpoints

Todas las rutas requieren autenticación Bearer/cookie, `X-Tenant` y pertenencia activa a la empresa.

```text
GET   /api/fiscal/identity
PATCH /api/fiscal/identity
GET   /api/fiscal/identity/branches/{branch}
PATCH /api/fiscal/identity/branches/{branch}
```

La lectura está disponible para miembros activos. Las modificaciones requieren `settings.manage`.
La sucursal siempre se resuelve dentro del tenant actual; una sucursal de otra empresa responde 404.

## Identidad De Empresa

`PATCH /api/fiscal/identity` acepta campos parciales:

```json
{
    "legal_name": "Empresa Fiscal, C.A.",
    "tax_id": "J-12345678-9",
    "fiscal_address": "Av. Principal, Local 1",
    "city": "Caracas",
    "state": "Distrito Capital",
    "phone": "+58 212 555 0000",
    "email": "fiscal@empresa.test",
    "tax_condition": "ordinary"
}
```

Las condiciones disponibles son `ordinary`, `formal`, `special`, `exempt` y `non_taxpayer`.
El RIF se valida con estructura venezolana, pero su existencia y vigencia deben ser confirmadas por
el administrador y el asesor tributario.

La información de empresa se conserva en `tenant_settings.settings.company`, que ya alimenta los
documentos comerciales y los recursos de configuración.

## Identidad De Sucursal

`PATCH /api/fiscal/identity/branches/{branch}` acepta:

```json
{
    "fiscal_address": "Av. Bolivar, Local 2",
    "city": "Valencia",
    "state": "Carabobo",
    "phone": "+58 241 555 0000",
    "email": "valencia@empresa.test",
    "tax_condition": "formal"
}
```

Los datos se almacenan en `branches` con `tenant_id`, se incluyen en `BranchResource` y viajan en
los eventos `branch.created`/`branch.updated`. Los eventos antiguos sin estos campos conservan la
información fiscal existente en el nodo destino.

## Límites

- Esta API no emite facturas fiscales.
- No genera series, números de control ni autorizaciones.
- El cálculo de IVA ya participa en `Sale`, `SaleItem` y `PosOrder`, conservando snapshots internos,
  pero todavía no está conectado a documentos fiscales emitidos.
- No integra máquina fiscal, forma libre ni proveedor digital.
- La condición fiscal configurada no constituye certificación ni cumplimiento legal.

## Pruebas

- `tests/Feature/Fiscal/FiscalIdentityApiTest.php`
- `tests/Feature/Sync/BranchWarehouseSyncTest.php`
- `tests/Feature/Sync/TenantSettingsSyncTest.php`
- `frontend/src/features/fiscal-identity/api.test.ts`
- `frontend/src/features/company-settings/CompanySettingsPanel.test.tsx`
