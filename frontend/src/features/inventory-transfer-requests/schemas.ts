/**
 * Schemas Zod para el modulo InventoryTransferRequests (inter-empresa).
 * Refleja los endpoints en app/Modules/InventoryTransferRequests/.
 *
 * Cubre:
 * - Schemas de lectura (lo que devuelve el backend).
 * - Schemas de form (store, accept, reject).
 * - Constantes de status, tabs (Enviadas/Recibidas/Pendientes/...).
 */
import { z } from 'zod';

// =====================================================================
// Constantes
// =====================================================================

export const TRANSFER_REQUEST_STATUSES = [
  'requested',
  'rejected',
  'cancelled',
  'completed',
] as const;
export type TransferRequestStatus = (typeof TRANSFER_REQUEST_STATUSES)[number];

export const TRANSFER_REQUEST_STATUS_LABELS: Record<TransferRequestStatus, string> = {
  requested: 'Pendiente',
  rejected: 'Rechazada',
  cancelled: 'Cancelada',
  completed: 'Completada',
};

// Tabs de la bandeja principal. "Enviadas" = originamos la solicitud.
// "Recibidas" = somos la empresa destino. "Pendientes" alias de requested.
// "Completadas" = completed. "Rechazadas" = rejected (cubre tambien
// cancelled para tener una papelera unificada).
export const TRANSFER_REQUEST_TABS = [
  'sent',
  'received',
  'pending',
  'completed',
  'rejected',
] as const;
export type TransferRequestTab = (typeof TRANSFER_REQUEST_TABS)[number];

export const TRANSFER_REQUEST_TAB_LABELS: Record<TransferRequestTab, string> = {
  sent: 'Enviadas',
  received: 'Recibidas',
  pending: 'Pendientes',
  completed: 'Completadas',
  rejected: 'Rechazadas/Canceladas',
};

// =====================================================================
// Entidades embebidas
// =====================================================================

const TenantLiteSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
});

const WarehouseLiteSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string().nullable().optional(),
});

const ProductLiteSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  sku: z.string().nullable().optional(),
  barcode: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  tracking_type: z.enum(['quantity', 'serialized']).optional(),
});

const SerialUnitSchema = z.union([
  z.string(),
  z.object({
    serial_type: z.string(),
    serial_number: z.string(),
  }),
]);

// =====================================================================
// Item
// =====================================================================

export const TransferRequestItemSchema = z.object({
  id: z.number().int().positive(),
  origin_product_id: z.number().int().positive(),
  origin_product: ProductLiteSchema.nullable().optional(),
  destination_product_id: z.number().int().positive().nullable().optional(),
  destination_product: ProductLiteSchema.nullable().optional(),
  quantity: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  product_unit_ids: z.array(z.number().int()).nullable().optional(),
  serial_units: z.array(SerialUnitSchema).nullable().optional(),
  out_stock_movement_id: z.number().int().nullable().optional(),
  in_stock_movement_id: z.number().int().nullable().optional(),
});
export type TransferRequestItem = z.infer<typeof TransferRequestItemSchema>;

// =====================================================================
// Solicitud (lectura)
// =====================================================================

export const TransferRequestSchema = z.object({
  id: z.number().int().positive(),
  sequence: z.number().int().positive().optional(),
  document_number: z.string().nullable().optional(),
  origin_tenant_id: z.number().int().positive(),
  destination_tenant_id: z.number().int().positive(),
  origin_tenant: TenantLiteSchema.nullable().optional(),
  destination_tenant: TenantLiteSchema.nullable().optional(),
  from_warehouse_id: z.number().int().positive(),
  destination_warehouse_id: z.number().int().positive().nullable().optional(),
  from_warehouse: WarehouseLiteSchema.nullable().optional(),
  destination_warehouse: WarehouseLiteSchema.nullable().optional(),
  status: z.enum(TRANSFER_REQUEST_STATUSES),
  reason: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  response_notes: z.string().nullable().optional(),
  requested_by: z.number().int().nullable().optional(),
  responded_by: z.number().int().nullable().optional(),
  requested_at: z.string().nullable().optional(),
  responded_at: z.string().nullable().optional(),
  completed_at: z.string().nullable().optional(),
  items: z.array(TransferRequestItemSchema).optional(),
  created_at: z.string().optional(),
});
export type TransferRequest = z.infer<typeof TransferRequestSchema>;

// =====================================================================
// Form: store (enviar solicitud)
// =====================================================================

const positiveNumber = z.coerce.number().positive();

export const StoreTransferRequestItemSchema = z.object({
  product_id: positiveNumber,
  quantity: positiveNumber,
  product_unit_ids: z.array(z.coerce.number().int().positive()).optional(),
});
export type StoreTransferRequestItem = z.input<typeof StoreTransferRequestItemSchema>;

export const StoreTransferRequestSchema = z
  .object({
    destination_tenant_slug: z.string().max(255).optional(),
    destination_user_email: z.string().email().max(255).optional(),
    from_warehouse_id: positiveNumber,
    reason: z.string().max(255).optional(),
    reference: z.string().max(150).optional(),
    notes: z.string().max(1000).optional(),
    items: z.array(StoreTransferRequestItemSchema).min(1, 'Agrega al menos una linea.'),
  })
  .transform((data) => ({
    ...data,
    reason: data.reason?.trim() || null,
    reference: data.reference?.trim() || null,
    notes: data.notes?.trim() || null,
  }))
  .refine(
    (data) => !!(data.destination_tenant_slug || data.destination_user_email),
    { message: 'Indica slug de empresa destino o email de usuario destino.', path: ['destination_tenant_slug'] },
  );
export type StoreTransferRequestValues = z.output<typeof StoreTransferRequestSchema>;

// =====================================================================
// Form: accept
// =====================================================================

export const AcceptTransferRequestItemSchema = z.object({
  request_item_id: positiveNumber,
  destination_product_id: positiveNumber,
});
export type AcceptTransferRequestItem = z.input<typeof AcceptTransferRequestItemSchema>;

export const AcceptTransferRequestSchema = z
  .object({
    destination_warehouse_id: positiveNumber,
    response_notes: z.string().max(1000).optional(),
    items: z.array(AcceptTransferRequestItemSchema).min(1, 'Debe mapear todos los items.'),
  })
  .transform((data) => ({
    ...data,
    response_notes: data.response_notes?.trim() || null,
  }));
export type AcceptTransferRequestValues = z.output<typeof AcceptTransferRequestSchema>;

// =====================================================================
// Form: reject
// =====================================================================

export const RejectTransferRequestSchema = z
  .object({
    response_notes: z.string().max(1000).optional(),
  })
  .transform((data) => ({
    ...data,
    response_notes: data.response_notes?.trim() || null,
  }));
export type RejectTransferRequestValues = z.output<typeof RejectTransferRequestSchema>;

// =====================================================================
// Filtros de listado
// =====================================================================

export const TransferRequestListFiltersSchema = z.object({
  search: z.string().default(''),
  status: z.enum(['all', ...TRANSFER_REQUEST_STATUSES] as const).default('all'),
  side: z.enum(['sent', 'received', 'all']).default('all'),
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(100).default(25),
});
export type TransferRequestListFilters = z.infer<typeof TransferRequestListFiltersSchema>;
