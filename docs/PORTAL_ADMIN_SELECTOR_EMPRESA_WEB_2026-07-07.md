# Portal administrativo: selector global de empresa

## Objetivo

Permitir que un usuario administrativo entre al portal web una sola vez y luego cambie la empresa activa desde el encabezado del panel, sin volver al login ni mezclar datos entre empresas.

## Implementacion realizada

- Se agrego el endpoint `POST /api/auth/switch-tenant`.
- El portal conserva en la sesion web la lista de empresas activas del usuario.
- El encabezado del portal muestra un selector `Empresa activa` cuando el usuario tiene mas de una empresa.
- Al cambiar la empresa:
  - el portal solicita un token nuevo para esa empresa;
  - se actualiza el nombre de la empresa activa;
  - se limpian datos cargados del tenant anterior;
  - se recargan metricas, inventario, usuarios y permisos segun la nueva empresa.

## Regla de seguridad

El token de una empresa no se reutiliza para consultar otra empresa. El cambio de empresa emite una nueva sesion autenticada y valida que el usuario pertenezca a la empresa destino.

## Experiencia esperada

1. El usuario inicia sesion con correo y contrasena.
2. El portal abre con una empresa inicial.
3. Si el usuario pertenece a varias empresas, aparece el selector en la parte superior derecha.
4. Al seleccionar otra empresa, el portal recarga los datos de esa empresa.
5. Valencia, Valencia Norte y Valencia Centro pueden revisarse desde el mismo panel, pero cada una mantiene sus propios productos, permisos, ventas y metricas.

## Pruebas relacionadas

- `tests/Feature/Auth/AuthApiTest.php`
- `tests/Feature/AdminPortal/AdminPortalWebTest.php`

