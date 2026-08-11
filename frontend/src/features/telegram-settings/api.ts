import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getOne, patchOne } from '@/api/client';
import { z } from 'zod';

export const TenantSettingsSchema = z.object({
  tenant_id: z.number().int(),
  settings: z.record(z.unknown()).optional(),
});

export type TenantSettings = z.infer<typeof TenantSettingsSchema>;

export interface TelegramSettings {
  enabled?: boolean;
  report_time?: string;
  low_stock_alerts?: boolean;
  low_stock_frequency?: 'daily' | '4h' | '8h';
  low_stock_threshold?: number;
  whitelist?: Array<{ id: number; name: string; telegram_id: string; tenant_slug?: string }>;
}

const settingsKeys = {
  all: ['tenant-settings'] as const,
};

export function useTenantSettings() {
  return useQuery({
    queryKey: settingsKeys.all,
    queryFn: async () => {
      const data = await getOne<{ data: unknown }>('/tenant-settings');
      const parsed = TenantSettingsSchema.safeParse(data?.data ?? data);
      if (!parsed.success) {
        throw new Error('Respuesta de configuración inválida');
      }
      return parsed.data;
    },
    staleTime: 30_000,
  });
}

export function useUpdateTenantSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (settings: Record<string, unknown>) => {
      await patchOne('/tenant-settings', { settings });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: settingsKeys.all });
    },
  });
}
