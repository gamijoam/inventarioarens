/**
 * Tests para InventoryTransferRequestsManager (bandeja con tabs).
 *
 * Cubre:
 *   - Skeleton mientras carga.
 *   - EmptyState en cada tab.
 *   - Tabla renderiza filas con origen/destino y badge de estado.
 *   - Acciones inline: Aceptar/Rechazar para recibidas+requested,
 *     Cancelar para enviadas+requested.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// Implementaciones mockeadas inline (mismo patron que otros tests del repo).
const mockUseTransferRequests = vi.fn();
const cancelMock = vi.fn();
const mockUseCancelTransferRequest = vi.fn(() => ({
  mutate: (id: number) => cancelMock(id),
  mutateAsync: async (id: number) => {
    cancelMock(id);
    return {};
  },
}));

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useTransferRequests: () => mockUseTransferRequests(),
  useCancelTransferRequest: () => mockUseCancelTransferRequest(),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: {
    getState: () => ({ tenant: { id: 1 } }),
  },
  hasAuthCookie: () => true,
  hasAuthCookieWithValue: () => 'mock-token',
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { InventoryTransferRequestsManager } from './InventoryTransferRequestsManager';

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
  reason: string;
  items: unknown[];
}> = {}) {
  return {
    id: 1,
    origin_tenant_id: 1,
    destination_tenant_id: 2,
    origin_tenant: { id: 1, name: 'Origin', slug: 'origin' },
    destination_tenant: { id: 2, name: 'Dest', slug: 'dest' },
    from_warehouse_id: 10,
    from_warehouse: { id: 10, code: 'W1' },
    status: 'requested',
    document_number: 'TREQ-1-000001',
    reason: 'Test',
    requested_at: '2026-07-15T10:00:00.000000Z',
    items: [],
    ...overrides,
  };
}

describe('InventoryTransferRequestsManager', () => {
  it('muestra skeleton mientras carga', () => {
    mockUseTransferRequests.mockReturnValue({ data: undefined, isLoading: true });
    const { container } = render(<InventoryTransferRequestsManager />, { wrapper: makeWrapper() });
    expect(container.querySelector('.h-32')).toBeTruthy();
  });

  it('muestra EmptyState cuando no hay solicitudes en tab Recibidas', () => {
    mockUseTransferRequests.mockReturnValue({
      data: [],
      isLoading: false,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    render(<InventoryTransferRequestsManager />, { wrapper: makeWrapper() });
    expect(screen.getByText(/sin solicitudes/i)).toBeInTheDocument();
  });

  it('renderiza filas para Recibidas y muestra botones Aceptar/Rechazar', () => {
    mockUseTransferRequests.mockReturnValue({
      // Solicitud originada en OTRA empresa y destinada a mi tenant (id=1).
      data: [makeRequest({ id: 5, origin_tenant_id: 2, destination_tenant_id: 1, status: 'requested' })],
      isLoading: false,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    render(
      <InventoryTransferRequestsManager
        onAccept={() => undefined}
        onReject={() => undefined}
      />,
      { wrapper: makeWrapper() },
    );
    expect(screen.getByText('TREQ-1-000001')).toBeInTheDocument();
    expect(screen.getByTestId('accept-5')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /rechazar solicitud/i })).toBeInTheDocument();
  });

  it('muestra boton Cancelar para solicitudes enviadas en estado requested', async () => {
    window.confirm = vi.fn(() => true);
    mockUseTransferRequests.mockReturnValue({
      data: [makeRequest({ id: 7, origin_tenant_id: 1, status: 'requested' })],
      isLoading: false,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    const user = userEvent.setup();
    render(<InventoryTransferRequestsManager />, { wrapper: makeWrapper() });

    const sentTab = screen.getByRole('tab', { name: /enviadas/i });
    await user.click(sentTab);

    const cancelBtn = screen.getByRole('button', { name: /cancelar solicitud/i });
    await user.click(cancelBtn);
    expect(cancelMock).toHaveBeenCalledWith(7);
  });

  it('no muestra acciones para solicitudes en estado terminal (completed)', () => {
    mockUseTransferRequests.mockReturnValue({
      data: [makeRequest({ id: 9, destination_tenant_id: 1, status: 'completed' })],
      isLoading: false,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    render(<InventoryTransferRequestsManager />, { wrapper: makeWrapper() });
    expect(screen.queryByTestId('accept-9')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /rechazar solicitud/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /cancelar solicitud/i })).not.toBeInTheDocument();
  });
});
