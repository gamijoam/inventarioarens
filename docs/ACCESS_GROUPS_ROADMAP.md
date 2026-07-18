# Access Groups Roadmap

Este documento define las fases de evolucion de `/access/groups` como panel de administracion
multi-empresa para Owners y Administradores.

## Contexto

`/access/groups` muestra Tenant Groups y sus empresas hijas. El backend usa jerarquia explicita:

- Tenant Group: `is_group = true`, `parent_id = null`.
- Tenant Spinoff: `is_group = false`, `parent_id = group.id`.
- Owner: usuario miembro activo del grupo con capacidad de administrar la organizacion.
- Administrador: usuario operativo dentro de una empresa, limitado por permisos efectivos y scopes.

El modulo debe respetar el arbol de permisos (`BasePermissions`) y los scopes definidos para
sucursales, almacenes, grupos de clientes y vendor-of.

## Fase 1 - Visibilidad Operativa

Objetivo: que el Owner entienda rapidamente empresas, usuarios, roles y alcance sin salir de
`/access/groups`.

Incluye:

- Resumen por grupo: cantidad de empresas, usuarios y roles en uso.
- Lista de empresas hijas con contador de usuarios.
- Lista de usuarios de la organizacion con estado, roles y empresas asociadas.
- Acceso rapido a la ficha del usuario para editar permisos, overrides y scopes.
- Nota contextual sobre gobierno de permisos.

No incluye todavia:

- Cambio inline de roles.
- Activar/inactivar usuario inline.
- Edicion inline de scopes.
- Auditoria visual por usuario.

## Fase 2 - Gestion De Usuarios

Objetivo: permitir operaciones seguras sobre usuarios desde la organizacion.

Propuesto:

- Cambiar estado del usuario dentro de una empresa.
- Asociar o remover usuario de una empresa hija.
- Seleccionar rol inicial al agregar usuario.
- Mostrar errores de permisos claramente para Administradores sin alcance.
- Respetar permisos como `tenants.users.attach`, `tenants.users.detach`, `users.update` y permisos
  de asignacion de roles si se agregan.

## Fase 3 - Roles Y Permisos

Objetivo: conectar `/access/groups` con el arbol jerarquico de permisos.

Propuesto:

- Ver matriz compacta de roles en uso por empresa.
- Abrir editor de rol o duplicar rol base desde la organizacion.
- Mostrar diferencias entre rol base, overrides y permisos efectivos.
- Enlazar al catalogo de permisos y al editor de roles existente.

## Fase 4 - Scopes

Objetivo: administrar el alcance operativo de los usuarios sin perder el modelo actual.

Propuesto:

- Editor de scopes por usuario dentro del contexto de empresa.
- Resumen visible de sucursales, almacenes, grupos de clientes y vendor-of.
- Bloqueos para evitar asignar scopes fuera del tenant actual.
- Tests cross-tenant obligatorios.

## Fase 5 - Auditoria Y Seguridad

Objetivo: dar trazabilidad a cambios de usuarios, roles y scopes.

Propuesto:

- Historial de cambios por usuario dentro del grupo.
- Filtros por actor, empresa, rol y fecha.
- Alertas para ultimo administrador activo o usuarios sin rol.
- Export basico para auditoria.

