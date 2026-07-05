# Permisos de modulos en WPF

## Objetivo

Corregir el caso donde el login abre el panel principal, pero todas las tarjetas del centro de modulos quedan bloqueadas o deshabilitadas.

## Causa encontrada

La aplicacion WPF habilita las tarjetas segun los permisos que devuelve el login.

Ejemplos:

- POS requiere `pos.view` o `pos.checkout`.
- Centro de Inventario requiere `products.view`.
- Entradas y salidas requiere permisos de movimientos o actualizacion de productos.
- Listas de precio requiere `products.update`.

En la base local con PostgreSQL, los usuarios demo existian y podian iniciar sesion, pero sus roles tenian cero permisos asociados. Por eso el backend respondia con `permissions: []` y el escritorio bloqueaba todos los modulos.

## Correccion aplicada

Se ajusto `DemoDataSeeder` para que cada vez que asigne un rol demo tambien sincronice los permisos base del rol.

Esto deja cubiertos casos como:

- Primera carga de una base nueva.
- Reejecucion de la semilla demo.
- Migracion de Docker a PostgreSQL local.
- Roles demo ya existentes pero sin permisos.

## Verificacion en base local

Despues de resembrar, los roles demo quedaron con permisos:

- Gerente: 60 permisos por empresa.
- Vendedor: 34 permisos por empresa.
- Administrador y Owner: permisos completos.

El login de `gerente.caracas@demo.test` vuelve a devolver permisos como:

- `pos.view`
- `pos.checkout`
- `products.view`
- `products.update`
- `cash_register.view`

## Pruebas ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Auth/AuthApiTest.php tests/Feature/Seeders/DemoDataSeederTest.php
```

Resultado:

- 9 pruebas pasadas.
- 99 aserciones.

## Nota operativa

Si el escritorio ya estaba abierto con una sesion vieja, hay que cerrar la aplicacion y volver a iniciar sesion para recibir el token y los permisos actualizados.
