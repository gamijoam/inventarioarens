/**
 * API del modulo Taller (ordenes de servicio).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, deleteOne, getMany, getOne, patchOne } from '@/api/client';

export const SERVICE_ORDER_TYPES = ['repair', 'warranty'] as const;
export const SERVICE_ORDER_STATUSES = [
  'received',
  'diagnosed',
  'in_progress',
  'ready',
  'delivered',
  'closed',
  'cancelled',
] as const;
export const SERVICE_ORDER_RESOLUTIONS = ['workshop', 'exchange', 'return_supplier'] as const;
export const PART_STATUSES = ['pending', 'consumed', 'returned'] as const;

const nullableTimestamp = z.string().nullable().optional();

export const ServiceOrderPartSchema = z.object({
  id: z.number().int().positive(),
  service_order_id: z.number().int(),
  product_id: z.number().int(),
  product: z
    .object({ id: z.number().int(), name: z.string(), sku: z.string().nullable().optional() })
    .nullable()
    .optional(),
  product_variant_id: z.number().int().nullable().optional(),
  warehouse_id: z.number().int(),
  quantity: z.union([z.number(), z.string()]).transform(Number),
  unit_cost: z.union([z.number(), z.string()]).nullable().optional().transform((v) => (v == null ? null : Number(v))),
  unit_price: z.union([z.number(), z.string()]).nullable().optional().transform((v) => (v == null ? null : Number(v))),
  base_unit_price: z.union([z.number(), z.string()]).nullable().optional().transform((v) => (v == null ? null : Number(v))),
  base_unit_cost: z.union([z.number(), z.string()]).nullable().optional().transform((v) => (v == null ? null : Number(v))),
  stock_movement_id: z.number().int().nullable().optional(),
  status: z.enum(PART_STATUSES),
  created_at: nullableTimestamp,
  updated_at: nullableTimestamp,
});
export type ServiceOrderPart = z.infer<typeof ServiceOrderPartSchema>;

export const ServiceOrderSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int(),
  order_number: z.string(),
  type: z.enum(SERVICE_ORDER_TYPES),
  warranty_claim_id: z.number().int().nullable().optional(),
  customer_id: z.number().int().nullable().optional(),
  customer_name: z.string().nullable().optional(),
  customer_phone: z.string().nullable().optional(),
  device_description: z.string().nullable().optional(),
  issue_description: z.string().nullable().optional(),
  diagnosis: z.string().nullable().optional(),
  status: z.enum(SERVICE_ORDER_STATUSES),
  priority: z.string().nullable().optional(),
  resolution: z.enum(SERVICE_ORDER_RESOLUTIONS).nullable().optional(),
  technician_id: z.number().int().nullable().optional(),
  technician: z
    .object({ id: z.number().int(), name: z.string().nullable().optional() })
    .nullable()
    .optional(),
  warehouse_id: z.number().int(),
  warehouse: z
    .object({ id: z.number().int(), code: z.string(), name: z.string() })
    .nullable()
    .optional(),
  labor_base_amount: z.union([z.number(), z.string()]).transform(Number),
  labor_local_amount: z.union([z.number(), z.string()]).transform(Number),
  parts_base_amount: z.union([z.number(), z.string()]).transform(Number),
  parts_local_amount: z.union([z.number(), z.string()]).transform(Number),
  total_base_amount: z.union([z.number(), z.string()]).transform(Number),
  total_local_amount: z.union([z.number(), z.string()]).transform(Number),
  notes: z.string().nullable().optional(),
  parts: z.array(ServiceOrderPartSchema).optional(),
  created_by: z.number().int().nullable().optional(),
  received_at: nullableTimestamp,
  technician_assigned_at: nullableTimestamp,
  diagnosed_at: nullableTimestamp,
  completed_at: nullableTimestamp,
  delivered_at: nullableTimestamp,
  cancelled_at: nullableTimestamp,
  created_at: nullableTimestamp,
  updated_at: nullableTimestamp,
});
export type ServiceOrder = z.infer<typeof ServiceOrderSchema>;

export interface ServiceOrderFilters {
  status?: string;
  technician_id?: number;
  type?: string;
  search?: string;
  limit?: number;
}

const workshopKeys = {
  all: ['service-orders'] as const,
  list: (filters: ServiceOrderFilters) => ['service-orders', 'list', filters] as const,
  detail: (id: number) => ['service-orders', 'detail', id] as const,
};

export function useServiceOrders(filters: ServiceOrderFilters = {}) {
  return useQuery({
    queryKey: workshopKeys.list(filters),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters.status) params.set('status', filters.status);
      if (filters.technician_id) params.set('technician_id', String(filters.technician_id));
      if (filters.type) params.set('type', filters.type);
      if (filters.search) params.set('search', filters.search);
      if (filters.limit) params.set('limit', String(filters.limit));
      const data = await getMany<unknown>(`/service-orders?${params.toString()}`);
      return z.array(ServiceOrderSchema).parse(data);
    },
  });
}

export function useServiceOrder(id: number) {
  return useQuery({
    queryKey: workshopKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<unknown>(`/service-orders/${id}`);
      return ServiceOrderSchema.parse(data);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateServiceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (values: Record<string, unknown>) => {
      const response = await api.post<{ data: ServiceOrder }>('/service-orders', values);
      return ServiceOrderSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useDiagnoseServiceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: Record<string, unknown> }) => {
      const response = await api.post<{ data: ServiceOrder }>(
        `/service-orders/${id}/diagnose`,
        values,
      );
      return ServiceOrderSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useAssignTechnician() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: Record<string, unknown> }) => {
      const response = await api.post<{ data: ServiceOrder }>(
        `/service-orders/${id}/assign-technician`,
        values,
      );
      return ServiceOrderSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useAddServiceOrderPart() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: Record<string, unknown> }) => {
      const response = await api.post<{ data: ServiceOrderPart }>(
        `/service-orders/${id}/parts`,
        values,
      );
      return ServiceOrderPartSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useRemoveServiceOrderPart() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orderId, partId }: { orderId: number; partId: number }) => {
      await deleteOne(`/service-orders/${orderId}/parts/${partId}`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useCompleteServiceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post<{ data: ServiceOrder }>(`/service-orders/${id}/complete`);
      return ServiceOrderSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useCancelServiceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post<{ data: ServiceOrder }>(`/service-orders/${id}/cancel`);
      return ServiceOrderSchema.parse(response.data.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}

export function useUpdateServiceOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, values }: { id: number; values: Record<string, unknown> }) => {
      const response = await patchOne<Record<string, unknown>, ServiceOrder>(
        `/service-orders/${id}`,
        values,
      );
      return ServiceOrderSchema.parse(response);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workshopKeys.all });
    },
  });
}