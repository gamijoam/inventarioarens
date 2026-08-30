import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, getPaginated, postOne } from '@/api/client';
import { type Paginated } from '@/types/api';
import { FiscalDocumentPreviewSchema, type FiscalDocumentPreview } from './schemas';

export interface FiscalDocumentPreviewFilters {
  sale_id?: number;
  status?: 'preview';
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export const fiscalDocumentKeys = {
  all: ['fiscal-documents'] as const,
  list: (filters: FiscalDocumentPreviewFilters) => [...fiscalDocumentKeys.all, filters] as const,
};

function buildFiscalDocumentQuery(filters: FiscalDocumentPreviewFilters = {}): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') params.set(key, String(value));
  }

  const query = params.toString();
  return query ? `?${query}` : '';
}

export function buildFiscalDocumentPreviewsPath(
  filters: FiscalDocumentPreviewFilters = {},
): string {
  return `/fiscal/documents${buildFiscalDocumentQuery(filters)}`;
}

export async function getFiscalDocumentPreviews(
  filters: FiscalDocumentPreviewFilters = {},
): Promise<Paginated<FiscalDocumentPreview>> {
  const response = await getPaginated<unknown>(buildFiscalDocumentPreviewsPath(filters));

  return {
    ...response,
    data: response.data.map((item) => FiscalDocumentPreviewSchema.parse(item)),
  };
}

export async function getFiscalDocumentPreview(id: number): Promise<FiscalDocumentPreview> {
  const response = await getOne<unknown>(`/fiscal/documents/${id}`);
  return FiscalDocumentPreviewSchema.parse(response);
}

export function useFiscalDocumentPreviews(
  filters: FiscalDocumentPreviewFilters = {},
  enabled = true,
) {
  return useQuery({
    queryKey: fiscalDocumentKeys.list(filters),
    queryFn: () => getFiscalDocumentPreviews(filters),
    enabled,
  });
}

export function buildFiscalDocumentPreviewPath(): string {
  return '/fiscal/documents/previews';
}

export async function createFiscalDocumentPreview(saleId: number): Promise<FiscalDocumentPreview> {
  const response = await postOne<{ sale_id: number }, unknown>(buildFiscalDocumentPreviewPath(), {
    sale_id: saleId,
  });

  return FiscalDocumentPreviewSchema.parse(response);
}

export function useCreateFiscalDocumentPreview() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (saleId: number) => createFiscalDocumentPreview(saleId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: fiscalDocumentKeys.all });
    },
  });
}

export type { FiscalDocumentPreview } from './schemas';
