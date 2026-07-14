/**
 * Helpers para dinero con snapshot de tasa.
 * El backend Laravel devuelve montos como string (decimal) en JSON para evitar float issues.
 *
 * Ver docs/FRONTEND_ARQUITECTURA.md §11.
 */

export type Currency = 'USD' | 'VES';

export interface Money {
  amount: string;
  currency: Currency;
}

export interface MoneyWithRate extends Money {
  base_amount?: string | null;
  base_currency?: 'USD';
  exchange_rate?: string | null;
  exchange_rate_type_code?: string | null;
  exchange_rate_type_id?: number | null;
}

/**
 * Formatea un monto para mostrar.
 * - Si el valor es null/undefined, devuelve '—' (campo enmascarado por backend o no aplica).
 * - Si no tiene moneda especificada, asume USD.
 *
 * @example
 *   formatMoney('1234.56')                  // "$1,234.56"
 *   formatMoney({ amount: '1234.56', currency: 'VES' })  // "Bs 1.234,56"
 *   formatMoney(null)                       // "—"
 */
export function formatMoney(
  value: string | number | Money | null | undefined,
  options: { showCurrency?: boolean } = {}
): string {
  if (value === null || value === undefined) return '—';

  const amount = typeof value === 'object' ? value.amount : String(value);
  const currency = typeof value === 'object' ? value.currency : 'USD';

  const num = parseFloat(amount);
  if (Number.isNaN(num)) return '—';

  const formatted = new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num);

  if (options.showCurrency === false) return formatted;

  return currency === 'USD' ? `$${formatted}` : `Bs ${formatted}`;
}

/**
 * Formatea un monto mostrando también su equivalente en USD cuando hay snapshot de tasa.
 *
 * @example
 *   formatMoneyWithRate({
 *     amount: '120000.00',
 *     currency: 'VES',
 *     base_amount: '3.20',
 *     exchange_rate: '37500',
 *   })
 *   // → "Bs 120.000,00 ($3.20 USD @ 37.500,00)"
 */
export function formatMoneyWithRate(money: MoneyWithRate | null | undefined): string {
  if (!money) return '—';
  const local = formatMoney(money);

  if (!money.base_amount || !money.exchange_rate) return local;

  const baseFormatted = new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseFloat(money.base_amount));

  const rate = parseFloat(money.exchange_rate);
  const formattedRate = Number.isFinite(rate)
    ? new Intl.NumberFormat('es-VE').format(rate)
    : money.exchange_rate;

  return `${local} ($${baseFormatted} USD @ ${formattedRate})`;
}

/**
 * Formatea específicamente costos sensibles (unit_cost, total_cost, etc.).
 * El backend enmascara estos campos con null cuando el user no tiene
 * `finance.costs.view`. Esta funcion es el helper canonico para esos casos.
 *
 * @example
 *   formatCost(item.unit_cost)  // "$120.50" o "—" si es null
 */
export function formatCost(value: string | number | null | undefined): string {
  return formatMoney(value);
}