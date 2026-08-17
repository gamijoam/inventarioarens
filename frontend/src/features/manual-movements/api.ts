import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createIdempotencyKey, getOne, getPaginated, postOne, withIdempotencyKey } from '@/api/client';
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

type IdempotentInput<T extends object> = T & { idempotencyKey?: string };

const idempotencyKeys = new WeakMap<object, string>();

function idempotencyKeyFor(input: IdempotentInput<object>): string {
  if (input.idempotencyKey) return input.idempotencyKey;

  const existing = idempotencyKeys.get(input);
  if (existing) return existing;

  const key = createIdempotencyKey();
  idempotencyKeys.set(input, key);

  return key;
}

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
  return useMutation<ManualMovement, Error, IdempotentInput<CreateManualMovement>>({
    mutationFn: async (values) => {
      const { idempotencyKey: _idempotencyKey, ...payload } = values;

      return ManualMovementSchema.parse(
        await postOne<CreateManualMovement, unknown>(
          '/inventory/manual-movements',
          payload,
          withIdempotencyKey(idempotencyKeyFor(values)),
        ),
      );
    },
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
    return useMutation<ManualMovement, Error, { id: number; reason?: string; idempotencyKey?: string }>({
      mutationFn: async (values) => {
        const { id, reason } = values;

        return ManualMovementSchema.parse(
          await postOne<{ reason?: string }, unknown>(
            path(id),
            reason ? { reason } : {},
            withIdempotencyKey(idempotencyKeyFor(values)),
          ),
        );
      },
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
