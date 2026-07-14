import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface SpinnerProps {
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  label?: string;
}

export function Spinner({ size = 'md', className, label }: SpinnerProps) {
  const sizeClass = size === 'sm' ? 'size-4' : size === 'lg' ? 'size-8' : 'size-6';

  return (
    <div className={cn('inline-flex items-center gap-2 text-text-muted', className)} role="status">
      <Loader2 className={cn(sizeClass, 'animate-spin text-primary')} aria-hidden="true" />
      {label && <span className="text-sm">{label}</span>}
      <span className="sr-only">{label ?? 'Cargando'}</span>
    </div>
  );
}