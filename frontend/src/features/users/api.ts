/**
 * API del modulo de Usuarios (TenantUser).
 *
 * Endpoints backend:
 *   GET    /api/users                        -> listado paginado (TanStack Query)
 *   GET    /api/users/{id}                   -> detalle (Fase B)
 *   POST   /api/users                        -> crear (Fase B)
 *   PATCH  /api/users/{id}                   -> actualizar nombre (Fase B)
 *   PATCH  /api/users/{id}/status            -> activar/inactivar (Fase B)
 *   PATCH  /api/users/{id}/roles             -> asignar roles (Fase B)
 *
 * Esta Fase A implementa solo useUsers. Las mutations quedan
 * documentadas pero las clases reales se harn en Fase B.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { getMany, postOne, patchOne } from '@/api/client';
import {
  UserListResponseSchema,
  type CreateUserInput,
  type UpdateUserRolesInput,
  type UpdateUserStatusInput,
  type UserListFilters,
  type User,
} from './schemas';

export type { UserListFilters } from './schemas';

export const userKeys = {
  all: ['users'] as const,
  lists: () => [...userKeys.all, 'list'] as const,
  list: (filters: UserListFilters) => [...userKeys.lists(), filters] as const,
  details: () => [...userKeys.all, 'detail'] as const,
  detail: (id: number) => [...userKeys.details(), id] as const,
};

function toQueryString(filters: UserListFilters): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v == null || v === '' || v === 'all') continue;
    params.set(k, String(v));
  }
  const q = params.toString();
  return q ? `?${q}` : '';
}

/**
 * Listado paginado de usuarios del tenant actual.
 * El backend ya filtra por tenant via middleware; el parametro `role_id`
 * es opcional y filtra usuarios que tengan ese rol asignado.
 */
export function useUsers(filters: UserListFilters) {
  return useQuery({
    queryKey: userKeys.list(filters),
    queryFn: async () => {
      const data = await getMany<unknown>(`/users${toQueryString(filters)}`);
      const parsed = UserListResponseSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('[useUsers] shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    placeholderData: (prev) => prev,
  });
}

/**
 * (Fase B) Crea un usuario y lo vincula al tenant actual con los roles dados.
 */
export function useCreateUser() {
  const qc = useQueryClient();
  return useMutation<User, Error, CreateUserInput>({
    mutationFn: (values) => postOne<CreateUserInput, User>('/users', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
    },
  });
}

/**
 * (Fase B) Asigna los roles del usuario en el tenant actual.
 */
export function useUpdateUserRoles() {
  const qc = useQueryClient();
  return useMutation<User, Error, { id: number; values: UpdateUserRolesInput }>({
    mutationFn: ({ id, values }) =>
      patchOne<UpdateUserRolesInput, User>(`/users/${id}/roles`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
      await qc.invalidateQueries({ queryKey: userKeys.detail(id) });
    },
  });
}

/**
 * (Fase B) Activa o inactiva el usuario en el tenant actual.
 */
export function useUpdateUserStatus() {
  const qc = useQueryClient();
  return useMutation<User, Error, { id: number; values: UpdateUserStatusInput }>({
    mutationFn: ({ id, values }) =>
      patchOne<UpdateUserStatusInput, User>(`/users/${id}/status`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
      await qc.invalidateQueries({ queryKey: userKeys.detail(id) });
    },
  });
}