import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { InventoryCatalogWorkspace } from '../InventoryCatalogWorkspace';

// Mock de @tanstack/react-router
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: any) => <a href={to}>{children}</a>,
  useNavigate: () => vi.fn(),
}));

// Mock de Can
vi.mock('@/components/permissions/Can', () => ({
  Can: ({ children }: any) => <>{children}</>,
}));

// Mock de hooks de API
vi.mock('@/features/inventory-center/api', () => ({
  useCategories: () => ({
    data: [
      { id: 1, name: 'Cauchos y Tripas', slug: 'cauchos' },
      { id: 2, name: 'Baterías', slug: 'baterias' },
    ],
  }),
  useBrands: () => ({ data: [{ id: 1, name: 'Pirelli' }] }),
  useWarrantyPolicies: () => ({ data: [] }),
  useUpdateProduct: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useProductStockByWarehouse: () => ({
    data: [
      {
        warehouse_id: 1,
        warehouse_name: 'Almacén Principal',
        warehouse_code: 'ALM-01',
        available: 15,
        reserved: 2,
        damaged: 0,
      },
    ],
    isLoading: false,
  }),
}));

// Mock de EditProductDialog
vi.mock('@/features/inventory-center/dialogs/EditProductDialog', () => ({
  EditProductDialog: ({ open }: any) => (open ? <div data-testid="mock-edit-dialog">Edit Dialog</div> : null),
}));

function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 101,
    tenant_id: 1,
    name: 'Caucho Bera 2.75-18 Delantero',
    sku: 'CAU-001',
    barcode: '7591234567890',
    description: null,
    long_description: null,
    image_url: 'https://images.example.com/caucho.jpg',
    primary_image_url: 'https://images.example.com/caucho.jpg',
    images: [],
    tracking_type: 'quantity',
    unit_of_measure: 'unit',
    track_stock: true,
    brand_id: 1,
    brand: { id: 1, name: 'Pirelli' },
    categories: [{ id: 1, name: 'Cauchos y Tripas' }],
    tags: [],
    base_price: 25,
    pricing_mode: 'manual',
    sale_currency: 'USD',
    prices: [
      {
        id: 1,
        price_list_id: 1,
        price: 25,
        currency: 'USD',
        is_active: true,
        price_list: { id: 1, name: 'Detal', is_default: true, is_active: true },
      },
    ],
    available_stock: 15,
    is_active: true,
    ...overrides,
  } as unknown as Product;
}

const mockPriceLists: PriceList[] = [
  { id: 1, name: 'Detal', code: 'DETAL', is_default: true, is_active: true } as PriceList,
];

const mockActiveRate = { id: 1, rate: 850, code: 'USD_VES', name: 'Tasa BCV' };

function renderWorkspace(props: Partial<React.ComponentProps<typeof InventoryCatalogWorkspace>> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const baseProps = {
    products: [makeProduct()],
    totalProducts: 1,
    totalPages: 1,
    currentPage: 1,
    isLoading: false,
    search: '',
    onSearchChange: vi.fn(),
    onWarehouseChange: vi.fn(),
    tracking: 'all' as const,
    onTrackingChange: vi.fn(),
    stock: 'all' as const,
    onStockChange: vi.fn(),
    status: 'all' as const,
    onStatusChange: vi.fn(),
    onPageChange: vi.fn(),
    warehouses: [{ id: 1, code: 'ALM-01', name: 'Almacén Principal' }],
    priceLists: mockPriceLists,
    activeRate: mockActiveRate,
    onNewProduct: vi.fn(),
  };

  render(
    <QueryClientProvider client={queryClient}>
      <InventoryCatalogWorkspace {...baseProps} {...props} />
    </QueryClientProvider>,
  );
}

describe('InventoryCatalogWorkspace', () => {
  it('renderiza la vista catálogo con tarjetas de productos, fotos y precios', () => {
    renderWorkspace();

    expect(screen.getByText('Caucho Bera 2.75-18 Delantero')).toBeInTheDocument();
    expect(screen.getByText('SKU: CAU-001')).toBeInTheDocument();
    expect(screen.getByText(/15 disponibles/)).toBeInTheDocument();
    expect(screen.getByText('$25,00')).toBeInTheDocument();
    expect(screen.getByText(/Bs 21\.250,00/)).toBeInTheDocument();

    const img = screen.getByAltText('Caucho Bera 2.75-18 Delantero');
    expect(img).toHaveAttribute('src', 'https://images.example.com/caucho.jpg');
  });

  it('muestra badge Agotado cuando el stock es 0 o nulo', () => {
    renderWorkspace({
      products: [makeProduct({ id: 102, name: 'Batería Gel 12V', available_stock: 0 })],
      warehouses: [],
    });

    expect(screen.getByText('Agotado')).toBeInTheDocument();
  });

  it('permite filtrar por categorías desde el selector', () => {
    const onCategoryChange = vi.fn();
    renderWorkspace({ categoryId: undefined, onCategoryChange });

    fireEvent.click(screen.getByTitle('Filtrar por categoría'));
    fireEvent.click(screen.getByRole('button', { name: /Cauchos y Tripas/ }));

    expect(onCategoryChange).toHaveBeenCalledWith(1);
  });

  it('abre el modal de gestión rápida e inspección al hacer clic en la tarjeta', () => {
    renderWorkspace({ warehouses: [] });

    fireEvent.click(screen.getByTestId('catalog-card-101'));

    expect(screen.getByText('Precios de Venta y Costo')).toBeInTheDocument();
    expect(screen.getByText('Existencias')).toBeInTheDocument();
    expect(screen.getByText('Almacén Principal')).toBeInTheDocument();
  });

  it('abre el diálogo de edición al hacer clic en el botón de edición rápida', () => {
    renderWorkspace({ warehouses: [] });

    fireEvent.click(screen.getByTestId('catalog-edit-btn-101'));

    expect(screen.getByTestId('mock-edit-dialog')).toBeInTheDocument();
  });

  it('agrega al mini carrito con el botón +', () => {
    renderWorkspace();
    fireEvent.click(screen.getByTestId('catalog-add-btn-101'));
    expect(screen.getByTestId('inventory-pending-cart-button')).toBeInTheDocument();
  });
});
