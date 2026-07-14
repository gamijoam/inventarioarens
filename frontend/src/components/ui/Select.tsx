import { forwardRef, type SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * Select HTML nativo estilado (no confundir con el wrapper Radix Select).
 * Para casos donde un <select multiple> o un dropdown simple es suficiente.
 * Para typeahead / multi-select usa Combobox o TreeSelect.
 */
export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, invalid, children, ...props }, ref) => {
    return (
      <select
        ref={ref}
        aria-invalid={invalid ?? undefined}
        className={cn(
          'flex h-9 w-full rounded border bg-surface px-3 text-sm shadow-sm transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
          'disabled:cursor-not-allowed disabled:opacity-50',
          invalid
            ? 'border-danger focus-visible:ring-danger'
            : 'border-border-strong hover:border-text-muted',
          className,
        )}
        {...props}
      >
        {children}
      </select>
    );
  },
);
Select.displayName = 'Select';