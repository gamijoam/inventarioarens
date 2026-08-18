import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OrganizationDashboardSchema } from '../organizationApi';
import { OrganizationDashboardView } from '../OrganizationDashboardView';

const switchToMock = vi.fn();

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({
    switchTo: switchToMock,
  }),
}));

const realApiPayload = {
  scope: 'organization',
  group: { id: 1, name: 'Tiendas Arens', slug: 'tiendas-arens' },
  period: { from: '2026-08-17', to: '2026-08-17' },
  totals: {
    sales_count: 6,
    sales_total_base_amount: 2540,
    pos_orders_count: 2,
    pos_paid_base_amount: 1190,
    open_cash_sessions: 2,
    receivable_balance_base_amount: 1240,
    payable_balance_base_amount: 1090,
    low_stock_count: 2,
  },
  companies: [
    {
      tenant_id: 2,
      name: 'Boca de Aroa',
      slug: 'boca-de-aroa',
      sales: { confirmed_count: 3, total_base_amount: 2270 },
      pos: { paid_orders_count: 1, paid_base_amount: 1095 },
      cash_register: { open_sessions_count: 1 },
      inventory: { low_stock_count: 1 },
      finance: {
        accounts_receivable_balance_base_amount: 1120,
        accounts_payable_balance_base_amount: 1045,
      },
    },
    {
      tenant_id: 3,
      name: 'Tucacas',
      slug: 'tucacas',
      sales: { confirmed_count: 3, total_base_amount: 270 },
      pos: { paid_orders_count: 1, paid_base_amount: 95 },
      cash_register: { open_sessions_count: 1 },
      inventory: { low_stock_count: 1 },
      finance: {
        accounts_receivable_balance_base_amount: 120,
        accounts_payable_balance_base_amount: 45,
      },
    },
  ],
};

describe('organization dashboard schema', () => {
  it('parsea la respuesta real de la API sin descartar campos numericos enteros', () => {
    const parsed = OrganizationDashboardSchema.parse(realApiPayload);

    expect(parsed.scope).toBe('organization');
    expect(parsed.totals.sales_total_base_amount).toBe(2540);
    expect(parsed.totals.open_cash_sessions).toBe(2);
    expect(parsed.companies).toHaveLength(2);
    expect(parsed.companies[0]?.name).toBe('Boca de Aroa');
    expect(parsed.companies[1]?.slug).toBe('tucacas');
  });

  it('acepta listas vacias de empresas para grupos sin hijas', () => {
    const empty = OrganizationDashboardSchema.parse({
      ...realApiPayload,
      totals: {
        sales_count: 0,
        sales_total_base_amount: 0,
        pos_orders_count: 0,
        pos_paid_base_amount: 0,
        open_cash_sessions: 0,
        receivable_balance_base_amount: 0,
        payable_balance_base_amount: 0,
        low_stock_count: 0,
      },
      companies: [],
    });

    expect(empty.companies).toEqual([]);
    expect(empty.totals.sales_count).toBe(0);
  });

  it('rechaza una respuesta sin el shape de organizacion', () => {
    expect(() =>
      OrganizationDashboardSchema.parse({ scope: 'tenant', sales: {} }),
    ).toThrow();
  });
});

describe('OrganizationDashboardView', () => {
  beforeEach(() => {
    switchToMock.mockReset();
  });

  it('renderiza totales consolidados y las empresas del grupo', () => {
    render(<OrganizationDashboardView data={OrganizationDashboardSchema.parse(realApiPayload)} />);

    expect(screen.getByText('Ventas del grupo')).toBeInTheDocument();
    expect(screen.getByText('Boca de Aroa')).toBeInTheDocument();
    expect(screen.getByText('Tucacas')).toBeInTheDocument();
    expect(screen.getByText('Empresas del grupo')).toBeInTheDocument();
  });

  it('muestra el estado vacio cuando el grupo no tiene empresas', () => {
    const emptyData = OrganizationDashboardSchema.parse({
      ...realApiPayload,
      totals: {
        sales_count: 0,
        sales_total_base_amount: 0,
        pos_orders_count: 0,
        pos_paid_base_amount: 0,
        open_cash_sessions: 0,
        receivable_balance_base_amount: 0,
        payable_balance_base_amount: 0,
        low_stock_count: 0,
      },
      companies: [],
    });

    render(<OrganizationDashboardView data={emptyData} />);

    expect(screen.getByText(/No hay empresas hijas/)).toBeInTheDocument();
  });

  it('permite entrar a una empresa hija mediante switchTo', async () => {
    const user = userEvent.setup();
    render(<OrganizationDashboardView data={OrganizationDashboardSchema.parse(realApiPayload)} />);

    const buttons = screen.getAllByRole('button', { name: /entrar/i });
    expect(buttons.length).toBe(2);

    await user.click(buttons[1] as HTMLButtonElement);

    await waitFor(() => {
      expect(switchToMock).toHaveBeenCalledWith('tucacas');
    });
  });
});
