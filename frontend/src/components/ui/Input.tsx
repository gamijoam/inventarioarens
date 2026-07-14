import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, invalid, type = 'text', ...props }, ref) => {
    return (
      <input
        ref={ref}
        type={type}
        aria-invalid={invalid ?? undefined}
        className={cn(
          'flex h-9 w-full rounded border bg-surface px-3 py-1 text-sm shadow-sm transition-colors',
          'placeholder:text-text-muted',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'file:border-0 file:bg-transparent file:text-sm file:font-medium',
          invalid
            ? 'border-danger focus-visible:ring-danger'
            : 'border-border-strong hover:border-text-muted',
          className,
        )}
        {...props}
      />
    );
  },
);
Input.displayName = 'Input';