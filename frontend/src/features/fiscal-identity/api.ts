import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, patchOne } from '@/api/client';

export const TAX_CONDITIONS = ['ordinary', 'formal', 'special', 'exempt', 'non_taxpayer'] as const;

const nullableText = z.string().nullable().optional();

export const FiscalBranchIdentitySchema = z.object({
  id: z.number().int(),
  tenant_id: z.number().int(),
  name: z.string(),
  code: z.string(),
  status: z.string(),
  fiscal_address: nullableText,
  city: nullableText,
  state: nullableText,
  phone: nullableText,
  email: nullableText,
  tax_condition: z.enum(TAX_CONDITIONS).nullable().optional(),
  created_at: nullableText,
  updated_at: nullableText,
});
export type FiscalBranchIdentity = z.infer<typeof FiscalBranchIdentitySchema>;

export const FiscalTenantIdentitySchema = z.object({
  id: z.number().int(),
  legal_name: nullableText,
  tax_id: nullableText,
  fiscal_address: nullableText,
  city: nullableText,
  state: nullableText,
  phone: nullableText,
  email: nullableText,
  tax_condition: z.enum(TAX_CONDITIONS).nullable().optional(),
});
export type FiscalTenantIdentity = z.infer<typeof FiscalTenantIdentitySchema>;

export const FiscalIdentitySchema = z.object({
  tenant: FiscalTenantIdentitySchema,
  branches: z.array(FiscalBranchIdentitySchema),
});
export type FiscalIdentity = z.infer<typeof FiscalIdentitySchema>;

export interface UpdateFiscalIdentityPayload {
  legal_name?: string | null;
  tax_id?: string | null;
  fiscal_address?: string | null;
  city?: string | null;
  state?: string | null;
  phone?: string | null;
  email?: string | null;
  tax_condition?: (typeof TAX_CONDITIONS)[number] | null;
}

export type UpdateBranchFiscalIdentityPayload = Omit<
  UpdateFiscalIdentityPayload,
  'legal_name' | 'tax_id'
>;

const fiscalIdentityKeys = {
  all: ['fiscal-identity'] as const,
};

export async function getFiscalIdentity(): Promise<FiscalIdentity> {
  const data = await getOne<unknown>('/fiscal/identity');
  const parsed = FiscalIdentitySchema.safeParse(data);
  if (!parsed.success) {
    throw new Error('Respuesta de identidad fiscal inválida');
  }

  return parsed.data;
}

export async function updateFiscalIdentity(
  payload: UpdateFiscalIdentityPayload,
): Promise<FiscalIdentity> {
  const data = await patchOne<UpdateFiscalIdentityPayload, unknown>('/fiscal/identity', payload);
  const parsed = FiscalIdentitySchema.safeParse(data);
  if (!parsed.success) {
    throw new Error('Respuesta de identidad fiscal inválida');
  }

  return parsed.data;
}

export async function updateBranchFiscalIdentity(
  branchId: number,
  payload: UpdateBranchFiscalIdentityPayload,
): Promise<FiscalBranchIdentity> {
  const data = await patchOne<UpdateBranchFiscalIdentityPayload, unknown>(
    `/fiscal/identity/branches/${branchId}`,
    payload,
  );
  const parsed = FiscalBranchIdentitySchema.safeParse(data);
  if (!parsed.success) {
    throw new Error('Respuesta de identidad fiscal inválida');
  }

  return parsed.data;
}

export function useFiscalIdentity() {
  return useQuery({
    queryKey: fiscalIdentityKeys.all,
    queryFn: getFiscalIdentity,
    staleTime: 30_000,
  });
}

export function useUpdateFiscalIdentity() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: updateFiscalIdentity,
    onSuccess: (identity) => {
      queryClient.setQueryData(fiscalIdentityKeys.all, identity);
    },
  });
}

export function useUpdateBranchFiscalIdentity() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      branchId,
      payload,
    }: {
      branchId: number;
      payload: UpdateBranchFiscalIdentityPayload;
    }) => updateBranchFiscalIdentity(branchId, payload),
    onSuccess: (branch) => {
      queryClient.setQueryData<FiscalIdentity>(fiscalIdentityKeys.all, (current) => {
        if (!current) return current;

        return {
          ...current,
          branches: current.branches.map((item) => (item.id === branch.id ? branch : item)),
        };
      });
    },
  });
}
