/**
 * Tests del CashPanel del POS (panel "Caja").
 * - Cierre ciego por defecto: oculta Esperado y diferencias.
 * - Modo standard (toggle) muestra Esperado y diferencias.
 * - Movimientos y cierre de turno.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CashPanel } from '../PosTerminal';
import type { CashRegisterSession } from '../api';
import type { CloseForm } from '../CashRegisterSetup';

function makeSession(overrides: Partial<CashRegisterSession> = {}): CashRegisterSession {
  return {
    id: 7,
    tenant_id: 1,
    branch_id: 1,
    cash_register_id: 2,
    cashier_id: 3,
    status: 'open',
    opening_base_amount: 100,
    expected_base_amount: 250.5,
    counted_base_amount: null,
    difference_base_amount: null,
    opened_at: '2026-08-20T14:00:00Z',
    cash_register: { id: 2, name: 'Caja 1', code: 'C1' },
    branch: { id: 1, name: 'Sucursal Central', code: 'SC' },
    cashier: { id: 3, name: 'María Pérez' },
    ...overrides,
  } as CashRegisterSession;
}

function makeCloseForm(overrides: Partial<CloseForm> = {}): CloseForm {
  return {
    sessionId: 7,
    usd: '',
    ves: '',
    notes: '',
    counts: [],
    blind: true,
    ...overrides,
  };
}

function makeProps(overrides: Record<string, unknown> = {}) {
  return {
    canMove: true,
    canClose: true,
    movement: { type: 'outflow', amount: '', notes: '' },
    closeForm: makeCloseForm(),
    rate: null,
    closing: false,
    onMovementChange: vi.fn(),
    onCloseForm: vi.fn(),
    onAddMovement: vi.fn(),
    onCloseSession: vi.fn(),
    ...overrides,
  };
}

describe('CashPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('muestra el hero del turno activo con caja, sesion, sucursal y cajero', () => {
    render(<CashPanel session={makeSession()} {...makeProps()} />);

    expect(screen.getByText('Turno activo')).toBeInTheDocument();
    expect(screen.getByText('Caja 1')).toBeInTheDocument();
    expect(screen.getByText('Sesión #7')).toBeInTheDocument();
    expect(screen.getByText('Sucursal Central')).toBeInTheDocument();
    expect(screen.getByText('María Pérez')).toBeInTheDocument();
    expect(screen.getByText('Turno abierto')).toBeInTheDocument();
  });

  it('por defecto el cierre es ciego: oculta Esperado y diferencias', () => {
    render(<CashPanel session={makeSession()} {...makeProps()} />);

    expect(screen.getByText('Fondo inicial')).toBeInTheDocument();
    expect(screen.getByText('Cierre ciego activo')).toBeInTheDocument();
    expect(screen.queryByText('Esperado')).not.toBeInTheDocument();
    expect(screen.queryByText('Esperado USD')).not.toBeInTheDocument();
    expect(screen.queryByText('Diferencia física USD')).not.toBeInTheDocument();
  });

  it('en modo standard muestra el esperado y la diferencia de cierre', () => {
    const props = makeProps({
      closeForm: makeCloseForm({ blind: false }),
    });
    render(
      <CashPanel
        session={makeSession({ counted_base_amount: 240, difference_base_amount: -10.5 })}
        {...props}
      />,
    );

    expect(screen.getByText('Esperado')).toBeInTheDocument();
    expect(screen.getByTestId('pos-cash-difference')).toBeInTheDocument();
    expect(screen.getByText(/10\.50/)).toBeInTheDocument();
  });

  it('el toggle de cierre ciego cambia el modo', () => {
    const onCloseForm = vi.fn();
    const props = makeProps({ onCloseForm });
    render(<CashPanel session={makeSession()} {...props} />);

    fireEvent.click(screen.getByTestId('pos-cash-blind-toggle'));

    expect(onCloseForm).toHaveBeenCalledWith(expect.objectContaining({ blind: false }));
  });

  it('no muestra diferencia si aun no se conto (modo standard)', () => {
    render(
      <CashPanel
        session={makeSession()}
        {...makeProps({ closeForm: makeCloseForm({ blind: false }) })}
      />,
    );
    expect(screen.queryByTestId('pos-cash-difference')).not.toBeInTheDocument();
  });

  it('registra movimiento y cierra turno desde sus secciones', () => {
    const props = makeProps({
      closeForm: makeCloseForm({ notes: 'cierre normal' }),
    });
    render(<CashPanel session={makeSession()} {...props} />);

    fireEvent.click(screen.getByTestId('pos-cash-movement-submit'));
    expect(props.onAddMovement).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByTestId('pos-cash-close-submit'));
    expect(props.onCloseSession).toHaveBeenCalledTimes(1);
  });

  it('la cantidad de una denominacion se ingresa tal cual (1 en el 10 = 1, no 100)', () => {
    const onCloseForm = vi.fn();
    render(
      <CashPanel
        session={makeSession()}
        {...makeProps({ onCloseForm })}
      />,
    );

    const qty = screen.getByLabelText('Cantidad de 10 USD');
    fireEvent.change(qty, { target: { value: '1' } });

    expect(onCloseForm).toHaveBeenCalledWith(
      expect.objectContaining({
        counts: [{ currency: 'USD', denomination: 10, quantity: 1 }],
      }),
    );
  });

  it('el total en USD suma las denominaciones ingresadas', () => {
    render(
      <CashPanel
        session={makeSession()}
        {...makeProps({
          closeForm: makeCloseForm({
            counts: [
              { currency: 'USD', denomination: 10, quantity: 1 },
              { currency: 'USD', denomination: 50, quantity: 2 },
            ],
          }),
        })}
      />,
    );

    // 10*1 + 50*2 = 110
    const usdInput = screen.getByTestId('pos-cash-closing-amount') as HTMLInputElement;
    expect(usdInput.value).toBe('110');
  });
});
