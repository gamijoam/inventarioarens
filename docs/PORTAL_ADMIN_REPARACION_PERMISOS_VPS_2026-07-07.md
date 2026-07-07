# Portal administrativo: reparacion de permisos en VPS

## Contexto

El portal administrativo puede iniciar sesion correctamente y aun asi mostrar:

`Tu usuario no tiene permiso para realizar esta accion.`

Esto ocurre cuando el usuario existe y pertenece a la empresa, pero su rol no tiene todos los permisos que necesita el modulo de usuarios y permisos. Para administrar usuarios no basta con `users.view`; tambien se requieren permisos de roles y actualizacion.

## Ajuste aplicado

- Los datos demo ahora asignan el rol `Administrador` a los usuarios gerentes de prueba.
- Se agrego el comando `access:promote-admin` para reparar usuarios ya existentes en produccion.
- El comando aplica el rol administrador en todas las empresas activas del usuario, o solo en las empresas indicadas con `--tenant`.

## Comando para VPS

Desde la carpeta del proyecto Laravel en el VPS:

```bash
php artisan access:promote-admin gerente.valencia@demo.test
php artisan optimize:clear
```

Si solo se quiere reparar una empresa especifica:

```bash
php artisan access:promote-admin gerente.valencia@demo.test --tenant=demo-valencia
php artisan optimize:clear
```

## Validacion esperada

Despues de ejecutar el comando:

- El usuario puede entrar a `https://app.miinventariofacil.com/admin`.
- El modulo `Usuarios y permisos` carga usuarios, perfiles y permisos.
- Las acciones de crear usuario, crear perfil y asignar permisos dejan de responder con 403.

## Nota operativa

Este comando no elimina roles existentes del usuario. Solo garantiza que tenga un rol administrador completo dentro de sus empresas activas.
