import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, postOne } from '@/api/client';

export interface LocalWorkerStatus {
  available: boolean;
  active: boolean;
  pid: number | null;
  message: string;
  service?: string;
  service_manager?: string;
}

export interface LocalSyncMetrics {
  outbox_pending: number;
  outbox_failed: number;
  inbox_received: number;
  inbox_failed: number;
  inbox_applied: number;
}

export interface LocalPrinterStatus {
  available: boolean;
  message: string;
  url: string;
}

export interface LocalLanStatus {
  enabled: boolean;
  bind_host: string;
  api_port: number;
  renderer_ports: number[];
  restart_required: boolean;
}

export interface LocalTenantStatus {
  id: number | null;
  name: string;
  slug: string;
  configured: boolean;
  ready: boolean;
  node_name: string | null;
  node_code: string | null;
  interval: number | null;
  worker: LocalWorkerStatus;
  last_success_at: string | null;
  last_attempt_at: string | null;
  last_error: string | null;
  sync: LocalSyncMetrics;
}

export interface LocalSupportStatus {
  storage_path: string;
  database_path: string;
  cloud_url: string;
  printer: LocalPrinterStatus;
  lan: LocalLanStatus;
  tenants: LocalTenantStatus[];
}

export interface ConnectLocalTenantPayload {
  code: string;
  node_name: string;
  node_code: string;
  interval: number;
  local_email: string;
  local_user_name?: string;
  local_password: string;
  selected_tenant_ids?: number[];
}

export interface PairingPreviewTenant {
  id: number;
  name: string;
  slug: string;
  parent_id: number | null;
  is_group: boolean;
}

export interface PairingPreviewResult {
  group?: PairingPreviewTenant;
  tenant?: PairingPreviewTenant;
  tenants: PairingPreviewTenant[];
}

export interface ConnectLocalTenantResult {
  tenant?: { name: string; slug: string };
  group?: { name: string; slug: string } | null;
  tenants?: { tenant: { name: string; slug: string } }[];
  download: { status: 'started' | 'completed'; message: string };
  worker?: { output: string; status: LocalWorkerStatus };
}

const localSupportKey = ['local-support'] as const;

export function localWorkerLabel(worker: LocalWorkerStatus): string {
  if (worker.service === 'SistemaInventarioSync') {
    return worker.active ? 'Motor Local activo' : 'Motor Local detenido';
  }

  return worker.active ? 'Worker activo' : 'Worker detenido';
}

export function normalizeSelectedTenantIds(ids: number[]): number[] {
  return [...new Set(ids.filter((id) => Number.isInteger(id) && id > 0))];
}

export function useLocalSupportStatus() {
  return useQuery({
    queryKey: localSupportKey,
    queryFn: () => getOne<LocalSupportStatus>('/local-support/status'),
    retry: false,
  });
}

export function useConnectLocalTenant() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ConnectLocalTenantPayload) =>
      postOne<ConnectLocalTenantPayload, ConnectLocalTenantResult>(
        '/local-support/connect',
        payload,
        {
          timeout: 180_000,
        },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function usePreviewPairingCode() {
  return useMutation({
    mutationFn: (code: string) =>
      postOne<{ code: string }, PairingPreviewResult>('/sync/pairing-codes/preview', { code }),
  });
}

export function useLocalServerMode() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (enabled: boolean) =>
      postOne<{ enabled: boolean }, LocalLanStatus>('/local-support/server-mode', { enabled }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalTenantSync() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ tenant, cycles = 1 }: { tenant: string; cycles?: number }) =>
      postOne<{ cycles: number }, { output: string }>(
        `/local-support/tenants/${tenant}/sync`,
        { cycles },
        { timeout: 180_000 },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalWorkerAction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      tenant,
      action,
    }: {
      tenant: string;
      action: 'install' | 'start' | 'stop' | 'restart';
    }) =>
      postOne<{ action: string }, { output: string; status: LocalWorkerStatus }>(
        `/local-support/tenants/${tenant}/worker`,
        { action },
        { timeout: 45_000 },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalRetryFailed() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (tenant: string) =>
      postOne<undefined, { reset: number; applied: number; failed: number }>(
        `/local-support/tenants/${tenant}/retry-failed`,
        undefined,
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export interface LocalPrinterActionResult {
  output: string;
  status: LocalPrinterStatus;
}

export function useLocalPrinterAction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (action: 'install' | 'start' | 'stop' | 'restart') =>
      postOne<{ action: string }, LocalPrinterActionResult>(
        '/local-support/printer/action',
        { action },
        { timeout: 45_000 },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalPrinterTest() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () =>
      postOne<undefined, { ok: boolean; message: string; status: LocalPrinterStatus }>(
        '/local-support/printer/test',
        undefined,
        { timeout: 45_000 },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}
