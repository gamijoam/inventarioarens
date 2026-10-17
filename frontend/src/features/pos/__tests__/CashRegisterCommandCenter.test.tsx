import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockUseCashSessionsPage = vi.fn();
const mockUseCashSessionDetail = vi.fn();
const mockUseReviewCashSession = vi.fn();
const mockCan = vi.fn();

vi.mock('@/permissions/useCan', () => ({
  useCan: (...args: unknown[]) => mockCan(...args) as unknown,
}));

vi.mock('../api', () => ({
  useCashSessionsPage: (...args: unknown[]) => mockUseCashSessionsPage(...args) as unknown,
  useCashSessionDetail: (...args: unknown[]) => mockUseCashSessionDetail(...args) as unknown,
  useReviewCashSession: () => mockUseReviewCashSession() as unknown,
}));

vi.mock('@/features/cash-register/ReportZDialog', () => ({
  ReportZDialog: ({ open, sessionId, onOpenChange }: { open: boolean; sessionId: number; onOpenChange: (open: boolean) => void }) =>
    open ? (
      <div role="dialog" aria-label="Reporte Z">
        Reporte Z del turno {sessionId}
        <button type="button" onClick={() => onOpenChange(false)}>Cerrar reporte</button>
      </div>
    ) : null,
}));

import { CashRegisterCommandCenter } from '../CashRegisterCommandCenter';

function makeSession(id: number, status: 'open' | 'closed' = 'closed') {
  return {
    id,
    status,
    branch_id: 1,
    cash_register_id: 1,
    cashier_id: 7,
    opening_base_amount: 50,
    opening_local_amount: 1000,
    expected_base_amount: 125,
    expected_local_amount: 4000,
    expected_cash_usd: 125,
    expected_cash_ves: 4000,
    counted_base_amount: status === 'closed' ? 123 : null,
    counted_local_amount: status === 'closed' ? 4000 : null,
    counted_cash_usd: status === 'closed' ? 123 : null,
    counted_cash_ves: status === 'closed' ? 4000 : null,
    difference_base_amount: status === 'closed' ? -2 : null,
    difference_local_amount: status === 'closed' ? 0 : null,
    difference_cash_usd: status === 'closed' ? -2 : null,
    difference_cash_ves: status === 'closed' ? 0 : null,
    review_status: 'pending' as const,
    opened_at: '2026-07-27T08:00:00Z',
    closed_at: status === 'closed' ? '2026-07-27T16:00:00Z' : null,
    cash_register: { id: 1, name: id === 7 ? 'Caja Principal' : 'Caja Secundaria', code: 'C1', branch_id: 1 },
    branch: { id: 1, name: 'Centro', code: 'CTR' },
    cashier: { id: 7, name: id === 7 ? 'Ana' : 'Luis', email: 'cajero@example.test' },
    movements: [
      {
        id: id * 10,
        type: 'outflow',
        method: 'cash',
        currency: 'USD',
        amount: 5,
        amount_base: 5,
        amount_local: 0,
        notes: 'Compra de insumos',
        created_at: '2026-07-27T12:00:00Z',
      },
    ],
  };
}

function pageResult(rows: ReturnType<typeof makeSession>[], total = rows.length) {
  return {
    data: {
      data: rows,
      meta: { current_page: 1, last_page: 1, per_page: 50, total },
    },
    isLoading: false,
    isFetching: false,
    isError: false,
    refetch: vi.fn(),
  };
}

describe('<CashRegisterCommandCenter>', () => {
  beforeEach(() => {
    mockUseCashSessionsPage.mockReset();
    mockUseCashSessionsPage.mockImplementation((filters: { status?: string }) =>
      pageResult(filters.status === 'open' ? [makeSession(8, 'open')] : [makeSession(7), makeSession(8)]),
    );
    mockUseCashSessionDetail.mockReturnValue({ isLoading: false, data: undefined });
    mockUseReviewCashSession.mockReturnValue({ mutate: vi.fn(), isPending: false });
    mockCan.mockReturnValue(true);
  });

  it('muestra turnos en tarjetas compactas, detalle y el mismo visor de Reporte Z', () => {
    render(
      <CashRegisterCommandCenter
        branches={[{ id: 1, name: 'Centro', code: 'CTR' }]}
        registers={[{ id: 1, name: 'Caja Principal', code: 'C1', branch_id: 1 }]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Turnos' })).toBeInTheDocument();
    expect(screen.getByText('Caja Principal')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Turno #7/ }));
    expect(screen.getByText('Resumen del turno #7')).toBeInTheDocument();
    expect(screen.getByText(/Salida · Compra de insumos/)).toBeInTheDocument();
    expect(screen.getByLabelText('Nota de revisión')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Reporte Z' }));
    expect(screen.getByRole('dialog', { name: 'Reporte Z' })).toHaveTextContent('turno 7');
  });

  it('filtra en una lista única por estado y conserva el contexto de lectura', () => {
    render(<CashRegisterCommandCenter branches={[]} registers={[]} />);

    fireEvent.change(screen.getByLabelText('Estado de los turnos'), { target: { value: 'open' } });

    expect(mockUseCashSessionsPage).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'open' }),
      expect.any(Object),
    );
    expect(screen.getByRole('button', { name: /Turno #8/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Turno #7/ })).not.toBeInTheDocument();
  });

  it('limpia la nota de revisión al cambiar de turno expandido', () => {
    render(<CashRegisterCommandCenter branches={[]} registers={[]} />);

    fireEvent.click(screen.getByRole('button', { name: /Turno #7/ }));
    fireEvent.change(screen.getByLabelText('Nota de revisión'), { target: { value: 'Revisar factura' } });
    fireEvent.click(screen.getByRole('button', { name: /Turno #8/ }));

    expect(screen.getByLabelText('Nota de revisión')).toHaveValue('');
  });

  it('distingue un error de carga de una lista vacía y permite reintentar', () => {
    const refetch = vi.fn();
    mockUseCashSessionsPage.mockReturnValue({
      data: undefined,
      isLoading: false,
      isFetching: false,
      isError: true,
      refetch,
    });

    render(<CashRegisterCommandCenter branches={[]} registers={[]} />);

    expect(screen.getByText('No se pudieron cargar los turnos. Intenta nuevamente.')).toBeInTheDocument();
    expect(screen.queryByText('No hay turnos registrados.')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
    expect(refetch).toHaveBeenCalled();
  });

  it('muestra estado vacío y oculta la revisión para usuarios de solo lectura', () => {
    mockUseCashSessionsPage.mockReturnValue(pageResult([]));
    mockCan.mockImplementation((permission: string) => permission === 'cash_register.view');

    render(<CashRegisterCommandCenter branches={[]} registers={[]} />);

    expect(screen.getByText('No hay turnos registrados.')).toBeInTheDocument();
    expect(screen.queryByLabelText('Nota de revisión')).not.toBeInTheDocument();
  });
});
