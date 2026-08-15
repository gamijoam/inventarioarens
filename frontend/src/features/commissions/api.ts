import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, deleteOne, getMany, patchOne, postOne } from '@/api/client';
import {
  CommissionPlanInputSchema,
  CommissionPlanSchema,
  CommissionControlSchema,
  CommissionLedgerSchema,
  CommissionEntrySchema,
  CommissionSettlementSchema,
  CommissionSimulationSchema,
  type CommissionPlan,
  type CommissionPlanInput,
  type CommissionSimulation,
  type CommissionSimulationInput,
  type CommissionLedger,
  type CommissionAdjustmentInput,
  type CommissionSettlement,
  type CommissionSettlementInput,
  type CommissionControl,
} from './schemas';

export const commissionKeys = {
  all: ['commissions'] as const,
  plans: () => [...commissionKeys.all, 'plans'] as const,
};

export async function fetchCommissionPlans(): Promise<CommissionPlan[]> {
  return z.array(CommissionPlanSchema).parse(await getMany<unknown>('/commission-plans'));
}

export async function simulateCommission(
  input: CommissionSimulationInput,
): Promise<CommissionSimulation> {
  return CommissionSimulationSchema.parse(
    await postOne<CommissionSimulationInput, unknown>('/commissions/simulate', input),
  );
}

export async function fetchCommissionEntries(ownOnly: boolean): Promise<CommissionLedger> {
  const response = await api.get(ownOnly ? '/commissions/mine' : '/commissions');
  return CommissionLedgerSchema.parse(response.data);
}

export interface CommissionControlFilters {
  date_from?: string;
  date_to?: string;
  user_id?: number;
  cashier_id?: number;
  payment_method_id?: number;
}

export async function fetchCommissionControl(
  filters: CommissionControlFilters = {},
): Promise<CommissionControl> {
  const response = await api.get('/commissions/control', { params: filters });
  return CommissionControlSchema.parse(response.data);
}

export async function approveCommissionEntries(entryIds: number[]) {
  return z.array(CommissionEntrySchema).parse(
    await postOne<{ entry_ids: number[] }, unknown>('/commissions/approve', {
      entry_ids: entryIds,
    }),
  );
}

export async function createCommissionSettlement(
  input: CommissionSettlementInput,
): Promise<CommissionSettlement> {
  return CommissionSettlementSchema.parse(
    await postOne<CommissionSettlementInput, unknown>('/commission-settlements', input),
  );
}

export async function createCommissionAdjustment(input: CommissionAdjustmentInput) {
  return CommissionEntrySchema.parse(
    await postOne<CommissionAdjustmentInput, unknown>('/commissions/adjustments', input),
  );
}

export async function downloadCommissionExport(): Promise<void> {
  const response = await api.get<Blob>('/commissions/export', { responseType: 'blob' });
  const url = URL.createObjectURL(response.data);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `comisiones-${new Date().toISOString().slice(0, 10)}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
}

export function useCommissionPlans() {
  return useQuery({ queryKey: commissionKeys.plans(), queryFn: fetchCommissionPlans });
}

export function useCommissionEntries(ownOnly: boolean) {
  return useQuery({
    queryKey: [...commissionKeys.all, 'entries', ownOnly ? 'mine' : 'all'],
    queryFn: () => fetchCommissionEntries(ownOnly),
  });
}

export function useCommissionControl(filters: CommissionControlFilters = {}) {
  return useQuery({
    queryKey: [...commissionKeys.all, 'control', filters],
    queryFn: () => fetchCommissionControl(filters),
  });
}

export function useCreateCommissionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CommissionPlanInput) =>
      postOne<CommissionPlanInput, CommissionPlan>(
        '/commission-plans',
        CommissionPlanInputSchema.parse(input),
      ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}

export function useUpdateCommissionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...input }: CommissionPlanInput & { id: number }) =>
      patchOne<CommissionPlanInput, CommissionPlan>(
        `/commission-plans/${id}`,
        CommissionPlanInputSchema.parse(input),
      ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}

export function useDeactivateCommissionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteOne(`/commission-plans/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}

export function useCommissionSimulation() {
  return useMutation({ mutationFn: simulateCommission });
}

export function useApproveCommissionEntries() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: approveCommissionEntries,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}

export function useCreateCommissionSettlement() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: createCommissionSettlement,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}

export function useCreateCommissionAdjustment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: createCommissionAdjustment,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: commissionKeys.all }),
  });
}
