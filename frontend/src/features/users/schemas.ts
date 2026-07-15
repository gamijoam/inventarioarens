/**
 * Schemas Zod para el modulo de Usuarios (TenantUser).
 * Cubre el listado, los filtros, y los shapes de create/update que se usan
 * en Fase B (placeholders por ahora).
 *
 * El endpoint backend es GET /api/access/users (paginated). Ver
 * app/Modules/AccessControl/Controllers/TenantUserController.php.
 */
import { z } from 'zod';

export const UserStatusSchema = z.enum(['active', 'inactive']);
export type UserStatus = z.infer<typeof UserStatusSchema>;

export const RoleSummarySchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
});
export type RoleSummary = z.infer<typeof RoleSummarySchema>;

export const UserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string().email(),
  status: UserStatusSchema,
  roles: z.array(RoleSummarySchema),
  created_at: z.string().nullable().optional(),
});
export type User = z.infer<typeof UserSchema>;

export const UserListFiltersSchema = z.object({
  search: z.string().default(''),
  role_id: z.coerce.number().int().positive().optional(),
  status: z.enum(['all', 'active', 'inactive']).default('all'),
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(100).default(25),
});
export type UserListFilters = z.input<typeof UserListFiltersSchema>;

export const UserListResponseSchema = z.object({
  data: z.array(UserSchema),
  meta: z.object({
    current_page: z.number().int(),
    last_page: z.number().int(),
    per_page: z.number().int(),
    total: z.number().int(),
    from: z.number().nullable(),
    to: z.number().nullable(),
  }),
});
export type UserListResponse = z.infer<typeof UserListResponseSchema>;

// =====================================================================
// Schemas de Fase B (placeholders para no romper imports futuros)
// =====================================================================

export const CreateUserInputSchema = z.object({
  name: z.string().min(1, 'Requerido.').max(150),
  email: z.string().email('Email invalido.').max(255),
  password: z.string().min(8, 'Minimo 8 caracteres.').optional().or(z.literal('')),
  roles: z.array(z.string()).default([]),
});
export type CreateUserInput = z.input<typeof CreateUserInputSchema>;

export const UpdateUserRolesInputSchema = z.object({
  roles: z.array(z.string()).default([]),
});
export type UpdateUserRolesInput = z.input<typeof UpdateUserRolesInputSchema>;

export const UpdateUserStatusInputSchema = z.object({
  status: UserStatusSchema,
});
export type UpdateUserStatusInput = z.input<typeof UpdateUserStatusInputSchema>;

export const UpdateUserInputSchema = z.object({
  name: z.string().min(1, 'Requerido.').max(150),
});
export type UpdateUserInput = z.input<typeof UpdateUserInputSchema>;