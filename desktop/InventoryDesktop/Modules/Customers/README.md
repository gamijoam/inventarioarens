# Modulo Clientes - Escritorio

Este modulo permite gestionar clientes desde la aplicacion local de escritorio.

## Alcance actual

- Listar clientes por empresa activa.
- Buscar por nombre, documento, telefono o correo.
- Filtrar clientes activos.
- Crear clientes.
- Editar datos fiscales y de contacto.
- Desactivar clientes sin borrar historial.
- Ver resumen de historial POS del cliente seleccionado.
- Habilitar acciones segun permisos del usuario.
- Mostrar un estado claro cuando no existan resultados.

## Integracion

El modulo consume las API existentes:

- `GET /api/customers`
- `POST /api/customers`
- `GET /api/customers/{id}?include=pos_history`
- `PATCH /api/customers/{id}`
- `DELETE /api/customers/{id}`

Los cambios locales quedan preparados para sincronizacion nube/local mediante los eventos del backend.

La sincronizacion esperada para clientes esta documentada en `docs/SINCRONIZACION_CLIENTES_ESCRITORIO_NUBE_2026-07-08.md`.

## Reglas operativas

- El consumidor final se protege para evitar desactivaciones accidentales desde la vista.
- Desactivar no elimina ventas ni historial.
- Crear, editar y desactivar dependen de `customers.create`, `customers.update` y `customers.delete`.
- El historial POS es informativo; la gestion de cobros pendientes sigue perteneciendo al modulo POS/Caja.
