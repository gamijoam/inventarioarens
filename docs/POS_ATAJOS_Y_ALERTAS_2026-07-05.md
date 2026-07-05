# POS - Atajos y alertas operativas

## Objetivo

Pulir la operación diaria del POS para que el cajero tenga atajos claros y para que los bloqueos importantes no queden escondidos como texto pequeño en la barra inferior.

## Implementado

- `F2` abre el selector manual de productos.
- En el selector manual, `F2` vuelve a enfocar el buscador.
- En el selector manual, `Enter` agrega el producto seleccionado.
- En el selector manual, `Esc` cierra la ventana.
- `F5` refresca la búsqueda actual del POS.
- `F8` abre la selección de cliente.
- `F9` reabre el último recibo confirmado de la sesión actual.
- `F12` abre o confirma el cobro, según la ventana activa.
- `Esc` en el buscador principal limpia el texto para seguir escaneando.
- `Esc` en la ventana de cobro cancela/cierra la ventana.

## Alertas

Ahora se muestra una alerta modal cuando el bloqueo requiere atención inmediata:

- Producto sin stock o stock ya consumido por el carrito actual.
- Carrito vacío al intentar cobrar.
- Falta de caja abierta asignada al usuario.
- Falta de métodos de pago activos.
- Error de conexión con la API.
- Venta rechazada por Laravel al confirmar.

Los mensajes suaves siguen en la barra inferior:

- Producto agregado.
- Carrito limpiado.
- Búsqueda actualizada.
- Descuento aplicado o retirado.

## Pruebas

- Se ejecutó `dotnet build desktop/InventoryDesktop/InventoryDesktop.csproj --no-restore -o .\desktop\InventoryDesktop\build-check`.
- Resultado: compilación correcta, 0 advertencias, 0 errores.
