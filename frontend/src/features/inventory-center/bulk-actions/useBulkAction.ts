/**
 * Hook useBulkAction: mutation wrapper para POST /api/inventory-center/products/bulk-action.
 * Invalida las queries correspondientes en success.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { postOne } from '@/api/client';
import { type BulkAction, type BulkActionInput } from '@/features/inventory-center/schemas';
import { productKeys } from '@/features/inventory-center/queries';

interface BulkActionResponse {
  data: { affected: number };
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
      const count = data?.data?.affected ?? vars.product_ids.length;
      toast.success(`Accion "${vars.action}" aplicada a ${count} producto(s).`);
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
    },
    onError: (err) => {
      toast.error(err instanceof Error ? err.message : 'Error al aplicar la accion.');
    },
  });
}

export type { BulkAction };
