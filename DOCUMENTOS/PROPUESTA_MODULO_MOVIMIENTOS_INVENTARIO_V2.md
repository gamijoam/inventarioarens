# Propuesta actualizada: Movimientos manuales de inventario

## Auditoria

Luego del análisis con Graphify se confirmó que el proyecto ya posee una arquitectura de movimientos de inventario.

No se debe crear un módulo InventoryMovements independiente porque duplicaría lógica existente.

Componentes existentes:

- app/Modules/Inventory/Services/InventoryMovementService.php
- StockMovement
- StockBalance
- AuditLogger
- Kardex
- Tests de inventario

## Situación actual

El sistema ya registra movimientos provenientes de:

- Compras
- Ventas
- Traslados
- Ajustes
- Daños
- Devoluciones

El faltante funcional es una interfaz para movimientos manuales realizados por usuarios autorizados.

## Objetivo

Agregar una gestión de movimientos manuales dentro del módulo Inventory, reutilizando InventoryMovementService.

Casos:

- Retiro interno de equipos.
- Consumo de materiales.
- Perdidas.
- Correcciones de inventario.
- Ingresos encontrados.
- Bajas autorizadas.

## Implementación propuesta

### Backend

Crear endpoints dentro del módulo Inventory.

Agregar tipos de movimiento:

- internal_withdrawal
- consumption
- loss
- inventory_found

Crear:

- InventoryMovementsController
- CreateInventoryMovementRequest
- InventoryMovementPolicy

Mantener:

- TenantManager
- Policies Laravel
- AuditLogger
- Services existentes

## Reglas importantes

Nunca modificar stock directamente desde Controller.

Todo cambio debe pasar por:

InventoryMovementService

El stock debe actualizarse mediante los métodos existentes.

## Permisos

Crear permisos:

- inventory.movements.view
- inventory.movements.create
- inventory.movements.cancel

## Frontend

Agregar sección:

Inventario

- Productos
- Traslados
- Compras
- Kardex
- Movimientos manuales

Crear componentes siguiendo la arquitectura actual.

## Tests

Agregar pruebas para:

- Crear movimiento autorizado.
- Rechazar usuario sin permiso.
- Validar aislamiento tenant.
- Confirmar actualización de stock.
- Confirmar auditoría.

## Orden de trabajo

1. Revisar rutas y permisos actuales.
2. Revisar StockMovement y modelos relacionados.
3. Crear backend.
4. Crear tests.
5. Ejecutar PHPUnit.
6. Crear frontend.
7. Ejecutar Pint.
8. Commit por fases.
