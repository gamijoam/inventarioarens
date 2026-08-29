/**
 * Hook useBulkAction: mutation wrapper para POST /api/inventory-center/products/bulk-action.
 * Invalida las queries correspondientes en success.
 */
import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { getOne, postOne } from '@/api/client';
import { type BulkAction, type BulkActionInput } from '@/features/inventory-center/schemas';
import { productKeys } from '@/features/inventory-center/queries';

interface BulkActionResponse {
  id?: number;
  status?: 'pending' | 'running' | 'completed' | 'failed';
  requested_count?: number;
  processed_count?: number;
  progress_percent?: number;
  updated_count?: number;
  skipped_count?: number;
}

export function useBulkAction() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: BulkActionInput) => {
      return postOne<BulkActionInput, BulkActionResponse>(
        '/inventory-center/products/bulk-action',
        input,
      );
    },
    onSuccess: (data, vars) => {
      if (data.status && data.id) {
        toast.success(
          `La clasificación fiscal de ${data.requested_count ?? 0} producto(s) fue enviada a procesamiento.`,
        );
      } else {
        const count = data.updated_count ?? vars.product_ids?.length ?? 0;
        toast.success(`Accion "${vars.action}" aplicada a ${count} producto(s).`);
      }
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
    onError: (err) => {
      toast.error(err instanceof Error ? err.message : 'Error al aplicar la accion.');
    },
  });
}

export function useBulkOperation(operationId: number | null) {
  const qc = useQueryClient();
  const query = useQuery({
    queryKey: ['inventory-center', 'bulk-operation', operationId],
    queryFn: () =>
      getOne<BulkActionResponse>(`/inventory-center/products/bulk-operations/${operationId}`),
    enabled: operationId !== null,
    refetchInterval: (query) =>
      query.state.data?.status === 'pending' || query.state.data?.status === 'running'
        ? 1000
        : false,
  });

  useEffect(() => {
    if (query.data?.status === 'completed') {
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    }
  }, [qc, query.data?.status]);

  return query;
}

export type { BulkAction };
export type { BulkActionResponse };
