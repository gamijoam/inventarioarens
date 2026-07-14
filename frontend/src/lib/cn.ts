import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Combina classNames con clsx + mergea conflictos de Tailwind con tailwind-merge.
 * Ej: cn('px-2 py-1', condition && 'bg-primary', className)
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}