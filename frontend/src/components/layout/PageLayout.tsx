import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PageLayoutProps {
  title: string;
  description?: string;
  icon?: ReactNode;
  actions?: ReactNode;
  breadcrumb?: ReactNode;
  children: ReactNode;
  className?: string;
}

/** Layout estandar para paginas de feature. */
export function PageLayout({
  title,
  description,
  icon,
  actions,
  breadcrumb,
  children,
  className,
}: PageLayoutProps) {
  return (
    <div className={cn('flex flex-col gap-6', className)}>
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1.5">
          {breadcrumb}
          <h1 className="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
            {icon}
            {title}
          </h1>
          {description && <p className="text-sm text-text-muted sm:text-base">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2.5">{actions}</div>}
      </header>
      <div className="w-full">{children}</div>
    </div>
  );
}