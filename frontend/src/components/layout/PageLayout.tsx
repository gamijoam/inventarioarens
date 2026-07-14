import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PageLayoutProps {
  title: string;
  description?: string;
  actions?: ReactNode;
  breadcrumb?: ReactNode;
  children: ReactNode;
  className?: string;
}

/** Layout estandar para paginas de feature. */
export function PageLayout({ title, description, actions, breadcrumb, children, className }: PageLayoutProps) {
  return (
    <div className={cn('flex flex-col gap-6', className)}>
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1">
          {breadcrumb}
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
          {description && <p className="text-sm text-text-muted">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </header>
      <div>{children}</div>
    </div>
  );
}