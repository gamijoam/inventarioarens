import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

const mockUseSales = vi.fn();
const mockUseSale = vi.fn();
const mockUseCancelSale = vi.fn();
const mockUseCurrentExchangeRatesForPos = vi.fn();
const mockUseCashSessions = vi.fn();
const mockUseCan = vi.fn();

vi.mock('@/features/sales/api', () => ({
  useSales: (filters: unknown) => mockUseSales(filters),
  useSale: (id: unknown) => mockUseSale(id),
  useCancelSale: () => ({ mutateAsync: mockUseCancelSale, isPending: false }),
  useReversePosSale: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

vi.mock('@/features/pos/api', () => ({
  useCurrentExchangeRatesForPos: () => mockUseCurrentExchangeRatesForPos(),
  useCashSessions: () => mockUseCashSessions(),
}));

vi.mock('@/permissions/useCan', () => ({
  useCan: (permission: string) => mockUseCan(permission),
}));

import { SalesManager } from '../SalesManager';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeSales = [
  {
    id: 101,
    status: 'confirmed',
    total_base_amount: 50,
    total_local_amount: 3000,
    created_at: '2026-09-12T10:00:00.000000Z',
    confirmed_at: '2026-09-12T10:05:00.000000Z',
    customer: {
      id: 1,
      name: 'Carlos Perez',
      document_type: 'V',
      document_number: '12345678',
      phone: '+58 412 1234567',
    },
    pos_order: {
      id: 55,
      status: 'paid',
      cashier_name: 'Cajero Principal',
      paid_at: '2026-09-12T10:05:00.000000Z',
      cash_register_session: {
        id: 1,
        status: 'open',
        branch_name: 'Sede Principal',
        cash_register_name: 'Caja 1',
      },
      payments: [
        {
          id: 1,
          method: 'cash',
          amount_base: 50,
          amount_local: 3000,
          status: 'captured',
        },
      ],
    },
    items: [
      {
        id: 1,
        sale_id: 101,
        warehouse_id: 1,
        warehouse_name: 'Almacén Central',
        product_id: 10,
        product_name: 'Batería 12V Pro',
        product_sku: 'BAT-12V',
        product_barcode: '7591234567890',
        quantity: 1,
        unit_price: 50,
        total_price: 50,
        currency: 'USD',
      },
    ],
    items_count: 1,
    promotion_applications: [],
  },
];

describe('SalesManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockUseCurrentExchangeRatesForPos.mockReturnValue({ data: [] });
    mockUseCashSessions.mockReturnValue({ data: [{ id: 1, status: 'open' }] });
    mockUseCan.mockReturnValue(true);
    mockUseSale.mockReturnValue({ data: fakeSales[0], isLoading: false });
  });

  it('permite escribir fluidamente en el buscador sin perder el foco ni desmontar el input', async () => {
    const user = userEvent.setup();

    mockUseSales.mockImplementation((filters: { search?: string }) => {
      if (filters?.search) {
        return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }, isLoading: true, isFetching: true };
      }
      return { data: { data: fakeSales, meta: { current_page: 1, last_page: 1, total: 1 } }, isLoading: false, isFetching: false };
    });

    render(<SalesManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByPlaceholderText(/Cliente, documento, producto, SKU o venta #/i);
    expect(searchInput).toBeInTheDocument();

    await user.click(searchInput);
    expect(searchInput).toHaveFocus();

    await user.type(searchInput, 'Carlos');

    expect(searchInput).toBeInTheDocument();
    expect(searchInput).toHaveValue('Carlos');
    expect(searchInput).toHaveFocus();
  });

  it('no desmonta la barra de búsqueda cuando la consulta inicial o de búsqueda está cargando', () => {
    mockUseSales.mockReturnValue({
      data: undefined,
      isLoading: true,
      isFetching: true,
    });

    render(<SalesManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByPlaceholderText(/Cliente, documento, producto, SKU o venta #/i);
    expect(searchInput).toBeInTheDocument();
  });

  it('filtra ventas y muestra estado vacío de búsqueda cuando no hay resultados', async () => {
    const user = userEvent.setup();

    mockUseSales.mockImplementation((filters: { search?: string }) => {
      if (filters?.search === 'Inexistente') {
        return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }, isLoading: false, isFetching: false };
      }
      return { data: { data: fakeSales, meta: { current_page: 1, last_page: 1, total: 1 } }, isLoading: false, isFetching: false };
    });

    render(<SalesManager />, { wrapper: makeWrapper() });

    expect(screen.getByText(/Carlos Perez/i)).toBeInTheDocument();

    const searchInput = screen.getByPlaceholderText(/Cliente, documento, producto, SKU o venta #/i);
    await user.type(searchInput, 'Inexistente');

    await waitFor(() => {
      expect(screen.getByText('Sin resultados')).toBeInTheDocument();
      expect(screen.getByText(/Ninguna venta coincide con el criterio de búsqueda/i)).toBeInTheDocument();
    });
  });

  it('muestra el botón con el texto "Anular" y no "Anular / revertir" al expandir una venta', async () => {
    const user = userEvent.setup();

    mockUseSales.mockReturnValue({
      data: { data: fakeSales, meta: { current_page: 1, last_page: 1, total: 1 } },
      isLoading: false,
      isFetching: false,
    });

    render(<SalesManager />, { wrapper: makeWrapper() });

    expect(screen.getByText(/Carlos Perez/i)).toBeInTheDocument();

    // Expand sale row by clicking the row
    await user.click(screen.getByText('#101'));

    // Assert that the button says "Anular"
    const anularButton = screen.getByRole('button', { name: /^anular$/i });
    expect(anularButton).toBeInTheDocument();

    // Assert that "Anular / revertir" does NOT exist anywhere
    expect(screen.queryByRole('button', { name: /anular \/ revertir/i })).not.toBeInTheDocument();
  });

  it('renderiza correctamente las tarjetas de resumen métrico con venta neta, bruta y exclusión de canceladas', () => {
    mockUseSales.mockReturnValue({
      data: {
        data: fakeSales,
        meta: { current_page: 1, last_page: 1, total: 10 },
        summary: {
          total_count: 10,
          confirmed_base_total: 500,
          confirmed_local_total: 30000,
          net_base_total: 450,
          net_local_total: 27000,
          refund_base_total: 50,
          refund_local_total: 3000,
          refund_count: 1,
          confirmed_count: 7,
          draft_count: 1,
          cancelled_count: 2,
          pos_count: 8,
        },
      },
      isLoading: false,
      isFetching: false,
    });

    render(<SalesManager />, { wrapper: makeWrapper() });

    expect(screen.getByText('Venta Neta (período)')).toBeInTheDocument();
    expect(screen.getByText('$450,00')).toBeInTheDocument();
    expect(screen.getByText('Venta Bruta Confirmada')).toBeInTheDocument();
    expect(screen.getByText('$500,00')).toBeInTheDocument();
    expect(screen.getByText('Ventas Confirmadas')).toBeInTheDocument();
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByText('Borrador / Canceladas')).toBeInTheDocument();
    expect(screen.getByText('1 / 2')).toBeInTheDocument();
    expect(screen.getByText('Origen POS')).toBeInTheDocument();
    expect(screen.getByText('8')).toBeInTheDocument();
    expect(screen.getByText(/Devuelto: -\$50,00 \(1\)/i)).toBeInTheDocument();
  });
});
