# Despliegue Tiendas Arens en VPS compartido

## Topologia

Tiendas Arens usa el mismo VPS de INVENTARIOARENS, pero no comparte la instalacion ni la
base de datos de MiInventarioFacil.

| Cliente | Dominio | Aplicacion | Base de datos | Puerto interno |
|---|---|---|---|---:|
| MiInventarioFacil | `app.miinventariofacil.com` | `/opt/inventarioarens-cloud` | `inventory_arens` | `8080` |
| Tiendas Arens | `app.tiendasarens.com` | `/opt/tiendasarens-cloud` | `inventory_tiendasarens` | `8082` |

La base nueva usa el rol PostgreSQL `tiendasarens_app`. No se deben copiar datos, tokens ni el
`.env` de una instalacion a la otra.

## Servicios aislados

- PHP-FPM: pool `tiendasarens`, socket `/run/php/php8.4-fpm-tiendasarens.sock`.
- Nginx: `/etc/nginx/sites-available/app.tiendasarens.com`.
- Traefik: `/root/deploy/core/traefik-config/tiendasarens-fixed.yml`.
- Queue: `tiendasarens-queue.service`.
- Sync: `tiendasarens-sync.service` y `tiendasarens-sync.timer`.
- Laravel: `/opt/tiendasarens-cloud/.env` con `APP_URL=https://app.tiendasarens.com`.

No reiniciar Traefik para cambios ordinarios. El provider de archivos observa la carpeta de
configuracion. No modificar los routers ni servicios de otros productos del VPS.

## DNS y TLS

Cloudflare debe mantener:

```text
app.tiendasarens.com  A  212.28.176.157
```

El certificado de `app.tiendasarens.com` se emite por el resolver Let's Encrypt de Traefik.
La landing `tiendasarens.com` y sus registros de correo no forman parte de esta instalacion.

## Inicializacion

La base se inicializo con migraciones y `RolesAndPermissionsSeeder`. El bootstrap publico se uso
una sola vez para crear el administrador y el tenant; despues la membresia del grupo se ajusto al
rol `Owner`:

- Tenant: `tiendasarens`.
- Dominio: `app.tiendasarens.com`.
- Bootstrap desactivado despues del alta (`APP_BOOTSTRAP_TOKEN` vacio).

No volver a habilitar el endpoint de bootstrap en produccion salvo que la base este vacia y exista
un procedimiento de recuperacion aprobado.

## Verificacion

```bash
curl -fsS https://app.tiendasarens.com/up
sudo -u postgres psql -d inventory_tiendasarens -c 'select count(*) from tenants;'
systemctl is-active tiendasarens-queue.service tiendasarens-sync.timer
```

Las pruebas iniciales deben confirmar tambien que `app.miinventariofacil.com` sigue respondiendo y
que no existen tablas ni datos cruzados entre `inventory_arens` e `inventory_tiendasarens`.

## Actualizacion de las dos instalaciones

Las instalaciones comparten el repositorio, pero no comparten el checkout, el `.env`, la base de
datos ni los procesos. Un `git pull` en una instalacion no actualiza la otra.

### 1. Preparar y respaldar

Antes de una migracion, revisar que cada checkout no tenga cambios locales inesperados y crear un
backup de su base correspondiente:

```bash
ssh root@212.28.176.157

sudo -u postgres pg_dump -Fc inventory_arens \
  -f /var/backups/inventory_arens-$(date +%Y%m%d-%H%M%S).dump
sudo -u postgres pg_dump -Fc inventory_tiendasarens \
  -f /var/backups/inventory_tiendasarens-$(date +%Y%m%d-%H%M%S).dump

cd /opt/inventarioarens-cloud
git status --short

cd /opt/tiendasarens-cloud
git status --short
```

No ejecutar `git checkout --` sobre cambios locales sin revisarlos. `frontend/dist/` no pertenece al
checkout versionado y debe actualizarse mediante un build separado.

### 2. Actualizar MiInventarioFacil

```bash
cd /opt/inventarioarens-cloud
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
systemctl reload php8.4-fpm
```

La migracion usa `inventory_arens` porque ese es el `DB_DATABASE` del `.env` de esta instalacion.
Si se desplega la importacion asincrona, el worker de esta instalacion debe incluir la cola
`imports` y usar un timeout de al menos una hora, por ejemplo:

```bash
php artisan queue:work --queue=imports,default --tries=1 --timeout=3600
```

Configurar tambien `DB_QUEUE_RETRY_AFTER` o `REDIS_QUEUE_RETRY_AFTER` por encima de `3600`.

### 3. Actualizar Tiendas Arens

```bash
cd /opt/tiendasarens-cloud
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
systemctl restart tiendasarens-queue.service
systemctl reload php8.4-fpm
```

La migracion usa `inventory_tiendasarens` porque ese es el `DB_DATABASE` del `.env` de esta
instalacion. El timer `tiendasarens-sync.timer` no requiere reinicio: cada ejecucion inicia un
proceso nuevo con el codigo actualizado.

### 4. Actualizar el frontend

`git pull` nunca actualiza el bundle servido. Desde el checkout local del repositorio, despues de
validar TypeScript y tests, generar el build administrativo:

```bash
cd frontend
pnpm install --frozen-lockfile
pnpm run build:admin
```

Vite genera `frontend/dist/admin/`, pero Laravel sirve la raiz `frontend/dist/`. Publicar el mismo
artefacto en cada instalacion, por separado:

```bash
rsync -az --delete frontend/dist/admin/ \
  root@212.28.176.157:/opt/inventarioarens-cloud/frontend/dist/

rsync -az --delete frontend/dist/admin/ \
  root@212.28.176.157:/opt/tiendasarens-cloud/frontend/dist/
```

Si los clientes necesitan versiones frontend diferentes, construir y publicar cada artefacto por
separado. No copiar `.env`, `storage`, `vendor` ni bases de datos entre instalaciones.

### 5. Verificacion final

```bash
curl -fsS https://app.miinventariofacil.com/up
curl -fsS https://app.tiendasarens.com/up
systemctl is-active tiendasarens-queue.service tiendasarens-sync.timer
```

No usar `php artisan view:cache` y no reiniciar Traefik para una actualizacion ordinaria de
Laravel o frontend. Las rutas de ambos dominios deben permanecer separadas.
