import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, postOne } from '@/api/client';
import type { Paginated } from '@/types/api';
import { payableKeys } from './queries';
import {
  ExecutePayablePaymentRequestSchema,
  PayableSchema,
  PayablePaymentRequestPayloadSchema,
  PayablePaymentRequestSchema,
  PayPayableSchema,
  type Payable,
  type PayableListFilters,
  type PayablePayment,
  type PayablePaymentRequest,
  type PayablePaymentRequestPayload,
  type PayPayableValues,
  type ExecutePayablePaymentRequestValues,
} from './schemas';

export function buildPayablesQuery(filters: PayableListFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.search) params.set('search', filters.search);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.supplier_id) params.set('supplier_id', String(filters.supplier_id));
  if (filters.due_from) params.set('due_from', filters.due_from);
  if (filters.due_to) params.set('due_to', filters.due_to);
  if (filters.page) params.set('page', String(filters.page));
  if (filters.limit) params.set('limit', String(filters.limit));
  const query = params.toString();
  return `/accounts-payable${query ? `?${query}` : ''}`;
}

export function usePayables(filters: PayableListFilters = {}) {
  return useQuery({
    queryKey: payableKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const response = await getPaginated<unknown>(buildPayablesQuery(filters));
      const data = z.array(PayableSchema).parse(response.data);
      return {
        ...response,
        data,
      } satisfies Paginated<Payable>;
    },
  });
}

export function usePayable(id: number | null) {
  return useQuery({
    queryKey: id ? payableKeys.detail(id) : [...payableKeys.details(), 'empty'],
    queryFn: async () => PayableSchema.parse(await getOne<unknown>(`/accounts-payable/${id}`)),
    enabled: Number.isFinite(id) && Number(id) > 0,
  });
}

export function usePayPayable() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: PayPayableValues }) =>
      postOne<PayPayableValues, PayablePayment>(
        `/accounts-payable/${id}/payments`,
        PayPayableSchema.parse(values),
      ),
    onSuccess: (_, { id }) => {
      void qc.invalidateQueries({ queryKey: payableKeys.lists() });
      void qc.invalidateQueries({ queryKey: payableKeys.detail(id) });
      void qc.invalidateQueries({ queryKey: ['pos', 'cash-sessions'] });
    },
  });
}

export function buildPayablePaymentRequestsQuery(
  filters: { status?: string; accounts_payable_id?: number; page?: number; limit?: number } = {},
): string {
  const params = new URLSearchParams();
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.accounts_payable_id) {
    params.set('accounts_payable_id', String(filters.accounts_payable_id));
  }
  if (filters.page) params.set('page', String(filters.page));
  if (filters.limit) params.set('limit', String(filters.limit));
  const query = params.toString();
  return `/accounts-payable-payment-requests${query ? `?${query}` : ''}`;
}

export function usePayablePaymentRequests(
  filters: { status?: string; accounts_payable_id?: number; page?: number; limit?: number } = {},
  options: { enabled?: boolean } = {},
) {
  return useQuery({
    queryKey: payableKeys.requestList(filters as Record<string, unknown>),
    queryFn: async () => {
      const response = await getPaginated<unknown>(buildPayablePaymentRequestsQuery(filters));
      const data = z.array(PayablePaymentRequestSchema).parse(response.data);
      return { ...response, data } satisfies Paginated<PayablePaymentRequest>;
    },
    enabled: options.enabled ?? true,
  });
}

function invalidatePayableRequests(qc: ReturnType<typeof useQueryClient>, accountId?: number) {
  void qc.invalidateQueries({ queryKey: payableKeys.lists() });
  void qc.invalidateQueries({ queryKey: payableKeys.requestLists() });
  if (accountId) void qc.invalidateQueries({ queryKey: payableKeys.detail(accountId) });
  void qc.invalidateQueries({ queryKey: ['pos', 'cash-sessions'] });
}

export function usePreparePayablePaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: PayablePaymentRequestPayload }) =>
      postOne<PayablePaymentRequestPayload, PayablePaymentRequest>(
        `/accounts-payable/${id}/payment-requests`,
        PayablePaymentRequestPayloadSchema.parse(values),
      ),
    onSuccess: (result, { id }) => invalidatePayableRequests(qc, result.accounts_payable_id ?? id),
  });
}

export function useApprovePayablePaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (request: PayablePaymentRequest) =>
      postOne<Record<string, never>, PayablePaymentRequest>(
        `/accounts-payable-payment-requests/${request.id}/approve`,
        {},
      ),
    onSuccess: (result) => invalidatePayableRequests(qc, result.accounts_payable_id),
  });
}

export function useExecutePayablePaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      request,
      values,
    }: {
      request: PayablePaymentRequest;
      values: ExecutePayablePaymentRequestValues;
    }) =>
      postOne<ExecutePayablePaymentRequestValues, PayablePaymentRequest>(
        `/accounts-payable-payment-requests/${request.id}/execute`,
        ExecutePayablePaymentRequestSchema.parse(values),
      ),
    onSuccess: (result) => invalidatePayableRequests(qc, result.accounts_payable_id),
  });
}

export function useRejectPayablePaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ request, reason }: { request: PayablePaymentRequest; reason: string }) =>
      postOne<{ reason: string }, PayablePaymentRequest>(
        `/accounts-payable-payment-requests/${request.id}/reject`,
        { reason },
      ),
    onSuccess: (result) => invalidatePayableRequests(qc, result.accounts_payable_id),
  });
}

export function useCancelPayablePaymentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ request, reason }: { request: PayablePaymentRequest; reason: string }) =>
      postOne<{ reason: string }, PayablePaymentRequest>(
        `/accounts-payable-payment-requests/${request.id}/cancel`,
        { reason },
      ),
    onSuccess: (result) => invalidatePayableRequests(qc, result.accounts_payable_id),
  });
}

export type { PayableListFilters };
