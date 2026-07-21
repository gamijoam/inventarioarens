# INVENTARIOARENS Toolbox

CLI unificado de operaciones para el SaaS INVENTARIOARENS. Cross-platform
(Linux + Windows). Una sola descarga, un solo comando, todo listo.

## Quickstart

### Linux
```bash
unzip inventoryarens-toolbox-v1.0.0.zip
cd inventoryarens-toolbox
./inventoryarens install sync
```

### Windows (CMD)
```
inventoryarens.bat install sync
```

### Windows (PowerShell, como Admin)
```
.\inventoryarens.ps1 install sync
```

Eso es todo. El CLI:
1. Se conecta al VPS via SSH (root@212.28.176.157, password `GaboMac12`)
2. Emite un nuevo token de sync via `php artisan sync:ensure-and-token`
3. Lo escribe en tu `.env` local
4. Configura el auto-start:
   - **Linux**: crea `~/.config/systemd/user/inventoryarens-sync.{service,timer}` y los habilita (cada 15s)
   - **Windows**: crea la Task `InventoryArensSync` que arranca en cada logon

## Comandos

| Comando | Que hace |
|---|---|
| `install sync` | Emite token + configura auto-start (Linux systemd / Windows Task Scheduler) |
| `install printer` | (Fase 2, stub) Instala printer agent en :17777 |
| `status` | Health check: DB, token, worker, last sync. Sale con codigo 0/1/2 segun estado. |
| `logs sync` | Tail del log del worker. Ctrl+C para salir. |
| `logs printer` | (Fase 2) Tail del log del printer agent. |
| `uninstall sync` | Detiene y elimina auto-start + limpia. |
| `token rotate` | Re-emite token sin reinstalar servicios. Util si el token expira. |
| `update` | `git pull` + `composer install` + `migrate` + reinicia worker. |

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

El codigo del CLI esta en `bin/inventoryarens` (single file, ~500 lineas, Python 3.8+ stdlib).
Para reconstruir el zip: `bash scripts/build-toolbox.sh`. Output: `dist/inventoryarens-toolbox-vX.Y.Z.zip`.

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
