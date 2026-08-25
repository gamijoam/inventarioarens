import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, patchOne } from '@/api/client';

export const TenantCapabilityDefinitionSchema = z.object({
  key: z.string(),
  label: z.string(),
  description: z.string(),
  required: z.boolean(),
  enabled: z.boolean(),
});

export const TenantCapabilitiesSchema = z.object({
  tenant_id: z.number().int().positive(),
  enabled: z.array(z.string()),
  capabilities: z.array(TenantCapabilityDefinitionSchema),
});

export type TenantCapabilityDefinition = z.infer<typeof TenantCapabilityDefinitionSchema>;
export type TenantCapabilities = z.infer<typeof TenantCapabilitiesSchema>;

const capabilityKeys = {
  all: ['tenant-capabilities'] as const,
};

function parseCapabilities(data: unknown): TenantCapabilities {
  const parsed = TenantCapabilitiesSchema.safeParse(data);
  if (!parsed.success) {
    throw new Error('Respuesta de capacidades invalida.');
  }

  return parsed.data;
}

export async function getTenantCapabilities(): Promise<TenantCapabilities> {
  return parseCapabilities(await getOne<unknown>('/tenant-capabilities'));
}

export async function updateTenantCapabilities(enabled: string[]): Promise<TenantCapabilities> {
  return parseCapabilities(
    await patchOne<{ capabilities: string[] }, unknown>('/tenant-capabilities', {
      capabilities: enabled,
    }),
  );
}

export function useTenantCapabilities() {
  return useQuery({
    queryKey: capabilityKeys.all,
    queryFn: getTenantCapabilities,
    staleTime: 30_000,
  });
}

export function useUpdateTenantCapabilities() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: updateTenantCapabilities,
    onSuccess: (data) => {
      queryClient.setQueryData(capabilityKeys.all, data);
    },
  });
}
