import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, patchOne, postOne, deleteOne } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { z } from 'zod';

export const CompanySettingsSchema = z.object({
  razon_social: z.string().nullable().optional(),
  rif: z.string().nullable().optional(),
  domicilio_fiscal: z.string().nullable().optional(),
  ciudad: z.string().nullable().optional(),
  estado: z.string().nullable().optional(),
  telefono: z.string().nullable().optional(),
  correo: z.string().nullable().optional(),
  website: z.string().nullable().optional(),
  regimen: z.string().nullable().optional(),
  logo_url: z.string().nullable().optional(),
  show_on: z
    .object({
      sale_ticket: z.boolean().optional(),
      guide: z.boolean().optional(),
      report_z: z.boolean().optional(),
      quotation: z.boolean().optional(),
    })
    .optional(),
});

export type CompanySettings = z.infer<typeof CompanySettingsSchema>;

export const TenantSettingsSchema = z.object({
  tenant_id: z.number().int(),
  settings: z
    .object({
      company: CompanySettingsSchema.optional(),
    })
    .passthrough(),
});

export type TenantSettings = z.infer<typeof TenantSettingsSchema>;

const settingsKeys = {
  all: ['tenant-settings'] as const,
};

export function useCompanySettings() {
  return useQuery({
    queryKey: settingsKeys.all,
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>('/tenant-settings');
      const parsed = TenantSettingsSchema.safeParse(data?.data ?? data);
      if (!parsed.success) {
        throw new Error('Respuesta de configuración inválida');
      }
      return parsed.data.settings?.company ?? {};
    },
    staleTime: 30_000,
  });
}

export function useUpdateCompanySettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (company: CompanySettings) => {
      const data = await patchOne<{ settings: { company: CompanySettings } }, { data: unknown }>(
        '/tenant-settings',
        { settings: { company } },
      );
      const parsed = TenantSettingsSchema.safeParse(data?.data ?? data);
      if (!parsed.success) {
        throw new Error('Respuesta de configuración inválida');
      }
      return parsed.data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: settingsKeys.all });
      const currentTenant = useSessionStore.getState().tenant;
      if (currentTenant && data.settings?.company?.razon_social) {
        useSessionStore.getState().setTenant({
          ...currentTenant,
          name: data.settings.company.razon_social,
        });
      }
    },
  });
}

export function useUploadCompanyLogo() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData();
      formData.append('logo', file);
      const data = await postOne<FormData, { logo_url: string; settings: TenantSettings['settings'] }>(
        '/tenant-settings/logo',
        formData,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
        },
      );
      return data;
    },
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: settingsKeys.all });
      const currentTenant = useSessionStore.getState().tenant;
      if (currentTenant && res?.logo_url) {
        useSessionStore.getState().setTenant({
          ...currentTenant,
          logo_url: res.logo_url,
        });
      }
    },
  });
}

export function useDeleteCompanyLogo() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await deleteOne('/tenant-settings/logo');
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: settingsKeys.all });
      const currentTenant = useSessionStore.getState().tenant;
      if (currentTenant) {
        useSessionStore.getState().setTenant({
          ...currentTenant,
          logo_url: null,
        });
      }
    },
  });
}
