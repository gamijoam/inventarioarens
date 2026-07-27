# Guia de Instalacion Local Multiempresa

## 1. Objetivo

Inventario Arens puede instalarse localmente en una computadora Windows con
SQLite y sincronizarse con el VPS PostgreSQL. Una misma instalacion local puede
operar varias empresas, siempre que cada empresa use su propio token y su
propio worker.

La instalacion local trabaja offline para las operaciones diarias. El VPS es
la autoridad del catalogo maestro y la sincronizacion se ejecuta cuando hay
conexion.

## 2. Arquitectura

```text
Frontend local
      |
Laravel local + SQLite (una sola computadora)
      |
Workers independientes por empresa
      |
HTTPS + Bearer token exclusivo
      |
VPS Laravel + PostgreSQL
```

Reglas importantes:

- No compartir el archivo SQLite por una carpeta de red.
- No usar un token de plataforma para reemplazar tokens de empresa.
- Cada tenant tiene un token, nodo y worker independientes.
- Una falla de sincronizacion de una empresa no debe detener las otras.
- El VPS no debe recibir conexiones directas desde la aplicacion local a
  PostgreSQL.

## 3. Estado actual

Ya disponible:

- Configuracion SQLite con WAL, foreign keys, `busy_timeout` y transacciones
  `IMMEDIATE`.
- Perfil de pruebas `phpunit.sqlite.xml`.
- Comando `local:install-sqlite`.
- Comando `local:configure-sync-tenants`.
- Comando `local:add-sync-tenant`.
- Configuracion `storage/app/sync-worker/sync-config.json`.
- Worker por tenant mediante `sync:run` o `sync:daemon`.
- Wrapper Windows `scripts/sync-worker-all.ps1`.
- Tareas independientes como:
  `SistemaInventarioSync-caracas` y `SistemaInventarioSync-valencia`.
- Centro tecnico grafico local en `/support` para vincular empresas, descargar
  su informacion y administrar sus workers sin exponer tokens.
- Pruebas PostgreSQL y SQLite de Auth, POS, Sync, DataImport, Bootstrap,
  AccessControl e InventoryCenter.

## 4. Instalacion SQLite

Desde la raiz del proyecto:

```bash
php artisan local:install-sqlite \
  --database=storage/app/inventario.sqlite
```

El comando:

- Crea el directorio y el archivo SQLite.
- Ejecuta las migraciones pendientes.
- No ejecuta `migrate:fresh`.
- No modifica `.env`.
- Rechaza `:memory:` para evitar una base efimera accidental.

Configurar el entorno de la instalacion:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=storage/app/inventario.sqlite
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
DB_SYNCHRONOUS=NORMAL
DB_TRANSACTION_MODE=IMMEDIATE
```

Para una instalacion de demostracion se puede usar `--seed`, pero no debe
usarse en una instalacion productiva que ya tenga datos.

## 5. Una empresa

### Flujo recomendado: interfaz grafica

No usar SSH, Postman ni copiar tokens manualmente.

1. En la nube, entrar en **Acceso > Organizaciones** con un Owner que tenga el
   permiso `sync.issue_token`.
2. En la organizacion correcta usar **Vincular equipo**.
3. Seleccionar la empresa, el usuario autorizado, el nombre del equipo y la
   vigencia del codigo. El codigo es de un solo uso.
4. En la computadora local abrir **Soporte tecnico Inventario Arens** desde el
   menu Inicio o visitar `http://127.0.0.1:5173/support`.
5. Pegar el codigo temporal, identificar el equipo y crear o actualizar la
   clave local del usuario.
6. Pulsar **Vincular y descargar empresa**. La aplicacion prepara la empresa,
   descarga la foto inicial y registra el worker de esa empresa.

La consola muestra cada empresa vinculada en una tarjeta independiente. Desde
ahi se puede sincronizar de inmediato, iniciar, detener, reiniciar o reparar
el inicio automatico de su worker. El token se guarda localmente y no se
muestra en pantalla.

Para agregar una segunda empresa a la misma computadora se repiten los seis
pasos. Cada una mantiene su propio token, nodo y worker.

### Flujo avanzado por consola

En el VPS, primero debe existir la empresa y un usuario perteneciente a ella.
El administrador emite un token exclusivo:

```bash
php artisan sync:issue-token nueva-empresa admin@nuevaempresa.com \
  --name=POS-01 --days=365
```

En la computadora local:

```powershell
$env:SYNC_NEW_TENANT_PASSWORD = "clave-local"
$env:SYNC_NEW_TENANT_TOKEN = "TOKEN_EMITIDO_POR_EL_VPS"

php artisan local:add-sync-tenant nueva-empresa "Nueva Empresa" admin@nueva.test `
  --cloud-url=https://app.miinventariofacil.com/api `
  --installation=POS-01
```

El comando prepara el tenant local, crea el usuario local y registra la
empresa en `sync-config.json`. El token se lee desde una variable de entorno y
no se imprime.

## 6. Varias empresas

Para agregar varias empresas de una vez:

```bash
php artisan local:configure-sync-tenants \
  --cloud-url=https://app.miinventariofacil.com/api \
  --installation=POS-01 \
  --tenant=caracas=TOKEN_CARACAS \
  --tenant=valencia=TOKEN_VALENCIA
```

La configuracion resultante contiene una entrada por empresa. No se deben
intercambiar tokens entre entradas.

En Windows:

```powershell
.\scripts\sync-worker-all.ps1 install
.\scripts\sync-worker-all.ps1 status
```

Acciones disponibles:

```powershell
.\scripts\sync-worker-all.ps1 start
.\scripts\sync-worker-all.ps1 stop
.\scripts\sync-worker-all.ps1 uninstall
```

## 7. Empresa hija nueva

Crear una empresa hija en la nube no la agrega automaticamente a una
instalacion local. El flujo actual es:

1. Crear la empresa hija bajo el grupo correcto.
2. Asociar el usuario administrador.
3. Emitir un token exclusivo para esa empresa.
4. Ejecutar `local:add-sync-tenant` en la computadora local.
5. Ejecutar `sync-worker-all.ps1 install`.
6. Esperar la foto inicial y verificar el estado del worker.

No es necesario reinstalar toda la aplicacion.

## 8. Pairing automatico

El API ya soporta un codigo temporal de vinculacion de un solo uso:

1. El Owner crea un codigo para una empresa hija con `POST /api/sync/pairing-codes`.
2. El VPS genera un codigo temporal con vencimiento corto.
3. El instalador local envia el codigo a `POST /api/sync/pairing-codes/redeem`.
4. El VPS valida que el codigo no haya expirado ni sido utilizado.
5. El VPS emite un token limitado a ese tenant.
6. El instalador crea el tenant local y guarda la credencial.
7. El instalador registra el worker de Windows.
8. El worker registra el nodo y recibe la foto inicial del catalogo.

Crear codigo, usando el token normal del Owner y `X-Tenant` del grupo:

```http
POST /api/sync/pairing-codes
X-Tenant: grupo-prueba
Authorization: Bearer <owner-token>
```

```json
{
  "target_tenant_id": 42,
  "user_email": "admin@nuevaempresa.test",
  "node_name": "POS Caracas",
  "expires_in_minutes": 15
}
```

Canjear desde el instalador, sin autenticacion previa:

```http
POST /api/sync/pairing-codes/redeem
```

```json
{
  "code": "ARNS-...",
  "node_code": "LOCAL-CARACAS"
}
```

El codigo no debe contener ni revelar el token real. El token se entrega una
sola vez por HTTPS y debe poder revocarse desde el panel.

## 9. Backups y actualizaciones

Antes de actualizar la aplicacion local:

```text
Crear backup de storage/app/inventario.sqlite
Actualizar archivos de la aplicacion
Ejecutar php artisan migrate --force
Verificar login y sync
```

Los backups deben almacenarse fuera del directorio de la aplicacion, por
ejemplo:

```text
C:\ProgramData\InventarioArens\backups\
```

Nunca borrar el archivo SQLite para resolver un problema sin crear primero un
backup y revisar `sync_outbox` y `sync_inbox`.

## 10. Diagnostico

Estado de todas las tareas Windows:

```powershell
.\scripts\sync-worker-all.ps1 status
```

Revisar logs por empresa:

```text
storage/logs/sync-worker-caracas.log
storage/logs/sync-worker-valencia.log
```

Problemas comunes:

- `Token does not belong to this tenant`: token equivocado en la entrada del
  tenant.
- `No se encontro la empresa`: falta preparar el tenant local.
- `Eventos bajados: 0`: worker detenido, URL incorrecta o token vencido.
- `database is locked`: verificar que SQLite no este en una carpeta compartida
  y conservar `DB_BUSY_TIMEOUT=5000`.

## 11. Pruebas

Suite SQLite completa:

```bash
php vendor/bin/phpunit -c phpunit.sqlite.xml --process-isolation
```

Pruebas de instalacion y multiempresa:

```bash
php vendor/bin/phpunit tests/Feature/Local
php vendor/bin/phpunit tests/Feature/Sync/SyncWorkerCommandTest.php
```

Antes de distribuir el instalador Windows también se debe probar el flujo
real con una empresa de prueba, una empresa hija adicional, desconexion de
Internet, reintento y restauracion de backup.

## 12. Limitaciones actuales

- El instalador `.exe` todavía requiere empaquetado de PHP, frontend y worker.
- El modo multi-PC con una SQLite central por LAN todavía no está habilitado.
- Un token sigue siendo exclusivo de un tenant por diseño de seguridad.

## 13. Construccion del instalador Windows

La operacion del `.exe` instalado se documenta en
`docs/INSTALADOR_WINDOWS_SQLITE_EXE.md`. Esa guia indica donde queda la base
SQLite, como crear el primer usuario local y por que los workers se instalan
despues de vincular una empresa.

La compilacion se realiza en Windows, preferiblemente en CI:

1. Instalar PHP portable NTS x64 compatible con PHP 8.3/8.4 en
   `build/windows-runtime/php`.
2. Instalar Composer, Node.js, pnpm e Inno Setup.
3. Ejecutar desde PowerShell:

```powershell
.\scripts\build-windows-installer.ps1
```

El builder:

- Compila `frontend/dist` con API local en `127.0.0.1:8787`.
- Crea un staging sin `.env`, SQLite, logs ni tokens.
- Incluye PHP portable y Laravel.
- Genera `InventarioArens-Setup-1.0.0.exe` si `iscc.exe` está disponible.

El instalador crea la aplicacion en `Program Files` y los datos persistentes
en `C:\ProgramData\InventarioArens`. El API local usa el puerto `8787` y el
frontend el puerto `5173`. El frontend no necesita Node en la computadora del
cliente; ambos procesos usan el PHP portable incluido.

El instalador no crea usuarios ni workers automaticamente. Despues de instalar:

- Para una instalacion nueva, abrir `http://127.0.0.1:5173/setup` y usar el
  `APP_BOOTSTRAP_TOKEN` del `.env` instalado.
- Para traer una empresa existente desde la nube, ejecutar el toolbox y usar
  `Recuperar tenant desde nube`.
- Para sincronizar, instalar el worker por empresa desde el toolbox.

El runtime PHP debe descargarse de una fuente oficial y verificarse antes de
copiarlo a `build/windows-runtime/php`. No se deben incluir tokens en el
staging ni en el instalador.
