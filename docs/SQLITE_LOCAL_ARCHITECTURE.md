# SQLite Local

## Alcance

SQLite se usara como base local de una instalacion por tienda. PostgreSQL
seguira siendo la base de datos del VPS y la autoridad central del catalogo
maestro.

La primera fase no comparte el archivo SQLite por red. Cada computadora local
opera contra una base local; el worker sincroniza con el VPS. El modo de varias
computadoras con una API local y una SQLite central se implementara despues.

## Configuracion

El perfil `.env.local-sqlite.example` activa:

- WAL para permitir lectura mientras el POS escribe.
- `busy_timeout` de 5 segundos para tolerar una escritura breve del worker.
- Claves foraneas activas.
- Transacciones `IMMEDIATE` para detectar temprano conflictos de escritura.

## Reglas

- No abrir `database.sqlite` directamente desde otra computadora.
- No usar una carpeta compartida de Windows como servidor SQLite.
- El worker debe ser el unico proceso que sincroniza con el VPS.
- Las ventas y movimientos operativos se generan localmente y viajan por
  `sync_outbox`.
- Los catálogos maestros llegan por `sync_inbox` y el VPS gana ante conflictos.

## Verificacion

Antes de distribuir el instalador se deben ejecutar migraciones y pruebas de
login, productos, POS, inventario, sync push, sync pull, reintentos y foto
inicial usando SQLite y PostgreSQL.

El perfil reproducible de PHPUnit para SQLite es `phpunit.sqlite.xml`:

```bash
php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/Sync
php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/POS
```

Las aserciones de cantidades deben comparar valores numericos, no la
representacion textual decimal. PostgreSQL devuelve normalmente `10.0000`,
mientras SQLite puede devolver `10` para la misma columna decimal.

## Instalacion

El comando crea el archivo y ejecuta las migraciones sin borrar datos
existentes. No modifica `.env`:

```bash
php artisan local:install-sqlite --database=storage/app/inventario.sqlite
```

Luego configura el entorno de la instalacion para usar SQLite:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=storage/app/inventario.sqlite
```

Para cargar los seeders por defecto se puede agregar `--seed`. El comando no
usa `migrate:fresh` y rechaza `:memory:` para evitar crear una base efimera por
accidente.

## Varias empresas

Una instalacion local puede atender varias empresas hijas sin compartir
credenciales entre ellas. Se agrega una entrada por empresa:

```bash
php artisan local:configure-sync-tenants \
  --cloud-url=https://app.miinventariofacil.com/api \
  --installation=POS-01 \
  --tenant=caracas=TOKEN_CARACAS \
  --tenant=valencia=TOKEN_VALENCIA
```

El comando escribe `storage/app/sync-worker/sync-config.json` con un nodo y
token independiente por tenant. Nunca se debe usar un token del grupo para
simular acceso a sus empresas hijas.

En Windows, el wrapper instala o consulta todas las tareas configuradas:

```powershell
.\scripts\sync-worker-all.ps1 install
.\scripts\sync-worker-all.ps1 status
```

Esto crea tareas separadas como `SistemaInventarioSync-caracas` y
`SistemaInventarioSync-valencia`. Si una empresa falla, las demás continúan
sin detener su sincronización.
