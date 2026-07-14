/**
 * Store de sesion del usuario actual.
 * Persiste token + user + tenant en localStorage (NO permissions/scopes,
 * que se rehidratan al hacer login/me).
 *
 * Ver docs/FRONTEND_ARQUITECTURA.md §9.1.
 */
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

import type { Tenant, User, UserScopes } from '@/types/user';

export interface SessionState {
  token: string | null;
  user: User | null;
  tenant: Tenant | null;
  roles: string[];
  permissions: Set<string>;
  scopeStatus: 'none' | 'allow' | 'restrict';
  scopes: UserScopes;
  expiresAt: string | null;

  setSession: (data: {
    token: string;
    expiresAt: string;
    user: User;
    tenant: Tenant;
    roles: string[];
    permissions: string[];
    scopeStatus: SessionState['scopeStatus'];
    scopes: UserScopes;
  }) => void;

  setTenant: (tenant: Tenant) => void;

  clearSession: () => void;

  isAuthenticated: () => boolean;
}

const emptyScopes: UserScopes = {
  branches: [],
  warehouses: [],
  customer_groups: [],
  vendor_of: [],
  branches_count: 0,
  warehouses_count: 0,
  customer_groups_count: 0,
  vendor_of_count: 0,
};

const initialState = {
  token: null,
  user: null,
  tenant: null,
  roles: [] as string[],
  permissions: new Set<string>(),
  scopeStatus: 'none' as const,
  scopes: emptyScopes,
  expiresAt: null,
};

export const useSessionStore = create<SessionState>()(
  persist(
    (set, get) => ({
      ...initialState,

      setSession: (data) =>
        set({
          token: data.token,
          expiresAt: data.expiresAt,
          user: data.user,
          tenant: data.tenant,
          roles: data.roles,
          permissions: new Set(data.permissions),
          scopeStatus: data.scopeStatus,
          scopes: data.scopes,
        }),

      setTenant: (tenant) => set({ tenant }),

      clearSession: () => set({ ...initialState, permissions: new Set() }),

      isAuthenticated: () => Boolean(get().token),
    }),
    {
      name: 'inventory_session',
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({
        // Solo persistimos lo minimo para sobrevivir un refresh.
        // permissions, scopes y roles se rehidratan con /api/auth/me al cargar.
        token: state.token,
        user: state.user,
        tenant: state.tenant,
        expiresAt: state.expiresAt,
      }),
    },
  ),
);