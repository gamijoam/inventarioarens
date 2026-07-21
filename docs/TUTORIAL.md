# TUTORIAL — INVENTARIOARENS Toolbox

Esta guia explica como un tecnico (sin conocimiento de Laravel, systemd, ni
de la arquitectura del SaaS) usa la toolbox para configurar una maquina local
(sincronizacion con el cloud + printer agent). Esta pensado para:

- Linux (Ubuntu/Debian) y macOS
- Windows 10/11 con PowerShell

## 0. Prereqs

- **Windows**: Python 3.8+ (https://python.org/Add-Python-to-Path) + PowerShell como Admin.
- **Linux**: Python 3.8+ (sudo apt install python3) + sshpass opcional (mejor experiencia).
- Para imprimir tickets: una impresora termica USB o de red con driver CUPS/lpr en Linux, o driver nativo en Windows.
- Credenciales del VPS en una nota segura: `212.28.176.157` user `root` password `GaboMac12` (ssh).

## 1. Instalar la toolbox

### Opcion A: descargar el zip del tecnico

1. El tecnico recibe `inventoryarens-toolbox-vX.Y.Z.zip` (por email, USB, etc).
2. Descomprimir en una carpeta estable, por ejemplo:
   - Linux:   `~/inventoryarens-toolbox/`
   - Windows: `C:\inventoryarens-toolbox\`
3. Verificar contenido:
   ```
   inventoryarens-toolbox/
   ├── inventoryarens            (CLI)
   ├── inventoryarens.bat         (Windows CMD wrapper)
   ├── inventoryarens.ps1         (Windows PS wrapper)
   ├── systemd/                   (Linux)
   ├── windows/                   (Windows)
   ├── README.md
   └── QUICKSTART.txt
   ```

### Opcion B: clonar del repo

Solo para developers, no para tecnicos:
```bash
git clone https://github.com/gamijoam/inventarioarens.git
cd inventarioarens
bash scripts/build-toolbox.sh
# Genera dist/inventoryarens-toolbox-v*.zip
```

## 2. Setup por primera vez (one-shot)

### Paso A: configurar el repo Laravel (si es una nueva maquina local)

Para que el sync worker funcione, el backend Laravel tiene que estar instalado:

```bash
git clone https://github.com/gamijoam/inventarioarens.git /opt/inventarioarens-cloud
cd /opt/inventarioarens-cloud
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Editar .env con tus credenciales DB locales y la URL del cloud
./artisan key:generate
./artisan migrate
```

### Paso B: instalar sync con auto-start

Abre una terminal en la carpeta donde descomprimiste el zip.

**Linux/macOS**:
```bash
cd ~/inventoryarens-toolbox
./inventoryarens install sync
```

**Windows (CMD)**:
```
cd C:\inventoryarens-toolbox
inventoryarens.bat install sync
```

**Windows (PowerShell, como Admin)**:
```powershell
cd C:\inventoryarens-toolbox
.\inventoryarens.ps1 install sync
```

Cuando pida la password del VPS, el tecnico la ingresa (o la deja en env var
`INVENTORYARENS_SSH_PASSWORD` para automatizar). Veras algo asi:

```
[1/4] Detectando OS y validando entorno OK
[2/4] Emitiendo token via SSH al VPS OK
[3/4] Escribiendo token en .env local OK
[4/4] Configurando auto-start en el boot OK
    (Unit files creados, systemctl enable --now OK, timer activo)

OK Install completo. Tenant: mi-empresa
i Token: TCrmkQT6RPC3jS8y66kH...
i Verifica con: inventoryarens status
```

### Paso C: instalar printer agent (opcional, solo si imprime tickets)

```bash
./inventoryarens install printer           # Linux
inventoryarens.bat install printer        # Windows
```

Por default escucha en `127.0.0.1:17777`. Para cambiar de puerto:

```bash
./inventoryarens install printer --port 18777
```

### Paso D: verificar que todo funciona

```bash
./inventoryarens status
```

Salida esperada:

```
CHECKLIST
  - DB local: skip  psql no instalado
  ✓ API cloud: ok  200 OK
  ✓ Token sync: ok  TCrmkQT6... (len 80)
  ✓ Worker sync - Timer systemd: active
  ✓ Printer agent: ok  responde en :17777
  ✓ Ultima sync: 2026-07-21 13:42:38

6/7 checks OK. 1 skipped. Acciones:
  - DB local: instalá psql (no critico).
```

**Si todo esta OK, el setup esta completo.** El worker de sync correra cada 15s
y el printer agent estara disponible para imprimir tickets.

## 3. Uso diario

### 3.1 Ver el estado del sistema

```bash
./inventoryarens status
```

Campos:
- **DB local**: necesita `psql` instalado. En Ubuntu: `apt install postgresql-client`.
- **API cloud**: el VPS responde a `/up`. Falla si Traefik o nginx-fpm estan caidos.
- **Token sync**: el token de Bearer guardado en `.env`. Expira en 365 dias.
- **Worker sync**: el systemd timer (Linux) o Task Scheduler (Windows).
- **Printer agent**: solo si el POS necesita imprimir tickets fisicos.
- **Ultima sync**: el timestamp del ultimo inbox aplicado.

### 3.2 Ver logs en tiempo real

```bash
./inventoryarens logs sync        # tail del worker de sync
./inventoryarens logs printer     # tail del printer agent
```

`Ctrl+C` para salir. En Linux el CLI usa `journalctl` automaticamente si no
encuentra un archivo de log.

### 3.3 Rotar el token de sync

Si el token expira (despues de 1 ano) o si sospechas compromiso:

```bash
./inventoryarens token rotate --tenant mi-empresa
```

Esto re-emite un token, lo escribe en `.env` y no reinstala el worker.
Para aplicar, reinicia el worker (siguiente paso).

### 3.4 Reinstalar (desinstalar + instalar)

```bash
./inventoryarens uninstall sync
./inventoryarens install sync
```

Util cuando cambias el VPS, el repo o el OS. El .env se preserva (a menos que
uses `--purge-env`).

### 3.5 Actualizar el repo (git pull + composer + migrate)

```bash
./inventoryarens update
```

Hace `git pull` + `composer install --no-dev` + `php artisan migrate --force` +
reinicia el worker. Tambien corre la migracion de las nuevas imagen tables si
existen.

## 4. Troubleshooting

### "Permission denied" al hacer install sync

- El CLI usa SSH al VPS. Verifica que la IP del VPS y la password sean correctas.
- En Windows, asegurate de que el CLI encuentra Python (where python).
- En Linux sin sshpass, el CLI usa Paramiko. Si Paramiko no esta instalado:
  ```bash
  pip3 install paramiko --break-system-packages
  ```

### "DB local: fail" en status

`psql` no esta instalado. En Ubuntu:
```bash
sudo apt install postgresql-client
```

No es critico (los demas checks funcionan).

### El worker no procesa eventos

1. Ver logs: `inventoryarens logs sync`
2. Revisa el log del sync timer:
   - Linux: `journalctl --user -u inventoryarens-sync.service -n 50`
   - Windows: abres el Visor de eventos y filtra por la task.
3. Verifica manualmente: `php artisan sync:apply-inbox mi-empresa --limit=10`

### El printer agent no imprime

1. Verifica: `curl http://127.0.0.1:17777/health`
2. Revisa logs: `inventoryarens logs printer`
3. **Linux**: prueba `lpstat -p` para ver las impresoras configuradas. Si esta vacio,
   configura CUPS (`apt install cups` y agrega la impresora via web UI en :631).
4. **Windows**: el nombre de la impresora debe ser exactamente el de Windows
   (`Get-Printer | Select Name` en PowerShell). Verifica que este en PrinterStations
   de la base de datos.

### "Otra instancia esta corriendo"

Hay un `toolbox.lock` en `~/.inventoryarens/` (Linux) o `%APPDATA%/inventoryarens/`
(Windows). Si el proceso anterior murio sin limpiar, borra el archivo:

```bash
rm ~/.inventoryarens/toolbox.lock
```

## 5. Comandos de referencia rapida

| Comando | Que hace |
|---|---|
| `install sync` | Emite token, configura auto-start |
| `install printer` | Configura auto-start del printer agent |
| `status` | Health check |
| `logs sync` / `logs printer` | Tail de logs |
| `uninstall sync` | Detiene + elimina auto-start |
| `uninstall printer` | Detiene + elimina el printer agent |
| `token rotate` | Re-emite token sin reinstalar |
| `update` | git pull + composer + migrate + restart |

## 6. Variables de entorno (opcional)

| Variable | Default | Que hace |
|---|---|---|
| `INVENTORYARENS_PHP` | `php` | Path al binario PHP |
| `INVENTORYARENS_SSH_HOST` | `212.28.176.157` | Host del VPS |
| `INVENTORYARENS_SSH_USER` | `root` | User SSH |
| `INVENTORYARENS_SSH_PASSWORD` | (none) | Password SSH (recomendado para automatizar) |
| `INVENTORYARENS_PYTHON` | (auto-detect) | Path al Python para wrapper Windows |
| `INVENTORYARENS_REPO` | (none) | Path al repo Laravel (alternativa a --repo) |
| `NO_COLOR` | (none) | Si esta set, desactiva color ANSI |

Para automatizar la password, exporta la variable:
```bash
export INVENTORYARENS_SSH_PASSWORD='mi-password'
inventoryarens install sync
```

O en Windows:
```powershell
$env:INVENTORYARENS_SSH_PASSWORD='mi-password'
inventoryarens.bat install sync
```

## 7. Estructura del zip

```
inventoryarens-toolbox/
├── inventoryarens              # CLI Python (~700 lineas, single-file, sin deps)
├── inventoryarens.bat           # Wrapper Windows CMD
├── inventoryarens.ps1           # Wrapper Windows PowerShell
├── systemd/                     # (Linux) 3 unit files
│   ├── inventoryarens-sync.service
│   ├── inventoryarens-sync.timer
│   └── inventoryarens-printer.service
├── windows/                      # (Windows) 2 PS1 wrappers
│   ├── install-task.ps1
│   └── uninstall-task.ps1
├── README.md                     # quickstart resumido
├── QUICKSTART.txt                 # one-liner para imprimir
├── TUTORIAL.md                   # este archivo
└── LICENSE
```

## 8. Para developers

El codigo del CLI es un single file Python 3.8+ (~700 lineas) en
`bin/inventoryarens`. Para rebuild el zip:
```bash
bash scripts/build-toolbox.sh
# Output: dist/inventoryarens-toolbox-vX.Y.Z.zip
```

Tests del CLI (subprocess):
```bash
python3 -m unittest tests.python.test_inventoryarens -v
python3 -m unittest tests.python.test_printer_serve -v
```
