/**
 * API del modulo de Grupos de Tenants (Fase 2 - Jerarquia explicita).
 *
 * Endpoints backend:
 *   GET    /api/tenant-groups                         -> grupos donde soy Owner
 *   POST   /api/tenant-groups                         -> crear grupo + tenant inicial (self-serve)
 *   GET    /api/tenant-groups/{group}/spinoffs        -> empresas hijas del grupo
 *   POST   /api/tenant-groups/{group}/tenants         -> crear spinoff dentro del grupo
 *   GET    /api/tenant-groups/{group}/users           -> usuarios de toda la organizacion (Owner only)
 *   POST   /api/tenant-groups/{group}/users           -> adjuntar usuario a un tenant del grupo (Owner only)
 *
 * Jerarquia explicita:
 *   Tenant Group (is_group=true, parent_id=null) -> contenedor de empresas.
 *   Tenant Spinoff (is_group=false, parent_id=group.id) -> empresa hija.
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

/** Shape del usuario devuelto por el endpoint de grupo. */
export const GroupUserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string().email(),
  status: z.enum(['active', 'inactive']),
  roles: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  tenants: z
    .array(
      z.object({
        id: z.number(),
        name: z.string(),
        slug: z.string(),
        is_group: z.boolean().optional(),
      }),
    )
    .optional(),
});
export type GroupUser = z.infer<typeof GroupUserSchema>;

const _CreateGroupPayloadSchema = z.object({
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
export type CreateGroupPayload = z.infer<typeof _CreateGroupPayloadSchema>;

const _CreateSpinoffPayloadSchema = z.object({
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
export type CreateSpinoffPayload = z.infer<typeof _CreateSpinoffPayloadSchema>;

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
      // BUG FIX: getOne() YA extrae `response.data.data` del body envuelto,
      // asi que retorna el array directamente.
      const items = await getOne<unknown[]>('/tenant-groups');
      return z.array(TenantGroupSchema).parse(Array.isArray(items) ? items : []);
    },
  });
}

const spinoffsKey = (groupIdOrSlug: number | string) =>
  ['access', 'tenant-groups', groupIdOrSlug, 'spinoffs'] as const;

const usersKey = (groupIdOrSlug: number | string) =>
  ['access', 'tenant-groups', groupIdOrSlug, 'users'] as const;

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
 * Lista los usuarios de toda la organizacion (grupo + spinoffs).
 * Solo Owners del grupo pueden llamar este endpoint.
 */
export function useGroupUsers(groupIdOrSlug: number | string, enabled = true) {
  return useQuery({
    queryKey: usersKey(groupIdOrSlug),
    queryFn: async (): Promise<GroupUser[]> => {
      const response = (await getOne<{ data: unknown[] }>(
        `/tenant-groups/${groupIdOrSlug}/users`,
      )) as { data?: unknown[] };
      const items = Array.isArray(response?.data) ? response.data : [];
      return z.array(GroupUserSchema).parse(items);
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
    },
  });
}

export interface GroupUserAttachInput {
  email: string;
  name: string;
  password?: string;
  tenant_slug?: string;
  status?: 'active' | 'inactive';
  roles?: string[];
}

/**
 * Adjunta un usuario a un tenant existente del grupo (o crea el usuario
 * primero si no existe). Solo Owners del grupo pueden llamar este endpoint.
 */
export function useAttachGroupUser(groupIdOrSlug: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: GroupUserAttachInput) =>
      postOne<GroupUserAttachInput, { data: unknown }>(
        `/tenant-groups/${groupIdOrSlug}/users`,
        payload,
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: usersKey(groupIdOrSlug) });
      void qc.invalidateQueries({ queryKey: groupsKey });
    },
  });
}

// Referencia para evitar warning de unused.
void _CreateGroupPayloadSchema;
void _CreateSpinoffPayloadSchema;