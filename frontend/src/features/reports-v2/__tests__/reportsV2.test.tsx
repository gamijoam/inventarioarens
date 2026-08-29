import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

import { ReportV2Schema } from '../schemas';
import { ReportsV2Manager } from '../ReportsV2Manager';

const noop = (): void => undefined;

class ResizeObserverMock {
  observe = noop;
  unobserve = noop;
  disconnect = noop;
}

vi.stubGlobal('ResizeObserver', ResizeObserverMock);

vi.mock('recharts', async (importOriginal) => {
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion
  const actual = (await importOriginal()) as Record<string, unknown>;
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
      <div style={{ width: 600, height: 320 }}>{children}</div>
    ),
  };
});

const catalogFixture = [
  {
    code: 'sales_overview',
    name: 'Ventas por período',
    domain: 'ventas',
    default_dimension: 'day',
    default_measure: 'sales_total',
    dimensions: ['day', 'week', 'month'],
    measures: ['sales_total', 'sales_total_local', 'sales_count', 'ticket_avg'],
    org_supported: true,
    has_warehouse_filter: false,
    has_low_stock_filter: false,
    has_local_amounts: true,
    date_range_required: true,
  },
  {
    code: 'stock_by_product',
    name: 'Stock por producto',
    domain: 'inventario',
    default_dimension: 'product',
    default_measure: 'stock_qty',
    dimensions: ['product', 'warehouse'],
    measures: ['stock_qty', 'stock_value'],
    org_supported: true,
    has_warehouse_filter: true,
    has_low_stock_filter: true,
    has_local_amounts: false,
    date_range_required: false,
  },
  {
    code: 'receivables_by_customer',
    name: 'Cuentas por cobrar por cliente',
    domain: 'finanzas',
    default_dimension: 'customer',
    default_measure: 'balance',
    dimensions: ['customer'],
    measures: ['balance', 'count'],
    org_supported: true,
    has_warehouse_filter: false,
    has_low_stock_filter: false,
    has_local_amounts: false,
    date_range_required: true,
  },
];

const reportFixture = {
  report: { code: 'sales_overview', name: 'Ventas por período', domain: 'ventas', dimension: 'day' },
  scope: 'tenant',
  period: { from: '2026-08-17', to: '2026-08-17' },
  rows: [
    {
      label: '2026-08-17',
      group_key: '2026-08-17',
      sales_total: 700,
      sales_total_local: 51800,
      sales_count: 2,
      ticket_avg: 350,
      rate: 74,
    },
  ],
  totals: { sales_total: 700, sales_total_local: 51800, sales_count: 2, ticket_avg: 350 },
  rate: 74,
};

const mockUseCatalog = vi.fn<(enabled: boolean) => unknown>();
const mockUseReport = vi.fn<(code: string, params: unknown, enabled: boolean) => unknown>();
const mockUseGroupSpinoffs = vi.fn<(id: number, enabled: boolean) => unknown>();

vi.mock('../api', () => ({
  useReportV2Catalog: (enabled: boolean) => mockUseCatalog(enabled),
  useReportV2: (code: string, params: unknown, enabled: boolean) =>
    mockUseReport(code, params, enabled),
}));

vi.mock('@/features/access/tenantGroupsApi', () => ({
  useGroupSpinoffs: (id: number, enabled: boolean) => mockUseGroupSpinoffs(id, enabled),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: <T,>(selector: (state: { tenant: { id: number; is_group: boolean } | null }) => T): T =>
    selector({ tenant: { id: 1, is_group: true } }),
}));

function makeWrapper() {
  const value: PermissionContextValue = {
    permissions: new Set(Object.values(PERMISSIONS)),
    roles: ['Owner'],
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
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>
    </QueryClientProvider>
  );
}

describe('reports v2 schema', () => {
  it('parsea la respuesta real del reporte', () => {
    const parsed = ReportV2Schema.parse(reportFixture);
    expect(parsed.report.code).toBe('sales_overview');
    expect(parsed.rows).toHaveLength(1);
    expect(parsed.rows[0]?.label).toBe('2026-08-17');
    expect(parsed.totals.sales_total).toBe(700);
  });

  it('acepta filas con metricas como numeros enteros', () => {
    const parsed = ReportV2Schema.parse(reportFixture);
    expect(parsed.rows[0]?.sales_count).toBe(2);
  });
});

describe('ReportsV2Manager', () => {
  beforeEach(() => {
    mockUseCatalog.mockReset();
    mockUseReport.mockReset();
    mockUseGroupSpinoffs.mockReset();
    mockUseCatalog.mockReturnValue({ data: catalogFixture, isLoading: false, isError: false });
    mockUseReport.mockReturnValue({
      data: reportFixture,
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    });
    mockUseGroupSpinoffs.mockReturnValue({
      data: [{ id: 4, name: 'OscarCell Yaracall', slug: 'oscarcell-yaracall' }],
      isLoading: false,
      isError: false,
    });
  });

  it('agrupa las plantillas por dominio', () => {
    render(<ReportsV2Manager />, { wrapper: makeWrapper() });

    expect(screen.getByText('Ventas por período')).toBeInTheDocument();
    expect(screen.getByText('Stock por producto')).toBeInTheDocument();
    expect(screen.getByText('Cuentas por cobrar por cliente')).toBeInTheDocument();
    expect(screen.getByText('Ventas')).toBeInTheDocument();
    expect(screen.getByText('Inventario')).toBeInTheDocument();
    expect(screen.getByText('Finanzas')).toBeInTheDocument();
  });

  it('al seleccionar una plantilla muestra controles, totales y tabla', async () => {
    const user = userEvent.setup();
    render(<ReportsV2Manager />, { wrapper: makeWrapper() });

    await user.click(screen.getByText('Ventas por período'));
    await user.click(screen.getByTitle('Tabla'));

    expect(screen.getByText('2026-08-17')).toBeInTheDocument();
    expect(screen.getByText('Tasa Bs/USD')).toBeInTheDocument();
    expect(screen.getAllByText('Bs 51.800,00 (~$700,00)').length).toBeGreaterThan(0);
    expect(screen.getByText('Tasa promedio (Bs/USD)')).toBeInTheDocument();
    expect(mockUseReport).toHaveBeenCalled();
  });

  it('cambia a vista de grafica sin errores', async () => {
    const user = userEvent.setup();
    const { container } = render(<ReportsV2Manager />, { wrapper: makeWrapper() });

    await user.click(screen.getByText('Ventas por período'));
    await user.click(screen.getByTitle('Barras'));

    expect(container.querySelector('svg')).not.toBeNull();
  });

  it('filtra por una empresa especifica en ambito grupo', async () => {
    const user = userEvent.setup();
    render(<ReportsV2Manager />, { wrapper: makeWrapper() });

    await user.click(screen.getByText('Ventas por período'));

    expect(screen.getByText('Todas')).toBeInTheDocument();

    const companySelect = screen.getByText('Todas').closest('select')!;
    await user.selectOptions(companySelect, '4');

    const lastCall = mockUseReport.mock.calls.at(-1);
    expect(lastCall?.[0]).toBe('sales_overview');
    expect(lastCall?.[1]).toMatchObject({ scope: 'organization', companyId: 4 });
  });
});
