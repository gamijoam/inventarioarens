/**
 * ProtectedRoleBadge: badge que indica que un rol es del sistema
 * (no se puede editar ni eliminar, solo clonar).
 *
 * Los 6 roles base son: Owner, Administrador, Gerente, Vendedor,
 * Almacen, Auditor. Cualquier rol fuera de esa lista es custom del tenant.
 */
import { Lock } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';

interface ProtectedRoleBadgeProps {
  isProtected?: boolean;
  className?: string;
}

export function ProtectedRoleBadge({ isProtected, className }: ProtectedRoleBadgeProps) {
  if (!isProtected) return null;
  return (
    <Badge variant="info" className={cn('gap-1 text-[10px]', className)}>
      <Lock className="size-2.5" aria-hidden="true" />
      Sistema
    </Badge>
  );
}