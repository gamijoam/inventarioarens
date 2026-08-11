import { z } from 'zod';

export const MANUAL_MOVEMENT_STATUSES = ['pending', 'approved', 'rejected'] as const;
export const MANUAL_MOVEMENT_TYPES = [
  'internal_consumption',
  'write_off',
  'adjustment_in',
  'adjustment_out',
  'return_internal',
  'damaged',
  'loss',
  'found',
] as const;

export const MANUAL_MOVEMENT_STATUS_LABELS = {
  pending: 'Pendiente',
  approved: 'Aprobado',
  rejected: 'Rechazado',
} as const;

export const MANUAL_MOVEMENT_TYPE_LABELS = {
  internal_consumption: 'Consumo interno',
  write_off: 'Baja',
  adjustment_in: 'Ajuste de entrada',
  adjustment_out: 'Ajuste de salida',
  return_internal: 'Devolución interna',
  damaged: 'Dañado',
  loss: 'Pérdida',
  found: 'Encontrado',
} as const;

const RelatedEntitySchema = z.object({
  id: z.number().int().positive().nullable(),
  name: z.string().nullable(),
});

export const ManualMovementSchema = z.object({
  id: z.number().int().positive(),
  type: z.enum(MANUAL_MOVEMENT_TYPES),
  reason: z.string(),
  notes: z.string().nullable().optional(),
  status: z.enum(MANUAL_MOVEMENT_STATUSES),
  product: RelatedEntitySchema,
  product_variant_id: z.number().int().positive().nullable().optional(),
  product_variant: z
    .object({
      id: z.number().int().positive(),
      color: z.string().nullable().optional(),
      sku_variant: z.string().nullable().optional(),
    })
    .nullable()
    .optional(),
  quantity: z.coerce.number().positive(),
  warehouse: RelatedEntitySchema,
  creator: RelatedEntitySchema,
  stock_movement_id: z.number().int().positive().nullable().optional(),
  approver: RelatedEntitySchema.optional(),
  approved_at: z.string().nullable().optional(),
  rejector: RelatedEntitySchema.optional(),
  rejected_at: z.string().nullable().optional(),
  rejection_reason: z.string().nullable().optional(),
  created_at: z.string(),
});

export type ManualMovement = z.infer<typeof ManualMovementSchema>;
export type ManualMovementStatus = (typeof MANUAL_MOVEMENT_STATUSES)[number];
export type ManualMovementType = (typeof MANUAL_MOVEMENT_TYPES)[number];
export const CreateManualMovementSchema = z.object({
  warehouse_id: z.number().int().positive('Selecciona un almacén.'),
  product_id: z.number().int().positive('Selecciona un producto.'),
  product_variant_id: z.number().int().positive().nullable().optional(),
  quantity: z.number().positive('La cantidad debe ser mayor a cero.'),
  type: z.enum(MANUAL_MOVEMENT_TYPES),
  reason: z.string().trim().min(1, 'El motivo es obligatorio.').max(255),
  notes: z.string().trim().nullable().optional(),
});
export type CreateManualMovement = z.infer<typeof CreateManualMovementSchema>;
export interface ManualMovementFilters {
  status?: ManualMovementStatus | 'all';
  type?: ManualMovementType | 'all';
  warehouse_id?: number | 'all';
  from?: string;
  to?: string;
  page?: number;
}
