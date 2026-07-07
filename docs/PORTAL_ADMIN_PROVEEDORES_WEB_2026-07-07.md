# Portal administrativo - Proveedores web

## Resumen

Se agrego la gestion de proveedores al portal administrativo web como una pantalla compacta de alta densidad. El objetivo es preparar el flujo previo a compras: crear proveedores, mantener datos fiscales/contacto y desactivar sin borrar historicos.

## Alcance implementado

- Nueva opcion `Proveedores` en el menu del portal administrativo.
- Pantalla `admin-suppliers-module` con filtros, tabla compacta y editor lateral.
- Busqueda por nombre, documento, correo o telefono.
- Filtro por estado: todos, activos e inactivos.
- Creacion y actualizacion de proveedores desde el portal.
- Desactivacion logica y reactivacion de proveedores.
- API `GET /api/suppliers` ampliada con filtros `search`, `active_status`, `limit` y `page`.

## Reglas operativas

- Los proveedores siguen aislados por empresa mediante `X-Tenant`.
- El documento fiscal sigue siendo unico solo dentro de cada empresa.
- Desactivar un proveedor no borra compras, cuentas por pagar ni reportes historicos.
- La vista usa el estandar compacto definido para el portal administrativo.

## Pruebas especificas

- `tests/Feature/AdminPortal/AdminPortalWebTest.php`
- `tests/Feature/Suppliers/SupplierApiTest.php`

## Siguiente paso recomendado

Con proveedores web listo, el siguiente avance natural es el modulo web de compras: crear orden de compra, asociar proveedor, registrar items y preparar recepcion de mercancia.
