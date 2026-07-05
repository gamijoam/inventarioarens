# Aislamiento multiempresa - validación backend

## Objetivo

Comprobar que el sistema mantiene separados los datos por empresa antes de seguir ampliando POS, caja e inventario.

La prueba crea dos empresas independientes con usuarios, productos, almacenes, cajas y ventas propias. La intención es confirmar que una empresa no pueda ver ni usar recursos de otra.

## Escenario probado

Empresas creadas dentro de la prueba:

- **Empresa Caracas**
  - Usuario: `cajero.caracas@demo.test`
  - Producto: `Telefono Caracas A06`
  - SKU: `SKU-COMPARTIDO`
  - Stock inicial: 5
  - Caja abierta propia

- **Empresa Valencia**
  - Usuario: `cajero.valencia@demo.test`
  - Producto: `Telefono Valencia A06`
  - SKU: `SKU-COMPARTIDO`
  - Stock inicial: 8
  - Caja abierta propia

Se usa el mismo SKU en ambas empresas para validar que el SKU puede repetirse entre empresas sin mezclar productos.

## Validaciones realizadas

- El usuario de Empresa Caracas solo ve el inventario de Empresa Caracas.
- El usuario de Empresa Valencia solo ve el inventario de Empresa Valencia.
- Un usuario de Empresa Caracas no puede operar usando el encabezado de Empresa Valencia.
- Una venta POS de Empresa Caracas no puede usar una caja de Empresa Valencia.
- Una venta POS de Empresa Caracas no puede usar almacén ni producto de Empresa Valencia.
- Cada empresa puede vender su producto con su propia caja.
- El listado de órdenes POS de cada empresa solo muestra sus ventas.
- El stock baja de forma independiente:
  - Empresa Caracas: de 5 a 4.
  - Empresa Valencia: de 8 a 7.

## Resultado

El aislamiento funcionó correctamente en backend.

Prueba específica:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Tenancy/OperationalTenantIsolationTest.php
```

Resultado:

- 1 prueba pasada.
- 33 aserciones.

Bloque ampliado de validación:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Tenancy/TenantIsolationTest.php tests/Feature/Tenancy/OperationalTenantIsolationTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php tests/Feature/CashRegister/CashRegisterApiTest.php tests/Feature/POS/PosCheckoutApiTest.php
```

Resultado:

- 46 pruebas pasadas.
- 333 aserciones.

## Nota importante

Estos datos se crean dentro del entorno de pruebas y se limpian automáticamente al terminar. No quedan guardados en la base de datos real.

Si se quiere probar manualmente desde la aplicación de escritorio, el siguiente paso es crear datos persistentes de una segunda empresa en la base local de desarrollo.
