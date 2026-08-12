# Laboratorio de dia simulado (lab:day)

Integrado el 2026-08-12. Es un runner que ejecuta un **ciclo real de negocio** contra la API de
INVENTARIOARENS (local o VPS) con datos desechables, y genera un reporte por fase.

El objetivo es probar la app **en situacion real**: login, ventas POS masivas (cantidad y
serializado), devolucion, compra con recepcion y traslado logistico, de forma repetible y
automatizable.

## 1. Comando backend: `php artisan lab:day`

```bash
php artisan lab:day \
  --tenants=3 --products=10 --password='labday-password-2026' \
  --prefix=labday --sales=10 --base-url=http://127.0.0.1:8000/api \
  --force
```

Opciones:

| Opcion | Default | Descripcion |
|---|---|---|
| `--tenants` | 3 | Empresas de laboratorio (3-5, alineado con `stress:seed`) |
| `--products` | 10 | Productos por empresa (10-200) |
| `--prefix` | `labday` | Prefijo seguro de los slugs (solo minusculas/numeros/guion) |
| `--password` | - | Clave de laboratorio (min 12) |
| `--sales` | 10 | Ventas POS simuladas por empresa |
| `--base-url` | `http://127.0.0.1:8000/api` | API destino (local o VPS) |
| `--seed-only` | - | Solo prepara datos, no ejecuta el ciclo |
| `--dry-run` | - | Prepara datos y valida, sin ejecutar operaciones |
| `--force` | - | Requerido, confirma creacion de datos de laboratorio |
| `--allow-production` | - | Requerido si `APP_ENV=production` (ventana aprobada) |

### 1.1 Fase seed (preparacion)

El comando delega en `stress:seed` con:

- `--role=gerente` (el lab ejecuta compras, traslados y devoluciones, que Vendedor no puede).
- `--warehouses=2` (los traslados necesitan almacen origen y destino distintos).
- `--supplier` (crea un proveedor `LAB-SUP-XX` por empresa para las compras).

Se preservo el contrato original de `stress:seed` (defaults `vendedor`/1 almacen/sin proveedor),
asi el laboratorio k6 existente sigue funcionando igual.

### 1.2 Fase ciclo de negocio (`LabDayService`)

`app/Support/Lab/LabDayService.php` recorre por cada empresa `{prefix}-XX`:

1. **Login**: `POST /auth/login` con `X-Tenant` -> token Bearer.
2. **Bootstrap**: `GET /pos/bootstrap` -> almacenes, sesion de caja abierta, lista de precio
   default, metodo de pago cash.
3. **Ventas POS**: `POST /pos/checkouts` x `--sales`, con `Idempotency-Key` unica por venta.
4. **Devolucion**: `POST /sales-returns` (sobre la primera venta) -> `approve` -> `process`
   con `refund_mode=none`.
5. **Compra**: `POST /purchases` (proveedor del lab, `purchase_currency=USD`) ->
   `PATCH /purchases/{id}/receive` (genera `account_payable`).
6. **Traslado logistico**: `POST /inventory-transfers` (`validation_mode=logistics`,
   `from_warehouse=WH1`, `to_warehouse=WH2`) -> `prepare` -> `dispatch` -> `receive`.

Cada fase registra `ok/fail` en el reporte. Si una fase lanza excepcion, el reporte marca
`error` para esa empresa y el comando termina con codigo de fallo (pero el resto de empresas
se procesa).

### 1.3 Reporte

`storage/app/lab-reports/<fecha>/<prefix>-<timestamp>.json`

```json
{
  "created_at": "...",
  "prefix": "labday",
  "base_url": "http://127.0.0.1:8000/api",
  "tenants": {
    "labday-01": {
      "phases": {
        "login": { "ok": true },
        "bootstrap": { "ok": true, "warehouse_id": 1, "session_id": 2 },
        "sales": { "attempts": 10, "paid": 10, "first_sale_id": 5 },
        "sales_return": { "requested": true, "approved": true, "processed": true },
        "purchase": { "draft": 3, "received": 3, "payable": true },
        "transfer": { "created": 7, "prepared": true, "dispatched": true, "received": true }
      }
    }
  }
}
```

## 2. Wrapper de escritorio: `scripts/run-day-lab.ps1`

Orquesta el lab completo desde Windows:

```powershell
# Local (Laragon + API en 127.0.0.1:8000)
.\scripts\run-day-lab.ps1 -Target local

# Local sin k6 ni Playwright (solo ciclo de negocio)
.\scripts\run-day-lab.ps1 -Target local -SkipK6 -SkipPlaywright

# VPS (ventana aprobada; corre seed+lab:day en el servidor, k6 contra la URL publica)
.\scripts\run-day-lab.ps1 -Target vps -AllowProduction
```

Parametros clave: `-Target local|vps`, `-Prefix`, `-Password`, `-Tenants`, `-Products`,
`-Sales`, `-K6Vus`, `-LocalApi`, `-VpsApi`, `-SkipK6`, `-SkipPlaywright`, `-AllowProduction`,
`-PhpPath`.

**Nota VPS**: el `lab:day` (que usa `Http` de Laravel hacia la propia API) se ejecuta dentro del
servidor. El wrapper local hace el `stress:seed` + `lab:day` solo en local; para VPS se corre
`php artisan lab:day ...` con SSH (ver `scripts/ssh_run.py`) o directamente en
`/opt/inventarioarens-cloud`, y el k6 se dispara contra la URL publica.

## 3. Integracion con los laboratorios existentes

| Laboratorio | Cuando | Que valida |
|---|---|---|
| `lab:day` (este) | Diario/por commit | Ciclo real de negocio end-to-end (login->POS->devolucion->compra->traslado) |
| k6 `pos-cash-inventory.js` | Carga/estres | Ventas concurrentes + serializado + idempotencia + latencias p95/p99 |
| k6 `pos-stock-race.js` | Colision | Solo un cajero vende la ultima unidad (cantidad e IMEI) |
| k6 `three-tenants-web.js` | Lectura multi-tenant | Dashboard/catalogo + aislamiento entre empresas |
| `sync-e2e-lab.ps1` | Sync | 2 nodos locales + nube: foto inicial, outbox, ACK, no-duplicados |
| `sync-smoke-test.ps1` | Sync rapido | Smoke de transporte cloud<->local |
| Playwright `frontend/e2e/` | UI | Flujos visuales POS (login, checkout, credit, pending, transfers) |

## 4. Buenas practicas y seguridad

- **Nunca** contra una empresa operativa: solo tenants `{prefix}-XX` desechables.
- **VPS**: requiere `-AllowProduction` + prefijo exclusivo; idealmente en una ventana aprobada
  y con observacion de CPU/PostgreSQL/`PERF` del VPS.
- El seed es idempotente (`updateOrCreate` por slug/sku), puede re-ejecutarse.
- El reporte del lab **no** contiene credenciales (solo status/ids).
- Si una fase falla por un bug real (no por datos), el reporte lo expone con el mensaje; corregir
  el codigo, no debilitar el lab.

## 5. Tests

- `tests/Feature/Console/LabDaySeedTest.php` - guardas de seguridad (force, production,
  password), preparacion (rol Gerente, 2 almacenes, proveedor, caja abierta), dry-run.
- `tests/Feature/Console/LabDayServiceTest.php` - ciclo HTTP completo con `Http::fake()`
  (login, bootstrap, ventas, devolucion, compra, traslado; errores de login y de sesion de caja).

Para correrlos:

```bash
php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/Console/LabDaySeedTest.php
php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/Console/LabDayServiceTest.php
```

## 6. Siguientes ampliaciones (idea)

- Agregar CxC/CxP real al ciclo (cobro de venta a credito + pago de CxP de compra).
- Escenario de devolucion con reembolso en efectivo desde caja (`refund_mode=cash`).
- Integracion del `lab:day` como job diario de GitHub Actions contra un backend de pruebas.
- Variantes en las ventas POS (producto con variante, descuentos, credit).
