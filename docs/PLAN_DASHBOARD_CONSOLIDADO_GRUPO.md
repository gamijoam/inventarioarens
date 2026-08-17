# Plan: Dashboard Consolidado del Grupo

Estado: **pendiente de implementacion**.

Este documento registra la decision funcional y tecnica para que el Owner de un grupo pueda
consultar todas sus empresas hijas sin cambiar de tenant repetidamente.

## Contexto

Tiendas Arens tiene un tenant grupo (`tiendasarens`) y empresas hijas:

- Tucacas.
- Boca de Aroa.
- Yaracal.
- Chichiriviche.
- Mirimire.
- Cumarebo.
- Guigue.

El grupo es el contexto de control y catalogo maestro. Cada empresa hija conserva su propia
operacion: ventas, cajas, inventario, almacenes, movimientos y saldos.

## Ubicacion funcional

### Tenant grupo

Cuando el usuario esta trabajando en el tenant grupo, `/dashboard` debe mostrar por defecto la
**Vista Consolidada / Vista de Jefe**:

- Totales del grupo.
- Desglose por empresa hija.
- Ranking comparativo.
- Cajas abiertas por empresa.
- Alertas de inventario consolidadas.
- Acceso rapido para entrar a una empresa especifica.

El grupo no debe recibir stock operativo por el solo hecho de consolidar la informacion.

### Tenant hija

Cuando el usuario esta en una empresa hija, el dashboard debe mostrar solamente esa empresa, salvo
que el usuario tenga autorizacion de Owner del grupo. Para un Owner se puede ofrecer un selector:

```text
[ Esta empresa: Tucacas ] [ Grupo completo: Tiendas Arens ]
```

Un Vendedor, Cajero, Almacen, Auditor o Gerente local no debe poder consultar el grupo completo
por cambiar un parametro HTTP.

## Autorizacion

La consulta consolidada debe requerir ambas condiciones:

1. El usuario pertenece activamente al grupo.
2. El usuario es Owner estricto del grupo, mediante `User::isStrictOwnerOf($group)` o la politica
   equivalente definida por el modulo de acceso.

Agregar un permiso especifico al catalogo base, recomendado:

```text
reports.organization.view
```

La autorizacion final debe combinar permiso y pertenencia/rol. No confiar solamente en
`reports.view`, `tenants.group.view` o en un `scope=organization` enviado por el frontend.

Respuestas esperadas:

- Owner del grupo: `200`.
- Administrador de una hija sin Owner del grupo: `403`.
- Usuario que no pertenece al grupo: `403`.
- Tenant inexistente o grupo no accesible: `404` o `403` segun la politica de ocultamiento vigente.

## Contrato backend propuesto

Extender el dashboard existente con un scope explícito:

```text
GET /api/dashboard/summary?scope=tenant
GET /api/dashboard/summary?scope=organization
```

El scope por defecto sigue siendo `tenant`, para no cambiar el comportamiento actual de usuarios
locales. Para `organization`, la respuesta debe incluir:

```json
{
  "scope": "organization",
  "group": {
    "id": 1,
    "name": "Tiendas Arens",
    "slug": "tiendasarens"
  },
  "period": { "from": "2026-08-17", "to": "2026-08-17" },
  "totals": {
    "sales_count": 0,
    "sales_total_base_amount": 0,
    "pos_orders_count": 0,
    "pos_paid_base_amount": 0,
    "open_cash_sessions": 0,
    "receivable_balance_base_amount": 0,
    "payable_balance_base_amount": 0
  },
  "companies": []
}
```

Cada elemento de `companies` debe contener como mínimo `tenant_id`, nombre, slug, ventas, POS,
cajas abiertas, cuentas por cobrar, cuentas por pagar y alertas de stock.

## Rendimiento y anti-N+1

No iterar empresas en PHP haciendo una consulta por tenant. El servicio debe:

- Resolver una sola vez los IDs del grupo y sus spinoffs.
- Ejecutar agregaciones SQL por `tenant_id` con `GROUP BY`.
- Combinar los resultados mediante subconsultas agregadas o CTEs.
- Devolver en la misma respuesta los totales globales y el desglose por empresa.
- Evitar cargar modelos Eloquent completos, relaciones anidadas o productos completos.

Las consultas deben filtrar por tenant, estado y rango de fechas usando los índices existentes. Antes
de cerrar la implementación revisar `EXPLAIN (ANALYZE, BUFFERS)` con volúmenes representativos.

El objetivo mínimo es que el número de queries no dependa del número de empresas. Los tests deben
detectar regresiones N+1 con `DB::listen` o `expectsDatabaseQueryCount` si la infraestructura de
tests lo permite.

## Separacion de inventario

El dashboard consolida datos, pero no mezcla inventarios operativos:

- El catálogo maestro se mantiene en el grupo y se replica como copia operativa a las hijas.
- El stock pertenece a la empresa y almacén donde existe físicamente.
- Tucacas puede tener tres almacenes sin convertirse en grupo padre de las demás empresas.
- Un traslado desde Tucacas hacia otra empresa debe seguir usando el flujo de traslado interempresa.
- La vista de stock debe mostrar disponibilidad por empresa y almacén, no sumar unidades como si
  fueran físicamente intercambiables.

## Plan TDD

### Backend primero

Crear `tests/Feature/Dashboard/OrganizationDashboardTest.php` antes de implementar el servicio:

1. Owner del grupo recibe totales y desglose de todas las hijas.
2. Las ventas de una empresa no aparecen en otra.
3. Un usuario local recibe `403` al solicitar `scope=organization`.
4. El grupo sin empresas hijas devuelve una respuesta válida con totales en cero.
5. Los periodos `today`, `week`, `month` y rango personalizado filtran correctamente.
6. Las consultas no crecen con el número de empresas.
7. Los importes USD/VES respetan los snapshots existentes y no recalculan tasas históricas.
8. Las cajas, CxC, CxP y alertas se agrupan por empresa correctamente.

### Implementacion backend

- Crear `OrganizationDashboardService` dentro del modulo Dashboard.
- Mantener `DashboardSummaryService` para el scope de un tenant.
- Adaptar `DashboardSummaryRequest` para validar el scope y autorizarlo.
- Adaptar `DashboardController` sin duplicar la logica de filtros de fechas.
- Agregar el permiso `reports.organization.view` a `BasePermissions` y a los roles previstos.
- Agregar pruebas cross-tenant y de permisos.

### Frontend despues del backend

- Agregar `OrganizationDashboardView` como componente separado.
- Mostrarlo solamente cuando el contexto y los permisos lo permitan.
- Incorporar selector `Esta empresa / Grupo completo` para Owners.
- Agregar estados de carga, error, vacio y rango de fechas.
- Probar el parser real de la respuesta API, incluyendo campos nullable y timestamps si se agregan.
- Mantener la navegacion a una empresa hija mediante cambio de tenant controlado.

## No implementar todavía

Este plan no autoriza por sí solo crear endpoints, migraciones, permisos ni cambios de UI. La
implementacion debe comenzar con los tests backend y revisarse antes de escribir el servicio.
