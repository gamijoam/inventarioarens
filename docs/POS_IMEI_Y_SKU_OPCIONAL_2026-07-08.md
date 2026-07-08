# POS, IMEI y SKU opcional

## Resumen

Se ajusto el flujo para que los telefonos y productos serializados puedan operarse por IMEI sin obligar al usuario a inventar un SKU manual.

## Decisiones

- El SKU queda como codigo interno opcional del catalogo.
- Si el usuario deja el SKU vacio al crear un producto, el backend genera uno automaticamente desde el nombre del producto.
- En telefonos, el dato operativo principal para vender una unidad especifica es el IMEI.
- El POS debe permitir escanear el IMEI desde el buscador principal y encontrar el producto asociado.
- El POS tambien intenta capturar texto escaneado aunque el cajero no haya hecho clic en el buscador, para mantener una operacion rapida.

## Cambios implementados

- La busqueda de `GET /api/inventory-center/summary` ahora revisa nombre, SKU e IMEI/serial en `product_units.serial_number`.
- El formulario WPF de producto marca el SKU como opcional.
- `POST /api/products` acepta productos sin SKU y genera un codigo interno unico por empresa.
- El POS mantiene el foco operativo en el buscador y redirige texto escaneado al campo de busqueda cuando el foco no esta en otro campo editable.
- Se agregaron pruebas para buscar por IMEI y para crear productos serializados sin SKU manual.

## Regla de negocio

Para un telefono como `Redmi Note 15`, el usuario puede crear el producto sin SKU. Luego, al ingresar inventario, carga los IMEI disponibles. En el POS, el cajero puede escanear el IMEI y vender exactamente esa unidad.

El SKU sigue existiendo porque ayuda internamente al catalogo, reportes, integraciones y sincronizacion, pero ya no debe bloquear el registro de productos que se controlan por IMEI.
