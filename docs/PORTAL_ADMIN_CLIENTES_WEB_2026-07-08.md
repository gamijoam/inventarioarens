# Portal administrativo - Clientes web

## Objetivo

Se agrego el modulo web de Clientes para administrar la cartera desde el portal administrativo. Este modulo esta pensado para tareas de supervision y mantenimiento: crear clientes, editar datos fiscales/contacto, desactivar o reactivar registros y consultar clientes por empresa.

## Alcance implementado

- Nueva seccion `Clientes` en el menu del portal administrativo.
- Tabla compacta de clientes con busqueda por nombre, documento, telefono o correo.
- Filtros por estado: todos, activos e inactivos.
- Filtro visual por tipo: cliente regular o consumidor final.
- Formulario lateral compacto para crear y editar clientes.
- Acciones para desactivar y reactivar clientes sin eliminarlos fisicamente.
- Validacion de permisos desde el frontend:
  - `customers.view` para consultar.
  - `customers.create` para crear.
  - `customers.update` para editar o reactivar.
  - `customers.delete` para desactivar.
- La API de clientes ahora acepta `active_status=all|active|inactive`, manteniendo compatibilidad con `active_only=1`.

## Sincronizacion

Las acciones de crear, actualizar, desactivar y reactivar clientes siguen usando el backend existente de clientes. Por eso generan eventos en `sync_outbox` mediante `SyncCatalogOutboxService` y quedan listas para sincronizar entre local y nube.

La web trabaja contra la empresa seleccionada en el portal. Si el administrador cambia de empresa desde el selector superior, el modulo limpia su estado y vuelve a consultar los clientes de la nueva empresa activa.

## Experiencia de usuario

El modulo respeta la regla permanente de alta densidad del portal:

- Tablas compactas.
- Formularios cortos.
- Botones claros.
- Menos espacio vacio.
- Mas informacion visible al 100% de zoom.

## Pruebas especificas

Se agregaron pruebas para validar:

- Que el portal incluya la seccion, tabla y formulario de clientes.
- Que la API pueda filtrar clientes activos e inactivos para administracion.

