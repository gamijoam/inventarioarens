import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { deleteOne, getOne, patchOne, postOne } from '@/api/client';
import type { Tenant, User } from '@/types/user';

export interface BootstrapStatus {
  enabled: boolean;
  database_empty: boolean;
  can_run: boolean;
  user_count: number;
  tenant_count: number;
}

export interface BootstrapPayload {
  name: string;
  email: string;
  password?: string;
  bootstrap_token: string;
  tenant?: {
    name: string;
    slug: string;
    domain?: string | null;
    plan?: string | null;
  };
}

export interface BootstrapResult {
  user: User;
  tenant: Tenant | null;
  token?: string;
  token_type?: string;
  expires_at?: string;
  initial_password?: string | null;
}

export interface MasterStats {
  totals: {
    platform_admins: number;
    total_tenants: number;
    total_groups: number;
    total_spinoffs: number;
    active_tenants: number;
    inactive_tenants: number;
  };
  groups_by_plan: Record<string, number>;
}

export interface MasterTenant extends Tenant {
  domain?: string | null;
  plan?: string | null;
  status: string;
  is_group: boolean;
  parent_id: number | null;
  spinoffs_count?: number;
  users_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface MasterAdmin extends User {
  is_platform_admin: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface TenantPayload {
  name: string;
  slug?: string;
  domain?: string | null;
  plan?: string | null;
  status?: string;
}

export interface CreateGroupPayload extends TenantPayload {
  slug: string;
  group_owner: {
    name: string;
    email: string;
    password?: string;
  };
}

export interface CreateSpinoffPayload extends TenantPayload {
  slug: string;
  admin: {
    name: string;
    email: string;
    password?: string;
  };
}

export interface PlatformAdminPayload {
  name: string;
  email: string;
  password?: string;
}

export const masterKeys = {
  all: ['master'] as const,
  stats: () => [...masterKeys.all, 'stats'] as const,
  groups: () => [...masterKeys.all, 'groups'] as const,
  spinoffs: (groupId: number | string) => [...masterKeys.all, 'groups', groupId, 'spinoffs'] as const,
  admins: () => [...masterKeys.all, 'admins'] as const,
};

export function useBootstrapStatus() {
  return useQuery({
    queryKey: ['bootstrap', 'status'],
    queryFn: () => getOne<BootstrapStatus>('/bootstrap/status'),
  });
}

export function useRunBootstrap() {
  return useMutation({
    mutationFn: (payload: BootstrapPayload) => postOne<BootstrapPayload, BootstrapResult>('/bootstrap', payload),
  });
}

export function useMasterStats() {
  return useQuery({
    queryKey: masterKeys.stats(),
    queryFn: () => getOne<MasterStats>('/master/stats'),
  });
}

export function useMasterGroups() {
  return useQuery({
    queryKey: masterKeys.groups(),
    queryFn: () => getOne<MasterTenant[]>('/master/groups'),
  });
}

export function useMasterSpinoffs(groupId: number | string | null) {
  return useQuery({
    queryKey: groupId ? masterKeys.spinoffs(groupId) : [...masterKeys.all, 'spinoffs', 'none'],
    queryFn: () => getOne<MasterTenant[]>(`/master/groups/${groupId}/tenants`),
    enabled: groupId != null,
  });
}

export function useMasterAdmins() {
  return useQuery({
    queryKey: masterKeys.admins(),
    queryFn: () => getOne<MasterAdmin[]>('/master/admins'),
  });
}

export function useCreateMasterGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateGroupPayload) => postOne<CreateGroupPayload, MasterTenant>('/master/groups', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.groups() });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useUpdateMasterGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: TenantPayload }) =>
      patchOne<TenantPayload, MasterTenant>(`/master/groups/${id}`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.groups() });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useDeactivateMasterGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteOne(`/master/groups/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.groups() });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useCreateMasterSpinoff(groupId: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateSpinoffPayload) =>
      postOne<CreateSpinoffPayload, MasterTenant>(`/master/groups/${groupId}/tenants`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.groups() });
      if (groupId != null) void qc.invalidateQueries({ queryKey: masterKeys.spinoffs(groupId) });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useUpdateMasterSpinoff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: TenantPayload }) =>
      patchOne<TenantPayload, MasterTenant>(`/master/tenants/${id}`, payload),
    onSuccess: (_data, vars) => {
      void qc.invalidateQueries({ queryKey: masterKeys.groups() });
      void qc.invalidateQueries({ queryKey: masterKeys.all });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
      void qc.invalidateQueries({ queryKey: [...masterKeys.all, 'tenant', vars.id] });
    },
  });
}

export function useDeactivateMasterSpinoff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteOne(`/master/tenants/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.all });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useCreateMasterAdmin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: PlatformAdminPayload) => postOne<PlatformAdminPayload, MasterAdmin>('/master/admins', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.admins() });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}

export function useUpdateMasterAdmin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PlatformAdminPayload> }) =>
      patchOne<Partial<PlatformAdminPayload>, MasterAdmin>(`/master/admins/${id}`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.admins() });
    },
  });
}

export function useResetMasterAdminPassword() {
  return useMutation({
    mutationFn: ({ id, password }: { id: number; password?: string }) =>
      postOne<{ password?: string }, { user_id: number; email: string; initial_password?: string | null; sessions_revoked: boolean }>(
        `/master/admins/${id}/reset-password`,
        password ? { password } : {},
      ),
  });
}

export function useRevokeMasterAdmin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteOne(`/master/admins/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: masterKeys.admins() });
      void qc.invalidateQueries({ queryKey: masterKeys.stats() });
    },
  });
}
