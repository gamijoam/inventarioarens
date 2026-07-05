# POS - Flujo de clientes en escritorio

## Objetivo

Cerrar el flujo de cliente dentro del POS de escritorio para que una venta pueda hacerse como **Consumidor final** o asociada a un cliente registrado.

## Implementado

- El POS muestra de forma visible el **Cliente actual** en el panel derecho.
- El cliente predeterminado es **Consumidor final**.
- El boton **F8 Buscar / crear** abre la ventana de seleccion de cliente.
- El boton **Consumidor final** limpia el cliente seleccionado y vuelve a venta rapida.
- Al seleccionar un cliente, el POS muestra nombre y detalle del documento/contacto.
- Al crear un cliente desde POS, queda seleccionado para la venta actual.
- Al confirmar la venta, WPF envia `customer_id` y `customer_name` al backend.
- Despues de confirmar la venta, el POS vuelve automaticamente a **Consumidor final** para evitar vender por error con el cliente anterior.

## Atajos agregados

Ventana **Seleccionar cliente**:

- `F2`: enfoca el buscador.
- `Esc`: cierra la ventana.
- `Enter` en el buscador: ejecuta busqueda.
- `Enter` fuera del buscador: selecciona el cliente marcado.

Ventana **Nuevo cliente**:

- `Enter`: crea el cliente, excepto cuando el foco esta en direccion fiscal.
- `Esc`: cancela y cierra la ventana.

## Backend usado

Busqueda de clientes:

```http
GET /api/customers?search={texto}&active_only=1&limit=20
```

Creacion rapida:

```http
POST /api/customers
```

Confirmacion POS:

```http
POST /api/pos/checkouts
```

El backend ya valida que el cliente pertenezca a la empresa actual y que el documento no se duplique dentro de la misma empresa.

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
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/Customers/CustomerApiTest.php tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/Auth/AuthApiTest.php
```

Resultado:

- 29 pruebas pasadas.
- 183 aserciones.
