# Entornos Separados: Main y Programa Fiscal

## Objetivo

El desarrollo fiscal se ejecuta en un worktree y una base SQLite independientes. Esto permite
probar migraciones y cambios fiscales sin modificar el codigo ni los datos del entorno normal.

## Entornos

| Entorno | Worktree | Rama | Base SQLite |
|---|---|---|---|
| Normal | `INVENTARIOARENS/` | `main` | `storage/framework/local-dev.sqlite` |
| Fiscal | `INVENTARIOARENS-fiscal/` | `programa-fiscal` | `storage/framework/local-fiscal.sqlite` |

El worktree fiscal tiene un `.env` local, no versionado, con `DB_CONNECTION=sqlite` y
`DB_DATABASE=storage/framework/local-fiscal.sqlite`. El `.env` de `main` no se modifica.

Las bases no se comparten. Una migracion ejecutada en el worktree fiscal solo afecta a
`local-fiscal.sqlite`.

## Rama Fiscal

La rama `programa-fiscal` contiene:

- `9bc2af3`: auditoria fiscal/funcional POS, hardening de operaciones y roadmap.
- `85afb6e`: correcciones posteriores de la suite base y compatibilidad SQLite.
- Este documento y la configuracion de aislamiento del archivo SQLite.

El prompt original de auditoria se conserva en el worktree fiscal como archivo de trabajo y no se
incluye en commits.

## Copia y Restauracion SQLite

No copiar manualmente el archivo mientras SQLite este usando WAL. Usar el respaldo SQLite de PHP:

```bash
php -r '$source = new SQLite3("storage/framework/local-dev.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Para respaldar antes de una migracion, usar otro nombre ignorado por Git:

```bash
php -r '$source = new SQLite3("storage/framework/local-fiscal.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal-backup.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Los archivos `local-fiscal*.sqlite`, incluidos sus archivos WAL/SHM, estan excluidos mediante
`storage/framework/.gitignore` y nunca deben subirse al repositorio.

## Flujo para Migraciones Fiscales

Trabajar desde el worktree fiscal:

```bash
cd /home/gamijoam/Documentos/INVENTARIOARENS-fiscal
php artisan optimize:clear
php artisan migrate
php vendor/bin/phpunit -c phpunit.sqlite.xml
vendor/bin/pint --test
```

Orden obligatorio:

1. Crear primero las pruebas de la migracion o funcionalidad.
2. Respaldar `local-fiscal.sqlite`.
3. Crear y ejecutar la migracion en `programa-fiscal`.
4. Ejecutar las pruebas afectadas y luego la suite SQLite completa.
5. Aplicar Pint y revisar el diff antes de hacer commit.

No ejecutar `php artisan migrate` desde `INVENTARIOARENS/` mientras se pretenda preservar el
entorno normal.

## Pruebas

`phpunit.sqlite.xml` utiliza `DB_DATABASE=:memory:`. Por eso sus pruebas no modifican ni
`local-dev.sqlite` ni `local-fiscal.sqlite`.

La base persistente fiscal se usa para probar migraciones, seeders y flujos manuales que requieran
datos conservados entre comandos.

## Documentacion de Auditoria e Implementacion

La rama fiscal conserva los documentos producidos durante la auditoria:

- `AUDITORIA_FISCAL_FUNCIONAL_POS_VENEZUELA_2026-08-16.md`
- `docs/GUIA_MEJORAS_POS_VENEZUELA.md`
- `docs/IMPLEMENTATION_LOG.md`
- `docs/SQLITE_LOCAL_ARCHITECTURE.md`

Tambien conserva los tests y cambios de implementacion del hardening POS, idempotencia,
concurrencia, creditos, sincronizacion y reservas.

## Requisitos Comunes

Para desarrollar el backend fiscal se necesita:

- Git.
- PHP 8.3 o superior; el proyecto se valida actualmente con PHP 8.4.
- Composer 2.
- Extensiones PHP `pdo_sqlite` y `sqlite3`.
- Extensiones PHP de Laravel: `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`,
  `tokenizer`, `xml` y `intl` cuando este disponible.
- Dependencias del proyecto instaladas con `composer install`.

No es necesario instalar PostgreSQL ni Docker para ejecutar el entorno fiscal local con SQLite.
PostgreSQL sigue siendo necesario para validar compatibilidad con el entorno cloud, no para el
flujo diario fiscal local.

La herramienta CLI `sqlite3` es opcional. Los respaldos documentados usan la clase `SQLite3` de
PHP, por lo que basta con tener activa la extension `sqlite3`.

## Preparacion en Linux

Verificar las herramientas:

```bash
git --version
php -v
composer --version
php -m | grep -E 'pdo_sqlite|sqlite3|mbstring|openssl|xml|ctype|curl|fileinfo'
```

Crear o actualizar el worktree fiscal desde el repositorio normal:

```bash
cd /ruta/INVENTARIOARENS
git worktree add ../INVENTARIOARENS-fiscal programa-fiscal
cd ../INVENTARIOARENS-fiscal
composer install --no-interaction --prefer-dist
```

Si el worktree ya existe, no se debe volver a ejecutar `git worktree add`. Entrar directamente en
el directorio fiscal y verificar:

```bash
cd /ruta/INVENTARIOARENS-fiscal
git branch --show-current
```

Debe mostrar `programa-fiscal`.

Crear el `.env` fiscal solo si no existe. Nunca versionarlo:

```bash
cp .env.local-sqlite.example .env
php artisan key:generate
```

En `.env` debe quedar:

```dotenv
APP_ENV=local
DB_CONNECTION=sqlite
DB_DATABASE=storage/framework/local-fiscal.sqlite
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
DB_SYNCHRONOUS=NORMAL
DB_TRANSACTION_MODE=IMMEDIATE
```

Crear la base fiscal desde la base normal usando el respaldo seguro de PHP, si se desea partir de
los datos actuales:

```bash
php -r '$source = new SQLite3("../INVENTARIOARENS/storage/framework/local-dev.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Aplicar migraciones y validar:

```bash
php artisan optimize:clear
php artisan migrate
php vendor/bin/phpunit -c phpunit.sqlite.xml
vendor/bin/pint --test
```

## Preparacion en Windows

Para desarrollo del repositorio se recomienda usar Git for Windows, PowerShell y PHP 8.4 de
Laragon. La ruta PHP usada actualmente por el proyecto es:

```text
C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe
```

Instalar o verificar:

- Git for Windows.
- Laragon con PHP 8.4.
- Composer para Windows.
- PHP con `pdo_sqlite` y `sqlite3` habilitadas en `php.ini`.

Verificar desde PowerShell:

```powershell
git --version
php -v
composer --version
php -m | Select-String "pdo_sqlite|sqlite3|mbstring|openssl|xml|ctype|curl|fileinfo"
```

Si `pdo_sqlite` o `sqlite3` no aparecen, abrir el `php.ini` correspondiente a la version activa de
Laragon y habilitar estas extensiones. Luego reiniciar la terminal para que `php` use la version
correcta.

Crear el worktree desde la carpeta normal:

```powershell
cd C:\Users\<usuario>\Documents\INVENTARIOARENS
git worktree add ..\INVENTARIOARENS-fiscal programa-fiscal
cd ..\INVENTARIOARENS-fiscal
composer install --no-interaction --prefer-dist
```

Crear `.env` fiscal:

```powershell
Copy-Item .env.local-sqlite.example .env
php artisan key:generate
```

Editar `.env` y configurar:

```dotenv
APP_ENV=local
DB_CONNECTION=sqlite
DB_DATABASE=storage/framework/local-fiscal.sqlite
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
DB_SYNCHRONOUS=NORMAL
DB_TRANSACTION_MODE=IMMEDIATE
```

Para copiar la base normal al entorno fiscal desde PowerShell, ejecutar desde el worktree fiscal:

```powershell
php -r '$source = new SQLite3("../INVENTARIOARENS/storage/framework/local-dev.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Aplicar migraciones y validar:

```powershell
php artisan optimize:clear
php artisan migrate
php vendor/bin/phpunit -c phpunit.sqlite.xml
vendor/bin/pint --test
```

La carpeta `C:\ProgramData\InventarioArens` corresponde a una instalacion local distribuida por
el instalador Windows. Para desarrollo del repositorio fiscal se debe usar la base dentro del
worktree, `storage\framework\local-fiscal.sqlite`, y no la base de `ProgramData`.

## Flujo Diario en Windows o Linux

Para trabajo fiscal, abrir el editor y la terminal en:

```text
INVENTARIOARENS-fiscal
```

Antes de editar, comprobar:

```bash
git branch --show-current
git status --short
```

Antes de una migracion fiscal:

1. Respaldar `local-fiscal.sqlite`.
2. Crear primero las pruebas.
3. Crear la migracion.
4. Ejecutar `php artisan migrate` en el worktree fiscal.
5. Ejecutar la suite SQLite y Pint.
6. Hacer commit solo desde el worktree fiscal.

Un cambio nuevo hecho en `main` debe llegar a fiscal mediante un commit y luego:

```bash
git merge main
```

Ese merge mueve codigo y migraciones, pero no mueve datos entre SQLite. Despues de incorporar una
migracion desde `main`, ejecutarla solo sobre `local-fiscal.sqlite`.

## Problemas Frecuentes

### `php` no se reconoce en Windows

Agregar la carpeta PHP de Laragon al `PATH` o usar la terminal de Laragon. Confirmar con
`php -v` que sea PHP 8.4 y no otra instalacion.

### `could not find driver`

La extension `pdo_sqlite` no esta activa en el PHP usado por la terminal. Revisar el `php.ini` de
la version mostrada por `php --ini`.

### `vendor/autoload.php` no existe

Ejecutar `composer install` dentro de `INVENTARIOARENS-fiscal`. Cada worktree tiene su propio
`vendor/` ignorado.

### La migracion aparece aplicada pero la tabla no existe

Verificar que el comando se ejecuto desde el worktree fiscal y que `.env` apunta a
`storage/framework/local-fiscal.sqlite`. Ejecutar `php artisan optimize:clear` y repetir
`php artisan migrate:status`.
