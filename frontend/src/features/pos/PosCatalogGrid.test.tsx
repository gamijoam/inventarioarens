import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Product } from '@/features/inventory-center/schemas';

const { mockUseProducts, mockUseCategories } = vi.hoisted(() => ({
  mockUseProducts: vi.fn(),
  mockUseCategories: vi.fn(),
}));

vi.mock('@/features/inventory-center/api', () => ({
  useProducts: mockUseProducts,
  useCategories: mockUseCategories,
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

import { PosCatalogGrid } from './PosCatalogGrid';

function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 3150,
    name: 'CAUCHO 3.00-18 TT - Q91 QUEIPA',
    sku: 'Q091-0410',
    barcode: null,
    track_stock: true,
    tracking_type: 'quantity',
    unit_of_measure: 'unit',
    base_price: 10,
    sale_currency: 'USD',
    available_stock: 9,
    ...overrides,
  } as unknown as Product;
}

function renderGrid(product: Product, onSelectProduct = vi.fn()) {
  mockUseProducts.mockReturnValue({
    data: {
      data: [product],
      meta: { current_page: 1, last_page: 1, per_page: 18, total: 1 },
    },
    isLoading: false,
    isError: false,
  });
  mockUseCategories.mockReturnValue({ data: [] });

  render(
    <PosCatalogGrid
      warehouseId={4}
      priceLists={[]}
      selectedPriceList={null}
      activeRate={null}
      onSelectProduct={onSelectProduct}
    />,
  );

  return onSelectProduct;
}

describe('PosCatalogGrid', () => {
  beforeEach(() => {
    mockUseProducts.mockReset();
    mockUseCategories.mockReset();
  });

  it('al tocar una tarjeta con stock agrega el producto (con su stock)', () => {
    const onSelect = renderGrid(makeProduct());

    fireEvent.click(screen.getByText('CAUCHO 3.00-18 TT - Q91 QUEIPA'));

    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 3150, available_stock: 9 }));
  });

  it('NO agrega al tocar una tarjeta agotada (available_stock 0)', () => {
    const onSelect = renderGrid(makeProduct({ available_stock: 0 }));

    fireEvent.click(screen.getByText('CAUCHO 3.00-18 TT - Q91 QUEIPA'));

    expect(onSelect).not.toHaveBeenCalled();
  });

  it('muestra u oculta el valor en Bs según la opción (showVesPrice)', () => {
    mockUseProducts.mockReturnValue({
      data: { data: [makeProduct()], meta: { current_page: 1, last_page: 1, per_page: 18, total: 1 } },
      isLoading: false,
      isError: false,
    });
    mockUseCategories.mockReturnValue({ data: [] });

    const { rerender } = render(
      <PosCatalogGrid
        warehouseId={4}
        priceLists={[]}
        selectedPriceList={null}
        activeRate={{ rate: 100, name: 'BCV' }}
        showVesPrice
        onSelectProduct={vi.fn()}
      />,
    );
    expect(screen.getByText(/Bs/)).toBeInTheDocument();

    rerender(
      <PosCatalogGrid
        warehouseId={4}
        priceLists={[]}
        selectedPriceList={null}
        activeRate={{ rate: 100, name: 'BCV' }}
        showVesPrice={false}
        onSelectProduct={vi.fn()}
      />,
    );
    expect(screen.queryByText(/Bs/)).not.toBeInTheDocument();
  });

  it('filtra por categoría desde el selector', async () => {
    mockUseProducts.mockReturnValue({
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 18, total: 0 } },
      isLoading: false,
      isError: false,
    });
    mockUseCategories.mockReturnValue({ data: [{ id: 7, name: 'CAUCHOS' }] });

    render(
      <PosCatalogGrid
        warehouseId={4}
        priceLists={[]}
        selectedPriceList={null}
        activeRate={null}
        onSelectProduct={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTitle('Filtrar por categoría'));
    fireEvent.click(await screen.findByText('CAUCHOS'));

    const lastFilters = mockUseProducts.mock.calls.at(-1)?.[0] as { category_id?: number } | undefined;
    expect(lastFilters?.category_id).toBe(7);
  });

  it('NO consulta productos sin almacén cuando warehouseId es null', () => {
    mockUseProducts.mockReturnValue({
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 18, total: 0 } },
      isLoading: false,
      isError: false,
    });
    mockUseCategories.mockReturnValue({ data: [] });

    render(
      <PosCatalogGrid
        warehouseId={null}
        priceLists={[]}
        selectedPriceList={null}
        activeRate={null}
        onSelectProduct={vi.fn()}
      />,
    );

    const filters = mockUseProducts.mock.calls[0]?.[0] as { warehouse_id?: number } | undefined;
    expect(filters?.warehouse_id).toBeUndefined();
  });
});
