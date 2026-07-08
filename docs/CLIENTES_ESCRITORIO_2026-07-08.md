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

## Pruebas operativas recomendadas

- Crear un cliente desde escritorio y verificar que se sincronice hacia la nube.
- Editar un cliente desde la nube y verificar que baje al escritorio.
- Desactivar un cliente desde escritorio y confirmar que no se borra su historial.
- Confirmar que un usuario sin permisos no pueda crear, editar o desactivar.

## Pendiente

- Integrar busqueda avanzada por saldo, frecuencia de compra o ultima venta cuando el modulo de reportes/clientes crezca.
- Mostrar un indicador visual por cliente si existen cambios locales pendientes de sincronizar.
