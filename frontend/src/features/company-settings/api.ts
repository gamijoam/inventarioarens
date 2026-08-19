import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, patchOne } from '@/api/client';
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: settingsKeys.all });
    },
  });
}
