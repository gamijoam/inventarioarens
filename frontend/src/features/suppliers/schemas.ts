/**
 * Schemas Zod para el modulo de Proveedores.
 * Reflejan los endpoints del backend en app/Modules/Suppliers/.
 */
import { z } from 'zod';

// Documentos Venezolanos (igual que Customer).
export const SUPPLIER_DOCUMENT_TYPES = ['V', 'E', 'J', 'G', 'P'] as const;
export type SupplierDocumentType = (typeof SUPPLIER_DOCUMENT_TYPES)[number];

export const SUPPLIER_DOCUMENT_LABELS: Record<SupplierDocumentType, string> = {
  V: 'V - Venezolano',
  E: 'E - Extranjero',
  J: 'J - Juridico',
  G: 'G - Gubernamental',
  P: 'P - Pasaporte',
};

// Lectura.
export const SupplierSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive().optional(),
  name: z.string(),
  document_type: z.string().nullable().optional(),
  document_number: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  email: z.string().nullable().optional(),
  fiscal_address: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  is_active: z.boolean().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});
export type Supplier = z.infer<typeof SupplierSchema>;

// Form.
export const StoreSupplierSchema = z
  .object({
    name: z
      .string()
      .min(1, 'El nombre es obligatorio.')
      .max(255),
    document_type: z.enum(SUPPLIER_DOCUMENT_TYPES).optional(),
    document_number: z
      .string()
      .max(50)
      .optional()
      // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
      .transform((s) => s?.trim() || null),
    phone: z
      .string()
      .max(50)
      .optional()
      // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
      .transform((s) => s?.trim() || null),
    email: z
      .string()
      .max(255)
      .email('Email invalido.')
      .optional()
      .or(z.literal('').transform(() => null)),
    fiscal_address: z
      .string()
      .max(2000)
      .optional()
      // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
      .transform((s) => s?.trim() || null),
    notes: z
      .string()
      .max(2000)
      .optional()
      // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
      .transform((s) => s?.trim() || null),
    is_active: z.boolean().default(true),
  })
  .transform((data) => ({
    ...data,
    is_active: data.is_active ?? true,
  }));
export type StoreSupplierValues = z.output<typeof StoreSupplierSchema>;
