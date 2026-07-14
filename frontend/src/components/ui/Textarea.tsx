import { forwardRef, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, invalid, rows = 3, ...props }, ref) => {
    return (
      <textarea
        ref={ref}
        rows={rows}
        aria-invalid={invalid ?? undefined}
        className={cn(
          'flex w-full rounded border bg-surface px-3 py-2 text-sm shadow-sm transition-colors',
          'placeholder:text-text-muted',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'resize-y min-h-[72px]',
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
Textarea.displayName = 'Textarea';