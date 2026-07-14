/**
 * Provider que expone los permisos, roles y scope del usuario actual.
 * Se inicializa una sola vez (en AppShell) con la respuesta de /api/auth/me.
 */
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';

import type { UserScopes } from '@/types/user';
import type { PermissionName } from './constants';

export interface PermissionContextValue {
  permissions: Set<string>;
  roles: string[];
  scopeStatus: 'none' | 'allow' | 'restrict';
  scopes: UserScopes;
}

export const PermissionContext = createContext<PermissionContextValue | undefined>(undefined);
PermissionContext.displayName = 'PermissionContext';

interface PermissionProviderProps {
  initial: PermissionContextValue;
  children: ReactNode;
}

export function PermissionProvider({ initial, children }: PermissionProviderProps) {
  const [value, setValue] = useState<PermissionContextValue>(initial);

  // Si la session cambia (login, switch-tenant, logout), actualizamos.
  useEffect(() => {
    setValue(initial);
  }, [initial]);

  return <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>;
}

export function usePermissionContext(): PermissionContextValue {
  const ctx = useContext(PermissionContext);
  if (!ctx) throw new Error('usePermissionContext debe usarse dentro de <PermissionProvider>');
  return ctx;
}

/** Helper para obtener el tipo del value sin acoplar al componente. */
export function buildPermissionValue(
  permissions: string[],
  roles: string[],
  scopeStatus: PermissionContextValue['scopeStatus'],
  scopes: UserScopes,
): PermissionContextValue {
  return {
    permissions: new Set(permissions),
    roles,
    scopeStatus,
    scopes,
  };
}

export type { PermissionName };