# Login multiempresa en escritorio

## Objetivo

Mejorar el login de la aplicación WPF para que el usuario pueda escribir su correo y ver automáticamente las empresas disponibles antes de iniciar sesión.

Esto permite usar un mismo correo en varias empresas sin mezclar datos entre ellas.

## Cambios realizados

### Backend

- El endpoint `POST /api/auth/tenants` ahora recibe solo el correo.
- Devuelve las empresas activas asociadas a ese correo.
- Si el correo no existe o no tiene empresas activas, devuelve una lista vacía.
- La contraseña se sigue validando únicamente en `POST /api/auth/login`.

Flujo backend:

1. El usuario escribe el correo.
2. WPF consulta empresas disponibles.
3. El usuario selecciona empresa.
4. El usuario escribe contraseña.
5. WPF inicia sesión contra esa empresa.
6. El token queda ligado a esa empresa y no puede usarse en otra.

### WPF

- Se simplificó el lado izquierdo del login.
- Se dejó solo la identidad principal: **Sistema de Inventario**.
- El selector de empresa se carga automáticamente al escribir un correo válido.
- Si el correo pertenece a varias empresas, se muestran todas en el selector.
- Si pertenece a una sola, queda seleccionada automáticamente.

## Caso soportado

Un solo usuario puede pertenecer a varias empresas:

- `usuario@demo.test` en Empresa A.
- `usuario@demo.test` en Empresa B.
- `usuario@demo.test` en Empresa C.

Cada empresa sigue siendo independiente. El login selecciona el contexto de empresa y el backend emite un token solo para esa empresa.

## Seguridad

- Listar empresas por correo no abre sesión.
- Para entrar sigue siendo obligatorio tener contraseña válida.
- El token generado en una empresa no puede usarse para consumir datos de otra.
- El middleware de tenant valida que el usuario pertenezca a la empresa seleccionada.

## Pruebas ejecutadas

Compilación WPF:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- Compilación correcta.
- 0 errores.

Pruebas backend:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/Tenancy/OperationalTenantIsolationTest.php
```

Resultado:

- 10 pruebas pasadas.
- 75 aserciones.
