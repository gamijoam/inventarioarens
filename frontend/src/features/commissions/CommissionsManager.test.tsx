import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CommissionsManager } from './CommissionsManager';

const simulate = vi.fn();
const mockCreatePlan = vi.fn();
const mockUpdatePlan = vi.fn();

vi.mock('./api', () => ({
  useCommissionPlans: () => ({ data: [{
    id: 1,
    name: 'Vendedores 1%',
    beneficiary_role: 'seller',
    percentage: '1.0000',
    conversion_policy: 'configured_rate',
    exchange_rate_type_id: 5,
    exchange_rate_type: { id: 5, code: 'BCV', name: 'Banco Central' },
    credit_policy: 'sale_confirmation',
    maturation_days: 0,
    allow_self_stacking: false,
    include_combos: true,
    include_discounts: true,
    is_active: true,
    starts_at: null,
    ends_at: null,
    assignments: [{ id: 1, user_id: 2, is_active: true, starts_at: null, ends_at: null, user: { id: 2, name: 'Oscar', email: 'oscar@example.test' } }],
    created_at: null,
    updated_at: null,
  }], isLoading: false }),
  useCommissionSimulation: () => ({ mutateAsync: simulate, data: undefined, isPending: false }),
  useCreateCommissionPlan: () => ({ mutateAsync: mockCreatePlan, isPending: false }),
  useUpdateCommissionPlan: () => ({ mutateAsync: mockUpdatePlan, isPending: false }),
  useDeactivateCommissionPlan: () => ({ mutateAsync: vi.fn() }),
}));

vi.mock('@/features/users/api', () => ({ useUsers: () => ({ data: { data: [] } }) }));
vi.mock('@/features/inventory-center/api', () => ({
  useExchangeRateTypes: () => ({ data: [{ id: 5, code: 'BCV', name: 'Banco Central', is_active: true }] }),
}));

describe('CommissionsManager simulator', () => {
  beforeEach(() => simulate.mockReset().mockResolvedValue({}));

  it('uses the active plan rate by default for a VES simulation', async () => {
    render(<CommissionsManager />);

    expect(screen.getByLabelText('Tipo de tasa del simulador')).toHaveValue('5');
    await userEvent.click(screen.getByRole('button', { name: 'Calcular escenario' }));

    expect(simulate).toHaveBeenCalledWith({
      amount: 6000,
      currency: 'VES',
      percentage: 1,
      exchange_rate_type_id: 5,
    });
  });

  it('muestra los toggles de combos y descuentos en el nuevo plan', async () => {
    render(<CommissionsManager />);
    await userEvent.click(screen.getByRole('button', { name: 'Nuevo plan' }));

    expect(screen.getByText('Incluir comisión en ventas de combos')).toBeInTheDocument();
    expect(screen.getByText('Incluir comisión en ventas con descuento')).toBeInTheDocument();
  });

  it('edita un plan existente prellenado y guarda los cambios', async () => {
    mockUpdatePlan.mockReset().mockResolvedValue({});
    render(<CommissionsManager />);

    await userEvent.click(screen.getByRole('button', { name: 'Editar Vendedores 1%' }));

    expect(screen.getByText('Editar plan de comisiones')).toBeInTheDocument();
    const nameInput = screen.getByPlaceholderText('Ej. Vendedores 3%');
    expect((nameInput as HTMLInputElement).value).toBe('Vendedores 1%');

    await userEvent.clear(nameInput);
    await userEvent.type(nameInput, 'Vendedores 2%');
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

    expect(mockUpdatePlan).toHaveBeenCalledWith(expect.objectContaining({ id: 1, name: 'Vendedores 2%' }));
  });
});
