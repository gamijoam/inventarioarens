const CASH_MOVEMENT_TYPES: Record<string, string> = {
  opening: 'Apertura',
  inflow: 'Entrada',
  outflow: 'Salida',
  pos_payment: 'Pago POS',
  adjustment: 'Ajuste',
};

const CASH_MOVEMENT_METHODS: Record<string, string> = {
  cash: 'Efectivo',
  card: 'Tarjeta',
  mobile_payment: 'Pago móvil',
  transfer: 'Transferencia',
  zelle: 'Zelle',
  external_financing: 'Financiamiento',
  customer_credit: 'Saldo a favor',
  other: 'Otro',
};

export function cashMovementTypeLabel(type?: string | null): string {
  return CASH_MOVEMENT_TYPES[type ?? ''] ?? type ?? 'Movimiento';
}

export function cashMovementMethodLabel(method?: string | null): string {
  return CASH_MOVEMENT_METHODS[method ?? ''] ?? method ?? 'Sin método';
}

export function cashMovementLabel(type?: string | null, method?: string | null): string {
  return `${cashMovementTypeLabel(type)} - ${cashMovementMethodLabel(method)}`;
}
