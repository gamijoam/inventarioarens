import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReportZDialog } from '../ReportZDialog';

const mockUseReportZ = vi.fn();

vi.mock('../reportZApi', () => ({
  useReportZ: (sessionId: number | null, enabled: boolean) => mockUseReportZ(sessionId, enabled),
  openReportZPdf: vi.fn(),
  downloadReportZPdf: vi.fn(),
  printReportZThermal: vi.fn(),
}));

vi.mock('@/features/printing/api', () => ({
  usePrinterStations: () => ({ data: [], isLoading: false, isError: false }),
}));

const zFixture = {
  z_number: 3,
  emitted_at: '2026-08-18T20:00:00+00:00',
  status: 'closed',
  tenant: { name: 'OscarCell', slug: 'oscar-cell' },
  branch: 'Sucursal Principal',
  cash_register: 'CAJA1',
  cashier: 'Cajero Demo',
  opened_at: '2026-08-18T08:00:00+00:00',
  closed_at: '2026-08-18T20:00:00+00:00',
  totals: {
    orders_count: 12,
    paid_base_amount: 500,
    paid_local_amount: 37500,
    expected_base_amount: 500,
    expected_local_amount: 37500,
    counted_base_amount: 500,
    counted_local_amount: 37500,
    difference_base_amount: 0,
    difference_local_amount: 0,
    difference_cash_usd: 0,
    difference_cash_ves: 0,
  },
  payments: [
    { name: 'Efectivo', method: 'cash', currency: 'USD', payments_count: 8, amount_base: 300, amount_local: 22500, exchange_rate: 75 },
    { name: 'Pago Móvil', method: 'mobile_payment', currency: 'VES', payments_count: 4, amount_base: 200, amount_local: 15000, exchange_rate: 75 },
  ],
  counts: [],
  categories: [
    {
      id: 1,
      name: 'Papelería',
      items_count: 5,
      amount_base: 50,
      amount_local: 3750,
      products: [
        { id: 10, name: 'Cuaderno', sku: 'CUAD-1', quantity: 3, amount_base: 30, amount_local: 2250 },
        { id: 11, name: 'Bolígrafo', sku: 'BOL-1', quantity: 2, amount_base: 20, amount_local: 1500 },
      ],
    },
    {
      id: 2,
      name: 'Tecnología',
      items_count: 1,
      amount_base: 150,
      amount_local: 11250,
      products: [
        { id: 20, name: 'Teclado USB', sku: 'TEC-1', quantity: 1, amount_base: 150, amount_local: 11250 },
      ],
    },
  ],
  customers: [
    {
      id: 1,
      name: 'Librería Escolar',
      document: 'J-12345678',
      orders_count: 2,
      amount_base: 200,
      amount_local: 15000,
    },
    {
      id: null,
      name: 'Consumidor Final',
      document: null,
      orders_count: 6,
      amount_base: 300,
      amount_local: 22500,
    },
  ],
};

describe('ReportZDialog', () => {
  beforeEach(() => {
    mockUseReportZ.mockReset();
    mockUseReportZ.mockReturnValue({ data: zFixture, isLoading: false, isError: false });
  });

  it('muestra el numero Z, totales y desglose de pagos', () => {
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    expect(mockUseReportZ).toHaveBeenCalledWith(5, true);
    expect(screen.getByText('Reporte Z #3')).toBeInTheDocument();
    expect(screen.getByText('Tickets')).toBeInTheDocument();
    expect(screen.getByText('Total USD')).toBeInTheDocument();
    expect(screen.getByText('Efectivo (USD) · 8')).toBeInTheDocument();
    expect(screen.getByText('Pago Móvil (VES) · 4')).toBeInTheDocument();
  });

  it('muestra los botones de impresion y descarga', () => {
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    expect(screen.getByText('Imprimir')).toBeInTheDocument();
    expect(screen.getByText('Descargar PDF')).toBeInTheDocument();
    expect(screen.getByText('Imprimir térmica')).toBeInTheDocument();
  });

  it('permite ver y filtrar por categorias y sus productos', async () => {
    const user = userEvent.setup();
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    // Click on Categorías tab
    const catTab = screen.getByRole('tab', { name: /Categorías/i });
    await user.click(catTab);

    // Verify categories and products are visible
    expect(screen.getAllByText('Papelería').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Tecnología').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Cuaderno')).toBeInTheDocument();
    expect(screen.getByText('Bolígrafo')).toBeInTheDocument();
    expect(screen.getByText('Teclado USB')).toBeInTheDocument();

    // Filter by searching
    const searchInput = screen.getByPlaceholderText(/Buscar categoría o producto/i);
    await user.type(searchInput, 'papeleria');

    expect(screen.getAllByText('Papelería').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Cuaderno')).toBeInTheDocument();
    expect(screen.queryByText('Teclado USB')).not.toBeInTheDocument();
  });

  it('permite ver y filtrar por clientes', async () => {
    const user = userEvent.setup();
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    // Click on Clientes tab
    const clientTab = screen.getByRole('tab', { name: /Clientes/i });
    await user.click(clientTab);

    // Verify customers are visible
    expect(screen.getByText('Librería Escolar')).toBeInTheDocument();
    expect(screen.getByText('J-12345678')).toBeInTheDocument();
    expect(screen.getByText('Consumidor Final')).toBeInTheDocument();

    // Filter by search
    const searchInput = screen.getByPlaceholderText(/Buscar cliente por nombre o documento/i);
    await user.type(searchInput, '12345678');

    expect(screen.getByText('Librería Escolar')).toBeInTheDocument();
    expect(screen.queryByText('Consumidor Final')).not.toBeInTheDocument();
  });
});
