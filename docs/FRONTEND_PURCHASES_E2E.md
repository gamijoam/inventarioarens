# FRONTEND_PURCHASES_E2E

> Flujo end-to-end del modulo de Compras en el frontend, paso a paso.
> Estado: 2026-07-15. Modulo completo (FASE 0-5).

## Vision general

El modulo de Compras permite al usuario:
1. **Crear** un borrador de orden de compra a un proveedor (con o sin IMEIs/seriales).
2. **Recibir** la mercancia (parcial o total), generando StockMovement, actualizando StockBalance, recalculando WAC y creando la CxP.
3. **Cancelar** el borrador si hay error.
4. **Pagar** la CxP desde el modulo AccountsPayable.

## Paginas y rutas

| Ruta | Componente | Permiso |
|---|---|---|
| `/purchases` | `routes/_authed/purchases.tsx` | `purchases.view` |

## Estructura visual de `/purchases`

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Centro de Inventario > Compras                                          │
│ "Gestion de ordenes de compra..."              [+ Nueva compra]         │
├─────────────────────────────────────────────────────────────────────────┤
│ Filtros: [search] [tipo] [stock] [almacen] [estado]                    │
├─────────────────────────────────────────────────────────────────────────┤
│ >  PO-001  2026-07-15  Distribuidora XYZ  [Recibido]  1,000.00  ...     │
│ >  PO-002  2026-07-14  Sin proveedor      [Borrador]    500.00  ...     │
└─────────────────────────────────────────────────────────────────────────┘
```

Al hacer click en una fila, se expande y muestra el `PurchaseSummary` + `QuickActionsBar`.

```
┌──────────────────────────────────────────────────────────────────────┐
│ Detalle de la compra                              [Recibir] [CxP] [...] │
├──────────────────────────────────────────────────────────────────────┤
│ PO-001    [Recibido]                                                  │
│ Emitida: 2026-07-15   Vence: 2026-07-30                              │
│                                              Total USD: $1,000.00      │
│ ●──●──●  Borrador → Parcial → Recibido                                │
│ ████████████████████ 100%                                            │
│ [Distribuidora XYZ] [USD (BCV) 36.5000]                              │
│ Items (2):                                                           │
│   CEPILLO DENTAL | ALMACEN2 | 10.00 / 10.00 | $100.00 c/u          │
└──────────────────────────────────────────────────────────────────────┘
```

## Flujo E2E: Crear compra → Recibir → Stock

### Paso 1: Crear borrador

1. Usuario hace click en **"+ Nueva compra"**.
2. Se abre `PurchaseFormDialog` (full-width, con scroll vertical).
3. **Header**:
   - Campo "Proveedor" (autocomplete, opcional)
   - Campo "Numero de documento" (opcional, placeholder "Auto si se deja vacio")
   - Campo "Fecha de emision" (default hoy)
   - Campo "Fecha de vencimiento" (opcional)
   - Select "Moneda": USD / VES
   - Si VES: aparece select "Tipo de tasa de cambio" (obligatorio)
4. **Items**:
   - Boton "+ Agregar linea" → agrega una fila
   - Cada fila es un card (no tabla, sin scroll horizontal) con:
     - Almacén (select) -- requerido, marcado con *
     - Producto (autocomplete con typeahead SKU/BC/nombre)
     - Cantidad (number, deshabilitado si el producto es serializado)
     - Costo unitario (number)
     - Subtotal (calculado en vivo)
     - IMEIs/Seriales (solo si el producto es `tracking_type === 'serialized'`)
     - Boton papelera (solo si hay > 1 linea)
5. **Footer**:
   - Total general (en vivo, calculado de la suma de subtotales)
   - Botones "Cancelar" / "Crear borrador"
6. Al submit:
   - Validacion client-side via `StorePurchaseSchema` (Zod)
   - Llamada `POST /api/purchases` via `useCreatePurchase`
   - Toast "Compra creada en borrador"
   - **Auto-abre el `ReceiveDialog`** (siguiente paso del flujo)

### Paso 2: Recibir mercancia

1. `ReceiveDialog` se abre con:
   - Lista de items pendientes del PO
   - Input "Recibir" (default = todo lo pendiente)
   - Si el item es serializado: lista readonly de IMEIs capturados
   - Fecha de recepcion (default hoy)
2. Si se cierra sin recibir: el PO queda en `draft`.
3. Si se confirma:
   - Llamada `PATCH /api/purchases/{id}/receive`
   - Backend: por cada item, crea `StockMovement type='purchase'` + actualiza `StockBalance.quantity_available` + crea `ProductUnit` (si serializado) + recalcula `WAC` + crea `AccountsPayable`.
   - Toast "Mercancia recibida. Stock actualizado."
   - El dialog se cierra y la lista se refresca

### Paso 3: Verificar stock

1. Sidebar → **Inventario**
2. Buscar el producto en la columna Stock
3. Click en el producto → tab **Stock** → debe verse:
   - Almacén: 10.00 (Disponible)
   - 0.00 (Reservado)
   - 0.00 (Dañado)

### Paso 4: Pagar la CxP

1. Volver a `/purchases` → click en la fila del PO
2. Si el PO está en `received` o `partially_received`, aparece el boton **"Pagar CxP"**
3. Click → navega a `/payables` (modulo AccountsPayable)
4. Alli el usuario registra el pago con la tasa de cambio

## Variante C: productos serializados (IMEIs)

Para productos con `tracking_type === 'serialized'` (ej: telefonos, tablets):

1. Al seleccionar el producto en el form, el campo **Cantidad** se deshabilita.
2. Aparece el componente `ImeiListInput` con N inputs vacios (N = cantidad).
3. Cada input valida formato regex `/^[A-Z0-9-]{6,32}$/`.
4. Cada IMEI debe ser unico dentro de la lista (no se permiten duplicados).
5. El backend rechaza seriales duplicados por tenant al recibir.

## Cache invalidation

`useCreatePurchase` y `useReceivePurchase` invalidan el cache de TanStack Query
para los productos afectados (detalle + stockByWarehouse + movements + serials + precios)
mas el listado global. Esto asegura que al volver al detalle del producto el stock
se vea actualizado sin hard refresh.

## Permisos

| Permiso | Lo que permite |
|---|---|
| `purchases.view` | Ver el listado y los detalles. |
| `purchases.create` | Crear borradores + cancelar. |
| `purchases.approve` | Recibir mercancia. |

`frontend/src/permissions/constants.ts` mapea:
- `PURCHASES_VIEW = 'purchases.view'`
- `PURCHASES_CREATE = 'purchases.create'`
- `PURCHASES_APPROVE = 'purchases.approve'` ← **IMPORTANTE**: NO `purchases.receive` (que no existe en backend).
- `PURCHASES_RECEIVE` (alias historico, no usar).

## Sidebar

Item "Compras" con icono `ShoppingBag` (entre "Proveedores" y "Traslados"). Permiso `PURCHASES_VIEW`.

## Tests

- **Backend**: 5 nuevos en FASE 0 (`PurchaseWacRecalculationTest`, `PurchaseOrderSyncTest`).
- **Frontend**: 18 schemas + 12 PurchaseSummary + 7 QuickActionsBar = 37 tests del modulo.
- **Total**: 171/171 OK.

## Documentacion relacionada

- `docs/PURCHASES_MODULE.md` — vision completa, endpoints, sync, deferred.
- `app/Modules/Purchases/README.md` (no creado) — documentacion interna del modulo backend.

## Roadmap

- **Variante B** (cajas/empaques): requiere migracion `units_per_purchase` + service. P1.
- **Editar IMEIs al recibir**: actualmente readonly, FASE 4 pendiente.
- **Imprimir PO**: boton placeholder en QuickActionsBar, falta generar PDF.
- **Tests E2E con Playwright**: pendiente.