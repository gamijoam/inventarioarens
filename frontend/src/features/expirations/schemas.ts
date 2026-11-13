import { z } from 'zod';

export const StockLotSchema = z.object({
  id: z.number(),
  lot_code: z.string().nullable().optional(),
  expires_on: z.string(),
  days_left: z.number(),
  quantity: z.coerce.number(),
  unit_cost: z.coerce.number().nullable().optional(),
  value_base_amount: z.coerce.number(),
  product: z.object({ id: z.number(), name: z.string(), sku: z.string().nullable().optional() }).nullable().optional(),
  variant: z
    .object({ id: z.number(), color: z.string().nullable().optional(), sku_variant: z.string().nullable().optional() })
    .nullable()
    .optional(),
  warehouse: z.object({ id: z.number(), name: z.string(), code: z.string().nullable().optional() }).nullable().optional(),
  updated_at: z.string().nullable().optional(),
});

export const StockLotsResponseSchema = z.object({
  data: z.array(StockLotSchema),
  summary: z.object({
    lots: z.number(),
    value_base_amount: z.coerce.number(),
    expired_value_base_amount: z.coerce.number(),
  }),
});

export type StockLot = z.infer<typeof StockLotSchema>;
export type StockLotsResponse = z.infer<typeof StockLotsResponseSchema>;

export interface StockLotFilters {
  status?: 'expiring' | 'expired' | 'all';
  days?: number;
  warehouse_id?: number;
}
