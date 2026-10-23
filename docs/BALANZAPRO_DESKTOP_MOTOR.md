# BalanzaPro Desktop + Motor Local

> Documento operativo de la suite de escritorio branded **BalanzaPro** (POS,
> Administrativo y Soporte Técnico) y del **Motor Local**, construidos desde la
> rama `client/balanzapro` del repo `gamijoam/inventarioarens`.
> Última actualización: 2026-10-23.

---

## 1. Qué se agregó

Se agregaron clientes Electron con branding propio para BalanzaPro, además del
Motor Local de BalanzaPro. Comparten el mismo código base que MiInventarioFácil
pero con:

- Nombre de producto, `appId`, ícono y ejecutable propios.
- **Canal de actualización independiente** (no se mezclan con los clientes del
  producto genérico).
- Puerto de renderer y `userData` propios (para no chocar con los genéricos).
- URL de nube por defecto `https://app.balanzapro.com/api`.

| Cliente | Producto | appId | Ejecutable | Canal de update | Puerto | userData |
|---|---|---|---|---|---|---|
| BalanzaPro POS | `BalanzaPro POS` | `com.balanzapro.pos` | `BalanzaPro-POS` | `balanzapro-pos` | 8791 | `BalanzaPro-POS` |
| BalanzaPro Admin | `BalanzaPro (Administrativo)` | `com.balanzapro.admin` | `BalanzaPro-Administrativo` | `balanzapro-admin` | 8792 | `BalanzaPro-Administrativo` |
| BalanzaPro Soporte | `BalanzaPro Soporte Técnico` | `com.balanzapro.technician` | `BalanzaPro-Soporte-Tecnico` | `balanzapro-technician` | 8793 | `BalanzaPro-Soporte` |

Genéricos (MiInventarioFácil / Tiendas):

| Cliente | Canal | Puerto |
|---|---|---|
| Administrativo | `admin` | 8788 |
| POS | `pos` | 8789 |
| Soporte Técnico | `technician` | 8790 |

Motor Local (instalador independiente): release `motor-v<version>[-balanzapro]`.

---

## 2. Archivos clave

Electron:

- `frontend/electron-builder.balanzapro-pos.yml`
- `frontend/electron-builder.balanzapro-admin.yml`
- `frontend/electron-builder.balanzapro-technician.yml`
- `frontend/electron/app-mode.cjs` — detecta modo (`admin|pos|technician`) y
  marca (`default|balanzapro`) desde el nombre del `.exe`, y arma el id de
  cliente (`balanzapro-<modo>`).
- `frontend/electron/app-config.cjs` — producto, appId, puerto y `userData` por
  cliente.
- `frontend/electron/update-policy.cjs` — canales de `electron-updater`.
- `frontend/electron/backend-runtime.cjs` — default de nube por marca y
  orígenes permitidos.
- `frontend/build/icons/balanzapro-*/` — íconos generados por
  `scripts/generate-app-icons.cjs`.

Release y empaquetado:

- `.github/workflows/release.yml` — publica un cliente
  `v<version>-<cliente>` (input `client`).
- `.github/workflows/release-motor.yml` — publica el Motor con inputs
  `version`, `variant` (`default|balanzapro`), `ref` y `prerelease`.
- `scripts/build-local-motor.ps1` — compila el Motor (acepta `-CloudUrl`).
- `installer/windows/MotorLocal.iss` — define `{#CloudUrl}` y se lo pasa al
  script de instalación.
- `scripts/install-local-motor.ps1` — instala servicios, SQLite y escribe el
  log `motor-install.log`; acepta `-CloudUrl`.
- `scripts/verify-electron-artifact.cjs`, `scripts/smoke-linux-appimage.cjs` —
  validación por cliente (mapean `balanzapro-*` a su bundle `dist/<modo>`).

---

## 3. Releases BalanzaPro (GitHub Actions)

Los tres clientes BalanzaPro se entregan juntos en `v0.2.66-balanzapro`, con un
`.exe`, `.blockmap` y metadata de actualización (`balanzapro-*.yml`) por canal.
El Motor y el setup todo-en-uno mantienen versiones independientes:
Motor `0.1.5`, FrankenPHP `1.12.7` y setup `1.0.1`. Electron permanece en
`43.3.0`. El release combinado declara Motor `0.1.5` como versión recomendada.

```bash
# Crear primero artefactos candidatos (no publica un release)
gh workflow run release-balanzapro.yml --ref client/balanzapro \
  -f ref=client/balanzapro -f publish=false
gh workflow run release-motor.yml --ref client/balanzapro \
  -f version=0.1.5 -f variant=balanzapro -f ref=client/balanzapro -f publish=false

# Tras probar el Motor 0.1.4 -> 0.1.5 y los tres clientes en Windows,
# publicar el mismo candidato indicando el ID de su ejecución en Actions.
gh workflow run release-motor.yml --ref client/balanzapro \
  -f version=0.1.5 -f variant=balanzapro -f ref=client/balanzapro \
  -f publish=true -f acceptance_verified=true -f candidate_run_id=<MOTOR_RUN_ID>
gh workflow run release-balanzapro.yml --ref client/balanzapro \
  -f ref=client/balanzapro -f publish=true -f acceptance_verified=true \
  -f candidate_run_id=<ELECTRON_RUN_ID>
```

El release de clientes se publica como latest y contiene los tres canales. Los
releases del Motor, setup, conector y clientes individuales quedan fuera de
latest. Los instaladores candidatos se deben validar antes de `publish=true`.
El setup completo se publica por separado en el sitio BalanzaPro y requiere sus
cuatro verificaciones manuales en el workflow `build-installer.yml`.

La versión Electron compartida sale de `frontend/package.json`; los tres
instaladores del release combinado usan la misma versión.

---

## 4. Instalación y sincronización local

El Motor es quien sincroniza; los clientes solo hablan con el Motor
(`127.0.0.1:8787`).

### 4.1 Orden de instalación

1. **Motor Local** (`Motor-Local-Sistema-Inventario-<version>.exe`, release
   `motor-v<version>-balanzapro`).
   - Deja `SYNC_CLOUD_URL = https://app.balanzapro.com/api`.
   - Crea SQLite en `C:\ProgramData\InventarioArens\inventario.sqlite`.
   - Instala los servicios `SistemaInventarioBackend` (8787),
     `SistemaInventarioPrinter` (17777) y `SistemaInventarioSync`.
2. **Soporte Técnico BalanzaPro** (ahí se pega el código de vinculación).
3. **POS / Administrativo BalanzaPro**.

### 4.2 Vincular empresas (sync)

En la nube (`app.balanzapro.com`), con un **Owner** (permiso `sync.issue_token`):

1. `Acceso → Organizaciones` → **Vincular equipo / Vincular una computadora**.
2. Elegir empresa (o **Grupo completo**), usuario autorizado, nombre del equipo
   y vigencia.
3. Generar el **código de un solo uso**.

En la PC (cliente **Soporte Técnico**):

4. Pegar el código, indicar nombre/código del equipo, email y contraseña local.
5. **Vincular y descargar** (empresa) o **Vincular y descargar grupo**.

El proceso crea la empresa local, guarda el token en `sync-config.json`,
registra el worker y descarga la foto inicial (catálogo, precios, tasas, etc.).

### 4.3 Verificación

```powershell
# Servicio de sync
Get-Service SistemaInventarioSync

# Empresas vinculadas SIN exponer tokens (solo slugs)
$p = Join-Path $env:APPDATA "InventarioArens\storage\app\sync-worker\sync-config.json"
(Get-Content $p -Raw | ConvertFrom-Json).tenants.PSObject.Properties.Name
```

Notas: cada empresa usa su propio token/nodo/worker. No compartir el SQLite por
red. El código de vinculación expira y es de un solo uso.

---

## 5. Troubleshooting: fallo de instalación del Motor

Síntoma: el instalador muestra un `Runtime Error` y el mensaje
**"El Motor Local no superó la instalación o sus health checks (código 1).
Revise motor-install.log"**.

El error lo lanza el `[Code]` de `MotorLocal.iss` cuando
`install-local-motor.ps1` devuelve código distinto de 0 (falló un paso).

### 5.1 Ubicación del log

```text
C:\ProgramData\InventarioArens\motor-install.log
```

`C:\ProgramData` es una carpeta **oculta**. En PowerShell:

```powershell
Get-Content "$env:ProgramData\InventarioArens\motor-install.log" -Tail 60
```

Si no aparece, buscarlo:

```powershell
Get-ChildItem "$env:ProgramData","$env:TEMP","$env:LOCALAPPDATA","$env:APPDATA" `
  -Filter motor-install.log -Recurse -Force -ErrorAction SilentlyContinue |
  Select-Object FullName
```

### 5.2 Qué buscar en el log

Una de estas líneas es la causa:

- `La migracion SQLite fallo: ...`
- `El backend no aprobo el health check en 127.0.0.1:8787.`
- `El agente no aprobo el health check en 127.0.0.1:17777.`
- `El supervisor de sincronizacion no permanece activo.`
- `WinSW no pudo instalar el servicio ...`
- `El Motor Local debe instalarse como administrador.`

### 5.3 Diagnóstico rápido

```powershell
# ¿Arranca el PHP portable? (causa #1: falta VC++ Redistributable x64)
& "$env:ProgramFiles\Sistema de Inventario\Motor\versions\0.1.0\runtime\php\php.exe" -v

# Log del servicio backend
Get-Content (Get-ChildItem "$env:ProgramData\InventarioArens\logs\services\backend\*.log" |
  Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName -Tail 40

# ¿Qué quedó instalado?
Get-ChildItem "$env:ProgramFiles\Sistema de Inventario" -Recurse -Force |
  Select-Object -First 50 FullName
```

Causa frecuente en Windows recién instalado: `php.exe` no arranca por faltar el
**Visual C++ Redistributable 2015-2022 x64**
(https://aka.ms/vs/17/release/vc_redist.x64.exe). El Motor aún no lo instala
automáticamente; si el log/`php -v` lo confirma, bundlearlo en el instalador es
el fix pendiente.

### 5.4 Correr el instalador a mano (ver el error en pantalla)

Como el `[Code]` de Inno corre el script con `runhidden`, si el log no aparece
se puede ejecutar el script de PowerShell directamente:

```powershell
$root = "$env:ProgramFiles\Sistema de Inventario\Motor"
$ver  = Get-ChildItem "$root\versions" -Directory -Force |
  Sort-Object Name -Descending | Select-Object -First 1
"Version detectada: $($ver.FullName)"
& "$($ver.FullName)\service\install-local-motor.ps1" `
  -PayloadRoot $ver.FullName `
  -MotorRoot $root `
  -DataRoot "$env:ProgramData\InventarioArens" `
  -Version $ver.Name `
  -CloudUrl "https://app.balanzapro.com/api"
```

Las líneas `[Motor Local] ...` muestran el paso exacto que falla. El instalador
hace rollback (restaura el SQLite previo y reintenta el runtime anterior) cuando
algo falla.

---

## 6. Historial de cambios (rama `client/balanzapro`)

- `2d68d096` — clientes BalanzaPro POS y Administrativo (branding, canales,
  puertos, userData, default de nube).
- `61b53556` — script `scripts/release-balanzapro-desktop.sh`.
- `8c95c47a` — fallback de descarga de PHP portable a `releases/archives/`.
- `75b2e472` — URL de nube del Motor parametrizable por variante.
- `28a49563` — orígenes 8791/8792 en el allowlist CSRF del Motor.
- `a7fa779e` — cliente Soporte Técnico branded (canal
  `balanzapro-technician`) y 8793 en CSRF.

### Releases publicados

| Tag | Contenido |
|---|---|
| `v0.2.58-balanzapro-pos` | `BalanzaPro-POS-0.2.58.exe` |
| `v0.2.58-balanzapro-admin` | `BalanzaPro-Administrativo-0.2.58.exe` |
| `v0.2.58-balanzapro-technician` | `BalanzaPro-Soporte-Tecnico-0.2.58.exe` |
| `motor-v0.1.0-balanzapro` | `Motor-Local-Sistema-Inventario-0.1.0.exe` |
