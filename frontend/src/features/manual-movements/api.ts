import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getOne, getPaginated, postOne } from '@/api/client';
import {
  ManualMovementSchema,
  type CreateManualMovement,
  type ManualMovement,
  type ManualMovementFilters,
} from './schemas';
import { productKeys } from '@/features/inventory-center/queries';

export const manualMovementKeys = {
  all: ['manual-movements'] as const,
  list: (filters: ManualMovementFilters) => [...manualMovementKeys.all, 'list', filters] as const,
  detail: (id: number) => [...manualMovementKeys.all, 'detail', id] as const,
};

function queryString(filters: ManualMovementFilters): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '' && value !== 'all') params.set(key, String(value));
  });
  const query = params.toString();
  return query ? `?${query}` : '';
}

export function useManualMovements(filters: ManualMovementFilters) {
  return useQuery({
    queryKey: manualMovementKeys.list(filters),
    queryFn: async () => {
      const response = await getPaginated<unknown>(
        `/inventory/manual-movements${queryString(filters)}`,
      );
      return { ...response, data: response.data.map((item) => ManualMovementSchema.parse(item)) };
    },
    placeholderData: (previous) => previous,
  });
}

export function useManualMovement(id: number) {
  return useQuery({
    queryKey: manualMovementKeys.detail(id),
    queryFn: async () =>
      ManualMovementSchema.parse(await getOne<unknown>(`/inventory/manual-movements/${id}`)),
    enabled: id > 0,
  });
}

export function useCreateManualMovement() {
  const queryClient = useQueryClient();
  return useMutation<ManualMovement, Error, CreateManualMovement>({
    mutationFn: async (values) =>
      ManualMovementSchema.parse(
        await postOne<CreateManualMovement, unknown>('/inventory/manual-movements', values),
      ),
    onSuccess: async (movement) => {
      await queryClient.invalidateQueries({ queryKey: manualMovementKeys.all });
      await queryClient.invalidateQueries({ queryKey: productKeys.all });
      queryClient.setQueryData(manualMovementKeys.detail(movement.id), movement);
    },
  });
}

function actionMutation(path: (id: number) => string) {
  return function useAction() {
    const queryClient = useQueryClient();
    return useMutation<ManualMovement, Error, { id: number; reason?: string }>({
      mutationFn: async ({ id, reason }) =>
        ManualMovementSchema.parse(
          await postOne<{ reason?: string }, unknown>(path(id), reason ? { reason } : {}),
        ),
      onSuccess: async (movement) => {
        await queryClient.invalidateQueries({ queryKey: manualMovementKeys.all });
        await queryClient.invalidateQueries({ queryKey: productKeys.all });
        queryClient.setQueryData(manualMovementKeys.detail(movement.id), movement);
      },
    });
  };
}

export const useApproveManualMovement = actionMutation(
  (id) => `/inventory/manual-movements/${id}/approve`,
);
export const useRejectManualMovement = actionMutation(
  (id) => `/inventory/manual-movements/${id}/reject`,
);

export interface AvailableProductUnit {
  id: number;
  product_id: number;
  product_variant_id?: number | null;
  variant_label?: string | null;
  warehouse_id: number;
  serial_type: 'serial' | 'imei';
  serial_number: string;
  status: string;
}

export function useAvailableSerials(
  productId: number | null,
  warehouseId: number,
  variantId?: number | null,
) {
  return useQuery({
    queryKey: ['available-serials-for-manual-movement', productId, warehouseId, variantId],
    queryFn: async () => {
      if (!productId || !warehouseId) return [];
      const params = new URLSearchParams({
        warehouse_id: String(warehouseId),
        status: 'available',
        limit: '500',
      });
      if (variantId && variantId > 0) {
        params.set('product_variant_id', String(variantId));
      }
      const response = await api.get<{ data: AvailableProductUnit[] }>(
        `/inventory-centers/products/${productId}/units?${params.toString()}`,
      );
      const body = response.data;
      if (Array.isArray(body)) return body as AvailableProductUnit[];
      if (Array.isArray(body?.data)) return body.data as AvailableProductUnit[];
      return [];
    },
    enabled: Boolean(productId && warehouseId > 0),
  });
}
