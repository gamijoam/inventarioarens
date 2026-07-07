# Portal administrativo - Usuarios y permisos

## Objetivo

Se habilito el modulo web de usuarios y permisos dentro del portal administrativo. La idea es que el administrador pueda trabajar desde la nube con los mismos accesos que luego se sincronizaran hacia las instalaciones locales.

## Implementacion realizada

- Se agrego una seccion real en `/admin` para `Usuarios`.
- La pantalla carga usuarios, roles y catalogo de permisos desde el backend.
- Permite crear o vincular usuarios a la empresa activa.
- Permite asignar perfiles de permisos a un usuario seleccionado.
- Permite activar o inactivar usuarios por empresa.
- Permite crear perfiles personalizados.
- Permite crear perfiles desde plantillas iniciales:
  - Perfil Cajero.
  - Perfil Inventario.
  - Perfil Gerente.
- Permite editar permisos de un perfil por grupos de modulo.
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
- `GET /api/roles`: lista perfiles/roles de la empresa activa.
- `POST /api/roles`: crea un perfil/rol nuevo.
- `PATCH /api/roles/{role}/permissions`: actualiza permisos del perfil/rol.
- `GET /api/permissions`: devuelve el catalogo de permisos agrupado por modulo.

## Consideraciones

- Un mismo correo puede pertenecer a varias empresas, pero sus perfiles se manejan por empresa.
- En backend se mantiene el concepto tecnico `roles`, pero en la interfaz se presenta como `perfiles de permisos` porque es mas claro para usuarios no tecnicos.
- Los perfiles base del sistema siguen protegidos por backend.
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
