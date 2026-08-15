import { formatMoney } from '@/lib/money';

export function formatControlNumber(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '-';

  const number = Number(value);
  if (!Number.isFinite(number)) return '-';

  return number.toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function formatControlPayment(
  amount: string | number | null | undefined,
  currency: string,
): string {
  const formatted = formatMoney(
    { amount: String(amount ?? ''), currency: currency === 'VES' ? 'VES' : 'USD' },
    { showCurrency: false },
  );

  return formatted === '—' ? '-' : `${formatted} ${currency}`;
}
