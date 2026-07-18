import type { CurrentExchangeRate } from '@/features/pos/api';

export interface BaseBalance {
  balance_base_amount: number;
}

export function activeUsdVesRate(rates: CurrentExchangeRate[]): CurrentExchangeRate | null {
  return (
    rates.find(
      (rate) =>
        rate.is_active !== false && rate.base_currency === 'USD' && rate.quote_currency === 'VES',
    ) ?? null
  );
}

export function currentLocalBalance(
  balance: BaseBalance,
  rate: CurrentExchangeRate | null,
): number | null {
  return rate && rate.rate > 0 ? balance.balance_base_amount * rate.rate : null;
}

export function numberLabel(value: number | null | undefined): string {
  return Number(value ?? 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function rateLabel(rate: CurrentExchangeRate | null): string {
  if (!rate || rate.rate <= 0) return 'Sin tasa activa USD/VES';
  return `${rate.exchange_rate_type_code ?? 'Tasa'} @ ${numberLabel(rate.rate)}`;
}
