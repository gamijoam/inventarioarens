# Clientes en escritorio

## Objetivo

Se agregó un módulo independiente de Clientes en la aplicación WPF para que el operador pueda registrar, editar, consultar y desactivar clientes sin depender del flujo del POS.

## Implementación

- Nueva tarjeta `Clientes` en el centro de módulos.
- Nueva vista compacta de clientes con búsqueda, filtro de activos y panel de detalle.
- Nueva ventana para crear o editar datos del cliente.
- Consulta de historial POS reciente por cliente.
- Desactivación lógica mediante la API existente.

## APIs usadas

- `GET /api/customers`
- `POST /api/customers`
- `GET /api/customers/{id}?include=pos_history`
- `PATCH /api/customers/{id}`
- `DELETE /api/customers/{id}`

## Pruebas

Se debe validar:

- Compilación del proyecto WPF.
- Pruebas específicas de clientes en PostgreSQL.
- Creación, edición y desactivación desde escritorio.
- Que el historial POS cargue sin bloquear la pantalla.

## Pendiente

- Agregar permisos finos en la interfaz para ocultar botones según `customers.create`, `customers.update` y `customers.delete`.
- Integrar búsqueda avanzada por saldo o compras cuando el módulo de reportes/clientes crezca.
