/**
 * Tests para la ruta /inventory-transfer-requests/$requestId (detalle).
 *
 * Cubre:
 *   - Loading skeleton mientras carga.
 *   - EmptyState cuando el request no existe.
 *   - Header con badge de estado + acciones contextuales segun rol.
 *   - NO muestra acciones cuando status es terminal.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const mockUseTransferRequest = vi.fn();
const mockUseAcceptTransferRequest = vi.fn();
const mockUseCancelTransferRequest = vi.fn();
const mockNavigate = vi.fn();

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useTransferRequest: (...args: unknown[]) => mockUseTransferRequest(...args),
  useAcceptTransferRequest: () => ({
    mutateAsync: mockUseAcceptTransferRequest,
    isPending: false,
  }),
  useCancelTransferRequest: () => ({
    mutate: mockUseCancelTransferRequest,
    isPending: false,
  }),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: <T,>(selector: (s: { tenant?: { id: number } | null }) => T): T =>
    selector({ tenant: { id: 1 } } as unknown as { tenant: { id: number } | null }),
}));

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ children, ...props }: { children: React.ReactNode }) => (
    <a {...props}>{children}</a>
  ),
  Outlet: () => null,
  createFileRoute: () => () => ({}),
}));

// Mockeamos los dialogs para no necesitar su implementacion aqui.
vi.mock('@/features/inventory-transfer-requests/components/AcceptInventoryTransferRequestDialog', () => ({
  AcceptInventoryTransferRequestDialog: () => null,
}));
vi.mock('@/features/inventory-transfer-requests/components/RejectInventoryTransferRequestDialog', () => ({
  RejectInventoryTransferRequestDialog: () => null,
}));

import { TransferRequestDetailInner } from '../$requestId';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeRequest(overrides: Partial<{
  id: number;
  origin_tenant_id: number;
  destination_tenant_id: number;
  status: 'requested' | 'completed' | 'rejected' | 'cancelled';
  document_number: string;
}> = {}) {
  return {
    id: 7,
    origin_tenant_id: 2,
    destination_tenant_id: 1,
    origin_tenant: { id: 2, name: 'Demo Caracas', slug: 'demo-caracas' },
    destination_tenant: { id: 1, name: 'Demo Valencia', slug: 'demo-valencia' },
    from_warehouse_id: 10,
    destination_warehouse_id: null,
    status: 'requested',
    document_number: 'TREQ-1-000007',
    reason: 'Reposicion',
    reference: null,
    notes: 'Por favor rapido',
    response_notes: null,
    requested_by: 99,
    responded_by: null,
    requested_at: '2026-07-15T10:00:00.000000Z',
    responded_at: null,
    completed_at: null,
    items: [],
    created_at: '2026-07-15T10:00:00.000000Z',
    ...overrides,
  };
}

describe('TransferRequestDetailInner', () => {
  beforeEach(() => {
    mockUseTransferRequest.mockReset();
    mockNavigate.mockReset();
    window.confirm = vi.fn(() => true);
  });

  it('muestra skeleton mientras carga', () => {
    mockUseTransferRequest.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/cargando solicitud/i)).toBeInTheDocument();
  });

  it('muestra EmptyState cuando el request no existe', () => {
    mockUseTransferRequest.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/no se encontro la solicitud/i)).toBeInTheDocument();
  });

  it('renderiza header con badge + datos origen/destino', () => {
    mockUseTransferRequest.mockReturnValue({
      data: makeRequest(),
      isLoading: false,
      isError: false,
    });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.getByText(/TREQ-1-000007/)).toBeInTheDocument();
    expect(screen.getByText(/Demo Caracas/)).toBeInTheDocument();
    expect(screen.getByText(/Demo Valencia/)).toBeInTheDocument();
  });

  it('muestra acciones Aceptar/Rechazar si soy destino + status=requested', () => {
    mockUseTransferRequest.mockReturnValue({
      data: makeRequest({ destination_tenant_id: 1, status: 'requested' }),
      isLoading: false,
      isError: false,
    });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.getByTestId('detail-accept-btn')).toBeInTheDocument();
    expect(screen.getByTestId('detail-reject-btn')).toBeInTheDocument();
  });

  it('muestra accion Cancelar si soy origen + status=requested', () => {
    mockUseTransferRequest.mockReturnValue({
      data: makeRequest({ origin_tenant_id: 1, destination_tenant_id: 2, status: 'requested' }),
      isLoading: false,
      isError: false,
    });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.getByTestId('detail-cancel-btn')).toBeInTheDocument();
    expect(screen.queryByTestId('detail-accept-btn')).not.toBeInTheDocument();
  });

  it('NO muestra acciones cuando status es completed', () => {
    mockUseTransferRequest.mockReturnValue({
      data: makeRequest({ status: 'completed' }),
      isLoading: false,
      isError: false,
    });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.queryByTestId('detail-accept-btn')).not.toBeInTheDocument();
    expect(screen.queryByTestId('detail-reject-btn')).not.toBeInTheDocument();
    expect(screen.queryByTestId('detail-cancel-btn')).not.toBeInTheDocument();
  });

  it('NO muestra acciones cuando status es rejected (terminal)', () => {
    mockUseTransferRequest.mockReturnValue({
      data: makeRequest({ status: 'rejected' }),
      isLoading: false,
      isError: false,
    });
    render(<TransferRequestDetailInner id={7} />, { wrapper: makeWrapper() });
    expect(screen.queryByTestId('detail-accept-btn')).not.toBeInTheDocument();
    expect(screen.queryByTestId('detail-cancel-btn')).not.toBeInTheDocument();
  });
});
