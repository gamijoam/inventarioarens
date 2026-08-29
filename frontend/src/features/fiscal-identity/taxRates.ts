import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getMany, patchOne, postOne } from '@/api/client';

export const FISCAL_TAX_CATEGORIES = ['taxable', 'exempt', 'exonerated', 'non_taxable'] as const;
export type FiscalTaxCategory = (typeof FISCAL_TAX_CATEGORIES)[number];

export const FISCAL_TAX_CATEGORY_LABELS: Record<FiscalTaxCategory, string> = {
  taxable: 'Gravado',
  exempt: 'Exento',
  exonerated: 'Exonerado',
  non_taxable: 'No gravado',
};

export const FiscalTaxRateSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  rate: z.union([z.number(), z.string()]),
  category: z.enum(FISCAL_TAX_CATEGORIES),
  is_active: z.boolean(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
});
export type FiscalTaxRate = z.infer<typeof FiscalTaxRateSchema>;
export const FiscalTaxRateInputSchema = z
  .object({
    code: z.string().trim().min(1).max(50),
    name: z.string().trim().min(1).max(120),
    rate: z.number().min(0).max(100),
    category: z.enum(FISCAL_TAX_CATEGORIES),
    is_active: z.boolean().optional(),
  })
  .superRefine((value, ctx) => {
    if (value.category !== 'taxable' && value.rate !== 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['rate'],
        message: 'Las categorías exentas deben tener tasa 0%.',
      });
    }
  });
export type FiscalTaxRateInput = z.input<typeof FiscalTaxRateInputSchema>;

const fiscalTaxRateKeys = {
  all: ['fiscal-tax-rates'] as const,
};

export async function getFiscalTaxRates(): Promise<FiscalTaxRate[]> {
  const data = await getMany<unknown>('/fiscal/tax-rates');
  const parsed = z.array(FiscalTaxRateSchema).safeParse(data);
  if (!parsed.success) {
    throw new Error('Respuesta de alícuotas fiscales inválida');
  }

  return parsed.data;
}

export function useFiscalTaxRates() {
  return useQuery({
    queryKey: fiscalTaxRateKeys.all,
    queryFn: getFiscalTaxRates,
    staleTime: 60_000,
  });
}

export function useCreateFiscalTaxRate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: FiscalTaxRateInput) =>
      postOne<FiscalTaxRateInput, FiscalTaxRate>('/fiscal/tax-rates', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: fiscalTaxRateKeys.all });
    },
  });
}

export function useUpdateFiscalTaxRate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...input }: FiscalTaxRateInput & { id: number }) =>
      patchOne<FiscalTaxRateInput, FiscalTaxRate>(`/fiscal/tax-rates/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: fiscalTaxRateKeys.all });
    },
  });
}
