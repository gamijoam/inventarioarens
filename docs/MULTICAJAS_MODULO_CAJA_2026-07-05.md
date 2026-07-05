# Multicajas en modulo Caja

## Objetivo

Se agrego la base formal para manejar varias cajas fisicas por empresa y sucursal.

Antes el sistema trabajaba solo con sesiones de caja. Ahora existe una entidad previa:

```txt
cash_registers
```

Cada caja fisica puede tener muchas sesiones historicas, pero solo una sesion abierta a la vez.

## Flujo operativo

1. La empresa crea cajas fisicas desde el modulo Caja:
   - `Caja Mostrador 1`
   - `Caja Mostrador 2`
   - `Caja Repuestos`
2. Cada caja pertenece a una sucursal.
3. El cajero abre turno en una caja especifica.
4. El POS usa la caja abierta del usuario actual.
5. Si otro cajero intenta abrir la misma caja fisica, el backend lo rechaza.
6. Si el cajero ya tiene una caja abierta, el backend tambien lo rechaza.

## Reglas implementadas

- Una caja fisica pertenece a una sola empresa.
- Una caja fisica pertenece a una sola sucursal.
- Una caja fisica puede estar `active` o `inactive`.
- Una caja inactiva no puede abrir turno.
- Una caja fisica no puede tener dos turnos abiertos al mismo tiempo.
- Un cajero no puede tener dos turnos abiertos al mismo tiempo.
- Las sesiones antiguas sin caja fisica pueden existir por historial, pero no habilitan ventas POS.

## APIs nuevas

### Listar cajas fisicas

```txt
GET /api/cash-register/registers
```

Permiso:

```txt
cash_register.view
```

### Crear caja fisica

```txt
POST /api/cash-register/registers
```

Permiso:

```txt
cash_register.open
```

Payload:

```json
{
  "branch_id": 1,
  "name": "Caja Mostrador 1",
  "code": "CJ-1",
  "status": "active",
  "notes": "Caja principal"
}
```

### Activar o desactivar caja fisica

```txt
PATCH /api/cash-register/registers/{id}
```

Payload:

```json
{
  "status": "inactive"
}
```

## API actualizada

La apertura de caja ahora acepta `cash_register_id`:

```txt
POST /api/cash-register/sessions
```

Payload:

```json
{
  "branch_id": 1,
  "cash_register_id": 1,
  "opening_currency": "USD",
  "opening_amount": 0,
  "notes": "Apertura de turno"
}
```

## Escritorio WPF

La opcion se implemento dentro del modulo Caja:

- seleccionar almacen/sucursal;
- crear caja fisica;
- seleccionar caja fisica;
- abrir turno en esa caja;
- ver turnos abiertos;
- cerrar turno seleccionado.

El POS no crea ni administra cajas. Solo usa la caja abierta del usuario.

Desde esta etapa, POS exige que esa caja abierta tenga una caja fisica asociada y activa. Si el usuario no tiene turno abierto en una caja fisica de la sucursal del almacen seleccionado, la aplicacion no abre el POS y ofrece ir al modulo Caja.

## Datos demo

`MultiCompanyLoginDemoSeeder` ahora crea dos cajas fisicas por empresa demo:

- caja de gerente;
- caja de cajero.

Tambien asocia las sesiones abiertas demo a esas cajas fisicas.

## Pruebas

Se agregaron pruebas para:

- crear caja fisica;
- abrir turno en caja fisica;
- bloquear dos cajeros intentando abrir la misma caja;
- validar que los datos demo multiempresa creen cajas fisicas sin duplicar.

Comandos ejecutados:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/CashRegister/CashRegisterApiTest.php tests/Feature/POS/PosCheckoutApiTest.php
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```
