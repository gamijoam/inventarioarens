# Entorno local con Laragon y PostgreSQL

## Objetivo

Sacar la ejecucion diaria del backend de Docker para reducir la latencia que se veia desde la aplicacion WPF.

Docker quedara como referencia historica, pero el flujo recomendado para desarrollo local pasa a ser:

- Laravel ejecutado con PHP local de Laragon.
- PostgreSQL instalado en Windows.
- WPF apuntando a `http://127.0.0.1:8000/api/`.

## Estado detectado

- PHP de Laragon instalado en `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.
- Composer disponible en `C:\laragon\bin\composer\composer.phar`.
- PostgreSQL local instalado con `psql` en `C:\Program Files\PostgreSQL\18\bin\psql.exe`.
- PostgreSQL local escucha en el puerto `5434`.
- Se activo `pdo_pgsql` y `pgsql` en el `php.ini` de Laragon.
- Se crearon las bases locales:
  - `inventory_arens`
  - `inventory_arens_testing`
- Se creo el usuario local de la aplicacion:
  - usuario: `inventory_arens`
  - clave: `secret`

## Cambio de configuracion

El proyecto queda apuntando a PostgreSQL local:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5434
DB_DATABASE=inventory_arens
DB_USERNAME=inventory_arens
DB_PASSWORD=secret
```

Las pruebas tambien quedan apuntando a la base local `inventory_arens_testing` en el puerto `5434`.

## Bloqueo pendiente

El proyecto requiere PHP `>= 8.4.1`.

Laragon tiene PHP `8.3.30`, por lo que Artisan no puede iniciar todavia:

```text
Composer dependencies require a PHP version >= 8.4.1
```

Para terminar de sacar el backend de Docker, hay que instalar PHP 8.4 en Laragon y seleccionar esa version.

## Paso adicional requerido

Instalar PHP 8.4 para Windows x64 en Laragon.

Ruta esperada sugerida:

```text
C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64
```

Despues de instalarlo:

1. Abrir Laragon.
2. Seleccionar la version PHP 8.4.
3. Activar en su `php.ini`:

```ini
extension=pdo_pgsql
extension=pgsql
```

4. Verificar:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' -v
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' -m
```

Debe aparecer `pdo_pgsql`.

## Comandos esperados al tener PHP 8.4

Limpiar configuracion:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' artisan config:clear
```

Migrar base local:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' artisan migrate --force
```

Cargar datos demo:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' artisan db:seed --class=DemoDataSeeder --force
```

Levantar API local:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' artisan serve --host=127.0.0.1 --port=8000
```

Ejecutar pruebas locales:

```powershell
& 'C:\laragon\bin\php\php-8.4.x-Win32-vs17-x64\php.exe' artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php
```

## Nota operativa

Si Docker vuelve a estar encendido y publica PostgreSQL en `5432`, no afecta esta configuracion porque el entorno local usa `5434`.

La app WPF no cambia: sigue consumiendo `http://127.0.0.1:8000/api/`.
