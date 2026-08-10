# Recompilar el frontend SPA del VPS — Runbook (2026-08-10)

> Cómo recompilar el frontend React que se sirve en `https://app.miinventariofacil.com`
> cuando un cambio de la UI (por ejemplo variantes, promociones, POS) **no aparece** en la nube.
> Ejemplo real: el 2026-08-10 el VPS servía un build del 2026-08-08 que no tenía la pestaña de
> variantes; la solución fue actualizar el código + recompilar + copiar el dist a la raíz que
> sirve Laravel.

---

## 1. Diagnóstico rápido — ¿el VPS tiene el frontend nuevo?

### 1.1 ¿En qué commit está el código del VPS?

```bash
ssh root@212.28.176.157   # password: GaboMac12 (NO usa key SSH)
cd /opt/inventarioarens-cloud
git log --oneline -3
git rev-list --left-right --count origin/main...HEAD   # "N 0" = HEAD está N commits detrás
```

Si `origin/main...HEAD` muestra `N 0`, hay que `git pull` (paso 3.1).

### 1.2 ¿Qué build sirve el servidor?

El backend Laravel sirve la SPA desde `frontend/dist/index.html` (ver `routes/web.php`),
**NO** desde `frontend/dist/admin/` ni desde un alias de nginx directo.

```bash
cd /opt/inventarioarens-cloud
ls -la frontend/dist/index.html          # fecha del build servido
curl -s http://127.0.0.1:8080/ | grep -o 'assets/index-[A-Za-z0-9_-]*\.js'
```

- La fecha de `frontend/dist/index.html` es la del build servido.
- Si la fecha es anterior a la de los cambios de la UI que esperas ver → hay que recompilar.
- El `dist/` está en `.gitignore`, por lo que `git pull` **NO** actualiza el build. Este es el
  error más común: el código nuevo llega, pero el navegador sigue viendo el bundle viejo.

### 1.3 Sanity check del bundle servido (¿contiene la feature?)

```bash
cd /opt/inventarioarens-cloud/frontend/dist
grep -rl 'variante' assets/*.js | head -5
grep -rl 'ProductVariantsTab\|Nueva variante\|Crear variante' assets/ | head -5
```

Si no hay match, el build no tiene la feature → recompilar.

---

## 2. Requisitos en el VPS

El VPS ya tiene instalado:

- Node.js v20.20.2 (`/usr/bin/node`) y npm.
- `frontend/node_modules` completo (697 paquetes), con `node_modules/.bin/vite` y `.bin/tsc`.
- `frontend/package-lock.json` (npm) + `pnpm-lock.yaml` (no es necesario pnpm para build).

No hace falta pnpm. El build admin se corre con `npm run build:admin`.

---

## 3. Procedimiento completo

### 3.1 Actualizar el código

```bash
cd /opt/inventarioarens-cloud
git checkout -- frontend/src/routeTree.gen.ts   # si quedó "M" local (archivo generado por TanStack Router)
git pull --ff-only origin main
```

> `frontend/src/routeTree.gen.ts` es generado por el plugin de TanStack Router. Si un build
> local lo dejó modificado, `git pull` puede fallar; descartarlo con `git checkout --`.

### 3.2 Backend (composer + optimizar + migrar)

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize:clear          # NUNCA usar view:cache
php artisan migrate --force         # si hay migraciones nuevas
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # idempotente, si cambian permisos
```

### 3.3 Recompilar el frontend admin

```bash
cd /opt/inventarioarens-cloud/frontend
npm run build:admin
```

- El build tarda ~12 segundos y produce `frontend/dist/admin/`.
- El script ejecuta `tsc --noEmit && vite build --mode admin`.
- Si hubiera errores de TypeScript, corregir y reintentar; nunca subir un build roto.

### 3.4 Publicar el build en la raíz que sirve Laravel

Laravel sirve `frontend/dist/index.html` + `frontend/dist/assets/*`. Vite compila en
`dist/admin/`, así que hay que copiarlo a la raíz:

```bash
cd /opt/inventarioarens-cloud/frontend
rm -rf dist/assets dist/index.html          # limpiar build viejo
cp -r dist/admin/. dist/
rm -rf dist/admin                           # opcional: dejar solo la raíz servida
```

### 3.5 Verificar

```bash
cd /opt/inventarioarens-cloud/frontend
grep -o 'assets/index-[A-Za-z0-9_-]*\.js' dist/index.html | head -1   # nuevo hash
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' http://127.0.0.1:8080/assets/<nuevo-hash>.js
curl -s http://127.0.0.1:8080/ | grep -o 'assets/index-[A-Za-z0-9_-]*\.js' | head -1
grep -rl 'Nueva variante\|Crear variante' dist/assets/ | head -3
```

- El hash de `dist/index.html` debe coincidir con el que devuelve `curl /`.
- El asset debe responder `200 application/javascript; charset=UTF-8`.

### 3.6 En el navegador

- **Hard refresh** (Ctrl+Shift+R) en `https://app.miinventariofacil.com` para descartar el
  cache del navegador. La respuesta de `/assets/*` incluye
  `Cache-Control: public, max-age=31536000, immutable` (hashes de Vite), así que un refresh
  duro es suficiente; los assets nuevos tienen nombre nuevo.

---

## 4. Resumen de la incidencia 2026-08-10

| Item | Detalle |
|---|---|
| Síntoma | En el VPS, productos no mostraban la pestaña de variantes. |
| Causa | El VPS servía `frontend/dist/index.html` de un build del 2026-08-08 (sin la UI de variantes) y el código estaba 2 commits detrás (`9650a04` vs `dfe5592`). |
| Fix | `git pull` → `dfe5592`, `composer install`, `optimize:clear`, `npm run build:admin`, copiar `dist/admin/*` → `dist/`. |
| Verificación | `curl /` devolvió el nuevo `index-ca5t-qrr.js`; el bundle incluye `Nueva variante`/`Crear variante` y la pantalla `pos_.armar`. |
| Nota | El build correcto quedó **descartando** el `dist/admin/` (la raíz `dist/` es la que sirve Laravel). |

---

## 5. Trampas y advertencias

- `frontend/dist/` está en `.gitignore` → **git pull jamás actualiza el build servido**. Siempre
  recompilar manualmente después de traer código nuevo.
- Nginx apunta a `public/` (backend). **No** tocar el vhost ni Traefik para servir el frontend;
  Laravel ya lo sirve vía `routes/web.php` (rutas `spa.root` y `spa.assets`).
- No usar `php artisan view:cache` (cachea Blade; los cambios no se ven hasta `view:clear`).
- El VPS no tiene pnpm; usar `npm run build:admin` (npm está instalado y `node_modules` ya existe).
- Si el build falla por TypeScript, es un cambio de la UI que no compila: corregir en el repo y
  reintentar. No desplegar un bundle roto.
