# Graphify Context Map

Este documento es el punto de entrada curado para consultar INVENTARIOARENS con Graphify.
Agrupa la documentacion existente en secciones estables para que las consultas del grafo
tengan nombres claros y rutas de referencia rapidas.

## Proyecto

INVENTARIOARENS es un SaaS multi-tenant de inventario y punto de venta. El backend es una API
REST Laravel con PostgreSQL, autenticacion por Bearer token o cookie httpOnly, y aislamiento por
`tenant_id`. El frontend vive en `frontend/` como SPA React + TypeScript.

Leer primero:

- [Arquitectura backend](ARCHITECTURE.md)
- [Mapa de modulos](MODULES.md)
- [Referencia API](API.md)
- [Bitacora de implementacion](IMPLEMENTATION_LOG.md)
- [Contrato para frontend](AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md)

## Tenancy Y Autenticacion

La aplicacion usa single database multi-tenant con `tenant_id`, `TenantManager`,
`TenantScope`, `BelongsToTenant`, `api.auth` y middleware `tenant`. Los grupos de tenants
representan propietarios SaaS y las empresas hijas son spinoffs.

Documentos clave:

- [Aislamiento multiempresa](AISLAMIENTO_MULTIEMPRESA_2026-07-05.md)
- [Tenancy API](TENANCY_API.md)
- [Bootstrap API](BOOTSTRAP_API.md)
- [Cookie auth API](AUTH_COOKIE_API.md)
- [Auditoria multi-tenancy](AUDIT_2026-07-11/01_MULTI_TENANCY.md)
- [Auditoria auth y seguridad](AUDIT_2026-07-11/02_AUTH_SEGURIDAD.md)

Nodos de codigo frecuentes:

- `TenantManager`
- `TenantScope`
- `BelongsToTenant`
- `ResolveTenant`
- `AuthenticateApiToken`
- `AuthService`
- `CookieIssuer`

## Permisos Y Scopes

El sistema usa Spatie Permission con teams por `tenant_id`. Los permisos base viven en
`BasePermissions` y los roles predefinidos incluyen Owner, Administrador, Gerente, Vendedor,
Almacen y Auditor. El frontend debe respetar permisos efectivos, scopes y mascaras de campo.

Documentos clave:

- [Diseno jerarquia de permisos](PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md)
- [Diseno scopes](SCOPES_DESIGN_2026-07-13.md)
- [Roadmap Access Groups](ACCESS_GROUPS_ROADMAP.md)
- [Instrucciones frontend permisos](INSTRUCCIONES_FRONTEND_PERMISSIONS.md)
- [Instrucciones frontend scopes](INSTRUCCIONES_FRONTEND_SCOPES.md)
- [Auditoria API design](AUDIT_2026-07-11/08_API_DESIGN.md)

Nodos de codigo frecuentes:

- `BasePermissions`
- `AccessControlService`
- `CapabilityResolver`
- `ScopeResolver`
- `PermissionCatalogService`

## Inventario Y Catalogo

El dominio de inventario cubre productos, marcas, categorias, tags, almacenes, sucursales,
existencias, movimientos, WAC, alertas, conteos fisicos y ubicaciones de almacen.

Documentos clave:

- [Catalogo inventario API](INVENTORY_CATALOG_API.md)
- [Alertas inventario API](INVENTORY_ALERTS_API.md)
- [Inventario fase 3](INVENTORY_PHASE3.md)
- [Modulo inventario diferido](INVENTORY_MODULE_DEFERRED.md)
- [Auditoria inventario e IMEI](AUDIT_2026-07-11/04_INVENTARIO_IMEI.md)

Nodos de codigo frecuentes:

- `Product`
- `StockBalance`
- `StockMovement`
- `InventoryMovementService`
- `InventoryValuationService`
- `InventoryAlertService`
- `StockCountService`
- `AlertHistoryService`

## Traslados Logisticos

Los traslados cubren solicitud, aceptacion, preparacion, despacho, recepcion, diferencias y
cancelacion. Hay modelos para transferencias operativas y solicitudes cross-tenant.

Documentos clave:

- [Modulo traslados](INVENTORY_TRANSFERS_MODULE.md)
- [Plan traslados logisticos](PLAN_MODULO_TRASLADOS_LOGISTICOS_2026-07-09.md)
- [Fase 1 backend](IMPLEMENTACION_TRASLADOS_FASE_1_BACKEND_2026-07-09.md)
- [Fase 2 solicitud](IMPLEMENTACION_TRASLADOS_FASE_2_SOLICITUD_LOGISTICA_2026-07-09.md)
- [Fase 3 preparacion](IMPLEMENTACION_TRASLADOS_FASE_3_PREPARACION_LOGISTICA_2026-07-09.md)
- [Fase 4 despacho](IMPLEMENTACION_TRASLADOS_FASE_4_DESPACHO_LOGISTICO_2026-07-09.md)
- [Fase 5 recepcion](IMPLEMENTACION_TRASLADOS_FASE_5_RECEPCION_LOGISTICA_2026-07-09.md)
- [Fase 5B diferencias](IMPLEMENTACION_TRASLADOS_FASE_5B_RESOLUCION_DIFERENCIAS_2026-07-10.md)
- [Fase 7 cancelacion](IMPLEMENTACION_TRASLADOS_FASE_7_CANCELACION_2026-07-10.md)
- [Frontend transfers E2E](FRONTEND_TRANSFERS_E2E.md)

Nodos de codigo frecuentes:

- `InventoryTransfer`
- `InventoryTransferService`
- `InventoryTransferRequest`
- `InventoryTransferRequestService`
- `AdminTransferService`

## Sync Local Nube

El sync usa un patron local-first con transactional outbox bidireccional. Los nodos locales hacen
push/pull, deduplican por `event_uuid`, aplican ACK solo despues de persistir y excluyen eventos del
propio nodo.

Documentos clave:

- [Diseno sync local nube](SINCRONIZACION_LOCAL_NUBE_2026-07-05.md)
- [Sync API transporte](SYNC_API_TRANSPORTE_2026-07-05.md)
- [Sync operations](SYNC_OPERATIONS.md)
- [Worker Windows operacion](SYNC_WORKER_WINDOWS_OPERACION_2026-07-06.md)
- [Worker Windows tarea programada](SYNC_WORKER_WINDOWS_TAREA_PROGRAMADA_2026-07-06.md)
- [Foto inicial catalogo](SYNC_FOTO_INICIAL_CATALOGO_2026-07-06.md)
- [Outbox inventario precios](SYNC_OUTBOX_INVENTARIO_PRECIOS_2026-07-06.md)
- [Outbox POS caja](SYNC_OUTBOX_EVENTOS_POS_CAJA_2026-07-05.md)
- [Auditoria sync engine](AUDIT_2026-07-11/03_SYNC_ENGINE.md)

Nodos de codigo frecuentes:

- `SyncController`
- `SyncWorkerService`
- `SyncEventApplier`
- `SyncCatalogOutboxService`
- `ApplySyncInboxCommand`
- `SyncToken`

## POS, Caja Y Dinero

El dominio monetario usa doble cuenta USD/VES y snapshot historico de tasa. POS, caja, pagos,
CXC, CXP, garantias y ajustes financieros deben conservar trazabilidad append-only.

Documentos clave:

- [Metodos de pago](MODULO_METODOS_PAGO.md)
- [Tasas de cambio](MODULO_TASAS_CAMBIO_2026-07-08.md)
- [Currency module](CURRENCY_MODULE.md)
- [Purchases module](PURCHASES_MODULE.md)
- [Frontend purchases E2E](FRONTEND_PURCHASES_E2E.md)
- [Auditoria POS caja tasas](AUDIT_2026-07-11/05_POS_CAJA_TASAS.md)
- [Auditoria CXC CXP garantias](AUDIT_2026-07-11/07_CXC_CXP_GARANTIAS.md)

Nodos de codigo frecuentes:

- `ExchangeRate`
- `ExchangeRateType`
- `ExchangeRateActivationService`
- `CashRegisterService`
- `PosOrder`
- `AccountsReceivableService`
- `AccountsPayableService`
- `FinancialAdjustmentService`

## Frontend SPA

El frontend moderno vive en `frontend/` y usa Vite, React, TypeScript, TanStack Query/Router/Table,
Tailwind, Radix UI y Zustand. Consume `/api/*` y debe respetar permisos, scopes y contrato de auth.

Documentos clave:

- [Frontend arquitectura](FRONTEND_ARQUITECTURA.md)
- [Frontend fases](FRONTEND_FASES.md)
- [Frontend permisos](FRONTEND_PERMISSIONS.md)
- [Instrucciones SaaS master](INSTRUCCIONES_FRONTEND_SAAS_MASTER.md)
- [Contrato para frontend](AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md)
- [Frontend README](../frontend/README.md)

Nodos de codigo frecuentes:

- `frontend/src/api/client.ts`
- `frontend/src/features/auth`
- `frontend/src/features/inventory-center`
- `frontend/src/features/transfers`
- `frontend/src/features/purchases`
- `frontend/src/features/users`

## Infraestructura Y Deploy

El proyecto local corre en Windows/Laragon y la nube real es el VPS `217.216.80.158` con Nginx,
PHP-FPM y PostgreSQL nativo. No se debe confundir con el otro SaaS MiInventarioFacil en otro VPS.

Documentos clave:

- [Build](../BUILD.md)
- [Deploy platform master](DEPLOY_PLATFORM_MASTER_2026-07-13.md)
- [Dominio app miinventariofacil](DOMINIO_APP_MIINVENTARIOFACIL_VPS_2026-07-07.md)
- [Entorno local Laragon](ENTORNO_LOCAL_LARAGON_POSTGRES_2026-07-05.md)
- [Entorno VPS Postgres local](ENTORNO_VPS_POSTGRES_LOCAL_2026-07-05.md)
- [API nube permanente](API_NUBE_PERMANENTE_Y_PRUEBA_DOMINIO_2026-07-07.md)

## Testing Y Calidad

Toda funcionalidad nueva requiere tests. Los cambios backend deben correr al menos el modulo afectado
y, si hay riesgo cross-tenant, pruebas de aislamiento. Sync tiene pruebas propias y smoke test cuando
aplica.

Documentos clave:

- [Auditoria calidad tests](AUDIT_2026-07-11/10_CALIDAD_TESTS.md)
- [Roadmap auditoria](AUDIT_2026-07-11/ROADMAP.md)
- [Pendientes backend](PENDIENTES_BACKEND_2026-07-12.md)
- [Correccion fallas preexistentes](CORRECCION_FALLAS_PREEXISTENTES_2026-07-10.md)

Nodos de codigo frecuentes:

- `tests/TestCase.php`
- `RefreshDatabase`
- `TenantIsolationTest`
- `OperationalTenantIsolationTest`
- `SyncApiTest`
- `InventoryTransferApiTest`

## Consultas Recomendadas Para Graphify

Usar estas preguntas como entrada rapida:

- `.\scripts\graphify.cmd query "como se resuelve el tenant actual y que servicios dependen de TenantManager"`
- `.\scripts\graphify.cmd query "que codigo participa en el flujo de sync outbox inbox"`
- `.\scripts\graphify.cmd query "que componentes frontend usan inventory-center api"`
- `.\scripts\graphify.cmd query "que servicios actualizan stock balances y stock movements"`
- `.\scripts\graphify.cmd path "ResolveTenant" "TenantManager"`
- `.\scripts\graphify.cmd path "InventoryTransferService" "StockMovement"`
- `.\scripts\graphify.cmd explain "BasePermissions"`
