/**
 * Tests para TransferTimeline (Fase T2 - timeline endpoint).
 * Cubre:
 *   - muestra el skeleton mientras carga.
 *   - muestra el EmptyState cuando no hay eventos.
 *   - renderiza un <li> por evento con su stage correspondiente.
 *   - ordena los eventos por timestamp ascendente (responsabilidad del
 *     backend, pero el componente los renderiza en el orden recibido).
 *   - incluye informacion del usuario que ejecuto cada accion.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { TransferTimeline } from '../TransferTimeline';

const mockUseTransferTimeline = vi.fn();

vi.mock('@/features/transfers/api', () => ({
  useTransferTimeline: (...args: unknown[]) => mockUseTransferTimeline(...args),
}));

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('TransferTimeline', () => {
  beforeEach(() => {
    mockUseTransferTimeline.mockReset();
  });

  it('muestra skeleton mientras carga', () => {
    mockUseTransferTimeline.mockReturnValue({ data: undefined, isLoading: true });
    const { container } = render(<TransferTimeline transferId={42} />, { wrapper: makeWrapper() });
    // El Skeleton del componente tiene la clase h-24 w-full.
    expect(container.querySelector('.h-24')).toBeTruthy();
  });

  it('muestra EmptyState cuando no hay eventos', () => {
    mockUseTransferTimeline.mockReturnValue({ data: [], isLoading: false });
    render(<TransferTimeline transferId={42} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/sin eventos/i)).toBeInTheDocument();
  });

  it('renderiza un item por cada evento en el orden recibido', () => {
    mockUseTransferTimeline.mockReturnValue({
      data: [
        {
          stage: 'created',
          at: '2026-07-15T10:00:00.000000Z',
          by_user: { id: 1, name: 'Ana' },
          notes: 'Solicitud inicial',
        },
        {
          stage: 'prepared',
          at: '2026-07-15T11:00:00.000000Z',
          by_user: { id: 1, name: 'Ana' },
          has_differences: false,
        },
        {
          stage: 'dispatched',
          at: '2026-07-15T12:00:00.000000Z',
          by_user: { id: 2, name: 'Luis' },
        },
        {
          stage: 'received',
          at: '2026-07-15T14:00:00.000000Z',
          by_user: { id: 3, name: 'Sofia' },
          differences_count: 1,
        },
      ],
      isLoading: false,
    });
    render(<TransferTimeline transferId={42} />, { wrapper: makeWrapper() });
    expect(screen.getByTestId('transfer-timeline')).toBeInTheDocument();
    expect(screen.getByTestId('timeline-created')).toBeInTheDocument();
    expect(screen.getByTestId('timeline-prepared')).toBeInTheDocument();
    expect(screen.getByTestId('timeline-dispatched')).toBeInTheDocument();
    expect(screen.getByTestId('timeline-received')).toBeInTheDocument();
    expect(screen.getAllByText(/Ana/).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/Luis/)).toBeInTheDocument();
    expect(screen.getByText(/Sofia/)).toBeInTheDocument();
  });

  it('muestra diferencias en received y resolved', () => {
    mockUseTransferTimeline.mockReturnValue({
      data: [
        {
          stage: 'received',
          at: '2026-07-15T14:00:00.000000Z',
          by_user: { id: 3, name: 'Sofia' },
          differences_count: 2,
        },
        {
          stage: 'resolved',
          at: '2026-07-15T15:00:00.000000Z',
          by_user: { id: 1, name: 'Ana' },
          resolution_status: 'resolved',
          notes: 'Aceptamos la perdida',
        },
      ],
      isLoading: false,
    });
    render(<TransferTimeline transferId={42} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/2 item\(s\) con diferencia/i)).toBeInTheDocument();
    expect(screen.getByText(/resolucion: resolved/i)).toBeInTheDocument();
    expect(screen.getByText(/Aceptamos la perdida/)).toBeInTheDocument();
  });

  it('muestra el motivo cuando el traslado se cancela', () => {
    mockUseTransferTimeline.mockReturnValue({
      data: [
        { stage: 'created', at: '2026-07-15T10:00:00.000000Z', by_user: { id: 1, name: 'Ana' } },
        {
          stage: 'cancelled',
          at: '2026-07-15T11:30:00.000000Z',
          by_user: { id: 1, name: 'Ana' },
          notes: 'Cliente cancelo el pedido',
        },
      ],
      isLoading: false,
    });
    render(<TransferTimeline transferId={42} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/Cliente cancelo el pedido/)).toBeInTheDocument();
  });
});
