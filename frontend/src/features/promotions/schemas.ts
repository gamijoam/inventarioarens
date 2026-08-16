import { z } from 'zod';

const nullableNumber = z
  .union([z.number(), z.string()])
  .nullable()
  .optional()
  .transform((value) => (value == null || value === '' ? null : Number(value)));

export const PromotionBenefitTypes = [
  'percent_discount',
  'fixed_discount',
  'fixed_item_price',
  'fixed_bundle_price',
  'free_item',
  'buy_x_get_y',
] as const;
export type PromotionBenefitType = (typeof PromotionBenefitTypes)[number];

export const PromotionScopes = [
  'invoice',
  'combo',
  'product_offer',
  'legacy_product_discount',
] as const;
export type PromotionScope = (typeof PromotionScopes)[number];
export type PromotionDomain = Exclude<PromotionScope, 'legacy_product_discount'>;

export function isInvoiceDiscountType(benefitType: PromotionBenefitType): boolean {
  return benefitType === 'percent_discount' || benefitType === 'fixed_discount';
}

export const PromotionPaymentCurrencies = ['ANY', 'VES'] as const;
export type PromotionPaymentCurrency = (typeof PromotionPaymentCurrencies)[number];

export const PromotionItemSchema = z
  .object({
    id: z.number().int().optional(),
    product_id: z.number().int(),
    product_name: z.string().nullable().optional(),
    quantity: nullableNumber,
    item_role: z.enum(['eligible', 'trigger', 'reward']).optional(),
    sort_order: z.number().int().optional(),
  })
  .passthrough()
  .transform((item) => ({
    ...item,
    quantity: item.quantity ?? 0,
  }));
export type PromotionItem = z.infer<typeof PromotionItemSchema>;

function inferPromotionScope(value: Record<string, unknown>): PromotionScope {
  if (value.benefit_type === 'fixed_bundle_price' || value.benefit_type === 'buy_x_get_y') {
    return 'combo';
  }
  if (value.benefit_type === 'fixed_item_price' || value.benefit_type === 'free_item') {
    return 'product_offer';
  }

  return Array.isArray(value.items) && value.items.length > 0
    ? 'legacy_product_discount'
    : 'invoice';
}

const promotionObjectSchema = z
  .object({
    id: z.number().int(),
    tenant_id: z.number().int().optional(),
    name: z.string(),
    code: z.string().nullable().optional(),
    benefit_type: z.enum(PromotionBenefitTypes),
    price_currency: z.literal('USD'),
    payment_currency: z.enum(PromotionPaymentCurrencies).default('ANY'),
    scope: z.enum(PromotionScopes),
    allows_combos: z.boolean().default(false),
    price_usd: nullableNumber,
    discount_percent: nullableNumber,
    discount_amount_usd: nullableNumber,
    priority: z.number().int(),
    is_active: z.boolean(),
    starts_at: z.string().nullable().optional(),
    ends_at: z.string().nullable().optional(),
    items: z.array(PromotionItemSchema),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
  })
  .passthrough()
  .transform((promotion) => ({
    ...promotion,
    price_usd: promotion.price_usd ?? 0,
    discount_percent: promotion.discount_percent,
    discount_amount_usd: promotion.discount_amount_usd,
  }));

export const PromotionSchema = z.preprocess((value) => {
  if (typeof value !== 'object' || value === null || Array.isArray(value) || 'scope' in value) {
    return value;
  }

  return { ...value, scope: inferPromotionScope(value as Record<string, unknown>) };
}, promotionObjectSchema);

type ParsedPromotion = z.infer<typeof PromotionSchema>;
export type Promotion = Omit<ParsedPromotion, 'scope' | 'allows_combos'> & {
  scope?: ParsedPromotion['scope'];
  allows_combos?: boolean;
};

export const PromotionItemInputSchema = z.object({
  product_id: z.number().int().positive(),
  quantity: z.number().positive(),
  item_role: z.enum(['eligible', 'trigger', 'reward']).optional(),
});

export const StorePromotionSchema = z
  .object({
    name: z.string().trim().min(1),
    code: z.string().trim().max(80).optional().or(z.literal('')),
    benefit_type: z.enum(PromotionBenefitTypes),
    price_currency: z.literal('USD'),
    payment_currency: z.enum(PromotionPaymentCurrencies).default('ANY'),
    allows_combos: z.boolean().default(false),
    price_usd: z.number().min(0).nullable().optional(),
    discount_percent: z.number().gt(0).max(100).nullable().optional(),
    discount_amount_usd: z.number().gt(0).nullable().optional(),
    priority: z.number().int().min(0),
    is_active: z.boolean(),
    starts_at: z.string().nullable().optional(),
    ends_at: z.string().nullable().optional(),
    items: z.array(PromotionItemInputSchema).default([]),
  })
  .superRefine((promotion, context) => {
    if (!isInvoiceDiscountType(promotion.benefit_type) && promotion.items.length === 0) {
      context.addIssue({
        code: z.ZodIssueCode.too_small,
        minimum: 1,
        inclusive: true,
        type: 'array',
        path: ['items'],
      });
    }
    if (promotion.benefit_type === 'fixed_bundle_price' && promotion.items.length < 2) {
      context.addIssue({
        code: z.ZodIssueCode.too_small,
        minimum: 2,
        inclusive: true,
        type: 'array',
        path: ['items'],
      });
    }
    if (promotion.benefit_type === 'percent_discount' && promotion.discount_percent == null) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['discount_percent'],
        message: 'El porcentaje es obligatorio.',
      });
    }
    if (promotion.benefit_type === 'fixed_discount' && promotion.discount_amount_usd == null) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['discount_amount_usd'],
        message: 'El monto fijo es obligatorio.',
      });
    }
    if (promotion.benefit_type === 'buy_x_get_y') {
      const roles = promotion.items.map((item) => item.item_role);
      if (!roles.includes('trigger') || !roles.includes('reward')) {
        context.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['items'],
          message: 'Debe definir componentes trigger y reward.',
        });
      }
    }
    if (
      ['fixed_item_price', 'fixed_bundle_price'].includes(promotion.benefit_type) &&
      promotion.price_usd == null
    ) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['price_usd'],
        message: 'El precio USD es obligatorio.',
      });
    }
  });
export type StorePromotionInput = z.input<typeof StorePromotionSchema>;
