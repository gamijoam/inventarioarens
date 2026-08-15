export interface ControlColumn {
  key: string;
  label: string;
  default_visible: boolean;
}

export type ControlPreset = 'full' | 'money' | 'payments' | 'commission_ves';

export function defaultControlColumns(columns: ControlColumn[]): string[] {
  return columns.filter((column) => column.default_visible).map((column) => column.key);
}

export function presetControlColumns(columns: ControlColumn[], preset: ControlPreset): string[] {
  const keys = columns.map((column) => column.key);
  if (preset === 'full') return keys;

  if (preset === 'money') {
    return keys.filter((key) =>
      ['quantity', 'product', 'amount_usd', 'amount_ves', 'equivalent_usd', 'total'].includes(key),
    );
  }

  if (preset === 'payments') {
    return keys.filter(
      (key) =>
        key === 'quantity' ||
        key === 'product' ||
        key.startsWith('payment_method_') ||
        key === 'total',
    );
  }

  return keys.filter((key) =>
    ['quantity', 'product', 'commission_ves', 'total', 'seller', 'date'].includes(key),
  );
}

export function reconcileControlColumns(columns: ControlColumn[], savedKeys: string[]): string[] {
  const available = new Set(columns.map((column) => column.key));
  return savedKeys.filter((key) => available.has(key));
}
