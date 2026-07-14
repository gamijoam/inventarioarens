import { format, formatDistanceToNow, parseISO } from 'date-fns';
import { es } from 'date-fns/locale';

/**
 * Formatea una fecha ISO 8601 para mostrar.
 *
 * @example
 *   formatDate('2026-07-13T15:30:00Z')  // "13 jul 2026"
 */
export function formatDate(iso: string | Date | null | undefined, pattern = 'PPP'): string {
  if (!iso) return '—';
  const date = typeof iso === 'string' ? parseISO(iso) : iso;
  if (Number.isNaN(date.getTime())) return '—';
  return format(date, pattern, { locale: es });
}

/**
 * Formatea fecha + hora corta.
 *
 * @example
 *   formatDateTime('2026-07-13T15:30:00Z')  // "13 jul 2026, 15:30"
 */
export function formatDateTime(iso: string | Date | null | undefined): string {
  return formatDate(iso, "PPP p");
}

/**
 * "Hace X tiempo" localizado en español.
 *
 * @example
 *   formatRelative('2026-07-13T15:30:00Z')  // "hace 2 horas"
 */
export function formatRelative(iso: string | Date | null | undefined): string {
  if (!iso) return '—';
  const date = typeof iso === 'string' ? parseISO(iso) : iso;
  if (Number.isNaN(date.getTime())) return '—';
  return formatDistanceToNow(date, { addSuffix: true, locale: es });
}

/**
 * Formatea un número como entero con separador de miles.
 *
 * @example
 *   formatNumber(1234)  // "1.234"
 *   formatNumber('1234.56')  // "1.234,56"
 */
export function formatNumber(value: string | number | null | undefined, decimals = 0): string {
  if (value === null || value === undefined) return '—';
  const num = typeof value === 'string' ? parseFloat(value) : value;
  if (Number.isNaN(num)) return '—';
  return new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num);
}