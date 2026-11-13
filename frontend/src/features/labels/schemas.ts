import { z } from 'zod';

export const LabelPackagingSchema = z.object({
  name: z.string(),
  factor: z.coerce.number(),
  barcode: z.string().nullable().optional(),
  price_usd: z.coerce.number(),
  price_ves: z.coerce.number().nullable().optional(),
});

export const ProductLabelSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  sku: z.string().nullable().optional(),
  barcode: z.string().nullable().optional(),
  unit_of_measure: z.string().nullable().optional(),
  price_usd: z.coerce.number(),
  price_ves: z.coerce.number().nullable().optional(),
  sale_currency: z.string().nullable().optional(),
  price_list_name: z.string().nullable().optional(),
  exchange_rate: z.coerce.number().nullable().optional(),
  exchange_rate_type_code: z.string().nullable().optional(),
  packagings: z.array(LabelPackagingSchema).default([]),
});

export const SkippedLabelSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  reason: z.string().nullable().optional(),
});

export const ProductLabelsResponseSchema = z.object({
  data: z.object({
    labels: z.array(ProductLabelSchema),
    skipped: z.array(SkippedLabelSchema).default([]),
    generated_at: z.string().nullable().optional(),
  }),
});

export type LabelPackaging = z.infer<typeof LabelPackagingSchema>;
export type ProductLabel = z.infer<typeof ProductLabelSchema>;
export type ProductLabelsResponse = z.infer<typeof ProductLabelsResponseSchema>;

export interface LabelsSelection {
  productIds?: number[];
  changedSince?: string;
  bulkAdjustmentId?: number;
  priceListId?: number | null;
}

export type LabelFormat = 'shelf' | 'a4';
