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
 * Formatea una fecha efectiva (ej. tasa de cambio) para mostrar en formato legible
 * DD/MM/YYYY (o DD/MM/YYYY h:mm A si incluye hora distinta de medianoche),
 * evitando el desfase de zona horaria cuando la fecha proviene de medianoche UTC (00:00:00Z).
 *
 * @example
 *   formatEffectiveDate('2026-10-20T00:00:00.000000Z') // "20/10/2026"
 *   formatEffectiveDate('2026-10-20')                   // "20/10/2026"
 *   formatEffectiveDate('2026-10-20 16:40:00')          // "20/10/2026 4:40 PM"
 */
export function formatEffectiveDate(dateStr: string | null | undefined): string {
  if (!dateStr) return '—';
  const clean = String(dateStr).trim();
  const match = clean.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  if (!match) {
    return dateStr;
  }

  const [, year, month, day, hour, minute] = match;
  const hasTime = hour !== undefined && minute !== undefined && (hour !== '00' || minute !== '00');
  const dateFormatted = `${day}/${month}/${year}`;

  if (hasTime) {
    const h = parseInt(hour, 10);
    const m = minute;
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${dateFormatted} ${h12}:${m} ${ampm}`;
  }

  return dateFormatted;
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

/**
 * Retorna la fecha local en formato YYYY-MM-DD según el calendario local del usuario (Venezuela),
 * evitando el desfase de toISOString() que convierte a UTC y adelanta 1 día a partir de las 8:00 PM.
 *
 * @example
 *   todayDateString() // "2026-09-17"
 */
export function getLocalDateString(date: Date = new Date()): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function todayDateString(): string {
  return getLocalDateString(new Date());
}