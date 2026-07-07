# Portal administrativo web - fase 1

## Objetivo

Crear una primera interfaz web para administradores en `GET /admin`, conectada al backend existente.

## Alcance implementado

- Login web para administradores.
- Busqueda de empresas por correo usando `POST /api/auth/tenants`.
- Inicio de sesion por empresa usando `POST /api/auth/login`.
- Dashboard administrativo usando `GET /api/admin-portal/dashboard`.
- Manejo visible de errores y tiempo maximo de espera para evitar que el login quede cargando indefinidamente.
- Cambio limpio entre login y dashboard: al iniciar sesion el portal vuelve al inicio de la pantalla y enfoca el panel principal.
- Rediseño compacto tipo consola administrativa, evitando una pantalla gigante con poco contenido util.
- Navegacion interna por modulos:
  - resumen;
  - ventas;
  - inventario;
  - caja;
  - usuarios;
  - sincronizacion.
- Las secciones que aun no tienen herramientas completas muestran un panel de preparacion, para que el crecimiento del portal sea ordenado.
- Selector de periodo: hoy, semana y mes.
- Metricas iniciales:
  - ventas confirmadas;
  - POS cobrado;
  - ordenes POS pendientes;
  - cajas abiertas;
  - inventario disponible;
  - productos activos;
  - stock bajo;
  - productos sin stock;
  - estado de sincronizacion.
- Alertas operativas basicas.
- Modulo web de inventario dentro del portal administrativo:
  - busqueda por nombre o SKU;
  - filtros por tipo de control y estado de stock;
  - tabla paginada para revisar productos;
  - editor rapido de precio base y moneda;
  - actualizacion mediante `PUT /api/products/{product}`;
  - consulta mediante `GET /api/inventory-center/summary`.
- La edicion de precio desde el portal usa la API existente de productos, por lo que el backend registra auditoria y prepara el evento de sincronizacion correspondiente.

## Criterio visual

- El portal no debe comportarse como pagina publicitaria.
- La primera pantalla debe priorizar lectura rapida, densidad profesional y controles claros.
- Las metricas deben verse sin obligar al administrador a bajar mucho.
- Las futuras herramientas deben vivir dentro de modulos, no mezcladas en un solo dashboard enorme.
- Los mensajes visibles para el usuario deben mantenerse en español.

## Archivos

- `routes/web.php`
- `resources/views/admin.blade.php`
- `resources/css/admin.css`
- `resources/js/admin.js`
- `vite.config.js`
- `tests/Feature/AdminPortal/AdminPortalWebTest.php`

## Reglas

- El portal web no se conecta directo a PostgreSQL.
- Toda la informacion se obtiene desde APIs protegidas.
- Los permisos reales siguen siendo responsabilidad del backend.
- Esta fase permite lectura gerencial y edicion controlada de precio base en inventario.
- La creacion completa de productos, usuarios, cajas y permisos sigue quedando para fases posteriores del portal.
- Si el backend no responde, el portal debe mostrar un mensaje claro en pantalla y reactivar los botones.
- El login y el dashboard no deben verse mezclados ni conservar la posicion de scroll anterior.

## Pruebas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\AdminPortal\AdminPortalWebTest.php tests\Feature\AdminPortal\AdminDashboardApiTest.php tests\Feature\InventoryCenter\InventoryCenterSummaryApiTest.php
pnpm build
```

## Despliegue en VPS

El portal web usa Vite. Despues de hacer `git pull` en el VPS, si cambiaron archivos de `resources/js`, `resources/css`, `resources/views` o `vite.config.js`, se deben recompilar los assets publicos.

El VPS debe tener Node.js 20.19+ o 22.12+. El error `Vite manifest not found at public/build/manifest.json` indica que el frontend no fue compilado despues del despliegue.

Comandos recomendados en `/opt/inventarioarens-cloud`:

```bash
pnpm install --frozen-lockfile
pnpm build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:clear
systemctl restart php8.4-fpm
systemctl reload nginx
```

Validacion:

```bash
curl -I https://app.miinventariofacil.com/admin
```

Debe responder `200`.

## Siguiente fase sugerida

- Agregar detalle por modulo dentro del portal:
  - ventas;
  - inventario;
  - caja;
  - sincronizacion;
  - usuarios y permisos.
- Preparar despliegue web en `https://app.miinventariofacil.com/admin`.
