/**
 * Tests del CashPanel rediseñado del POS (panel "Caja").
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CashPanel } from '../PosTerminal';
import type { CashRegisterSession } from '../api';

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

const baseProps = {
  canMove: true,
  canClose: true,
  movement: { type: 'outflow', amount: '', notes: '' },
  closingAmount: '',
  onMovementChange: vi.fn(),
  onClosingAmount: vi.fn(),
  onAddMovement: vi.fn(),
  onCloseSession: vi.fn(),
};

describe('CashPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('muestra el hero del turno activo con caja, sesion, sucursal y cajero', () => {
    render(<CashPanel session={makeSession()} {...baseProps} />);

    expect(screen.getByText('Turno activo')).toBeInTheDocument();
    expect(screen.getByText('Caja 1')).toBeInTheDocument();
    expect(screen.getByText('Sesión #7')).toBeInTheDocument();
    expect(screen.getByText('Sucursal Central')).toBeInTheDocument();
    expect(screen.getByText('María Pérez')).toBeInTheDocument();
    expect(screen.getByText('Turno abierto')).toBeInTheDocument();
  });

  it('muestra fondo inicial y esperado formateados', () => {
    render(<CashPanel session={makeSession()} {...baseProps} />);

    expect(screen.getByText('Fondo inicial')).toBeInTheDocument();
    expect(screen.getByText('Esperado')).toBeInTheDocument();
    expect(screen.getByText(/\$100\.00/)).toBeInTheDocument();
    expect(screen.getByText(/\$250\.50/)).toBeInTheDocument();
  });

  it('muestra la diferencia de cierre cuando la sesion ya fue contada', () => {
    render(
      <CashPanel
        session={makeSession({ counted_base_amount: 240, difference_base_amount: -10.5 })}
        {...baseProps}
      />,
    );

    expect(screen.getByTestId('pos-cash-difference')).toBeInTheDocument();
    expect(screen.getByText(/-?\$10\.50/)).toBeInTheDocument();
  });

  it('no muestra diferencia si aun no se conto', () => {
    render(<CashPanel session={makeSession()} {...baseProps} />);
    expect(screen.queryByTestId('pos-cash-difference')).not.toBeInTheDocument();
  });

  it('registra movimiento y cierra turno desde sus secciones', () => {
    render(<CashPanel session={makeSession()} {...baseProps} />);

    fireEvent.click(screen.getByTestId('pos-cash-movement-submit'));
    expect(baseProps.onAddMovement).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByTestId('pos-cash-close-submit'));
    expect(baseProps.onCloseSession).toHaveBeenCalledTimes(1);
  });
});
