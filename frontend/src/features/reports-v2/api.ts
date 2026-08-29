import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { api, getMany, getOne } from '@/api/client';
import {
  ReportV2CatalogItemSchema,
  ReportV2Schema,
  type ReportV2CatalogItem,
  type ReportV2,
} from './schemas';

export function useReportV2Catalog(enabled: boolean) {
  return useQuery<ReportV2CatalogItem[]>({
    queryKey: ['reports', 'v2', 'catalog'] as const,
    queryFn: async () =>
      z.array(ReportV2CatalogItemSchema).parse(await getMany<unknown>('/reports/v2')),
    enabled,
    staleTime: 5 * 60 * 1000,
  });
}

export interface ReportV2Params {
  scope: 'tenant' | 'organization';
  dimension?: string;
  dateFrom?: string;
  dateTo?: string;
  warehouseId?: number;
  lowStockOnly?: boolean;
  lowStockThreshold?: number;
  companyId?: number;
  limit?: number;
}

export function buildReportV2Query(params: ReportV2Params): string {
  const query = new URLSearchParams();
  query.set('scope', params.scope);
  if (params.dimension) query.set('dimension', params.dimension);
  if (params.dateFrom) query.set('date_from', params.dateFrom);
  if (params.dateTo) query.set('date_to', params.dateTo);
  if (params.warehouseId) query.set('warehouse_id', String(params.warehouseId));
  if (params.lowStockOnly) query.set('low_stock_only', '1');
  if (params.lowStockThreshold !== undefined) {
    query.set('low_stock_threshold', String(params.lowStockThreshold));
  }
  if (params.companyId) query.set('company_id', String(params.companyId));
  if (params.limit) query.set('limit', String(params.limit));
  return query.toString();
}

export function useReportV2(code: string, params: ReportV2Params, enabled: boolean) {
  const query = buildReportV2Query(params);

  return useQuery<ReportV2>({
    queryKey: ['reports', 'v2', code, query] as const,
    queryFn: async () =>
      ReportV2Schema.parse(await getOne<unknown>(`/reports/v2/${code}?${query}`)),
    enabled: enabled && code.length > 0,
    staleTime: 15_000,
  });
}

export async function downloadReportV2(
  code: string,
  params: ReportV2Params,
  format: 'csv' | 'xlsx' | 'pdf',
): Promise<void> {
  const query = buildReportV2Query(params);
  const response = await api.get(`/reports/v2/${code}/export?${query}&format=${format}`, {
    responseType: 'blob',
  });
  const url = URL.createObjectURL(response.data as Blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `${code}.${format}`;
  anchor.click();
  URL.revokeObjectURL(url);
}
