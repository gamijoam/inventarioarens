# Cosas por instalar o ejecutar por primera vez

Checklist rapido para preparar una PC local o servidor antes de usar INVENTARIOARENS.

## 1. PHP con extensiones requeridas

El proyecto necesita PHP 8.3+ y estas extensiones activas:

- `pdo_pgsql`
- `pgsql`
- `gd`
- `fileinfo`
- `mbstring`
- `openssl`
- `curl`
- `zip`

La extension `gd` es obligatoria para subir imagenes de productos, generar WebP y crear miniaturas.

Verificar:

```powershell
php -m | findstr /i "gd pdo_pgsql pgsql fileinfo mbstring openssl curl zip"
```

En Linux:

```bash
php -m | grep -Ei "gd|pdo_pgsql|pgsql|fileinfo|mbstring|openssl|curl|zip"
```

## 2. Activar GD en Windows con Laragon

Opcion recomendada desde la raiz del proyecto:

```powershell
.\scripts\enable-php-gd.ps1
```

Si usas un PHP especifico:

```powershell
.\scripts\enable-php-gd.ps1 -PhpBin "C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe"
```

Luego reinicia Laragon o el servidor PHP.

Verificar:

```powershell
php -m | findstr /i gd
```

Debe mostrar:

```text
gd
```

Metodo manual:

1. Ejecuta:

```powershell
php --ini
```

2. Abre el archivo `php.ini` que indique `Loaded Configuration File`.

3. Busca esta linea:

```ini
;extension=gd
```

4. Cambiala por:

```ini
extension=gd
```

5. Reinicia Laragon o el servidor PHP.

6. Verifica:

```powershell
php -m | findstr /i gd
```

Debe mostrar:

```text
gd
```

## 3. Instalar GD en Linux / VPS

Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y php-gd php-pgsql php-mbstring php-curl php-zip
sudo systemctl restart php8.4-fpm
sudo systemctl restart nginx
```

Si el servidor usa otra version de PHP, ajusta el servicio:

```bash
systemctl list-units | grep php
```

Verificar:

```bash
php -m | grep -i gd
```

## 4. Instalar dependencias del backend

```bash
composer install
php artisan key:generate
php artisan migrate --force
php artisan optimize:clear
```

## 5. Instalar dependencias del frontend

Desde `frontend/`:

```powershell
npm install
```

Si `npm install` falla por cache corrupta:

```powershell
npm cache verify
npm cache clean --force
npm install
```

Si PowerShell bloquea `pnpm.ps1`, usa el `.cmd`:

```powershell
pnpm.cmd install
pnpm.cmd dev
```

## 6. Configurar sincronizacion local

Desde la raiz del proyecto:

```powershell
.\bin\inventoryarens.ps1 wizard
```

Si Windows dice que no encuentra Python, instala Python 3.8+ desde python.org marcando
`Add Python to PATH`, o define el ejecutable manualmente:

```powershell
$env:INVENTORYARENS_PYTHON="C:\Python311\python.exe"
```

Para revisar una empresa especifica:

```powershell
.\bin\inventoryarens.ps1 status --tenant demo-caracas
```

Para abrir la herramienta tecnica interactiva:

```powershell
powershell -ExecutionPolicy Bypass -File .\bin\inventoryarens.ps1 toolbox
```

Acciones frecuentes:

```powershell
powershell -ExecutionPolicy Bypass -File .\bin\inventoryarens.ps1 worker restart --tenant demo-caracas
powershell -ExecutionPolicy Bypass -File .\bin\inventoryarens.ps1 sync retry-failed --tenant demo-caracas
powershell -ExecutionPolicy Bypass -File .\bin\inventoryarens.ps1 images retry-failed --tenant demo-caracas
```

## 7. Configurar agente de impresion local

Cada PC que imprime o genera tickets digitales necesita el agente local:

```powershell
.\bin\inventoryarens.ps1 install printer
curl http://127.0.0.1:17777/health
```

En Linux:

```bash
./bin/inventoryarens install printer
curl http://127.0.0.1:17777/health
```

## 8. Prueba minima final

Backend:

```bash
php artisan route:list
php vendor/bin/phpunit --filter ProductImage
```

Frontend:

```powershell
cd frontend
npm run typecheck
npm run dev
```

Si las imagenes fallan en local, revisar primero:

```powershell
php -m | findstr /i gd
```

Sin `gd`, la galeria de imagenes no puede procesar fotos.
