import { z } from 'zod';

const nullableTimestamp = z.string().nullable().optional();

export const CommissionPlanSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  beneficiary_role: z.enum(['seller', 'cashier']),
  percentage: z.string(),
  conversion_policy: z.enum(['sale_snapshot', 'configured_rate']),
  exchange_rate_type_id: z.number().int().positive().nullable(),
  exchange_rate_type: z
    .object({ id: z.number().int().positive(), code: z.string(), name: z.string() })
    .nullable(),
  credit_policy: z.enum(['proportional_collections', 'sale_confirmation']),
  maturation_days: z.number().int().min(0),
  allow_self_stacking: z.boolean(),
  include_combos: z.boolean(),
  include_discounts: z.boolean(),
  is_active: z.boolean(),
  starts_at: nullableTimestamp,
  ends_at: nullableTimestamp,
  assignments: z.array(
    z.object({
      id: z.number().int().positive(),
      user_id: z.number().int().positive(),
      is_active: z.boolean(),
      starts_at: nullableTimestamp,
      ends_at: nullableTimestamp,
      user: z.object({
        id: z.number().int().positive(),
        name: z.string(),
        email: z.string(),
      }),
    }),
  ),
  created_at: nullableTimestamp,
  updated_at: nullableTimestamp,
});

export type CommissionPlan = z.infer<typeof CommissionPlanSchema>;

export const CommissionPlanInputSchema = z
  .object({
    name: z.string().trim().min(1, 'Indica un nombre.'),
    beneficiary_role: z.enum(['seller', 'cashier']),
    percentage: z.coerce.number().positive().max(100),
    conversion_policy: z.enum(['sale_snapshot', 'configured_rate']),
    exchange_rate_type_id: z.number().int().positive().nullable(),
    credit_policy: z.enum(['proportional_collections', 'sale_confirmation']),
    maturation_days: z.coerce.number().int().min(0).max(365),
    allow_self_stacking: z.boolean(),
    include_combos: z.boolean(),
    include_discounts: z.boolean(),
    is_active: z.boolean(),
    starts_at: z.string().nullable().optional(),
    ends_at: z.string().nullable().optional(),
    user_ids: z.array(z.number().int().positive()).min(1, 'Selecciona al menos una persona.'),
  })
  .superRefine((data, context) => {
    if (data.conversion_policy === 'configured_rate' && !data.exchange_rate_type_id) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['exchange_rate_type_id'],
        message: 'Selecciona la tasa para convertir ventas en bolivares.',
      });
    }
  });

export type CommissionPlanInput = z.infer<typeof CommissionPlanInputSchema>;

export const CommissionSimulationSchema = z.object({
  currency: z.enum(['USD', 'VES']),
  input_amount: z.string(),
  percentage: z.string(),
  exchange_rate_type_id: z.number().int().positive().nullable(),
  exchange_rate_type_code: z.string().nullable(),
  exchange_rate: z.string().nullable(),
  rate_effective_at: nullableTimestamp,
  eligible_base_amount: z.string(),
  commission_base_amount: z.string(),
});

export type CommissionSimulation = z.infer<typeof CommissionSimulationSchema>;

export interface CommissionSimulationInput {
  amount: number;
  currency: 'USD' | 'VES';
  percentage: number;
  exchange_rate_type_id?: number;
}

export const CommissionEntrySchema = z.object({
  id: z.number().int().positive(),
  entry_uuid: z.string().uuid(),
  sale_id: z.number().int().positive().nullable(),
  pos_order_id: z.number().int().positive().nullable(),
  sale_item_id: z.number().int().positive().nullable(),
  beneficiary_role: z.enum(['seller', 'cashier']),
  beneficiary: z.object({ id: z.number().int().positive(), name: z.string(), email: z.string() }),
  entry_type: z.enum(['earning', 'reversal', 'adjustment']),
  plan_name_snapshot: z.string(),
  percentage_snapshot: z.string(),
  sale_currency: z.enum(['USD', 'VES']),
  source_amount: z.string(),
  eligible_base_amount: z.string(),
  exchange_rate_type_code: z.string().nullable(),
  exchange_rate: z.string().nullable(),
  commission_base_amount: z.string(),
  adjustment_reason: z.string().nullable().optional(),
  status: z.enum(['pending', 'available', 'approved', 'paid', 'reversed']),
  approved_at: nullableTimestamp,
  earned_at: nullableTimestamp,
  available_at: nullableTimestamp,
  created_at: nullableTimestamp,
  updated_at: nullableTimestamp,
});

export const CommissionLedgerSchema = z.object({
  data: z.array(CommissionEntrySchema),
  summary: z.object({
    total_base_amount: z.string(),
    available_base_amount: z.string(),
    pending_base_amount: z.string(),
    approved_base_amount: z.string().optional().default('0.0000'),
    paid_base_amount: z.string().optional().default('0.0000'),
    currency_breakdown: z.object({
      total_usd: z.string(),
      total_ves: z.string(),
      available_usd: z.string(),
      available_ves: z.string(),
      approved_usd: z.string(),
      approved_ves: z.string(),
      paid_usd: z.string(),
      paid_ves: z.string(),
    }),
    payables: z.array(
      z.object({
        user_id: z.number().int(),
        name: z.string(),
        email: z.string().nullable(),
        available_usd: z.string(),
        available_ves: z.string(),
        approved_usd: z.string(),
        approved_ves: z.string(),
        paid_usd: z.string(),
        paid_ves: z.string(),
        total_usd: z.string(),
        total_ves: z.string(),
      }),
    ),
  }),
});

export type CommissionLedger = z.infer<typeof CommissionLedgerSchema>;

export const CommissionControlColumnSchema = z.object({
  key: z.string(),
  label: z.string(),
  default_visible: z.boolean(),
});

export const CommissionControlPaymentSchema = z.object({
  code: z.string(),
  label: z.string(),
  amount: z.string(),
  currency: z.string(),
  amount_base: z.string(),
  amount_local: z.string(),
});

export const CommissionControlRowSchema = z.object({
  id: z.string(),
  date: nullableTimestamp,
  order_id: z.number().int().positive(),
  seller: z.object({ id: z.number(), name: z.string(), email: z.string() }).nullable(),
  cashier: z.object({ id: z.number(), name: z.string(), email: z.string() }).nullable(),
  branch: z.object({ id: z.number(), name: z.string(), code: z.string() }).nullable(),
  quantity: z.string(),
  product: z.object({ id: z.number().nullable(), sku: z.string().nullable(), name: z.string() }),
  sale_currency: z.enum(['USD', 'VES']),
  amount_usd: z.string().nullable(),
  amount_ves: z.string().nullable(),
  equivalent_usd: z.string().nullable(),
  exchange_rate_type_code: z.string().nullable(),
  exchange_rate: z.string().nullable(),
  payment_columns: z.record(CommissionControlPaymentSchema),
  financing_method: z.string().nullable(),
  financing_level: z.string().nullable(),
  financed_amount: z.string().nullable(),
  total: z.string(),
  commission_usd: z.string(),
  commission_ves: z.string().nullable(),
});

export type CommissionControlRow = z.infer<typeof CommissionControlRowSchema>;

export const CommissionControlSchema = z.object({
  data: z.array(CommissionControlRowSchema),
  summary: z.object({
    quantity: z.string(),
    amount_usd: z.string(),
    amount_ves: z.string(),
    equivalent_usd: z.string(),
    total: z.string(),
    commission_usd: z.string(),
    commission_ves: z.string(),
    payment_columns: z.record(CommissionControlPaymentSchema),
  }),
  meta: z.object({
    columns: z.array(CommissionControlColumnSchema),
    payment_columns: z.array(z.object({ key: z.string(), code: z.string(), label: z.string() })),
    total: z.number().int().nonnegative(),
  }),
});

export type CommissionControl = z.infer<typeof CommissionControlSchema>;

export const CommissionSettlementSchema = z.object({
  id: z.number().int().positive(),
  settlement_uuid: z.string().uuid(),
  status: z.literal('paid'),
  payment_currency: z.enum(['USD', 'VES']),
  total_base_amount: z.string(),
  total_local_amount: z.string(),
  payment_amount: z.string(),
  exchange_rate_type_code: z.string().nullable(),
  exchange_rate: z.string().nullable(),
  reference: z.string().nullable(),
  notes: z.string().nullable(),
  beneficiary: z.object({ id: z.number().int().positive(), name: z.string(), email: z.string() }),
  entry_uuids: z.array(z.string().uuid()),
  paid_at: nullableTimestamp,
  created_at: nullableTimestamp,
  updated_at: nullableTimestamp,
});

export type CommissionSettlement = z.infer<typeof CommissionSettlementSchema>;

export interface CommissionSettlementInput {
  entry_ids: number[];
  payment_currency: 'USD' | 'VES';
  exchange_rate_type_id?: number;
  reference?: string;
  notes?: string;
}

export interface CommissionAdjustmentInput {
  beneficiary_user_id: number;
  beneficiary_role: 'seller' | 'cashier';
  amount_base: number;
  reason: string;
}
