/**
 * Schemas Zod para el modulo de Traslados (InventoryTransfers).
 * Refleja los endpoints del backend en app/Modules/InventoryTransfers/.
 *
 * Cubre:
 * - Schema de lectura (lo que devuelve el backend).
 * - Schemas de form (store, prepare, dispatch, receive, resolve).
 * - Schemas de driver (transportista).
 * - Schema de checklist (preparation/reception).
 * - Constantes de status, type, validation_mode.
 * - Schemas de filtros para el listado.
 */
import { z } from 'zod';

// =====================================================================
// Constantes del dominio
// =====================================================================

export const TRANSFER_STATUSES = [
  'requested',
  'prepared',
  'prepared_with_differences',
  'dispatched',
  'completed',
  'completed_with_differences',
  'cancelled',
] as const;
export type TransferStatus = (typeof TRANSFER_STATUSES)[number];

export const TRANSFER_STATUS_LABELS: Record<TransferStatus, string> = {
  requested: 'Solicitado',
  prepared: 'Preparado',
  prepared_with_differences: 'Preparado con diferencias',
  dispatched: 'Despachado',
  completed: 'Completado',
  completed_with_differences: 'Completado con diferencias',
  cancelled: 'Cancelado',
};

export const TRANSFER_TYPES = ['internal'] as const;
export type TransferType = (typeof TRANSFER_TYPES)[number];

export const TRANSFER_VALIDATION_MODES = ['simple', 'logistics'] as const;
export type TransferValidationMode = (typeof TRANSFER_VALIDATION_MODES)[number];

// =====================================================================
// Warehouse embebido (simplificado para no acoplar a Transfer sin necesidad)
// =====================================================================

export const TransferWarehouseSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string().optional().nullable(),
  branch_id: z.number().int().nullable().optional(),
  branch_name: z.string().nullable().optional(),
});

// =====================================================================
// Product embebido
// =====================================================================

const TransferProductSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  sku: z.string().nullable().optional(),
  tracking_type: z.enum(['quantity', 'serialized']).optional(),
});

// =====================================================================
// Driver (transportista) embebido
// =====================================================================

export const TransferDriverSchema = z.object({
  id: z.number().int().positive(),
  inventory_transfer_id: z.number().int().positive(),
  name: z.string(),
  document_number: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  vehicle_plate: z.string().nullable().optional(),
  carrier_company: z.string().nullable().optional(),
  picked_up_at: z.string().nullable().optional(),
  delivered_at: z.string().nullable().optional(),
  signed_by_driver_at: z.string().nullable().optional(),
  signature_driver_url: z.string().nullable().optional(),
  signed_by_receiver_at: z.string().nullable().optional(),
  signature_receiver_url: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  is_driver_signed: z.boolean().optional(),
  is_receiver_signed: z.boolean().optional(),
});
export type TransferDriver = z.infer<typeof TransferDriverSchema>;

// =====================================================================
// Item de Transfer
// =====================================================================

const SerialUnitSchema = z.union([
  z.string(),
  z.object({
    serial_type: z.string(),
    serial_number: z.string(),
  }),
]);

export const TransferItemSchema = z.object({
  id: z.number().int().positive(),
  inventory_transfer_id: z.number().int().positive(),
  product_id: z.number().int().positive(),
  product: TransferProductSchema.nullable().optional(),
  warehouse_id: z.number().int().positive(),
  warehouse: TransferWarehouseSchema.nullable().optional(),
  quantity: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  requested_quantity: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  prepared_quantity: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  received_quantity: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  difference_quantity: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  serial_units: z.array(SerialUnitSchema).nullable().optional(),
  prepared_product_unit_ids: z.array(z.number().int()).nullable().optional(),
  received_product_unit_ids: z.array(z.number().int()).nullable().optional(),
  out_stock_movement_id: z.number().int().nullable().optional(),
  in_stock_movement_id: z.number().int().nullable().optional(),
  resolution_status: z.string().optional(),
  resolution_notes: z.string().nullable().optional(),
});
export type TransferItem = z.infer<typeof TransferItemSchema>;

// =====================================================================
// Transfer (lectura)
// =====================================================================

export const TransferSchema = z.object({
  id: z.number().int().positive(),
  sequence: z.number().int().positive().optional(),
  document_number: z.string().nullable().optional(),
  guide_number: z.string().nullable().optional(),
  type: z.enum(TRANSFER_TYPES),
  validation_mode: z.enum(TRANSFER_VALIDATION_MODES),
  status: z.enum(TRANSFER_STATUSES),
  from_warehouse_id: z.number().int().positive(),
  to_warehouse_id: z.number().int().positive(),
  from_warehouse: TransferWarehouseSchema.nullable().optional(),
  to_warehouse: TransferWarehouseSchema.nullable().optional(),
  reason: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  total_base_amount: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  total_local_amount: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  received_base_amount: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  received_local_amount: z.union([z.number(), z.string()]).nullable().optional().transform((v) => v == null ? null : Number(v)),
  resolution_status: z.string().optional(),
  resolution_notes: z.string().nullable().optional(),
  driver: TransferDriverSchema.nullable().optional(),
  items_count: z.number().int().optional(),
  created_by: z.number().int().nullable().optional(),
  processed_at: z.string().nullable().optional(),
  requested_at: z.string().nullable().optional(),
  prepared_at: z.string().nullable().optional(),
  dispatched_at: z.string().nullable().optional(),
  received_at: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  resolved_at: z.string().nullable().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
  items: z.array(TransferItemSchema).optional(),
});
export type Transfer = z.infer<typeof TransferSchema>;

// =====================================================================
// Checklist (preparation / reception)
// =====================================================================

export const ChecklistStageSchema = z.enum(['preparation', 'reception']);
export type ChecklistStage = z.infer<typeof ChecklistStageSchema>;

export const ChecklistItemSchema = z.object({
  id: z.number().int().positive(),
  inventory_transfer_item_id: z.number().int().positive(),
  product_id: z.number().int().positive(),
  product_name: z.string().optional().nullable(),
  product_sku: z.string().optional().nullable(),
  tracking_type: z.string().optional().nullable(),
  expected_quantity: z.number().nullable(),
  checked_quantity: z.number().nullable(),
  difference_quantity: z.number().nullable(),
  expected_product_unit_ids: z.array(z.number().int()).optional(),
  checked_product_unit_ids: z.array(z.number().int()).optional(),
  reason: z.string().optional().nullable(),
  notes: z.string().optional().nullable(),
  progress_percent: z.number().int().optional(),
});
export type ChecklistItem = z.infer<typeof ChecklistItemSchema>;

export const ChecklistPayloadSchema = z.object({
  stage: ChecklistStageSchema,
  status: z.string(),
  progress_percent: z.number().int(),
  items: z.array(ChecklistItemSchema),
});
export type ChecklistPayload = z.infer<typeof ChecklistPayloadSchema>;

// =====================================================================
// Schemas de form
// =====================================================================

const trimmedRequired = (max: number) =>
  z.string().max(max).transform((s) => s.trim()).refine((s) => s.length > 0, 'Requerido.');

const isoDate = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'Formato YYYY-MM-DD.')
  .optional()
  .or(z.literal('').transform(() => undefined));

const positiveNumber = z.coerce.number().positive();

export const StoreTransferItemSchema = z.object({
  product_id: positiveNumber,
  warehouse_id: positiveNumber,
  quantity: positiveNumber,
  product_unit_ids: z.array(z.coerce.number().int().positive()).optional().default([]),
});
export type StoreTransferItem = z.output<typeof StoreTransferItemSchema>;

export const StoreTransferSchema = z
  .object({
    from_warehouse_id: positiveNumber,
    to_warehouse_id: positiveNumber,
    validation_mode: z.enum(TRANSFER_VALIDATION_MODES).default('simple'),
    type: z.enum(TRANSFER_TYPES).default('internal'),
    reason: z.string().max(255).optional(),
    reference: z.string().max(150).optional(),
    notes: z.string().max(1000).optional(),
    processed_at: isoDate,
    document_number: z.string().max(100).optional(),
    items: z.array(StoreTransferItemSchema).min(1, 'Agrega al menos una linea.'),
  })
  .transform((data) => ({
    ...data,
    type: data.type ?? 'internal',
    validation_mode: data.validation_mode ?? 'simple',
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    reason: data.reason?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    reference: data.reference?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    notes: data.notes?.trim() || null,
    processed_at: data.processed_at ?? null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    document_number: data.document_number?.trim() || null,
  }));
export type StoreTransferValues = z.output<typeof StoreTransferSchema>;

export const PrepareTransferItemSchema = z.object({
  inventory_transfer_item_id: positiveNumber,
  prepared_quantity: z.coerce.number().min(0).optional(),
  prepared_product_unit_ids: z.array(z.coerce.number().int().positive()).optional(),
  difference_reason: z.string().max(255).optional(),
  difference_notes: z.string().max(1000).optional(),
});
export type PrepareTransferItem = z.input<typeof PrepareTransferItemSchema>;

export const PrepareTransferSchema = z
  .object({
    prepared_at: isoDate,
    notes: z.string().max(1000).optional(),
    items: z.array(PrepareTransferItemSchema).min(1, 'Debe preparar todos los items del traslado.'),
  })
  .transform((data) => ({
    ...data,
    prepared_at: data.prepared_at ?? null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    notes: data.notes?.trim() || null,
  }));
export type PrepareTransferValues = z.output<typeof PrepareTransferSchema>;

export const ReceiveTransferItemSchema = z.object({
  // El backend (ReceiveInventoryTransferRequest) espera 'inventory_transfer_id'
  // (el ID del item del transfer, NO del transfer). Mapeamos en el form
  // via el alias inventory_transfer_item_id para consistencia UI.
  inventory_transfer_id: positiveNumber,
  received_quantity: z.coerce.number().min(0).optional(),
  received_product_unit_ids: z.array(z.coerce.number().int().positive()).optional(),
  difference_reason: z.string().max(255).optional(),
  difference_notes: z.string().max(1000).optional(),
});
export type ReceiveTransferItem = z.input<typeof ReceiveTransferItemSchema>;

export const ReceiveTransferSchema = z
  .object({
    received_at: isoDate,
    notes: z.string().max(1000).optional(),
    items: z.array(ReceiveTransferItemSchema).min(1, 'Debe recibir todos los items del traslado.'),
  })
  .transform((data) => ({
    ...data,
    received_at: data.received_at ?? null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    notes: data.notes?.trim() || null,
  }));
export type ReceiveTransferValues = z.output<typeof ReceiveTransferSchema>;

export const CancelTransferSchema = z
  .object({
    cancelled_at: isoDate,
    cancellation_reason: z
      .string()
      .min(5, 'Minimo 5 caracteres.')
      .max(1000)
      .transform((s) => s.trim()),
  })
  .transform((data) => ({
    ...data,
    cancelled_at: data.cancelled_at ?? null,
  }));
export type CancelTransferValues = z.output<typeof CancelTransferSchema>;

export const AssignDriverSchema = z
  .object({
    name: trimmedRequired(150),
    document_number: z.string().max(50).optional(),
    phone: z.string().max(50).optional(),
    vehicle_plate: z.string().max(20).optional(),
    carrier_company: z.string().max(150).optional(),
    picked_up_at: isoDate,
    delivered_at: isoDate,
    signed_by_driver_at: isoDate,
    signature_driver_url: z.string().url('URL invalida.').max(500).optional(),
    signed_by_receiver_at: isoDate,
    signature_receiver_url: z.string().url('URL invalida.').max(500).optional(),
    notes: z.string().max(2000).optional(),
  })
  .transform((data) => ({
    ...data,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    document_number: data.document_number?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    phone: data.phone?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    vehicle_plate: data.vehicle_plate?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    carrier_company: data.carrier_company?.trim() || null,
    picked_up_at: data.picked_up_at ?? null,
    delivered_at: data.delivered_at ?? null,
    signed_by_driver_at: data.signed_by_driver_at ?? null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    signature_driver_url: data.signature_driver_url?.trim() || null,
    signed_by_receiver_at: data.signed_by_receiver_at ?? null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    signature_receiver_url: data.signature_receiver_url?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    notes: data.notes?.trim() || null,
  }));
export type AssignDriverValues = z.output<typeof AssignDriverSchema>;

export const CheckChecklistItemSchema = z
  .object({
    checked_quantity: z.coerce.number().min(0).optional(),
    checked_product_unit_ids: z.array(z.coerce.number().int().positive()).optional(),
    reason: z.string().max(255).optional(),
    notes: z.string().max(1000).optional(),
  })
  .transform((data) => ({
    ...data,
    checked_quantity: data.checked_quantity ?? null,
    checked_product_unit_ids: data.checked_product_unit_ids ?? [],
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    reason: data.reason?.trim() || null,
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    notes: data.notes?.trim() || null,
  }));
export type CheckChecklistItemValues = z.output<typeof CheckChecklistItemSchema>;

// =====================================================================
// Filtros de listado
// =====================================================================

export const TransferListFiltersSchema = z.object({
  search: z.string().default(''),
  status: z.enum(['all', ...TRANSFER_STATUSES] as const).default('all'),
  validation_mode: z.enum(['all', ...TRANSFER_VALIDATION_MODES] as const).default('all'),
  from_warehouse_id: z.coerce.number().int().positive().optional(),
  to_warehouse_id: z.coerce.number().int().positive().optional(),
  date_from: isoDate,
  date_to: isoDate,
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(50).default(25),
});
export type TransferListFilters = z.infer<typeof TransferListFiltersSchema>;
