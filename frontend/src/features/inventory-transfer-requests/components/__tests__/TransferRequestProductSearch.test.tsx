import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { TransferRequestProductSearch } from '../TransferRequestProductSearch';
import * as api from '@/features/inventory-transfer-requests/api';
import type { Product } from '@/features/inventory-center/schemas';

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useTransferRequestProducts: vi.fn(),
  transferRequestKeys: {
    all: ['inventory-transfer-requests'] as const,
    productSearches: () => ['inventory-transfer-requests', 'product-search'] as const,
    productSearch: vi.fn(),
  },
}));

const mockProducts: Product[] = [
  {
    id: 1,
    tenant_id: 1,
    name: 'Lavadora Arens De 11 Kilos',
    sku: 'LAV-11KG',
    barcode: '7501001',
    tracking_type: 'quantity',
    is_active: true,
    available_stock: 5,
  } as Product,
  {
    id: 2,
    tenant_id: 1,
    name: 'Batería Original Samsung Galaxy',
    sku: 'BAT-SAM-01',
    barcode: null,
    tracking_type: 'serialized',
    is_active: true,
    available_stock: 0,
  } as Product,
  {
    id: 3,
    tenant_id: 1,
    name: 'Mouse Inalámbrico XM-M300',
    sku: 'XM-M300',
    barcode: '8901234',
    tracking_type: 'quantity',
    is_active: true,
    available_stock: 12,
  } as Product,
];

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
}

describe('TransferRequestProductSearch', () => {
  const mockUseTransferRequestProducts = vi.mocked(api.useTransferRequestProducts);

  beforeEach(() => {
    vi.clearAllMocks();
    mockUseTransferRequestProducts.mockReturnValue({
      data: mockProducts,
      isLoading: false,
      isFetching: false,
      isError: false,
    } as any);
  });

  it('permite buscar por multi-token sin requerir frase exacta (ej. "lavadora 11")', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <TransferRequestProductSearch
        index={0}
        value=""
        selectedProduct={null}
        onChange={onChange}
      />,
      { wrapper: makeWrapper() },
    );

    const input = screen.getByRole('textbox');
    await user.click(input);
    await user.type(input, 'lavadora 11');

    await waitFor(() => {
      expect(screen.getByText('Lavadora Arens De 11 Kilos')).toBeInTheDocument();
    });
    expect(screen.queryByText('Batería Original Samsung Galaxy')).not.toBeInTheDocument();
  });

  it('soporta acentos y diacriticos (ej. "bateria" encuentra "Batería")', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <TransferRequestProductSearch
        index={0}
        value=""
        selectedProduct={null}
        onChange={onChange}
      />,
      { wrapper: makeWrapper() },
    );

    const input = screen.getByRole('textbox');
    await user.click(input);
    await user.type(input, 'bateria');

    await waitFor(() => {
      expect(screen.getByText('Batería Original Samsung Galaxy')).toBeInTheDocument();
    });
  });

  it('muestra la existencia del producto (Stock: X o Sin stock (0))', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <TransferRequestProductSearch
        index={0}
        value=""
        selectedProduct={null}
        onChange={onChange}
      />,
      { wrapper: makeWrapper() },
    );

    const input = screen.getByRole('textbox');
    await user.click(input);

    await waitFor(() => {
      expect(screen.getByText('Stock: 5')).toBeInTheDocument();
      expect(screen.getByText('Sin stock (0)')).toBeInTheDocument();
    });
  });

  it('al seleccionar un producto llama a onChange con el id y el producto completo', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <TransferRequestProductSearch
        index={0}
        value=""
        selectedProduct={null}
        onChange={onChange}
      />,
      { wrapper: makeWrapper() },
    );

    const input = screen.getByRole('textbox');
    await user.click(input);

    const option = await screen.findByText('Mouse Inalámbrico XM-M300');
    await user.click(option);

    expect(onChange).toHaveBeenCalledWith('3', mockProducts[2]);
  });
});
