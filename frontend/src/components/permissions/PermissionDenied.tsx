import { Lock } from 'lucide-react';
import { cn } from '@/lib/cn';

interface PermissionDeniedProps {
  permission?: string;
  message?: string;
  className?: string;
}

/**
 * Mensaje informativo para mostrar cuando un user no tiene permiso.
 * Se usa como fallback dentro de <Can I="..." fallback={<PermissionDenied />}>
 */
export function PermissionDenied({ permission, message, className }: PermissionDeniedProps) {
  return (
    <div
      role="status"
      className={cn(
        'flex items-start gap-2 rounded-lg border border-dashed border-warning bg-warning/5 p-3 text-sm text-text-secondary',
        className,
      )}
    >
      <Lock className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
      <div>
        <p className="font-medium text-text-primary">
          {message ?? 'No tienes permiso para esta acción.'}
        </p>
        {permission && (
          <p className="mt-0.5 text-xs text-text-muted">
            Permiso requerido: <code className="rounded bg-bg px-1 py-0.5">{permission}</code>
          </p>
        )}
      </div>
    </div>
  );
}