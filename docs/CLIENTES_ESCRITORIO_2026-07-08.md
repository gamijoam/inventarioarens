# Clientes en escritorio

## Objetivo

Se agrego un modulo independiente de Clientes en la aplicacion WPF para que el operador pueda registrar, editar, consultar y desactivar clientes sin depender del flujo del POS.

## Implementacion

- Nueva tarjeta `Clientes` en el centro de modulos.
- Nueva vista compacta de clientes con busqueda, filtro de activos y panel de detalle.
- Nueva ventana para crear o editar datos del cliente.
- Consulta de historial POS reciente por cliente.
- Desactivacion logica mediante la API existente.
- Botones de crear, editar y desactivar controlados por permisos del usuario.
- Estado visual cuando la busqueda no devuelve resultados.
- Eventos de sincronizacion para altas, cambios y desactivaciones de clientes.

## APIs usadas

- `GET /api/customers`
- `POST /api/customers`
- `GET /api/customers/{id}?include=pos_history`
- `PATCH /api/customers/{id}`
- `DELETE /api/customers/{id}`

## Permisos aplicados

- `customers.view`: permite abrir el modulo.
- `customers.create`: habilita `Nuevo cliente`.
- `customers.update`: habilita `Editar`.
- `customers.delete`: habilita `Desactivar`.

El consumidor final queda protegido para evitar desactivaciones accidentales desde la vista.

## Pruebas realizadas

- Compilacion del proyecto WPF.
- Pruebas especificas de clientes en PostgreSQL.
- Integracion visual de permisos en la pantalla de escritorio.
- Pruebas de sincronizacion para confirmar que `customer.created` y `customer.updated` se aplican en el receptor.

## Sincronizacion local-nube

Los clientes ahora participan en la sincronizacion bidireccional:

- Crear un cliente por API, escritorio o portal web genera `customer.created` en `sync_outbox`.
- Editar un cliente genera `customer.updated`.
- Desactivar un cliente tambien genera `customer.updated` con `is_active = false`; no se borra fisicamente.
- La foto inicial de una empresa incluye clientes existentes, para que una computadora nueva los descargue desde la nube junto con productos, precios, stock, cajas y tasas.
- El aplicador de eventos reconstruye clientes usando la clave de negocio `tenant_id + document_type + document_number`.

Importante: modificar filas manualmente en HeidiSQL no dispara eventos de sincronizacion. Para que un cambio suba o baje automaticamente debe pasar por la API del sistema, la app WPF o el portal web.

## Pruebas operativas recomendadas

- Desactivar un cliente desde escritorio y confirmar que no se borra su historial.
- Confirmar que un usuario sin permisos no pueda crear, editar o desactivar.

## Pendiente

- Integrar busqueda avanzada por saldo, frecuencia de compra o ultima venta cuando el modulo de reportes/clientes crezca.
- Mostrar un indicador visual por cliente si existen cambios locales pendientes de sincronizar.
