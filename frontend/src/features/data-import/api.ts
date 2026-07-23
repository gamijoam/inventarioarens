/**
 * API del modulo de Importacion Masiva de Datos.
 *
 * Endpoints cubiertos:
 *   GET    /api/import/sessions
 *   POST   /api/import/sessions
 *   GET    /api/import/sessions/{id}
 *   DELETE /api/import/sessions/{id}
 *   GET    /api/import/sessions/{id}/entities/{entity}/rows
 *   GET    /api/import/sessions/{id}/report
 *   POST   /api/import/sessions/{id}/entities/{entity}/upload
 *   POST   /api/import/sessions/{id}/entities/{entity}/run
 *   GET    /api/import/templates/{entity}
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, getMany, postOne } from '@/api/client';
import { type DataImportEntitySchema, DataImportSchema } from './schemas';

export const SUPPORTED_ENTITIES = [
  'branches',
  'warehouses',
  'brands',
  'categories',
  'tags',
  'products',
  'price_lists',
  'payment_methods',
  'customers',
  'suppliers',
] as const;

export type SupportedEntity = (typeof SUPPORTED_ENTITIES)[number];

export const ENTITY_LABELS: Record<SupportedEntity, string> = {
  branches: 'Sucursales',
  warehouses: 'Almacenes',
  brands: 'Marcas',
  categories: 'Categorias',
  tags: 'Tags',
  products: 'Productos',
  price_lists: 'Listas de precios',
  payment_methods: 'Metodos de pago',
  customers: 'Clientes',
  suppliers: 'Proveedores',
};

export const dataImportKeys = {
  all: ['data-import'] as const,
  sessions: () => [...dataImportKeys.all, 'sessions'] as const,
  session: (id: number) => [...dataImportKeys.sessions(), id] as const,
  rows: (id: number, entity: string) =>
    [...dataImportKeys.session(id), 'entity', entity, 'rows'] as const,
  template: (entity: string) => [...dataImportKeys.all, 'template', entity] as const,
};

export function useDataImportSessions() {
  return useQuery({
    queryKey: dataImportKeys.sessions(),
    queryFn: async () => {
      const response = await getMany<{ data: unknown[] } | unknown[]>('/import/sessions');
      const raw = Array.isArray(response) ? response : (response as { data: unknown[] }).data;
      return z.array(DataImportSchema).parse(raw ?? []);
    },
  });
}

export function useDataImportSession(id: number) {
  return useQuery({
    queryKey: dataImportKeys.session(id),
    queryFn: async () => {
      const response = await getMany<unknown>(`/import/sessions/${id}`);
      const raw = (response as { data?: unknown }).data ?? response;
      return DataImportSchema.parse(raw);
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useCreateDataImportSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload?: { meta?: Record<string, unknown> }) => {
      const response = await postOne<unknown>('/import/sessions', payload ?? {});
      const raw = (response as { data?: unknown }).data ?? response;
      return DataImportSchema.parse(raw);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: dataImportKeys.sessions() });
    },
  });
}

export function useDeleteDataImportSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/import/sessions/${id}`);
      return id;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: dataImportKeys.sessions() });
    },
  });
}

export function useUploadImportFile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { sessionId: number; entity: SupportedEntity; file: File }) => {
      const fd = new FormData();
      fd.append('file', payload.file);
      const response = await api.post<{ data: unknown }>(
        `/import/sessions/${payload.sessionId}/entities/${payload.entity}/upload`,
        fd,
      );
      const raw = (response as { data?: { session?: unknown } }).data ?? {};
      return DataImportSchema.parse(raw.session);
    },
    onSuccess: (_, variables) => {
      void qc.invalidateQueries({ queryKey: dataImportKeys.session(variables.sessionId) });
    },
  });
}

export interface RunSummary {
  total: number;
  ok: number;
  skipped: number;
  failed: number;
  status: string;
  error_summary?: { row: number; natural_key: string | null; errors: Record<string, unknown> }[] | null;
}

export function useRunImportEntity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { sessionId: number; entity: SupportedEntity }): Promise<{ summary: RunSummary; session: unknown }> => {
      const response = await api.post<unknown>(
        `/import/sessions/${payload.sessionId}/entities/${payload.entity}/run`,
        {},
      );
      const raw = response as unknown;
      const data = (raw as { data?: { summary: RunSummary; session: unknown } }).data ?? raw;
      return data as { summary: RunSummary; session: unknown };
    },
    onSuccess: (_, variables) => {
      void qc.invalidateQueries({ queryKey: dataImportKeys.session(variables.sessionId) });
    },
  });
}

export function useImportEntityRows(sessionId: number, entity: SupportedEntity) {
  return useQuery({
    queryKey: dataImportKeys.rows(sessionId, entity),
    queryFn: async () => {
      const response = await getMany<{ data: unknown[] } | unknown[]>(
        `/import/sessions/${sessionId}/entities/${entity}/rows`,
      );
      const raw = Array.isArray(response) ? response : (response as { data: unknown[] }).data;
      return z.array(z.unknown()).parse(raw ?? []);
    },
    enabled: Number.isFinite(sessionId) && sessionId > 0,
  });
}

export function templateUrl(entity: SupportedEntity): string {
  return `/import/templates/${entity}`;
}

export function reportUrl(sessionId: number): string {
  return `/import/sessions/${sessionId}/report`;
}

/**
 * Descarga un archivo (plantilla o reporte) via axios para que envie el
 * header `X-Requested-With: XMLHttpRequest` requerido por la proteccion CSRF.
 * Devuelve un Blob URL que el caller debe liberar tras usar.
 */
export async function downloadImportFile(url: string, filename: string): Promise<void> {
  const response = await api.get<Blob>(url, { responseType: 'blob' });
  const contentType = String(response.headers['content-type'] ?? 'text/csv');
  const blob = new Blob([response.data], { type: contentType });
  const objectUrl = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = objectUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(objectUrl);
}

export type DataImportEntity = z.infer<typeof DataImportEntitySchema>;
