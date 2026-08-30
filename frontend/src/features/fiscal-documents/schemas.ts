import { z } from 'zod';

const amount = z.union([z.number(), z.string()]).transform((value) => Number(value));

const CompanySnapshotSchema = z
  .object({
    legal_name: z.string().nullable().optional(),
    tax_id: z.string().nullable().optional(),
    fiscal_address: z.string().nullable().optional(),
    city: z.string().nullable().optional(),
    state: z.string().nullable().optional(),
    phone: z.string().nullable().optional(),
    email: z.string().nullable().optional(),
    website: z.string().nullable().optional(),
    tax_condition: z.string().nullable().optional(),
  })
  .passthrough();

const CustomerSnapshotSchema = z
  .object({
    id: z.number().int().positive().nullable().optional(),
    name: z.string(),
    fiscal_name: z.string().nullable().optional(),
    document_type: z.string().nullable().optional(),
    document_number: z.string().nullable().optional(),
    fiscal_address: z.string().nullable().optional(),
    phone: z.string().nullable().optional(),
    email: z.string().nullable().optional(),
    is_generic: z.boolean().optional(),
  })
  .passthrough();

const TotalsSnapshotSchema = z
  .object({
    total_base_amount: amount,
    total_local_amount: amount,
    fiscal_taxable_base_amount: amount,
    fiscal_taxable_local_amount: amount,
    fiscal_exempt_base_amount: amount,
    fiscal_exempt_local_amount: amount,
    fiscal_exonerated_base_amount: amount,
    fiscal_exonerated_local_amount: amount,
    fiscal_non_taxable_base_amount: amount,
    fiscal_non_taxable_local_amount: amount,
    fiscal_tax_base_amount: amount,
    fiscal_tax_local_amount: amount,
    fiscal_snapshot_at: z.string().nullable().optional(),
  })
  .passthrough();

const FiscalItemSnapshotSchema = z
  .object({
    tax_code: z.string().nullable().optional(),
    tax_source: z.string().nullable().optional(),
    tax_override_code: z.string().nullable().optional(),
    tax_name: z.string().nullable().optional(),
    category: z.string().nullable().optional(),
    tax_rate: z.number().nullable().optional(),
    prices_include_tax: z.boolean().optional(),
  })
  .passthrough();

const FiscalDocumentItemSchema = z
  .object({
    id: z.number().int().positive(),
    sale_item_id: z.number().int().positive(),
    quantity: amount,
    sale_currency: z.string(),
    unit_price: amount,
    total_amount: amount,
    base_unit_price: amount,
    base_total_amount: amount,
    local_total_amount: amount,
    product_snapshot: z
      .object({ name: z.string().nullable().optional(), sku: z.string().nullable().optional() })
      .passthrough(),
    warehouse_snapshot: z.record(z.string(), z.unknown()).nullable().optional(),
    commercial_snapshot: z.record(z.string(), z.unknown()).nullable().optional(),
    fiscal_snapshot: FiscalItemSnapshotSchema,
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
  })
  .passthrough();

export const FiscalDocumentPreviewSchema = z
  .object({
    id: z.number().int().positive(),
    tenant_id: z.number().int().positive(),
    sale_id: z.number().int().positive(),
    document_type: z.literal('internal_preview'),
    document_mode: z.literal('internal_preview'),
    status: z.literal('preview'),
    officially_issued: z.literal(false),
    company_snapshot: CompanySnapshotSchema,
    branch_snapshot: z.record(z.string(), z.unknown()).nullable().optional(),
    customer_snapshot: CustomerSnapshotSchema.nullable().optional(),
    totals_snapshot: TotalsSnapshotSchema,
    snapshot_at: z.string(),
    items: z.array(FiscalDocumentItemSchema),
    created_by: z.number().int().positive().nullable().optional(),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
  })
  .passthrough();

export type FiscalDocumentPreview = z.infer<typeof FiscalDocumentPreviewSchema>;
