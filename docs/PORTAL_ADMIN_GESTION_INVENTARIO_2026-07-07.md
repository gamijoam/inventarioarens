# Portal administrativo - Gestión de inventario

## Objetivo

Se inició la gestión de inventario dentro del portal administrativo web para que un administrador pueda consultar productos de la empresa, filtrar el catálogo y realizar cambios básicos que luego quedan listos para sincronizarse con las instalaciones locales.

## Implementación realizada

- Se amplió el módulo web **Inventario / Productos y precios**.
- Se agregó filtro por estado comercial:
  - Todos.
  - Activos.
  - Inactivos.
- La tabla ahora muestra dos estados separados:
  - Estado de stock: disponible, stock bajo o sin stock.
  - Estado de venta: activo o inactivo.
- El editor rápido permite cambiar:
  - Precio base.
  - Moneda de venta.
  - Estado comercial del producto.
- Al guardar, se usa la API existente de productos, por lo que el cambio genera auditoría y evento de sincronización cuando corresponde.

## Backend

Se actualizó el endpoint:

`GET /api/inventory-center/summary`

Nuevo filtro opcional:

`active_status`

Valores:

- `active`: solo productos activos. Es el comportamiento predeterminado para no afectar la app de escritorio.
- `inactive`: solo productos inactivos.
- `all`: productos activos e inactivos. El portal administrativo usa este modo para gestión.

## API de actualización usada por el portal

`PUT /api/products/{id}`

Campos usados por el portal:

- `base_price`
- `sale_currency`
- `is_active`

## Pruebas cubiertas

- La página `/admin` contiene los controles de gestión de inventario.
- El centro de inventario mantiene el comportamiento normal de mostrar activos por defecto.
- El centro de inventario puede incluir productos inactivos cuando el portal lo solicita.

## Próxima fase sugerida

La siguiente mejora natural es agregar edición de precios por lista desde el portal web:

- Precio base.
- Precio detal.
- Precio mayor.
- Precio técnico.
- Validación de productos sin precio por lista.
- Evento de sincronización hacia sedes locales.
