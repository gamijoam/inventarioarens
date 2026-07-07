# Portal administrativo - Usuarios y permisos

## Objetivo

Se habilito el modulo web de usuarios y permisos dentro del portal administrativo. La idea es que el administrador pueda trabajar desde la nube con los mismos accesos que luego se sincronizaran hacia las instalaciones locales.

## Implementacion realizada

- Se agrego una seccion real en `/admin` para `Usuarios`.
- La pantalla carga usuarios, roles y catalogo de permisos desde el backend.
- Permite crear o vincular usuarios a la empresa activa.
- Permite asignar roles a un usuario seleccionado.
- Permite activar o inactivar usuarios por empresa.
- Permite crear roles personalizados.
- Permite editar permisos de un rol por grupos de modulo.
- La interfaz respeta permisos del usuario autenticado:
  - `users.create`
  - `users.update`
  - `roles.create`
  - `roles.update`

## APIs utilizadas

- `GET /api/users`: lista usuarios de la empresa activa.
- `POST /api/users`: crea o vincula un usuario a la empresa activa.
- `PATCH /api/users/{tenantUser}/status`: activa o inactiva el usuario en esa empresa.
- `PATCH /api/users/{tenantUser}/roles`: actualiza roles del usuario dentro de la empresa.
- `GET /api/roles`: lista roles de la empresa activa.
- `POST /api/roles`: crea un rol nuevo.
- `PATCH /api/roles/{role}/permissions`: actualiza permisos del rol.
- `GET /api/permissions`: devuelve el catalogo de permisos agrupado por modulo.

## Consideraciones

- Un mismo correo puede pertenecer a varias empresas, pero sus roles se manejan por empresa.
- Los roles base del sistema siguen protegidos por backend.
- Los cambios quedan auditados por el modulo de control de acceso.
- Esta fase prepara el portal para administrar permisos desde la nube y luego sincronizarlos a los equipos locales.

## Pruebas especificas

Se deben ejecutar:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\AdminPortal\AdminPortalWebTest.php tests\Feature\AccessControl\AccessControlApiTest.php
```

Y compilar assets:

```powershell
pnpm build
```
