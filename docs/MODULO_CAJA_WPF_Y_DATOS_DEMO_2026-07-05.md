# Modulo Caja WPF y datos demo ampliados

## Objetivo

Permitir que el usuario pueda abrir una caja desde la aplicacion de escritorio antes de entrar al POS.

El POS no debe operar si el cajero no tiene una caja abierta. Por eso se agrego una opcion visible en el centro de modulos.

## Cambios en escritorio

Se agrego el modulo **Caja** al centro de modulos.

La primera version permite:

- Ver cajas abiertas de la empresa.
- Seleccionar el almacen de trabajo.
- Detectar la sucursal asociada al almacen.
- Definir moneda inicial de apertura: `USD` o `VES`.
- Definir monto inicial.
- Abrir caja para el usuario logueado.
- Volver al panel y entrar al POS cuando la caja ya esta abierta.

## Flujo esperado

1. Iniciar sesion.
2. Entrar al modulo **Caja**.
3. Seleccionar el almacen.
4. Colocar monto inicial, si aplica.
5. Presionar **Abrir caja**.
6. Volver al centro de modulos.
7. Entrar al **POS**.

Si la caja queda abierta correctamente, el POS podra cobrar.

## Datos demo ampliados

Se agregaron 50 productos adicionales por empresa demo.

Resultado esperado por empresa:

- 62 productos activos.
- 50 productos con SKU `DEMO-*`.

Resultado total entre las dos empresas demo:

- 124 productos activos.
- 100 productos demo ampliados.

Estos productos son por cantidad, tienen stock inicial y combinan precios en USD y VES para probar busqueda, rendimiento, listas de precio, POS e inventario.

## Pruebas ejecutadas

Compilacion WPF:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- Compilacion correcta.
- 0 errores.

Pruebas backend:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Seeders/DemoDataSeederTest.php tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/Auth/AuthApiTest.php
```

Resultado:

- 25 pruebas pasadas.
- 220 aserciones.

## Nota de operacion

Si el POS indica que no hay caja abierta, se debe abrir desde el modulo **Caja**. El sistema mantiene las cajas por cajero, por eso una caja abierta por otro usuario no habilita el cobro para el usuario actual.
