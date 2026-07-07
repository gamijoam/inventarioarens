# Portal admin - gestion web de productos

## Objetivo

Permitir que un administrador gestione productos desde el portal web sin tocar la base de datos directamente. La web usa las APIs del backend, por lo que se mantienen permisos, auditoria, aislamiento por empresa y sincronizacion local-nube.

## Flujo implementado

- `Nuevo producto` abre el editor compacto del Centro de Inventario web.
- La tabla incluye filtros rapidos `Todos`, `Activos` e `Inactivos` para administrar el catalogo sin perder densidad visual.
- La vista muestra un resumen de filtros aplicados para saber si se esta viendo todo el catalogo o solo una parte.
- El administrador puede definir nombre, SKU, tipo de control, precio base, moneda, tasa, garantia y estado.
- `Guardar producto` usa `POST /api/products` cuando el producto es nuevo.
- `Guardar producto` usa `PUT /api/products/{product}` cuando el producto ya existe.
- `Desactivar` usa `DELETE /api/products/{product}` y no borra registros fisicos.
- `Activar` usa `PATCH /api/products/{product}` con `is_active=true` para devolver el producto al catalogo comercial.

## Sincronizacion

- Crear producto genera evento `product.created` en `sync_outbox`.
- Editar producto genera evento `product.updated`.
- Desactivar producto tambien genera `product.updated` con `is_active=false`.
- Reactivar producto tambien genera `product.updated` con `is_active=true`.
- Los locales aplican esos eventos por SKU y empresa, sin mezclar datos entre tenants.

## Reglas operativas

- El stock no se carga desde la creacion del producto; se carga por Entradas y Salidas.
- No se debe borrar fisicamente un producto con historico, ventas, movimientos o seriales.
- Si el producto queda inactivo, no debe estar disponible para POS ni operaciones nuevas.
- Si un producto fue desactivado por error o vuelve a venderse, se reactiva desde la tabla o desde el editor sin crear un SKU duplicado.
- La revision de inactivos debe hacerse desde el filtro rapido para recuperar productos ya existentes antes de crear uno nuevo.
- La web mantiene la UI de alta densidad definida en `docs/GUIA_UI_ALTA_DENSIDAD_PORTAL_ADMIN_2026-07-07.md`.

## Pruebas realizadas

- `pnpm build`
- `php artisan test tests/Feature/AdminPortal/AdminPortalWebTest.php tests/Feature/Products/ProductApiTest.php`
