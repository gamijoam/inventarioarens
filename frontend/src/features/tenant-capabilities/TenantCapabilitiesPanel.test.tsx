import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockMutateAsync = vi.fn();
const mockSetCapabilities = vi.fn();
const mockState = {
  data: {
    tenant_id: 7,
    enabled: ['dashboard', 'catalog', 'inventory', 'customers', 'suppliers'],
    capabilities: [
      {
        key: 'dashboard',
        label: 'Dashboard',
        description: 'Resumen operativo.',
        required: true,
        enabled: true,
      },
      {
        key: 'inventory',
        label: 'Inventario',
        description: 'Stock y movimientos.',
        required: true,
        enabled: true,
      },
      {
        key: 'pos',
        label: 'POS',
        description: 'Venta de mostrador.',
        required: false,
        enabled: false,
      },
    ],
  },
  isLoading: false,
  isError: false,
};

vi.mock('./api', () => ({
  useTenantCapabilities: () => mockState,
  useUpdateTenantCapabilities: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: Object.assign(vi.fn(), {
    getState: () => ({ setCapabilities: mockSetCapabilities }),
  }),
}));

import { TenantCapabilitiesPanel } from './TenantCapabilitiesPanel';

describe('TenantCapabilitiesPanel', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset();
    mockSetCapabilities.mockReset();
    mockMutateAsync.mockResolvedValue({
      ...mockState.data,
      enabled: [...mockState.data.enabled, 'pos'],
    });
  });

  it('shows required capabilities locked and optional capabilities toggleable', () => {
    render(<TenantCapabilitiesPanel />);

    expect(screen.getByRole('switch', { name: 'Dashboard' })).toBeDisabled();
    expect(screen.getByRole('switch', { name: 'Inventario' })).toBeDisabled();
    expect(screen.getByRole('switch', { name: 'POS' })).not.toBeDisabled();
    expect(screen.getByText('Capacidades activas')).toBeTruthy();
  });

  it('saves the selected optional capabilities and refreshes the session store', async () => {
    render(<TenantCapabilitiesPanel />);

    fireEvent.click(screen.getByRole('switch', { name: 'POS' }));
    fireEvent.click(screen.getByRole('button', { name: 'Guardar capacidades' }));

    await waitFor(() => expect(mockMutateAsync).toHaveBeenCalledWith(['pos']));
    expect(mockSetCapabilities).toHaveBeenCalledWith([
      'dashboard',
      'catalog',
      'inventory',
      'customers',
      'suppliers',
      'pos',
    ]);
  });
});
