import { forwardRef, type HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export interface AlertProps extends HTMLAttributes<HTMLDivElement> {
  variant?: 'info' | 'success' | 'warning' | 'danger';
}

const variantClasses = {
  info: 'border-info/30 bg-info/5 text-info',
  success: 'border-success/30 bg-success/5 text-success',
  warning: 'border-warning/30 bg-warning/5 text-warning',
  danger: 'border-danger/30 bg-danger/5 text-danger',
};

export const Alert = forwardRef<HTMLDivElement, AlertProps>(
  ({ className, variant = 'info', ...props }, ref) => (
    <div
      ref={ref}
      role="alert"
      className={cn(
        'rounded-lg border px-4 py-3 text-sm',
        variantClasses[variant],
        className,
      )}
      {...props}
    />
  ),
);
Alert.displayName = 'Alert';

export function AlertTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return <h4 className={cn('mb-1 font-medium leading-none', className)} {...props} />;
}

export function AlertDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <div className={cn('text-sm [&_p]:leading-relaxed', className)} {...props} />;
}