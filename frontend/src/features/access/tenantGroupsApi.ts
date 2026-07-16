/**
 * API del modulo de Grupos de Tenants (Fase 2 - Jerarquia explicita).
 *
 * Endpoints backend:
 *   GET    /api/tenant-groups                         -> grupos donde soy Owner
 *   POST   /api/tenant-groups                         -> crear grupo + tenant inicial (self-serve)
 *   GET    /api/tenant-groups/{group}/spinoffs        -> empresas hijas del grupo
 *   POST   /api/tenant-groups/{group}/tenants         -> crear spinoff dentro del grupo
 *
 * Jerarquia explicita:
 *   Tenant Group (is_group=true, parent_id=null) -> contenedor de empresas.
 *   Tenant Spinoff (is_group=false, parent_id=group.id) -> empresa hija.
 *
 * Solo los Owners del grupo (rol "Owner" con team_id = group.id) pueden
 * crear spinoffs dentro del grupo. Para crear un grupo propio (self-serve)
 * NO se requiere ser platform admin.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, postOne } from '@/api/client';

// =====================================================================
// Schemas
// =====================================================================

export const TenantGroupSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  domain: z.string().nullable().optional(),
  plan: z.string().nullable().optional(),
  status: z.string(),
  children_count: z.number().int().nonnegative().optional(),
  users_count: z.number().int().nonnegative().optional(),
  is_owner: z.boolean(),
});
export type TenantGroup = z.infer<typeof TenantGroupSchema>;

export const TenantSpinoffSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  slug: z.string(),
  domain: z.string().nullable().optional(),
  plan: z.string().nullable().optional(),
  status: z.string(),
  users_count: z.number().int().nonnegative().optional(),
});
export type TenantSpinoff = z.infer<typeof TenantSpinoffSchema>;

const CreateGroupPayloadSchema = z.object({
  group: z.object({
    name: z.string().min(1, 'Requerido').max(255),
    slug: z
      .string()
      .min(1)
      .max(100)
      .regex(/^[a-z0-9-]+$/, 'Solo letras minusculas, numeros y guiones'),
    plan: z.string().max(50).optional(),
    domain: z.string().max(255).optional(),
  }),
  tenant: z.object({
    name: z.string().min(1, 'Requerido').max(255),
    slug: z
      .string()
      .min(1)
      .max(100)
      .regex(/^[a-z0-9-]+$/, 'Solo letras minusculas, numeros y guiones'),
    domain: z.string().max(255).optional(),
    plan: z.string().max(50).optional(),
    branch: z
      .object({
        name: z.string().min(1).max(255),
        code: z.string().min(1).max(50),
      })
      .optional(),
    warehouse: z
      .object({
        name: z.string().min(1).max(255),
        code: z.string().min(1).max(50),
      })
      .optional(),
    exchange_rate_type: z
      .object({
        code: z.string().min(1).max(50),
        name: z.string().min(1).max(255),
      })
      .optional(),
  }),
  admin: z.object({
    name: z.string().min(1, 'Requerido').max(255),
    email: z.string().email('Email invalido').max(255),
    password: z.string().min(8, 'Minimo 8 caracteres').max(255).optional(),
  }),
});
export type CreateGroupPayload = z.infer<typeof CreateGroupPayloadSchema>;

const CreateSpinoffPayloadSchema = z.object({
  name: z.string().min(1, 'Requerido').max(255),
  slug: z
    .string()
    .min(1)
    .max(100)
    .regex(/^[a-z0-9-]+$/, 'Solo letras minusculas, numeros y guiones'),
  domain: z.string().max(255).optional(),
  plan: z.string().max(50).optional(),
  admin: z.object({
    name: z.string().min(1, 'Requerido').max(255),
    email: z.string().email('Email invalido').max(255),
    password: z.string().min(8, 'Minimo 8 caracteres').max(255).optional(),
  }),
  branch: z
    .object({
      name: z.string().min(1).max(255),
      code: z.string().min(1).max(50),
    })
    .optional(),
  warehouse: z
    .object({
      name: z.string().min(1).max(255),
      code: z.string().min(1).max(50),
    })
    .optional(),
  exchange_rate_type: z
    .object({
      code: z.string().min(1).max(50),
      name: z.string().min(1).max(255),
    })
    .optional(),
});
export type CreateSpinoffPayload = z.infer<typeof CreateSpinoffPayloadSchema>;

// =====================================================================
// Hooks
// =====================================================================

const groupsKey = ['access', 'tenant-groups'] as const;

/**
 * Lista los grupos (is_group=true) donde el user autenticado es Owner.
 */
export function useTenantGroups() {
  return useQuery({
    queryKey: groupsKey,
    queryFn: async (): Promise<TenantGroup[]> => {
      const response = (await getOne<{ data: unknown[] }>('/tenant-groups')) as {
        data?: unknown[];
      };
      const items = Array.isArray(response?.data) ? response.data : [];
      return z.array(TenantGroupSchema).parse(items);
    },
  });
}

const spinoffsKey = (groupIdOrSlug: number | string) =>
  ['access', 'tenant-groups', groupIdOrSlug, 'spinoffs'] as const;

/**
 * Lista los spinoffs (empresas hijas) de un grupo donde el user es Owner.
 */
export function useGroupSpinoffs(groupIdOrSlug: number | string, enabled = true) {
  return useQuery({
    queryKey: spinoffsKey(groupIdOrSlug),
    queryFn: async (): Promise<TenantSpinoff[]> => {
      const response = (await getOne<{ data: unknown[] }>(
        `/tenant-groups/${groupIdOrSlug}/spinoffs`,
      )) as { data?: unknown[] };
      const items = Array.isArray(response?.data) ? response.data : [];
      return z.array(TenantSpinoffSchema).parse(items);
    },
    enabled,
  });
}

/**
 * Crea un grupo + tenant inicial en una sola transaccion (self-serve).
 * El admin del payload queda como Owner del grupo y Administrador del tenant.
 */
export function useCreateTenantGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateGroupPayload) =>
      postOne<CreateGroupPayload, { data: { group: TenantGroup; tenant: TenantSpinoff } }>(
        '/tenant-groups',
        payload,
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: groupsKey });
      void qc.invalidateQueries({ queryKey: ['auth', 'available-tenants'] });
    },
  });
}

/**
 * Crea un spinoff (empresa hija) dentro de un grupo. Solo Owners del grupo.
 */
export function useCreateSpinoff(groupIdOrSlug: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateSpinoffPayload) =>
      postOne<CreateSpinoffPayload, { data: TenantSpinoff }>(
        `/tenant-groups/${groupIdOrSlug}/tenants`,
        payload,
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: spinoffsKey(groupIdOrSlug) });
      void qc.invalidateQueries({ queryKey: groupsKey });
      void qc.invalidateQueries({ queryKey: ['auth', 'available-tenants'] });
    },
  });
}

void CreateGroupPayloadSchema;
void CreateSpinoffPayloadSchema;
