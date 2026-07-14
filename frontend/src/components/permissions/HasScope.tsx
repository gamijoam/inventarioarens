import type { ReactNode } from 'react';
import { useHasScope, type ScopeCategory } from '@/permissions/useScope';

interface HasScopeProps {
  category: ScopeCategory;
  id: number;
  fallback?: ReactNode;
  children: ReactNode;
}

/** Renderiza children SOLO si el user puede ver este recurso segun su scope. */
export function HasScope({ category, id, fallback = null, children }: HasScopeProps) {
  return useHasScope(category, id) ? <>{children}</> : <>{fallback}</>;
}