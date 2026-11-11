import type { CashMovementPayload } from './api';

export type CashMovementFormType = 'inflow' | 'outflow' | 'adjustment_in' | 'adjustment_out';

export const CASH_MOVEMENT_TYPE_OPTIONS: { value: CashMovementFormType; label: string }[] = [
  { value: 'inflow', label: 'Entrada' },
  { value: 'outflow', label: 'Salida' },
  { value: 'adjustment_in', label: 'Ajuste sobrante (+)' },
  { value: 'adjustment_out', label: 'Ajuste faltante (-)' },
];

/**
 * Traduce el tipo elegido en el formulario al contrato del backend: un ajuste
 * por faltante viaja como `adjustment` con monto negativo.
 */
export function toCashMovementRequest(
  formType: string,
  amount: number,
): Pick<CashMovementPayload, 'type' | 'amount'> {
  const value = Math.abs(amount);
  if (formType === 'adjustment_out') return { type: 'adjustment', amount: -value };
  if (formType === 'adjustment_in' || formType === 'adjustment') {
    return { type: 'adjustment', amount: value };
  }
  return { type: formType === 'inflow' ? 'inflow' : 'outflow', amount: value };
}

export type CloseConfirmationField = 'confirm_zero_count' | 'confirm_open_orders';

/**
 * El backend pide confirmacion explicita al cerrar con conteo en cero o con
 * ordenes pendientes que ya tienen abonos. Devuelve el campo a confirmar y el
 * mensaje a mostrar al usuario.
 */
export function closeConfirmationRequired(
  error: unknown,
): { field: CloseConfirmationField; message: string } | null {
  const fieldErrors = (error as { fieldErrors?: Record<string, string[]> } | null)?.fieldErrors;
  if (!fieldErrors) return null;

  for (const field of ['confirm_open_orders', 'confirm_zero_count'] as const) {
    const message = fieldErrors[field]?.[0];
    if (message) return { field, message };
  }

  return null;
}

const LONG_OPEN_SESSION_HOURS = 24;

/**
 * Un turno abierto por dias mezcla jornadas en un solo arqueo y oculta
 * faltantes. Devuelve el aviso a mostrar o null si el turno es reciente.
 */
export function openSessionAgeWarning(
  openedAt: string | null | undefined,
  now: Date = new Date(),
): string | null {
  if (!openedAt) return null;
  const opened = new Date(openedAt);
  if (Number.isNaN(opened.getTime())) return null;

  const hours = (now.getTime() - opened.getTime()) / 3_600_000;
  if (hours < LONG_OPEN_SESSION_HOURS) return null;

  const days = Math.floor(hours / 24);
  const age = days >= 2 ? `${days} días` : `${Math.floor(hours)} horas`;

  return `Este turno lleva ${age} abierto. Ciérralo y abre uno nuevo cada jornada para que el arqueo cuadre por día.`;
}

export function sessionHasCashDifference(session: {
  difference_cash_usd?: number | null;
  difference_cash_ves?: number | null;
}): boolean {
  return (
    Math.abs(Number(session.difference_cash_usd ?? 0)) >= 0.01 ||
    Math.abs(Number(session.difference_cash_ves ?? 0)) >= 0.01
  );
}
