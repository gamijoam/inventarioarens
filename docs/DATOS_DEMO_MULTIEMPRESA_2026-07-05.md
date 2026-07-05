# Datos demo multiempresa

## Objetivo

Se agrego una semilla demo pequena para probar el login multiempresa, el aislamiento por empresa y el acceso al POS con caja abierta.

La semilla crea dos empresas por cada grupo de correos demo y dos productos distintos por empresa.

## Comando de carga local

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan db:seed --class=MultiCompanyLoginDemoSeeder
```

## Credenciales

La clave demo para todos estos usuarios es:

```txt
password
```

## Empresas por correo

### Caracas

Correos:

- `gerente.caracas@demo.test`
- `cajero.caracas@demo.test`

Empresas visibles:

- `Demo Caracas Este`
- `Demo Caracas Norte`

Productos:

- Demo Caracas Norte:
  - `Nevera Ejecutiva Caracas Norte`
  - `Cable USB-C Caracas Norte`
- Demo Caracas Este:
  - `Samsung A06 Caracas Este`
  - `Audifonos Caracas Este`

### Valencia

Correos:

- `gerente.valencia@demo.test`
- `cajero.valencia@demo.test`

Empresas visibles:

- `Demo Valencia Centro`
- `Demo Valencia Norte`

Productos:

- Demo Valencia Centro:
  - `Laptop Oficina Valencia Centro`
  - `Mouse Inalambrico Valencia Centro`
- Demo Valencia Norte:
  - `iPhone 11 Valencia Norte`
  - `Cargador Rapido Valencia Norte`

## Caja y POS

Cada empresa queda con una sucursal, un almacen y una caja abierta para el gerente y el cajero demo.

Esto permite entrar al POS sin tener que abrir caja manualmente durante las pruebas de aislamiento.

## Reglas de aislamiento

- Cada empresa tiene sus propios productos.
- Cada empresa tiene su propio almacen.
- Cada empresa tiene sus propias cajas abiertas.
- Un producto de Caracas Norte no debe verse en Valencia Centro ni en ninguna otra empresa.
- El selector de empresas del login debe mostrar solo las empresas activas asociadas al correo escrito.

## Prueba automatica

La validacion especifica queda en:

```txt
tests/Feature/Seeders/MultiCompanyLoginDemoSeederTest.php
```

Valida que:

- Cada correo demo vea dos empresas.
- Cada empresa tenga dos productos.
- Cada empresa tenga stock aislado.
- Cada empresa tenga cajas abiertas demo.
- Ejecutar el seeder varias veces no duplique los datos principales.
