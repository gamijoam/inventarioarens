# Prueba automatizada de sincronizacion local-nube

Fecha: 2026-07-05

## Objetivo

Se agrego un asistente de prueba para validar la sincronizacion local <-> nube sin tener que ejecutar cada paso manualmente.

Archivo agregado:

- `scripts/sync-smoke-test.ps1`

El script prepara una prueba controlada con:

- empresa por defecto: `demo-caracas`;
- nodo local: `LOCAL-SMOKE-01`;
- producto de prueba: `SYNC-SMOKE-001`;
- lista de precio de prueba: `SMOKE`;
- API nube temporal en `http://127.0.0.1:8010/api`.

## Que valida

La prueba valida dos direcciones:

1. **Local hacia nube**
   - Crea un evento pendiente en el `sync_outbox` local.
   - Ejecuta `php artisan sync:run`.
   - Verifica que el evento local quede `processed`.
   - Verifica que la nube reciba el evento en `sync_inbox`.

2. **Nube hacia local**
   - Crea un evento pendiente en el `sync_outbox` de la nube.
   - La API nube temporal entrega ese evento al worker local.
   - El local lo guarda en `sync_inbox`.
   - El aplicador local actualiza el precio del producto en la lista `SMOKE`.
   - Verifica que el precio local quede en `77.77 USD`.

## Requisitos antes de ejecutar

1. PostgreSQL local activo:

```text
127.0.0.1:5434
BD: inventory_arens
Usuario: inventory_arens
```

2. PostgreSQL del VPS accesible directo:

```text
Host: 217.216.80.158
Puerto: 5432
BD: inventory_arens
Usuario: postgres
```

3. Ejecutar sin VPN si el VPN bloquea o altera el protocolo PostgreSQL.

4. No debe estar ocupado el puerto `8010` en la PC.

## Comando

Desde la raiz del proyecto:

```powershell
.\scripts\sync-smoke-test.ps1 -CloudDbPassword "CLAVE_POSTGRES_DEL_VPS"
```

Si quieres usar otro puerto para la API nube temporal:

```powershell
.\scripts\sync-smoke-test.ps1 -CloudDbPassword "CLAVE_POSTGRES_DEL_VPS" -CloudApiPort 8011
```

## Resultado esperado

Debe mostrar al final algo similar a:

```json
{
  "ok": true,
  "precio_local_smoke": 77.77,
  "precio_esperado": 77.77,
  "outbox_local": "processed",
  "inbox_nube": "received",
  "outbox_nube": "processed",
  "inbox_local": "applied"
}
```

Y luego:

```text
PRUEBA COMPLETADA: sincronizacion local <-> nube validada.
```

## Que datos deja

La prueba deja datos minimos para auditoria tecnica:

- producto `SYNC-SMOKE-001`;
- lista `SMOKE`;
- eventos en `sync_outbox` y `sync_inbox`;
- token temporal de API en la nube con vencimiento de 7 dias.

Estos datos se pueden mantener para diagnostico mientras se estabiliza la sincronizacion. Mas adelante se puede agregar una opcion de limpieza si se desea.

## Seguridad

El script no modifica `.env` y no guarda la clave del VPS en Git.

La clave se pasa al proceso en tiempo de ejecucion:

```powershell
-CloudDbPassword "..."
```

La API nube temporal se levanta solo en `127.0.0.1`, por lo tanto no queda expuesta en internet.

## Prueba realizada al crear esta herramienta

Se valido la sintaxis del script con PowerShell:

```powershell
$content = Get-Content -LiteralPath scripts\sync-smoke-test.ps1 -Raw
[scriptblock]::Create($content) | Out-Null
```

Resultado:

```text
PowerShell OK
```
