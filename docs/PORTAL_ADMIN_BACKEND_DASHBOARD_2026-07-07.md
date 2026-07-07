# Portal administrativo web - API base de dashboard

## Objetivo

Preparar el primer contrato backend para el futuro portal web administrativo de `app.miinventariofacil.com`.

La idea es que la web consulte Laravel por HTTPS y Laravel consulte PostgreSQL. El navegador nunca debe conectarse directo a la base de datos.

## Modulo agregado

Se agrego el modulo:

```txt
app/Modules/AdminPortal
```

Archivos principales:

```txt
app/Modules/AdminPortal/routes.php
app/Modules/AdminPortal/Controllers/AdminDashboardController.php
app/Modules/AdminPortal/Requests/AdminDashboardRequest.php
app/Modules/AdminPortal/Services/AdminDashboardService.php
```

## API agregada

```txt
GET /api/admin-portal/dashboard
```

Esta API devuelve:

- empresa activa;
- periodo consultado;
- ventas confirmadas;
- ordenes POS pagadas;
- ordenes POS pendientes;
- cajas fisicas activas;
- turnos/cajas abiertas;
- monto esperado en cajas abiertas;
- productos activos;
- stock disponible, reservado y danado;
- productos con stock bajo;
- productos sin stock;
- estado general de sincronizacion;
- alertas operativas.

## Permisos

La API acepta usuarios con al menos uno de estos permisos:

```txt
reports.view
finance_reports.view
sales.view
products.view
cash_register.view
```

## Reglas tecnicas

- Es una API solo lectura.
- Respeta `api.auth` y `tenant`.
- Todas las consultas filtran por la empresa activa.
- Usa agregados SQL para evitar N+1.
- No devuelve catalogos completos, ventas completas ni pagos completos.
- Sirve como base para la pantalla principal del portal web.

## Prueba realizada

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\AdminPortal\AdminDashboardApiTest.php
```

Resultado:

- 3 pruebas pasadas;
- 27 aserciones.
