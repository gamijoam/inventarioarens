import { useQuery } from '@tanstack/react-query';

import { api } from '@/api/client';
import { ProductLabelsResponseSchema, type LabelsSelection } from './schemas';

export function buildLabelsQuery(selection: LabelsSelection): string {
  const params = new URLSearchParams();
  (selection.productIds ?? []).forEach((id) => params.append('product_ids[]', String(id)));
  if (selection.changedSince) params.set('changed_since', selection.changedSince);
  if (selection.bulkAdjustmentId) params.set('bulk_adjustment_id', String(selection.bulkAdjustmentId));
  if (selection.priceListId) params.set('price_list_id', String(selection.priceListId));
  return `/product-labels?${params.toString()}`;
}

export function hasSelection(selection: LabelsSelection | null): selection is LabelsSelection {
  if (!selection) return false;
  return (selection.productIds?.length ?? 0) > 0 || Boolean(selection.changedSince) || Boolean(selection.bulkAdjustmentId);
}

export function useProductLabels(selection: LabelsSelection | null) {
  return useQuery({
    queryKey: ['product-labels', selection],
    queryFn: async () => ProductLabelsResponseSchema.parse((await api.get(buildLabelsQuery(selection ?? {}))).data),
    enabled: hasSelection(selection),
  });
}
