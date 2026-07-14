# SYNC_OPERATIONS

> Estado del sistema de sincronizacion local-nube al 2026-07-14.
> Combina: setup inicial del VPS, comandos artisan del backend, wrapper
> local (Windows), y operacion del worker daemon. Documenta tambien los
> pendientes que quedan fuera de este modulo.

## TL;DR (operador del SaaS)

```powershell
# En el local (Windows) - obtener token de sync para un tenant y pushear:
cd C:\Users\gafit\Documents\INVENTARIOARENS
python scripts/sync_token.py <tenant-slug> --run
# Ejemplos:
python scripts/sync_token.py mi-empresa --run
python scripts/sync_token.py grupo-prueba --run

# Solo emitir token (no tocar .env):
python scripts/sync_token.py <tenant-slug> --print
```

El script automaticamente:
1. SSH al VPS (paramiko con la password guardada).
2. Ejecuta `php artisan sync:ensure-and-token <slug>` en el VPS.
3. Parsea el `TOKEN=...` del output.
4. Actualiza `SYNC_CLOUD_URL` y `SYNC_CLOUD_TOKEN` en el `.env` local.
5. Si le pasaste `--run`, ejecuta `php artisan sync:run <slug>` para hacer el push inmediato.

---

## Estado actual del VPS (217.216.80.158)

| Componente | Estado | Notas |
|---|---|---|
| Git HEAD | commit `254c739` (main) | `feat(dev): script sync_token.py + AGENTS.md sync setup` |
| BD `inventory_arens` | Wipeada + migrada | 75+ migraciones (sync, catalog, warehouses, alerts, etc) |
| Sistema de sync (5 modelos) | Funcionando | `SyncNode`, `SyncOutbox`, `SyncInbox`, `SyncState`, `SyncTenantReadiness` |
| Composer | `composer dump-autoload` OK | Modelos cargan sin error |
| Cache | `php artisan optimize:clear` ejecutado | Config + routes + views limpios |
| Worker daemon | `inventarioarens-sync.timer` activo | Corre cada **15 segundos** |
| Workers de cron | `inventarioarens-sync.timer` | Apunta a `inventoryarens-sync.service` |
| URL cloud | `https://app.miinventariofacil.com/api` | Config en `.env` del VPS |

## Comandos artisan disponibles (VPS)

| Comando | Uso | Que hace |
|---|---|---|
| `php artisan sync:ensure-and-token <slug>` | Setup one-liner | Crea tenant + user + pivot + sync_node (si faltan) y emite token. Idempotente: revoca tokens anteriores. |
| `php artisan sync:issue-token <slug> <email>` | Solo emitir | Emite token (requiere tenant + user preexistentes). |
| `php artisan sync:apply-inbox` | Manual | Procesa eventos en sync_inbox de un tenant especifico. |
| `php artisan sync:apply-all-inboxes` | Manual (cron) | Procesa inbox de TODOS los tenants activos. |
| `php artisan sync:run <slug>` | One-shot | Un ciclo de push local→cloud + pull cloud→local. Se corre en el LOCAL. |
| `php artisan sync:daemon <slug>` | Loop | Mantiene sync corriendo por ciclos. Se corre en el LOCAL con run-sync-hidden.vbs. |
| `php artisan sync:reset-readiness` | Mantenimiento | Resetea el estado local de preparacion. |
| `php artisan sync:prepare-local` | Setup | Prepara un tenant + user para poder ejecutar la primera sync. |

## Worker daemon configurado

`/etc/systemd/system/inventarioarens-sync.service`:
- Tipo: `oneshot` (corre, termina, espera timer).
- Exec: `/usr/bin/php /opt/inventarioarens-cloud/artisan sync:apply-all-inboxes --limit=200`
- User: `www-data`, WorkingDirectory: `/opt/inventarioarens-cloud`
- Log: `/var/log/inventarioarens-sync.log` (append stdout+stderr)

`/etc/systemd/system/inventarioarens-sync.timer`:
- `OnBootSec=10sec`, `OnUnitActiveSec=15sec`
- Activado y corriendo.

Comandos utiles:
```bash
systemctl status inventarioarens-sync.timer
systemctl list-timers --all | grep inventarioarens
tail -f /var/log/inventarioarens-sync.log
```

## Setup inicial (one-time, ya hecho)

Los pasos que se ejecutaron en esta sesion para dejar el VPS funcional:

1. **Wipe DB + recreate + migrate**:
   ```bash
   ssh root@217.216.80.158
   sudo -u postgres pg_dump inventory_arens | gzip > /tmp/inventory_arens_backup_20260715_004614.sql.gz
   sudo -u postgres psql -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='inventory_arens' AND pid <> pg_backend_pid();"
   sudo -u postgres psql -c 'DROP DATABASE inventory_arens;'
   sudo -u postgres psql -c 'CREATE DATABASE inventory_arens OWNER postgres;'
   cd /opt/inventarioarens-cloud && php artisan migrate --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   ```

2. **Pull + composer dump-autoload**:
   ```bash
   cd /opt/inventarioarens-cloud
   git pull --rebase origin main
   composer dump-autoload --classmap-authoritative
   php artisan optimize:clear
   ```

3. **Setup tenant demo** (que ya existia antes del wipe):
   ```php
   // Correr via tinker en el VPS (o usar sync:ensure-and-token que automatiza esto):
   $t = Tenant::firstOrCreate(['slug' => 'mi-empresa'], ['name' => 'Mi Empresa Inicial']);
   $u = User::firstOrCreate(['email' => 'gabo@gabo.com'], [..., 'is_platform_admin' => true]);
   $t->users()->attach($u, ['status' => 'active']);
   ```

4. **Configurar worker daemon**:
   ```bash
   # Ver docs/SYNC_AUTO_WORKER_Y_API_PERMANENTE_2026-07-06.md para el detalle.
   systemctl daemon-reload
   systemctl enable --now inventarioarens-sync.timer
   ```

## Operacion diaria (operador del SaaS)

### Agregar un tenant nuevo (one-liner desde el local)

```powershell
python scripts/sync_token.py nuevo-tenant --user=sync@local --node-name="Local-Node"
```

Output:
```
[OK] Tenant creado: nuevo-tenant (id=N, name='Nuevo Tenant')
[OK] User creado: sync@local (id=N, password='xxxxxx')
[OK] Pivot tenant_user creado: tenant=nuevo-tenant, user=sync@local, status=active
[NEW] SyncNode creado: LOCAL-NUEVO-TENANT-VMI3267687 (id=N, type=local)
[NEW] Token emitido (vence en 365 dias)

========================================================
SYNC TOKEN para tenant "nuevo-tenant"
========================================================
  Tenant ID:    N
  Tenant slug:  nuevo-tenant
  User ID:      N
  User email:   sync@local
  Node ID:      N
  Node code:    LOCAL-NUEVO-TENANT-VMI3267687

  TOKEN=AbCdEf123...

  Copia este token AHORA. No se vuelve a mostrar.
  Para usar en el .env del local:
    SYNC_CLOUD_URL=https://app.miinventariofacil.com/api
    SYNC_CLOUD_TOKEN=AbCdEf123...
========================================================

[OK] C:\Users\gafit\Documents\INVENTARIOARENS\.env actualizado (SYNC_CLOUD_TOKEN + SYNC_CLOUD_URL)
```

### Sincronizar un tenant (push local → cloud + pull cloud → local)

```powershell
python scripts/sync_token.py mi-empresa --run
# (el --run hace el push y el pull al final)
```

### Monitorear el worker daemon (cada 15s)

```powershell
ssh root@217.216.80.158 "tail -f /var/log/inventarioarens-sync.log"
```

### Forzar una corrida inmediata del apply-inbox (sin esperar 15s)

```powershell
ssh root@217.216.80.158 "cd /opt/inventarioarens-cloud && php artisan sync:apply-all-inboxes"
```

## Scripts utiles del local

| Script | Para que sirve | Cuando usarlo |
|---|---|---|
| `scripts/sync_token.py` | Principal: SSH + emit token + update .env. | **Usa este siempre que necesites un token.** |
| `scripts/ssh_run.py` | Helper general para correr un comando en el VPS via paramiko. | Para correr un comando cualquiera en el VPS. |
| `scripts/vps_pull_migrate.py` | Pull + rebase + composer dump-autoload + optimize:clear. | Cada vez que se pushea un commit al repo. |
| `scripts/vps_status.py` | Inspeccionar estado del VPS (timer, sync, models). | Para ver el estado actual sin tocar nada. |
| `scripts/vps_wipe.py` | **DANGER**: wipe BD + recreate + migrate. | Solo una vez en setup inicial. **NUNCA en produccion con datos.** |

## Limitaciones arquitecturales (deferred a futuro)

1. **Token por tenant (no multi-tenant)**: un token solo sirve para un tenant. Para multi-tenant se necesitaria un token de platform-admin (is_platform_admin=true) o un endpoint de sync SIN middleware `tenant`. Esto lo dejo como follow-up. Ver `docs/INVENTORY_MODULE_DEFERRED.md`.

2. **Worker solo hace apply-inbox**: el systemd timer actual solo procesa eventos que llegan al cloud. NO hace el push desde el local (eso lo hace el run-sync-hidden.vbs en Windows). El setup final para un push periodico automatico desde el local depende de la configuracion del Windows worker (que ya esta en `scripts/run-sync-hidden.vbs`).

3. **AuthToken de sync tiene id auto-incremental**: cuando se reusa un token existente, NO se reusa el id (siempre se emite uno nuevo). Esto es una decision de seguridad: rotacion.

## Commits hechos en esta sesion (2026-07-14)

| Commit | Descripcion |
|---|---|
| `b725735` | `chore: stash pre-VPS-restore` (82 archivos) |
| `59f930d` | `fix(sync): anadir modelos Eloquent` |
| `5dfbe2b` | `fix(sync): corregir import path de BelongsToTenant` |
| `4931bd0` | `feat(sync): sync:apply-all-inboxes` |
| `1bfabbad` | `fix(sync): tenants usan 'status', no 'is_active'` |
| `d4daeb4` | `fix(sync): corregir contenido duplicado de los 5 modelos sync` |
| `69242a8e` | `fix(sync): reusar token valido existente` (deprecado) |
| `c98163b` | `fix: rotar tokens` (revertido por push error) |
| `8ba8ad5` | `fix: rotar tokens en sync ensure-and-token` |
| `3ea24b3` | `fix: quitar Token ID` (mismo fix) |
| `4ed8907` | `fix: quitar Token ID` (con --reset-author) |
| `254c739` | `feat(dev): script sync_token.py + AGENTS.md sync setup` |

## Troubleshooting

### El push inicial da 401 Unauthenticated

El `SYNC_CLOUD_TOKEN` en tu `.env` no coincide con el hash en la BD del VPS. El wrapper deberia haber puesto el correcto, pero si lo cambiaste manualmente:

1. Borra el token en el VPS:
   ```bash
   ssh root@217.216.80.158 "cd /opt/inventarioarens-cloud && php artisan tinker --execute='
     \\App\\Modules\\Auth\\Models\\AuthToken::where(\"tenant_id\", 1)->update([\"revoked_at\" => now()]);
   '"
   ```
2. Vuelve a correr el wrapper:
   ```powershell
   python scripts/sync_token.py mi-empresa --print
   ```
3. Copia el nuevo token al `.env`.

### Worker daemon no esta corriendo

```bash
ssh root@217.216.80.158
systemctl status inventarioarens-sync.timer
# Si dice "inactive" o "failed":
systemctl daemon-reload
systemctl enable --now inventarioarens-sync.timer
systemctl list-timers --all | grep inventarioarens
```

### El push no aplica en el VPS

```bash
ssh root@217.216.80.158 "cd /opt/inventarioarens-cloud && tail -50 /var/log/inventarioarens-sync.log"
```

Busca errores `last_error` o mensajes de `failed`. Si hay errores de CSRF (origin mismatch), revisa `app.allowed_origins_for_csrf` en `.env` del VPS.

### Modelos sync "Class not found"

```bash
ssh root@217.216.80.158 "cd /opt/inventarioarens-cloud && composer dump-autoload --classmap-authoritative"
```

### Quiero un token para un tenant que no existe todavia

Usa el wrapper con el slug nuevo. Crea todo automaticamente:

```powershell
python scripts/sync_token.py mi-otro-tenant --user=admin@local
```

Y si no quieres que toque el `.env`:

```powershell
python scripts/sync_token.py mi-otro-tenant --print
# (copias manualmente el token al .env)
```