# INVENTARIOARENS Toolbox

> Guia completa de Windows, Linux y VPS: `docs/SYNC_TOOLBOX_WINDOWS_LINUX.md`.

CLI unificado de operaciones para el SaaS INVENTARIOARENS. Cross-platform
(Linux + Windows). Una sola descarga, un solo comando, todo listo.

## Quickstart

### Linux
```bash
unzip inventoryarens-toolbox-v1.0.0.zip
cd inventoryarens-toolbox
./inventoryarens wizard
```

### Windows (CMD)
```
inventoryarens.bat wizard
```

### Windows (PowerShell, como Admin)
```
.\inventoryarens.ps1 wizard
```

Eso es todo. El CLI:
1. Se conecta al VPS via SSH (root@212.28.176.157, password `GaboMac12`)
2. Emite un nuevo token de sync via `php artisan sync:ensure-and-token`
3. Lo guarda por tenant en `storage/app/sync-worker/sync-config.json`
4. Configura el auto-start:
   - **Linux**: crea `~/.config/systemd/user/inventoryarens-sync.{service,timer}` y los habilita (cada 15s)
   - **Windows**: crea una Task por tenant: `SistemaInventarioSync-{tenant}`

Para sincronizar dos empresas en la misma computadora, ejecuta el asistente dos veces:

```bash
./inventoryarens wizard --tenant demo-caracas --user admin@demo.test
./inventoryarens wizard --tenant demo-valencia --user admin@demo.test
./inventoryarens status --tenant demo-caracas
./inventoryarens status --tenant demo-valencia
```

El intervalo recomendado para una PC local es `15` segundos. La tarea de Windows/systemd actua como vigilante; el worker interno es el que sincroniza en ciclos cortos.

## Comandos

| Comando | Que hace |
|---|---|
| `wizard` | Asistente interactivo para agregar una empresa a esta PC |
| `install sync` | Emite token + configura auto-start (Linux systemd / Windows Task Scheduler) |
| `worker install-task --tenant <slug>` | Repara sólo el auto-start local usando el token ya guardado, sin emitir token nuevo |
| `install printer` | Instala/repara el printer agent en :17777 |
| `printer status` | Verifica tarea/servicio y health check del agente local |
| `printer start/stop/restart` | Controla el agente local de impresion |
| `printer test` | Prueba `http://127.0.0.1:17777/health` sin depender de curl |
| `status --tenant <slug>` | Health check: DB, token, worker, last sync. Sale con codigo 0/1/2 segun estado. |
| `logs sync --tenant <slug>` | Tail del log del worker de una empresa. Ctrl+C para salir. |
| `logs printer` | Tail del log del printer agent. |
| `uninstall sync` | Detiene y elimina auto-start + limpia. |
| `token rotate` | Re-emite token sin reinstalar servicios. Util si el token expira. |
| `update` | `git pull` + `composer install` + `migrate` + reinicia worker. |

## VPS cloud: activar, detener y diagnosticar sync

En el VPS `212.28.176.157`, el worker correcto no es por empresa local. Es un timer de sistema que procesa los inbox de todos los tenants activos:

```bash
ssh root@212.28.176.157
cd /opt/inventarioarens-cloud
```

Activar o reparar el timer:

```bash
cat >/etc/systemd/system/inventarioarens-sync.service <<'EOF'
[Unit]
Description=InventarioArens sync apply-inbox (todos los tenants)
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/opt/inventarioarens-cloud
ExecStart=/usr/bin/php artisan sync:apply-all-inboxes --limit=200
StandardOutput=append:/var/log/inventarioarens-sync.log
StandardError=append:/var/log/inventarioarens-sync.log

[Install]
WantedBy=multi-user.target
EOF

cat >/etc/systemd/system/inventarioarens-sync.timer <<'EOF'
[Unit]
Description=Run InventarioArens sync every 15 seconds

[Timer]
OnBootSec=10sec
OnUnitActiveSec=15sec
Unit=inventarioarens-sync.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now inventarioarens-sync.timer
```

Diagnosticar:

```bash
systemctl status inventarioarens-sync.timer --no-pager
systemctl status inventarioarens-sync.service --no-pager
journalctl -u inventarioarens-sync.service -n 100 --no-pager
tail -n 100 /var/log/inventarioarens-sync.log
```

Ejecutar una pasada manual:

```bash
cd /opt/inventarioarens-cloud
sudo -u www-data php artisan sync:apply-all-inboxes --limit=200
```

Detener temporalmente:

```bash
systemctl stop inventarioarens-sync.timer
```

Desactivar:

```bash
systemctl disable --now inventarioarens-sync.timer
```

Reactivar:

```bash
systemctl enable --now inventarioarens-sync.timer
```

## PC local: varias empresas

Cada empresa local debe tener su propio token y su propio worker.

Windows crea tareas:

```text
SistemaInventarioSync-demo-caracas
SistemaInventarioSync-demo-valencia
```

Linux crea timers de usuario:

```text
inventoryarens-sync-demo-caracas.timer
inventoryarens-sync-demo-valencia.timer
```

Agregar una empresa:

```bash
./inventoryarens wizard --tenant demo-caracas --user admin@demo.test
```

Revisar una empresa:

```bash
./inventoryarens status --tenant demo-caracas
./inventoryarens logs sync --tenant demo-caracas
```

Detener o quitar una empresa de esta PC:

```bash
./inventoryarens uninstall sync --tenant demo-caracas
```

## Toolbox interactivo para tecnico

Cuando no quieras recordar comandos, usa el menu:

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 toolbox
```

Linux:

```bash
./inventoryarens toolbox
```

Desde ahi puedes:

- diagnosticar la PC,
- ver, iniciar, detener o reiniciar el worker,
- correr una sincronizacion manual,
- reintentar eventos fallidos del inbox,
- reintentar un `sync_inbox` por ID,
- reintentar imagenes fallidas y descargarlas,
- emitir sync de imagen por SKU,
- recuperar un tenant local desde la nube.

Comandos directos utiles:

```powershell
# Estado del worker de una empresa
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 worker status --tenant demo-caracas

# Reiniciar worker
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 worker restart --tenant demo-caracas

# Reparar sólo la tarea local del worker, sin pedir token nuevo
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 worker install-task --tenant demo-caracas

# Reiniciar worker y reintentar fallidos
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 worker refresh-and-retry --tenant demo-caracas

# Reintentar todos los eventos fallidos
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 sync retry-failed --tenant demo-caracas

# Reintentar solo un inbox especifico
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 sync retry-inbox --tenant demo-caracas --id 316

# Reintentar imagenes fallidas y descargar archivos
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 images retry-failed --tenant demo-caracas

# Emitir nuevamente el evento de imagen de un producto
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 images emit --tenant demo-caracas --product-sku COCOSETE-3
```

Los comandos de reintento no borran datos. Solo cambian eventos fallidos de `sync_inbox` a `received`,
limpian el error anterior y ejecutan el aplicador normal.

Para una revision mas automatica:

```powershell
# Diagnostica un tenant y sugiere acciones
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 doctor --tenant demo-caracas

# Diagnostica y aplica reparaciones seguras: reinicia worker + reintenta inbox fallidos
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 doctor --tenant demo-caracas --fix

# Revisar todos los tenants configurados en esta PC
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 status --all
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 worker restart --all
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 sync retry-failed --all
```

Cuando necesites enviar informacion para soporte sin revelar tokens:

```powershell
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 support bundle --tenant demo-caracas
```

El ZIP se crea en `storage/app/support-bundles/` e incluye diagnostico, logs locales y
`sync-config.redacted.json` con tokens ocultos.

## Status - ejemplo de output

```
CHECKLIST
  ✓ DB local: conecta (127.0.0.1:5432)
  ✓ API cloud: 200 OK
  ✓ Token sync: SYNC_CLOUD_... (len 80)
  ✓ Worker sync - Timer systemd: active
  ✓ Worker sync - Service systemd: active
  ✓ Worker sync - Ultima corrida: Tue 2026-07-21 16:00:00 CEST
  ✓ Ultima sync: 2026-07-21 16:00:12

8/8 checks OK. Todo bien.
```

## Personalizacion

Variables de entorno (opcional):

| Variable | Default | Que hace |
|---|---|---|
| `INVENTORYARENS_PHP` | `php` | Path al binario PHP |
| `INVENTORYARENS_SSH_HOST` | `212.28.176.157` | Host del VPS |
| `INVENTORYARENS_SSH_USER` | `root` | User SSH |
| `INVENTORYARENS_PYTHON` | (auto-detect) | Path al Python para el wrapper Windows |

## Troubleshooting

### "Otra instancia esta corriendo"
El lock esta en `~/.inventoryarens/toolbox.lock` (Linux) o `%APPDATA%/inventoryarens/toolbox.lock` (Windows). Borra el archivo si quedo stale.

### "psql: connection to server failed"
Tu `.env` local tiene DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD mal. El CLI los lee para el health check.

### "API cloud: 502 Bad Gateway"
El VPS nuevo (212.28.176.157) tiene nginx-fpm detras de Traefik. Si Traefik se cayo, las requests al API fallan. Revisa con `curl -v https://app.miinventariofacil.com/api/up`.

### "Token sync: NOT set in DB cloud"
El VPS tiene un user/tenant mismatch. Verifica que tu email (`gabo@gabo.com` por default) este linkeado al tenant correcto via `tenant_user` table.

### En Windows: "Python is not recognized"
Instala Python 3.8+ desde https://python.org o Microsoft Store. Marcá "Add Python to PATH" durante la instalacion. O setea `INVENTORYARENS_PYTHON=C:\Python311\python.exe`.

## Para developers

El codigo del CLI esta en `bin/inventoryarens` (single file, Python 3.8+ stdlib).

Reconstruir el zip en Linux/macOS:

```bash
bash scripts/build-toolbox.sh
```

Reconstruir el zip en Windows:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\build-toolbox.ps1
```

Output: `dist/inventoryarens-toolbox-vX.Y.Z.zip`.

Estructura interna:
```
inventoryarens-toolbox/
├── inventoryarens              # CLI Python (unico binario cross-platform)
├── inventoryarens.bat           # wrapper Windows CMD
├── inventoryarens.ps1           # wrapper Windows PowerShell
├── systemd/                     # Linux (usado por install en Linux)
│   ├── inventoryarens-sync.service
│   ├── inventoryarens-sync.timer
│   └── inventoryarens-printer.service
├── windows/                      # Windows (usado por install en Windows)
│   ├── install-task.ps1
│   └── uninstall-task.ps1
├── README.md                     # este archivo
├── QUICKSTART.txt                 # quickstart embebido
└── LICENSE
```
