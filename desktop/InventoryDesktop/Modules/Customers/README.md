# Módulo Clientes - Escritorio

Este módulo permite gestionar clientes desde la aplicación local de escritorio.

## Alcance actual

- Listar clientes por empresa activa.
- Buscar por nombre, documento, teléfono o correo.
- Filtrar clientes activos.
- Crear clientes.
- Editar datos fiscales y de contacto.
- Desactivar clientes sin borrar historial.
- Ver resumen de historial POS del cliente seleccionado.

## Integración

El módulo consume las API existentes:

- `GET /api/customers`
- `POST /api/customers`
- `GET /api/customers/{id}?include=pos_history`
- `PATCH /api/customers/{id}`
- `DELETE /api/customers/{id}`

Los cambios locales quedan preparados para sincronización nube/local mediante los eventos del backend.

## Reglas operativas

- El consumidor final se protege para evitar desactivaciones accidentales desde la vista.
- Desactivar no elimina ventas ni historial.
- El historial POS es informativo; la gestión de cobros pendientes sigue perteneciendo al módulo POS/Caja.
