# Propuesta técnica: Módulo de Movimientos de Inventario

## Objetivo

Crear un módulo profesional de movimientos de inventario para cubrir operaciones que actualmente no representan una compra ni un traslado.

La implementación debe respetar la arquitectura actual de InventarioArens:

- Arquitectura modular Laravel.
- Services para lógica de negocio.
- Policies para permisos.
- Multi-tenant.
- Auditoría.
- Tests Feature.
- Integración con servicios existentes de stock.

---

# Situación actual

Actualmente existen dos formas principales de afectar stock:

1. Compras
   - Generan entradas de inventario.

2. Traslados
   - Generan movimientos entre ubicaciones/empresas.

El módulo InventoryTransfers ya está correctamente desarrollado y no debe utilizarse para otros casos.

---

# Problema identificado

Faltan operaciones reales de inventario como:

- Retiro interno por un jefe.
- Entrega de equipos a empleados.
- Consumo de materiales.
- Pérdidas.
- Productos dañados.
- Ajustes por inventario físico.
- Devoluciones.
- Correcciones administrativas.

Estas operaciones deben tener trazabilidad.

---

# Solución propuesta

Crear nuevo módulo:

app/Modules/InventoryMovements/

Estructura esperada:

InventoryMovements/

- Models/
  - InventoryMovement.php

- Services/
  - InventoryMovementService.php

- Controllers/
  - InventoryMovementController.php

- Policies/
  - InventoryMovementPolicy.php

- Requests/

- Tests/

Debe seguir el mismo patrón utilizado por InventoryTransfers.

---

# Modelo InventoryMovement

Crear tabla:

inventory_movements

Campos sugeridos:

- id
- tenant_id
- warehouse_id
- product_id
- quantity
- type
- direction
- reason
- notes
- created_by
- approved_by
- reference_type
- reference_id
- status
- created_at
- updated_at

---

# Tipos de movimientos

## Entradas

PURCHASE_RECEIPT

RETURN

INVENTORY_FOUND

## Salidas

INTERNAL_WITHDRAWAL

CONSUMPTION

LOSS

DAMAGE

INVENTORY_ADJUSTMENT

---

# Flujo recomendado

Ejemplo retiro interno:

Usuario autorizado:

Inventario
-> Nuevo movimiento
-> Seleccionar tipo
-> Seleccionar producto
-> Cantidad
-> Motivo
-> Enviar

Dependiendo de permisos:

Empleado solicita.
Supervisor aprueba.
Stock se actualiza.

---

# Permisos

Crear InventoryMovementPolicy.

Permisos sugeridos:

inventory_movements.view
inventory_movements.create
inventory_movements.approve
inventory_movements.cancel

No todos los usuarios deben poder modificar stock directamente.

---

# Integración con stock

IMPORTANTE:

No modificar stock directamente desde Controllers.

Usar un servicio central:

InventoryMovementService
        |
        v
Servicio actual de stock

La lógica debe reutilizar la arquitectura existente.

---

# Auditoría

Cada movimiento debe guardar:

- Usuario.
- Fecha.
- Producto.
- Cantidad.
- Motivo.
- Stock anterior.
- Stock posterior.
- Aprobador.

---

# Frontend esperado

Nueva sección:

Inventario

- Productos
- Compras
- Traslados
- Movimientos

Pantalla de movimientos:

Columnas:

Fecha
Producto
Tipo
Cantidad
Usuario
Estado

Filtros:

- Fecha.
- Producto.
- Usuario.
- Tipo.
- Almacén.

---

# Estados del movimiento

DRAFT

PENDING_APPROVAL

APPROVED

REJECTED

CANCELLED

---

# Tests requeridos

Crear:

tests/Feature/InventoryMovements/

Casos:

- Usuario autorizado crea movimiento.
- Usuario sin permiso no puede crear.
- Aprobación modifica stock.
- Rechazo no modifica stock.
- Validación multi-tenant.
- Registro correcto de auditoría.

---

# Orden de implementación

## Fase 1 Backend

- Crear módulo.
- Migración.
- Modelo.
- Service.
- Policy.
- Controller.
- Requests.
- Tests.

## Fase 2 Integración

- Conectar con stock.
- Validar reservas.
- Validar productos serializados si aplica.

## Fase 3 Frontend

- Menú.
- Listado.
- Formulario.
- Aprobaciones.
- Filtros.

## Fase 4 Mejoras

- Kardex.
- Exportaciones.
- Reportes.
- Auditoría avanzada.

---

# Regla principal para futuras modificaciones

No reemplazar ni alterar InventoryTransfers.

Los traslados representan transferencias físicas.

InventoryMovements debe representar ajustes y operaciones administrativas de inventario.

La implementación debe conservar la arquitectura actual del proyecto.
