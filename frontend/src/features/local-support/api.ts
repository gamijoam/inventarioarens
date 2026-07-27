import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, postOne } from '@/api/client';

export interface LocalWorkerStatus {
  available: boolean;
  active: boolean;
  pid: number | null;
  message: string;
}

export interface LocalTenantStatus {
  id: number;
  name: string;
  slug: string;
  configured: boolean;
  node_name: string | null;
  node_code: string | null;
  interval: number | null;
  worker: LocalWorkerStatus;
  last_success_at: string | null;
  last_attempt_at: string | null;
  last_error: string | null;
}

export interface LocalSupportStatus {
  storage_path: string;
  database_path: string;
  cloud_url: string;
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
}

export interface ConnectLocalTenantResult {
  tenant: { name: string; slug: string };
  sync: { cycles: number; output: string; worker: LocalWorkerStatus };
  worker: { output: string; status: LocalWorkerStatus };
}

const localSupportKey = ['local-support'] as const;

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
      postOne<ConnectLocalTenantPayload, ConnectLocalTenantResult>('/local-support/connect', payload, {
        timeout: 180_000,
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalTenantSync() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ tenant, cycles = 1 }: { tenant: string; cycles?: number }) =>
      postOne<{ cycles: number }, { output: string }>(`/local-support/tenants/${tenant}/sync`, { cycles }, { timeout: 180_000 }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}

export function useLocalWorkerAction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ tenant, action }: { tenant: string; action: 'install' | 'start' | 'stop' | 'restart' }) =>
      postOne<{ action: string }, { output: string; status: LocalWorkerStatus }>(
        `/local-support/tenants/${tenant}/worker`,
        { action },
        { timeout: 45_000 },
      ),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: localSupportKey }),
  });
}
