import type { ReactNode } from 'react';
import { useCan } from '@/permissions/useCan';

interface CanProps {
  I: string;
  fallback?: ReactNode;
  children: ReactNode;
}

/**
 * Renderiza children SOLO si el user tiene el permiso `I`.
 * Si no, renderiza fallback (por defecto null).
 *
 * @example
 *   <Can I={PERMISSIONS.PRODUCTS_CREATE}>
 *     <Button>Nuevo producto</Button>
 *   </Can>
 */
export function Can({ I, fallback = null, children }: CanProps) {
  return useCan(I) ? <>{children}</> : <>{fallback}</>;
}