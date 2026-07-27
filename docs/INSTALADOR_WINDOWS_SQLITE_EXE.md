# Instalador Windows SQLite (.exe)

Esta guia explica que hace el instalador local de Windows, donde guarda la base
SQLite y que pasos faltan despues de instalar.

## Rutas de una instalacion local

El instalador separa codigo y datos:

```text
C:\Program Files\InventarioArens
```

Contiene la aplicacion, PHP portable, frontend compilado, scripts y `.env`.

```text
C:\ProgramData\InventarioArens
```

Contiene datos persistentes de la computadora:

```text
C:\ProgramData\InventarioArens\inventario.sqlite
C:\ProgramData\InventarioArens\logs\
C:\ProgramData\InventarioArens\backups\
```

El archivo SQLite oficial de la instalacion es:

```text
C:\ProgramData\InventarioArens\inventario.sqlite
```

Si ese archivo pesa `0 bytes`, la instalacion local no termino correctamente.

## Que hace el instalador

Al instalar, ejecuta:

1. Crea `C:\ProgramData\InventarioArens`.
2. Copia `.env.local-sqlite.example` como `.env` si no existe.
3. Configura:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=C:\ProgramData\InventarioArens\inventario.sqlite
LARAVEL_STORAGE_PATH=C:\ProgramData\InventarioArens
APP_URL=http://127.0.0.1:8787
```

4. Genera `APP_KEY`.
5. Genera `APP_BOOTSTRAP_TOKEN` si esta vacio.
6. Fuerza `APP_ENV=local`, `SESSION_SECURE_COOKIE=false` y los origins locales
   `http://127.0.0.1:5173,http://localhost:5173`. Esto es obligatorio porque
   el API local usa HTTP; una cookie `Secure` no volveria al API y el frontend
   mostraria erroneamente “sesion caducada”.
7. Ejecuta migraciones con `local:install-sqlite`.

El log del instalador queda en:

```text
C:\ProgramData\InventarioArens\logs\install-local.log
```

## Que NO hace el instalador

El instalador no crea usuarios automaticamente y no instala workers de sync.

Esto es intencional:

- Una instalacion nueva puede ser una empresa standalone creada desde `/setup`.
- Tambien puede ser una recuperacion de una empresa existente desde la nube.
- Cada empresa necesita su propio token antes de instalar su worker.

## Primer usuario local

Si la base local esta vacia y quieres usar la PC como instalacion nueva:

1. Abre el acceso `Configurar Inventario Arens`.
2. O entra manualmente a:

```text
http://127.0.0.1:5173/setup
```

3. Copia el token desde:

```text
C:\Program Files\InventarioArens\.env
```

Busca:

```dotenv
APP_BOOTSTRAP_TOKEN=...
```

Ese token se usa una sola vez para crear el primer Platform Admin o la primera
organizacion local.

## Vincular o recuperar una empresa desde la nube

Si la empresa ya existe en el VPS, no uses `/setup` para crear otra empresa.
Usa el acceso **Soporte tecnico Inventario Arens** creado por el instalador o
abre:

```text
http://127.0.0.1:5173/support
```

Flujo recomendado:

1. En la nube, un Owner abre **Acceso > Organizaciones > Vincular equipo**.
2. El Owner selecciona empresa y usuario, y genera un codigo temporal de un
   solo uso.
3. En esta computadora, el tecnico pega ese codigo en **Soporte tecnico**,
   identifica el equipo y define la clave local del usuario.
4. La aplicacion prepara el tenant, descarga la informacion y activa el
   worker sin exponer el token.

Cada empresa queda con su propio worker:

```text
SistemaInventarioSync-{tenant}
```

Ejemplo:

```text
SistemaInventarioSync-demo-caracas
SistemaInventarioSync-mi-empresa
```

## Workers

Los workers se instalan despues de tener un tenant y un token.

El `.exe` no puede instalar un worker automaticamente porque todavia no sabe:

- que empresa se va a sincronizar,
- que usuario autorizara el token,
- que nombre tendra este equipo,
- si esta PC sincronizara una o varias empresas.

En **Soporte tecnico**, cada empresa tiene acciones para sincronizar ahora,
iniciar, detener, reiniciar o reparar su inicio automatico. El toolbox se
mantiene como recurso avanzado para diagnosticos excepcionales, no como flujo
normal de un tecnico.

## Verificacion rapida

Revisar SQLite:

```powershell
Get-Item "C:\ProgramData\InventarioArens\inventario.sqlite"
```

Revisar log del instalador:

```powershell
Get-Content "C:\ProgramData\InventarioArens\logs\install-local.log" -Tail 80
```

Revisar errores del API local:

```powershell
Get-Content "C:\ProgramData\InventarioArens\logs\api.err.log" -Tail 80
```

Revisar tareas de sync:

```powershell
Get-ScheduledTask | Where-Object TaskName -like "SistemaInventarioSync-*"
```

## Reparar una instalacion incompleta

Si `inventario.sqlite` esta en `0 bytes` o `APP_KEY` esta vacio:

1. Instala nuevamente usando el instalador mas reciente.
2. Ejecuta el instalador como administrador.
3. Revisa:

```text
C:\ProgramData\InventarioArens\logs\install-local.log

La reparacion debe ejecutarse como administrador. El instalador otorga permiso
de escritura al usuario local sobre `C:\ProgramData\InventarioArens`, porque
alli viven la base SQLite, logs, cache y archivos de sincronizacion. Sin ese
permiso la aplicacion puede abrir, pero el API devuelve `500` al intentar crear
un log o escribir en SQLite.

Durante la instalacion se crea `runtime\php\php.ini` y se habilitan
automáticamente `pdo_sqlite` y `sqlite3` (además de extensiones operativas como
GD y cURL). Si esas extensiones no cargan, la instalacion se detiene con un
error claro; nunca debe dejar una SQLite vacia presentada como lista.

Tambien se genera una `APP_KEY` valida si el archivo `.env` trae la clave
vacia. Una clave ya existente se conserva para no invalidar datos locales.

La ruta `LARAVEL_STORAGE_PATH` se resuelve durante el arranque del backend,
antes de que Laravel cargue su configuracion. Esto evita que el backend intente
escribir dentro de `Program Files`, incluso cuando su servidor interno crea un
proceso separado.

El cliente frontend tambien tiene fallback a
`http://127.0.0.1:8787/api` cuando el build no tiene `VITE_API_BASE_URL`, para
que una compilacion local no termine enviando el login al servidor estatico de
Vite en el puerto `5173`.

Para reparar una instalacion ya hecha con el codigo actualizado, abrir
PowerShell como administrador y ejecutar:

```powershell
powershell -ExecutionPolicy Bypass -File ".\installer\windows\repair-existing-installation.ps1"
```

El script guarda una copia de `.env`, detiene los servicios locales, corrige
los archivos de arranque, prepara SQLite y conserva los datos que ya existan.
```

Si necesitas repararlo manualmente:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\Program Files\InventarioArens\installer\windows\install-local.ps1" -AppRoot "C:\Program Files\InventarioArens"
```

Despues verifica que SQLite ya no pese `0 bytes`.
