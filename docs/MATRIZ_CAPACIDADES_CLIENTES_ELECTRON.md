# Matriz de capacidades, clientes y Electron

Fecha: 2026-08-25
Estado: contrato inicial de arquitectura
Alcance: backend Laravel, frontend React, clientes Electron y Motor Local

## 1. Objetivo

Este documento define que parte del sistema es comun, que capacidades pertenecen a cada cliente y
que servicios locales deben existir para que Administrativo, POS y Soporte Tecnico funcionen sin
duplicar el backend.

La matriz separa cuatro conceptos que no deben mezclarse:

1. **Modo de cliente**: `admin`, `pos` o `technician`.
2. **Capacidad contratada del tenant**: el modulo que la empresa tiene habilitado.
3. **Permiso del usuario**: la accion que ese usuario puede realizar.
4. **Scope operativo**: las sucursales, almacenes o grupos que puede consultar.

El backend es la autoridad para las capacidades, permisos y scopes. Electron y React solo deben
representar la configuracion recibida y nunca otorgar acceso por su cuenta.

## 2. Arquitectura canonica

```text
Sistema de Inventario (Administrativo) --\
Sistema de Inventario (POS) -------------+--> API Laravel en el Motor Local
Soporte Tecnico -------------------------/           |
                                                     +--> SQLite local
                                                     +--> Sync supervisor
                                                     +--> Agente de impresion
                                                     +--> API nube
```

### Propiedad de cada componente

| Componente | Propietario | Responsabilidad | No debe hacer |
|---|---|---|---|
| Motor Local | Instalador independiente | Laravel, PHP, SQLite, migraciones, servicios, impresion y sync | Depender de la carpeta de un cliente Electron |
| Administrativo | Canal `admin` | Gestion empresarial y operativa | Instalar, actualizar o eliminar el Motor |
| POS | Canal `pos` | Venta, cobro, caja y operacion de mostrador | Ejecutar migraciones o administrar servicios |
| Soporte Tecnico | Canal `technician` | Vinculacion, diagnostico y soporte local | Ser propietario de los workers permanentes |
| Backend cloud | Despliegue Laravel | Operacion multi-tenant y sincronizacion nube | Confiar en filtros hechos por el frontend |

El Motor Local es el runtime compartido. Cerrar o desinstalar un cliente Electron no debe detenerlo
ni borrar sus datos.

## 3. Clientes Electron actuales

| Cliente | `appMode` | Funcion | Rutas principales | Canal | App ID | Puerto renderer |
|---|---|---|---|---|---|---:|
| Administrativo | `admin` | Gestion completa de la empresa | Todas las rutas permitidas por permisos | `admin` | `com.inventarioarens.admin` | 8788 |
| POS | `pos` | Venta y caja de mostrador | `/pos/*` | `pos` | `com.inventarioarens.pos` | 8789 |
| Soporte Tecnico | `technician` | Instalacion, vinculacion y diagnostico | `/support/*` | `technician` | `com.inventarioarens.technician` | 8790 |

La separacion de rutas por `appMode` existe en `frontend/src/config/branding.ts` y
`frontend/src/routes/_authed.tsx`. Esa separacion es una defensa de interfaz, no una autorizacion
de seguridad. El backend debe continuar validando permisos en cada endpoint.

## 4. Capacidades por cliente

La siguiente tabla describe el comportamiento objetivo y sirve como inventario inicial. `Actual`
indica que existe en el sistema; `Objetivo` indica la forma en que debe gobernarse cuando se agregue
la capa de capacidades por tenant.

| Capacidad | Administrativo | POS | Soporte Tecnico | Actual | Objetivo |
|---|---|---|---|---|---|
| Inicio de sesion y seleccion de empresa | Si | Si | Si | Si | Comun |
| Cambio de empresa autorizado | Si | Si | Si | Si | Comun |
| Dashboard gerencial | Si | No | No | Si | Opcional por tenant y permiso |
| Productos y catalogo | Si | Lectura | Diagnostico | Si | Comun/Inventario |
| Marcas, categorias y etiquetas | Si | Lectura | No | Si | Modulo Inventario |
| Variantes y productos serializados | Si | Lectura/venta | Diagnostico | Si | Modulo Inventario |
| Almacenes y sucursales | Si | Seleccion operativa | Diagnostico | Si | Comun/Inventario |
| Stock y movimientos | Si | Consulta necesaria | Diagnostico | Si | Comun/Inventario |
| Conteos y ajustes | Si | No | No | Si | Modulo Inventario |
| Compras y recepciones | Si | No | No | Si | Modulo Compras |
| Ventas administrativas | Si | No | No | Si | Modulo Ventas |
| POS y checkout | Si, si tiene permiso | Si | No | Si | Modulo POS |
| Caja y sesiones | Si, si tiene permiso | Si | No | Si | Modulo Caja |
| Metodos de pago | Gestion | Uso | Diagnostico | Si | Modulo POS |
| Clientes | Gestion | Consulta/alta limitada | No | Si | Comun/Modulo Ventas |
| Proveedores | Gestion | No | No | Si | Modulo Compras |
| Devoluciones | Gestion | Operacion autorizada | No | Si | Modulo Ventas/POS |
| Cuentas por cobrar | Si | Cobro limitado | No | Si | Modulo Finanzas |
| Cuentas por pagar | Si | No | No | Si | Modulo Finanzas |
| Tasas y moneda | Gestion | Consulta | No | Si | Localizacion de mercado |
| Transferencias internas | Si | No | No | Si | Modulo Inventario |
| Solicitudes interempresa | Si | No | No | Si | Modulo Organizaciones |
| Garantias y taller | Si | No | No | Si | Modulos verticales |
| Comisiones y promociones | Si | Uso | No | Si | Modulos comerciales |
| Reportes | Si | Reportes POS limitados | No | Si | Modulo Reportes |
| Importacion de datos | Si | No | No | Si | Modulo Importacion |
| Impresion | Configuracion/uso | Tickets | Prueba/diagnostico | Si | Servicio del Motor |
| Sync local-nube | Consulta | Uso transparente | Gestion | Si | Servicio del Motor |
| Vinculacion de empresas | No | No | Si | Si | Modulo Soporte |
| Diagnostico del Motor Local | No | Estado visible | Si | Parcial | Servicio del Motor |

## 5. Nucleo comun

Estos componentes deben permanecer compartidos entre clientes y empresas:

| Area | Componentes |
|---|---|
| Identidad | Usuarios, tenants, membresias, sesiones y tokens |
| Autorizacion | Roles, permisos, overrides y scopes |
| Catalogo | Productos, SKU, marcas, categorias, etiquetas y variantes |
| Inventario | Almacenes, balances, movimientos, reservas y unidades serializadas |
| Trazabilidad | Auditoria, idempotencia, snapshots y referencias historicas |
| Comercio | Clientes, proveedores, ventas y compras basicas |
| API | Contratos JSON, errores, paginacion y autenticacion |
| Sync | Outbox, inbox, mapeos, deduplicacion y ACK |
| Operacion local | Motor Local, SQLite, logs, health checks y configuracion |

El nucleo no debe contener condiciones especificas del cliente como `if cliente_x` o `if appMode`
para decidir reglas contables o de inventario. Esas diferencias deben vivir en modulos o politicas
de dominio identificables.

## 6. Modulos opcionales

| Modulo | Dependencias principales | Clientes candidatos |
|---|---|---|
| POS | Productos, clientes, precios, pagos, caja, impresion | POS, Administrativo |
| Compras | Productos, proveedores, inventario, cuentas por pagar | Administrativo |
| Finanzas | Ventas, pagos, tasas, cuentas por cobrar/pagar | Administrativo |
| Promociones | Productos, listas de precio, POS | POS, Administrativo |
| Comisiones | Ventas, usuarios, pagos, tasas | Administrativo |
| Garantias | Ventas, productos serializados, clientes | Administrativo |
| Taller | Clientes, productos, inventario, usuarios | Administrativo |
| Transferencias interempresa | Grupos, tenants hijos, inventario serializado, sync | Administrativo |
| Importacion | Productos, clientes, proveedores, jobs | Administrativo |
| Telegram | Tenant settings, alertas, usuarios autorizados | Administrativo |
| Impresion | Motor Local, perfiles, estaciones y jobs | POS, Administrativo, Soporte |
| Sync offline | Motor Local, tokens, nodos y SQLite | Todos los clientes locales |

Un modulo opcional debe tener, como minimo:

- Identificador estable.
- Nombre y etiqueta visible.
- Permisos asociados.
- Rutas frontend asociadas.
- Dependencias de otros modulos.
- Requisitos de servicios locales.
- Reglas de sync, si replica datos.
- Pruebas de habilitado y deshabilitado.

## 7. Capas de acceso

La decision final de acceso debe seguir este orden:

```text
Cliente Electron
  -> capability del tenant
    -> permiso efectivo del usuario
      -> scope del recurso
        -> policy y autorizacion backend
          -> operacion de negocio
```

Ejemplo para POS:

```text
tenant tiene pos
AND usuario tiene pos.checkout
AND usuario tiene scope sobre el almacen
AND policy permite vender el producto
=> checkout permitido
```

Que la ruta exista en el bundle no implica que pueda utilizarse. Que la ruta no aparezca en el menu
tampoco reemplaza la autorizacion del backend.

## 8. Contrato objetivo de capacidades

El contrato futuro de sesion/bootstrap debe poder expresar, sin que el frontend lo calcule:

```json
{
  "client": {
    "mode": "pos",
    "name": "Sistema de Inventario (POS)",
    "version": "0.2.57"
  },
  "tenant": {
    "id": 10,
    "slug": "demo-caracas",
    "branding_profile": "default"
  },
  "capabilities": [
    "catalog",
    "inventory.read",
    "pos",
    "cash_register",
    "printing",
    "sync"
  ],
  "permissions": [
    "products.view",
    "pos.checkout",
    "cash_register.open"
  ],
  "local_services": {
    "backend": "required",
    "printer": "required",
    "sync": "required"
  }
}
```

Reglas del contrato:

- `capabilities` no reemplaza `permissions`.
- El frontend no puede agregar capacidades localmente.
- El backend debe rechazar endpoints de una capacidad inactiva aunque el usuario tenga el permiso.
- El modo Electron restringe la experiencia, no cambia la seguridad del backend.
- El Motor Local expone estado y version, pero no secretos de sync al renderer.

## 9. Estado actual de Electron

### Ya resuelto

- App IDs diferentes para los tres clientes.
- Nombres de ejecutables diferentes.
- Carpetas de instalacion separadas.
- Canales de update separados.
- Directorios de datos Electron separados.
- Deteccion de modo por ejecutable.
- Rutas restringidas por modo.
- Motor Local independiente como arquitectura operativa canonica.
- Servicios `SistemaInventarioBackend`, `SistemaInventarioPrinter` y `SistemaInventarioSync`.

### Pendiente o a vigilar

- Los archivos `electron-builder.*.yml` aun incluyen los tres directorios `dist/*`; cada instalador
  puede contener bundles que no necesita.
- El runtime Electron conserva compatibilidad con el modelo anterior de arranque local; debe
  mantenerse subordinado al Motor Local y no volver a crear ownership compartido.
- La documentacion historica de `docs/ELECTRON_UPDATES_AND_TECHNICIAN.md` contiene secciones que
  describen el runtime embebido anterior. La implementacion del Motor Local y este documento tienen
  precedencia para releases nuevos.
- El frontend usa `VITE_APP_MODE` y definiciones estaticas de branding; aun no consume un perfil
  visual o un catalogo de capacidades por tenant.
- El sidebar se filtra por permisos, pero no por entitlements de modulos.

## 10. Orden de implementacion

### Fase 0: contrato y seguridad

Estado: parcialmente implementada el 2026-08-25.

- Mantener esta matriz como fuente de decisiones.
- [x] Agregar pruebas de tenant header contra tenant de URL en Access Control.
- [x] Endurecer validaciones de scopes para branches, warehouses y customer groups.
- [ ] Extender la misma auditoria a todas las rutas con tenant en URL.
- [ ] Documentar de forma unica el ownership del Motor Local en los documentos historicos.

### Fase 1: capacidades del tenant

Estado: vertical implementada el 2026-08-25.

- [x] Definir catalogo de modulos y dependencias en `BaseCapabilities`.
- [x] Persistir capacidades activas por tenant.
- [x] Conservar todos los modulos para tenants existentes.
- [x] Crear tenants nuevos con nucleo + inventario.
- [x] Exponer capacidades en login, `/api/auth/me` y cambio de tenant.
- [x] Rechazar en backend rutas de modulos deshabilitados mediante middleware.
- [x] Administrar capacidades con `GET/PATCH /api/tenant-capabilities`.
- [x] Administrar capacidades desde React en `/settings/capabilities`.
- [x] Agregar pruebas de capacidad habilitada, deshabilitada y aislamiento.

### Fase 2: frontend y Electron

- [x] Filtrar el menu por `appMode`, capacidad y permiso.
- [x] Mantener el backend como autoridad mediante middleware de capacidades.
- [ ] Crear perfiles de cliente visuales sin duplicar features.
- [x] Verificar que cada instalador solo publique el bundle que corresponde.
- [ ] Validar que actualizar un cliente no detenga ni reemplace el Motor Local.

### Fase 3: branding runtime

- Logo, nombre, colores, tema y textos por tenant.
- Fallback seguro al branding del cliente Electron.
- Pruebas de login, refresh, cambio de tenant y logout.

### Fase 4: extensiones de dominio

- Campos personalizados solamente donde exista un caso real.
- Workflows especiales como modulos aislados.
- Eventos de sync definidos por modulo.
- No introducir un workflow engine general sin dos casos concretos que lo justifiquen.

## 11. Criterios de aceptacion iniciales

La primera fase se considera correcta cuando:

- Administrativo, POS y Soporte siguen funcionando contra el mismo Motor Local.
- Cerrar o desinstalar un cliente no detiene backend, impresion, SQLite ni sync.
- Un usuario con permiso pero sin capacidad no puede invocar el modulo.
- Un usuario con capacidad pero sin permiso tampoco puede invocarlo.
- Un cliente Electron no puede ampliar capacidades modificando su bundle.
- La API rechaza tenant de URL incompatible con el tenant autenticado.
- Los clientes muestran diagnostico si el Motor Local no esta saludable.
- Cada release identifica claramente si cambia cliente, Motor Local o ambos.
- Los tests cubren las combinaciones habilitado/deshabilitado y aislamiento multi-tenant.

## 12. Fuentes

- `docs/MODULES.md`
- `docs/FRONTEND_ARQUITECTURA.md`
- `docs/FRONTEND_PERMISSIONS.md`
- `docs/ELECTRON_UPDATES_AND_TECHNICIAN.md`
- `docs/MOTOR_LOCAL_WINDOWS_PLAN_2026-08-14.md`
- `docs/MOTOR_LOCAL_WINDOWS_IMPLEMENTACION_2026-08-14.md`
- `frontend/src/config/branding.ts`
- `frontend/src/routes/_authed.tsx`
- `frontend/src/components/layout/Sidebar.tsx`
- `frontend/electron/app-config.cjs`
- `frontend/electron/main.cjs`
- `frontend/electron/backend-runtime.cjs`
- `frontend/electron-builder.admin.yml`
- `frontend/electron-builder.pos.yml`
- `frontend/electron-builder.technician.yml`
