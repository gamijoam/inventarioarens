/**
 * Schemas Zod para el modulo de Clientes.
 * Reflejan los endpoints del backend en app/Modules/Customers/.
 */
import { z } from 'zod';

// Documentos Venezolanos: V (Venezolano), E (Extranjero),
// J (Juridico/Empresa), G (Gubernamental), P (Pasaporte).
export const CUSTOMER_DOCUMENT_TYPES = ['V', 'E', 'J', 'G', 'P'] as const;
export type CustomerDocumentType = (typeof CUSTOMER_DOCUMENT_TYPES)[number];

export const CUSTOMER_DOCUMENT_LABELS: Record<CustomerDocumentType, string> = {
  V: 'V - Venezolano',
  E: 'E - Extranjero',
  J: 'J - Juridico',
  G: 'G - Gubernamental',
  P: 'P - Pasaporte',
};

// Lectura (lo que devuelve el backend).
export const CustomerSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive().optional(),
  name: z.string(),
  document_type: z.string().nullable().optional(),
  document_number: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  email: z.string().nullable().optional(),
  fiscal_address: z.string().nullable().optional(),
  is_generic: z.boolean().optional(),
  is_active: z.boolean().optional(),
  customer_group_id: z.number().int().nullable().optional(),
  zone_id: z.number().int().nullable().optional(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});
export type Customer = z.infer<typeof CustomerSchema>;

// Form de creacion/edicion.
export const StoreCustomerSchema = z
  .object({
    name: z
      .string()
      .min(1, 'El nombre es obligatorio.')
      .max(255),
    document_type: z.enum(CUSTOMER_DOCUMENT_TYPES, {
      errorMap: () => ({ message: 'Tipo de documento invalido.' }),
    }),
    document_number: z
      .string()
      .min(1, 'El numero de documento es obligatorio.')
      .max(50)
      .transform((s) => s.trim()),
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
    is_generic: z.boolean().optional(),
    is_active: z.boolean().default(true),
  })
  .transform((data) => ({
    ...data,
    is_active: data.is_active ?? true,
    is_generic: data.is_generic ?? false,
  }));
export type StoreCustomerValues = z.output<typeof StoreCustomerSchema>;
