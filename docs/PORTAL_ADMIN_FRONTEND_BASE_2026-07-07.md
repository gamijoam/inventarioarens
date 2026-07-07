# Portal administrativo web - fase 1

## Objetivo

Crear una primera interfaz web para administradores en `GET /admin`, conectada al backend existente.

## Alcance implementado

- Login web para administradores.
- Busqueda de empresas por correo usando `POST /api/auth/tenants`.
- Inicio de sesion por empresa usando `POST /api/auth/login`.
- Dashboard administrativo usando `GET /api/admin-portal/dashboard`.
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
- Esta fase es de lectura gerencial; no crea productos, usuarios, precios ni cajas.

## Pruebas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\AdminPortal\AdminPortalWebTest.php tests\Feature\AdminPortal\AdminDashboardApiTest.php
pnpm build
```

## Siguiente fase sugerida

- Agregar detalle por modulo dentro del portal:
  - ventas;
  - inventario;
  - caja;
  - sincronizacion;
  - usuarios y permisos.
- Preparar despliegue web en `https://app.miinventariofacil.com/admin`.
