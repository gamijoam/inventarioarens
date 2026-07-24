import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

const mockMutateAsync = vi.fn();
const mockUseTenantGroups = vi.fn();
const mockUseGroupSpinoffs = vi.fn();
const mockUseGroupUsers = vi.fn();
const mockUsePromoteTenant = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));

const mockSessionTenant: { id: number; name: string; slug: string; is_group?: boolean; parent_id?: number | null } | null = null;

vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: { tenant: typeof mockSessionTenant }) => unknown) =>
    selector({ tenant: mockSessionTenant }),
}));

vi.mock('@/features/access/tenantGroupsApi', () => ({
  useTenantGroups: () => mockUseTenantGroups(),
  useGroupSpinoffs: () => mockUseGroupSpinoffs(),
  useGroupUsers: () => mockUseGroupUsers(),
  useCreateTenantGroup: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
  useCreateSpinoff: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
  useAttachGroupUser: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
  usePromoteTenant: () => mockUsePromoteTenant(),
}));

vi.mock('@tanstack/react-router', () => ({
  createFileRoute: () => () => ({}),
  useNavigate: () => vi.fn(),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { PromoteToGroupDialog } from '../PromoteToGroupDialog';

function renderWithProviders(ui: ReactNode) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('PromoteToGroupDialog', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset();
  });

  it('muestra contexto y dispara la promocion al confirmar', async () => {
    mockMutateAsync.mockResolvedValue({
      data: { id: 1, name: 'Demo', slug: 'demo', status: 'active', is_owner: true },
    });
    const onPromoted = vi.fn();
    const onOpenChange = vi.fn();

    renderWithProviders(
      <PromoteToGroupDialog
        open
        onOpenChange={onOpenChange}
        tenant={{ id: 42, name: 'Mi Empresa', slug: 'mi-empresa' }}
        onPromoted={onPromoted}
      />,
    );

    expect(screen.getByTestId('promote-context-banner')).toHaveTextContent('Mi Empresa');
    expect(screen.getByTestId('promote-context-banner')).toHaveTextContent('mi-empresa');

    await userEvent.click(screen.getByTestId('promote-confirm'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalledWith(42);
    });
    await waitFor(() => {
      expect(onPromoted).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'demo', is_owner: true }),
      );
    });
  });

  it('muestra toast de error si la mutacion falla', async () => {
    mockMutateAsync.mockRejectedValue(new Error('No permitido'));
    const onPromoted = vi.fn();

    renderWithProviders(
      <PromoteToGroupDialog
        open
        onOpenChange={vi.fn()}
        tenant={{ id: 1, name: 'Demo', slug: 'demo' }}
        onPromoted={onPromoted}
      />,
    );

    await userEvent.click(screen.getByTestId('promote-confirm'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    expect(onPromoted).not.toHaveBeenCalled();
  });
});