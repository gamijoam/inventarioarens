import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

import {
  PermissionContext,
  type PermissionContextValue,
} from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

const mockUsePaymentMethods = vi.fn();
const mockCreatePaymentMethod = vi.fn();
const mockUpdatePaymentMethod = vi.fn();
const mockDeletePaymentMethod = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, ...props }: { children: ReactNode }) => <a {...props}>{children}</a>,
}));

vi.mock('../api', () => ({
  usePaymentMethods: () => mockUsePaymentMethods(),
  useCreatePaymentMethod: () => ({ mutate: mockCreatePaymentMethod, isPending: false }),
  useUpdatePaymentMethod: () => ({ mutate: mockUpdatePaymentMethod, isPending: false }),
  useDeletePaymentMethod: () => ({ mutate: mockDeletePaymentMethod, isPending: false }),
}));

import { PaymentMethodsSetup } from '../PaymentMethodsSetup';

function makeWrapper(perms: string[] = [PERMISSIONS.PAYMENT_METHODS_UPDATE]) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const value: PermissionContextValue = {
    permissions: new Set(perms),
    roles: [],
    scopeStatus: 'none',
    scopes: {
      branches: [],
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: 0,
      warehouses_count: 0,
      customer_groups_count: 0,
      vendor_of_count: 0,
    },
  };

  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>
      <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>
    </QueryClientProvider>
  );
}

const fakeMethods = [
  {
    id: 1,
    name: 'Efectivo USD',
    code: 'CASH_USD',
    method: 'cash',
    currency_mode: 'USD',
    requires_reference: false,
    is_active: true,
    sort_order: 1,
    report_code: 'EF_USD',
    report_label: 'Efectivo USD',
    report_visible: true,
    report_sort_order: 1,
  },
  {
    id: 2,
    name: 'Pago Móvil Banesco',
    code: 'PAGO_MOVIL_BANESCO',
    method: 'mobile_payment',
    currency_mode: 'VES',
    requires_reference: true,
    is_active: true,
    sort_order: 2,
    report_code: 'PM_BAN',
    report_label: 'Pago Móvil',
    report_visible: true,
    report_sort_order: 2,
  },
];

describe('PaymentMethodsSetup', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renderiza la lista de métodos de pago con sus datos de identificación y badges', () => {
    mockUsePaymentMethods.mockReturnValue({
      data: fakeMethods,
      isLoading: false,
    });

    render(<PaymentMethodsSetup />, { wrapper: makeWrapper() });

    expect(screen.getByText('Efectivo USD')).toBeInTheDocument();
    expect(screen.getByText('CASH_USD')).toBeInTheDocument();
    expect(screen.getByText('Pago Móvil Banesco')).toBeInTheDocument();
    expect(screen.getByText('PAGO_MOVIL_BANESCO')).toBeInTheDocument();

    // Badges de tipo operativo y moneda en la lista
    expect(screen.getAllByText('Efectivo').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Pago movil').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Solo USD').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Solo VES').length).toBeGreaterThanOrEqual(1);
  });

  it('permite actualizar campos de reporte y toggles del método', async () => {
    const user = userEvent.setup();
    mockUsePaymentMethods.mockReturnValue({
      data: fakeMethods,
      isLoading: false,
    });

    render(<PaymentMethodsSetup />, { wrapper: makeWrapper() });

    // Editar input de etiqueta de reporte
    const labelInput = screen.getByLabelText('Etiqueta de reporte para Efectivo USD');
    await user.clear(labelInput);
    await user.type(labelInput, 'Efectivo Dólares');
    await user.tab();

    expect(mockUpdatePaymentMethod).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 1,
        report_label: 'Efectivo Dólares',
      }),
    );
  });

  it('permite eliminar un método de pago con el botón de papelera', async () => {
    const user = userEvent.setup();
    mockUsePaymentMethods.mockReturnValue({
      data: fakeMethods,
      isLoading: false,
    });

    render(<PaymentMethodsSetup />, { wrapper: makeWrapper() });

    const deleteBtn = screen.getByLabelText('Eliminar metodo Efectivo USD');
    await user.click(deleteBtn);

    expect(mockDeletePaymentMethod).toHaveBeenCalledWith(1);
  });
});
