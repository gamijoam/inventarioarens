import type { ReactNode } from 'react';
import { useCanAny } from '@/permissions/useCan';

interface CanAnyProps {
  any: readonly string[];
  fallback?: ReactNode;
  children: ReactNode;
}

/** Renderiza children si el user tiene AL MENOS UNO de los permisos. */
export function CanAny({ any: perms, fallback = null, children }: CanAnyProps) {
  return useCanAny(perms) ? <>{children}</> : <>{fallback}</>;
}