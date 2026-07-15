/**
 * Schemas Zod para el modulo de Compras.
 * Reflejan los endpoints del backend en app/Modules/Purchases/.
 *
 * El backend NO incluye update ni delete: una compra no se "edita", se
 * cancela y se recrea. Por eso el frontend tampoco expone esos casos.
 */
import { z } from 'zod';

// =====================================================================
// Constantes del dominio
// =====================================================================

export const PURCHASE_STATUSES = [
  'draft',
  'partially_received',
  'received',
  'cancelled',
] as const;
export type PurchaseStatus = (typeof PURCHASE_STATUSES)[number];

export const PURCHASE_STATUS_LABELS: Record<PurchaseStatus, string> = {
  draft: 'Borrador',
  partially_received: 'Recibido parcial',
  received: 'Recibido',
  cancelled: 'Cancelado',
};

export const PURCHASE_CURRENCIES = ['USD', 'VES'] as const;
export type PurchaseCurrency = (typeof PURCHASE_CURRENCIES)[number];

// Tipos de serial para productos serializados (telefonos con IMEI, etc).
// El backend los acepta en PurchaseItem.serial_units[].
export const SERIAL_TYPES = ['imei', 'serial'] as const;
export type SerialType = (typeof SERIAL_TYPES)[number];

export const SERIAL_TYPE_LABELS: Record<SerialType, string> = {
  imei: 'IMEI',
  serial: 'Serial',
};

// =====================================================================
// Schemas de lectura (lo que devuelve el backend)
// =====================================================================

/**
 * Shape exacto de un PurchaseItem serializado por PurchaseItemResource.
 * Los campos de costo (unit_cost, total_cost, etc.) son GATED por el
 * permiso `finance.costs.view` y se omiten del JSON si el user no lo
 * tiene. El frontend debe manejar su ausencia (no son `null`).
 */
export const PurchaseItemSchema = z.object({
  id: z.number().int().positive(),
  purchase_order_id: z.number().int().positive(),
  warehouse_id: z.number().int().positive(),
  product_id: z.number().int().positive(),
  quantity: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  received_quantity: z.union([z.number(), z.string()]).transform((v) => Number(v)),
  serial_units: z.array(z.unknown()).nullable().optional(),
  stock_movement_id: z.number().int().nullable().optional(),
  // Costos (opcionales por permisos).
  unit_cost: z.union([z.number(), z.string()]).nullable().optional(),
  total_cost: z.union([z.number(), z.string()]).nullable().optional(),
  base_unit_cost: z.union([z.number(), z.string()]).nullable().optional(),
  base_total_cost: z.union([z.number(), z.string()]).nullable().optional(),
  // Relaciones opcionales (eager loaded en show).
  product: z.unknown().nullable().optional(),
  warehouse: z.unknown().nullable().optional(),
});
export type PurchaseItem = z.infer<typeof PurchaseItemSchema>;

/**
 * Shape exacto de un PurchaseOrder serializado por PurchaseOrderResource.
 * Los montos son strings (decimal:4) -- los normalizamos a number.
 */
export const PurchaseSchema = z.object({
  id: z.number().int().positive(),
  supplier_id: z.number().int().nullable().optional(),
  status: z.enum(PURCHASE_STATUSES),
  document_number: z.string().nullable().optional(),
  issued_at: z.string().nullable().optional(),
  due_date: z.string().nullable().optional(),
  purchase_currency: z.enum(PURCHASE_CURRENCIES),
  exchange_rate_type_id: z.number().int().nullable().optional(),
  exchange_rate_type_code: z.string().nullable().optional(),
  exchange_rate: z.union([z.number(), z.string()]).nullable().optional(),
  total_base_amount: z.union([z.number(), z.string()]).nullable().optional(),
  total_local_amount: z.union([z.number(), z.string()]).nullable().optional(),
  received_base_amount: z.union([z.number(), z.string()]).nullable().optional(),
  received_local_amount: z.union([z.number(), z.string()]).nullable().optional(),
  items_count: z.number().int().optional(),
  created_by: z.number().int().nullable().optional(),
  received_at: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
  // Relaciones opcionales.
  supplier: z.unknown().nullable().optional(),
  items: z.array(PurchaseItemSchema).optional(),
});
export type Purchase = z.infer<typeof PurchaseSchema>;

// =====================================================================
// Schemas de form (crear draft + recibir + cancelar)
// =====================================================================

const trimmedOptionalString = (max: number) =>
  z
    .string()
    .max(max)
    .optional()
    // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
    .transform((s) => s?.trim() || null);

const trimmedRequiredString = (max: number) =>
  z
    .string()
    .max(max)
    .transform((s) => s.trim())
    .refine((s) => s.length > 0, 'Requerido.');

const isoDate = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'Formato YYYY-MM-DD.')
  .optional()
  .or(z.literal('').transform(() => null));

const positiveNumber = (max = 999_999) =>
  z.coerce.number().positive('Debe ser mayor que 0.').max(max);

/**
 * Linea de item para crear el draft. El backend exige seriales para
 * productos `tracking_type='serialized'` (la validacion se hace del
 * lado del server usando `product.requiresSerializedTracking()`).
 */
export const PurchaseItemInputSchema = z.object({
  warehouse_id: positiveNumber(),
  product_id: positiveNumber(),
  quantity: positiveNumber(),
  unit_cost: positiveNumber(),
  serial_units: z
    .array(
      z.object({
        serial_type: z.enum(SERIAL_TYPES),
        serial_number: trimmedRequiredString(255),
      }),
    )
    .optional()
    .default([]),
});
export type PurchaseItemInput = z.input<typeof PurchaseItemInputSchema>;

/**
 * Form completo de "crear compra en borrador" (POST /api/purchases).
 */
export const StorePurchaseSchema = z
  .object({
    supplier_id: z.coerce.number().int().positive().optional(),
    document_number: trimmedOptionalString(100),
    issued_at: isoDate,
    due_date: isoDate,
    purchase_currency: z.enum(PURCHASE_CURRENCIES, {
      errorMap: () => ({ message: 'Moneda invalida.' }),
    }),
    exchange_rate_type_id: z.coerce.number().int().positive().optional(),
    items: z.array(PurchaseItemInputSchema).min(1, 'Agrega al menos una linea.'),
  })
  .transform((data) => ({
    ...data,
    document_number: data.document_number ?? null,
    issued_at: data.issued_at ?? null,
    due_date: data.due_date ?? null,
    exchange_rate_type_id: data.exchange_rate_type_id ?? undefined,
  }));
export type StorePurchaseValues = z.output<typeof StorePurchaseSchema>;
export type StorePurchaseInput = z.input<typeof StorePurchaseSchema>;

/**
 * Item para "recibir mercancia" (PATCH /api/purchases/{id}/receive).
 * Si se omite `items[]`, el backend recibe TODO el pendiente de una vez.
 */
export const ReceivePurchaseItemSchema = z.object({
  purchase_item_id: positiveNumber(),
  quantity: positiveNumber(),
  serial_units: z
    .array(
      z.object({
        serial_type: z.enum(SERIAL_TYPES),
        serial_number: trimmedRequiredString(255),
      }),
    )
    .optional()
    .default([]),
});
export type ReceivePurchaseItemInput = z.input<typeof ReceivePurchaseItemSchema>;

export const ReceivePurchaseSchema = z
  .object({
    received_at: isoDate,
    items: z.array(ReceivePurchaseItemSchema).optional(),
  })
  .transform((data) => ({
    ...data,
    received_at: data.received_at ?? null,
    items: data.items ?? null,
  }));
export type ReceivePurchaseValues = z.output<typeof ReceivePurchaseSchema>;

// =====================================================================
// Filtros del listado
// =====================================================================

export const PurchaseListFiltersSchema = z.object({
  search: z.string().default(''),
  status: z.enum(['all', ...PURCHASE_STATUSES] as const).default('all'),
  supplier_id: z.coerce.number().int().positive().optional(),
  date_from: isoDate,
  date_to: isoDate,
});
export type PurchaseListFilters = z.infer<typeof PurchaseListFiltersSchema>;
