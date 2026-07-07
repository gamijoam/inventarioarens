# Portal administrativo: precios por lista en inventario

## Resumen

Se agregó al portal administrativo la edición de precios por lista dentro del módulo de Inventario. La idea es que el administrador pueda abrir un producto, revisar qué listas de precio tiene configuradas y completar precios faltantes sin salir de la pantalla de gestión.

## Funcionamiento

- El panel de edición del producto mantiene el precio base, moneda y estado comercial.
- Debajo se muestra el bloque **Precios por lista**.
- Cada lista activa de la empresa aparece con:
  - nombre y código de la lista;
  - precio específico;
  - moneda;
  - estado activo/inactivo;
  - indicador visual de si está configurada o falta precio.
- El botón **Copiar base** rellena las listas vacías usando el precio base y la moneda actual del producto.
- El botón **Guardar listas de precio** envía los cambios al backend y deja los eventos listos para sincronización local-nube.

## Reglas importantes

- Una lista de precio existe para toda la empresa, pero cada producto puede tener su propio precio en esa lista.
- Si una lista no tiene precio para un producto, el POS debe mostrar que ese producto no tiene precio en esa lista.
- Los cambios se hacen por producto para evitar modificaciones masivas accidentales.
- Esta pantalla no reemplaza la administración de listas; solo asigna precios del producto a listas existentes.

## APIs usadas

- `GET /api/price-lists?active_only=1`
  - Obtiene las listas activas de la empresa autenticada.
- `GET /api/products/{product}/prices`
  - Obtiene los precios por lista del producto seleccionado.
- `PUT /api/products/{product}/prices`
  - Actualiza los precios por lista del producto.

## Pruebas relacionadas

- `tests/Feature/AdminPortal/AdminPortalWebTest.php`
  - Verifica que el portal renderice el bloque de precios por lista.
- `tests/Feature/Products/ProductApiTest.php`
  - Verifica la gestión de precios por lista y cotización con lista seleccionada.
