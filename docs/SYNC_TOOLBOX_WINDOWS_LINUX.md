# Sync Toolbox: Windows, Linux y VPS

Esta guia explica como activar, desactivar, diagnosticar y reparar la sincronizacion de INVENTARIOARENS.

Hay dos tipos de worker:

- **Worker cloud/VPS**: corre en `212.28.176.157` y procesa el inbox de todos los tenants.
- **Worker local por empresa**: corre en cada PC local y sincroniza una empresa especifica contra el cloud.

## 1. Crear el ZIP del toolbox

### Windows

Desde la raiz del repo:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\build-toolbox.ps1
```

Salida:

```text
dist\inventoryarens-toolbox-vX.Y.Z.zip
```

### Linux/macOS

Desde la raiz del repo:

```bash
bash scripts/build-toolbox.sh
```

Salida:

```text
dist/inventoryarens-toolbox-vX.Y.Z.zip
```

## 2. Instalar sync en Windows local

Descomprime el ZIP en una carpeta estable, por ejemplo:

```text
C:\inventoryarens-toolbox
```

Abre PowerShell como administrador:

```powershell
cd C:\inventoryarens-toolbox
.\inventoryarens.ps1 wizard
```

Tambien puedes usar CMD:

```cmd
cd C:\inventoryarens-toolbox
inventoryarens.bat wizard
```

El asistente pregunta:

- slug de empresa/tenant,
- email del usuario autorizado,
- nombre visible del equipo/caja,
- codigo unico del nodo,
- intervalo de sincronizacion en segundos, recomendado `15`.

Ejemplo:

```powershell
.\inventoryarens.ps1 wizard `
  --tenant demo-caracas `
  --user admin@demo.test `
  --node-name "Laptop Caja Caracas" `
  --node-code LAPTOP-GABO-DEMO-CARACAS `
  --interval 15
```

### Que crea en Windows

Por cada empresa crea una tarea:

```text
SistemaInventarioSync-{tenant}
```

Ejemplo:

```text
SistemaInventarioSync-demo-caracas
SistemaInventarioSync-demo-valencia
```

Cada tenant queda con su token en:

```text
storage\app\sync-worker\sync-config.json
```

Cada tenant escribe su log en:

```text
storage\logs\sync-worker-{tenant}.log
```

## 3. Diagnosticar sync en Windows

Estado general de una empresa:

```powershell
.\inventoryarens.ps1 status --tenant demo-caracas
```

Ver log del worker:

```powershell
.\inventoryarens.ps1 logs sync --tenant demo-caracas
```

Ver tarea programada:

```powershell
schtasks /query /tn SistemaInventarioSync-demo-caracas /fo LIST /v
```

Iniciar tarea manualmente:

```powershell
schtasks /run /tn SistemaInventarioSync-demo-caracas
```

Detener worker manualmente:

```powershell
.\scripts\sync-worker.cmd stop -TenantSlug demo-caracas
```

Levantar worker manualmente:

```powershell
.\scripts\sync-worker.cmd start -TenantSlug demo-caracas
```

Ejecutar una sincronizacion puntual:

```powershell
.\scripts\sync-worker.cmd run -TenantSlug demo-caracas
```

## 4. Desactivar o reparar sync en Windows

Quitar una empresa de esta PC:

```powershell
.\inventoryarens.ps1 uninstall sync --tenant demo-caracas
```

Reinstalar esa empresa:

```powershell
.\inventoryarens.ps1 wizard --tenant demo-caracas --user admin@demo.test --interval 15
```

Rotar token sin reinstalar:

```powershell
.\inventoryarens.ps1 token rotate `
  --tenant demo-caracas `
  --user admin@demo.test `
  --node-name "Laptop Caja Caracas" `
  --node-code LAPTOP-GABO-DEMO-CARACAS `
  --interval 15
```

Si el asistente muestra `Private key file is encrypted`, escribe la password SSH cuando la pida, o define la variable solo para esa consola:

```powershell
$env:INVENTORYARENS_SSH_PASSWORD="clave-del-vps"
.\inventoryarens.ps1 wizard
```

## 5. Instalar sync en Linux local

Descomprime el ZIP:

```bash
unzip inventoryarens-toolbox-vX.Y.Z.zip
cd inventoryarens-toolbox
```

Ejecuta:

```bash
./inventoryarens wizard
```

O con parametros:

```bash
./inventoryarens wizard \
  --tenant demo-caracas \
  --user admin@demo.test \
  --node-name "Linux Caja Caracas" \
  --node-code LINUX-DEMO-CARACAS-01 \
  --interval 15
```

### Que crea en Linux local

Por empresa crea units de usuario:

```text
~/.config/systemd/user/inventoryarens-sync-{tenant}.service
~/.config/systemd/user/inventoryarens-sync-{tenant}.timer
```

Ejemplo:

```text
inventoryarens-sync-demo-caracas.service
inventoryarens-sync-demo-caracas.timer
```

El timer ejecuta `sync:run {tenant}`. No usa `sync:apply-all-inboxes`, porque ese comando es para el VPS.

## 6. Diagnosticar sync en Linux local

Estado desde el toolbox:

```bash
./inventoryarens status --tenant demo-caracas
```

Logs desde el toolbox:

```bash
./inventoryarens logs sync --tenant demo-caracas
```

Estado systemd:

```bash
systemctl --user status inventoryarens-sync-demo-caracas.timer --no-pager
systemctl --user status inventoryarens-sync-demo-caracas.service --no-pager
```

Logs systemd:

```bash
journalctl --user -u inventoryarens-sync-demo-caracas.service -n 100 --no-pager
```

Ejecutar una pasada manual:

```bash
php artisan sync:run demo-caracas --limit=50
```

## 7. Desactivar o reparar sync en Linux local

Detener temporalmente:

```bash
systemctl --user stop inventoryarens-sync-demo-caracas.timer
```

Reactivar:

```bash
systemctl --user enable --now inventoryarens-sync-demo-caracas.timer
```

Quitar una empresa de esta PC:

```bash
./inventoryarens uninstall sync --tenant demo-caracas
```

Reinstalar:

```bash
./inventoryarens wizard --tenant demo-caracas --user admin@demo.test --interval 15
```

Si `systemctl --user` no funciona en un Linux sin sesion de usuario persistente, habilita linger:

```bash
sudo loginctl enable-linger "$USER"
systemctl --user daemon-reload
systemctl --user enable --now inventoryarens-sync-demo-caracas.timer
```

## 8. VPS cloud: worker global

En el VPS `212.28.176.157`, el worker correcto procesa todos los tenants:

```bash
ssh root@212.28.176.157
cd /opt/inventarioarens-cloud
```

Activar o reparar:

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

## 9. Agente de impresion local

El agente de impresion es local por computadora. No vive en el VPS. Cada PC que tenga una impresora
termica o que deba generar tickets digitales necesita su propio agente escuchando en:

```text
http://127.0.0.1:17777
```

### Windows

Instalar o reparar el agente:

```powershell
.\inventoryarens.ps1 install printer
```

Probar que esta vivo:

```powershell
curl http://127.0.0.1:17777/health
.\inventoryarens.ps1 status --tenant demo-caracas
```

Revisar la tarea:

```powershell
schtasks /query /tn InventoryArensPrinter /fo LIST /v
```

Iniciarla manualmente:

```powershell
schtasks /run /tn InventoryArensPrinter
```

Desinstalarla:

```powershell
.\inventoryarens.ps1 uninstall printer
```

Notas importantes para Windows:

- La tarea `InventoryArensPrinter` se crea para iniciar con el usuario actual.
- Si la estacion usa carpeta `Desktop\Tickets`, los archivos se guardan en el escritorio de ese usuario.
- Si la tarea se ejecuta como SYSTEM, `Desktop\Tickets` apunta al escritorio del sistema y no al tuyo; por eso el toolbox la instala en el login del usuario actual.
- Si PowerShell bloquea el script, usa:

```powershell
powershell -ExecutionPolicy Bypass -File .\inventoryarens.ps1 install printer
```

### Linux local

Instalar o reparar:

```bash
./inventoryarens install printer
```

Probar:

```bash
curl http://127.0.0.1:17777/health
./inventoryarens status --tenant demo-caracas
```

Estado systemd:

```bash
systemctl --user status inventoryarens-printer.service --no-pager
journalctl --user -u inventoryarens-printer.service -n 100 --no-pager
```

Desinstalar:

```bash
./inventoryarens uninstall printer
```

Si `systemctl --user` no arranca despues de reiniciar, habilita linger:

```bash
sudo loginctl enable-linger "$USER"
systemctl --user daemon-reload
systemctl --user enable --now inventoryarens-printer.service
```

### Modo digital

En el modulo de impresion, una estacion en modo `Digital` o `Ambas` genera archivos en la carpeta
configurada de la estacion.

Ejemplos de carpeta:

```text
Desktop\Tickets
C:\Tickets
/home/caja/Tickets
```

Si la carpeta no existe, el agente intenta crearla. Si no tiene permisos, el job queda fallido y el
error se ve en el POS o en `/printing`.

### Si `status` muestra `Printer agent: HTTP 000`

Significa que no hay nada respondiendo en `127.0.0.1:17777`.

En Windows:

```powershell
.\inventoryarens.ps1 install printer
schtasks /run /tn InventoryArensPrinter
curl http://127.0.0.1:17777/health
```

En Linux:

```bash
./inventoryarens install printer
systemctl --user restart inventoryarens-printer.service
curl http://127.0.0.1:17777/health
```

Desactivar:

```bash
systemctl disable --now inventarioarens-sync.timer
```

Reactivar:

```bash
systemctl enable --now inventarioarens-sync.timer
```

## 9. Reglas importantes

- Una PC puede sincronizar varias empresas.
- Cada empresa necesita su propio token.
- Cada empresa local debe tener su propio worker/timer/task.
- En Windows el nombre es `SistemaInventarioSync-{tenant}`.
- En Linux local el nombre es `inventoryarens-sync-{tenant}`.
- En el VPS no hay worker por empresa; hay un solo timer global.
- El intervalo recomendado local es `15` segundos.
- El comando local correcto es `sync:run {tenant}`.
- El comando cloud correcto es `sync:apply-all-inboxes`.
