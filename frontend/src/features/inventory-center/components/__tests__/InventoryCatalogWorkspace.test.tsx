import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { InventoryCatalogWorkspace } from '../InventoryCatalogWorkspace';

// Mock de @tanstack/react-router
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: any) => <a href={to}>{children}</a>,
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
  {
    id: 1,
    name: 'Detal',
    code: 'DETAL',
    is_default: true,
    is_active: true,
  } as PriceList,
];

const mockActiveRate = {
  id: 1,
  rate: 850,
  code: 'USD_VES',
  name: 'Tasa BCV',
};

describe('InventoryCatalogWorkspace', () => {
  it('renderiza la vista catálogo con tarjetas de productos, fotos y precios', () => {
    const product = makeProduct();
    render(
      <InventoryCatalogWorkspace
        products={[product]}
        totalProducts={1}
        totalPages={1}
        currentPage={1}
        isLoading={false}
        search=""
        onSearchChange={vi.fn()}
        onWarehouseChange={vi.fn()}
        tracking="all"
        onTrackingChange={vi.fn()}
        stock="all"
        onStockChange={vi.fn()}
        status="all"
        onStatusChange={vi.fn()}
        onPageChange={vi.fn()}
        warehouses={[{ id: 1, code: 'ALM-01', name: 'Almacén Principal' }]}
        priceLists={mockPriceLists}
        activeRate={mockActiveRate}
        onNewProduct={vi.fn()}
      />,
    );

    // Debe mostrar el nombre del producto
    expect(screen.getByText('Caucho Bera 2.75-18 Delantero')).toBeInTheDocument();

    // Debe mostrar el SKU
    expect(screen.getByText('SKU: CAU-001')).toBeInTheDocument();

    // Debe mostrar la disponibilidad de stock
    expect(screen.getByText('15 disp.')).toBeInTheDocument();

    // Debe mostrar el precio en USD ($25,00 con locale es-VE)
    expect(screen.getByText('$25,00')).toBeInTheDocument();

    // Debe mostrar el precio convertido en VES (25 * 850 = 21.250,00)
    expect(screen.getByText(/Bs 21\.250,00/)).toBeInTheDocument();

    // Debe mostrar la imagen con alt
    const img = screen.getByAltText('Caucho Bera 2.75-18 Delantero');
    expect(img).toHaveAttribute('src', 'https://images.example.com/caucho.jpg');
  });

  it('muestra badge Agotado cuando el stock es 0 o nulo', () => {
    const outProduct = makeProduct({ id: 102, name: 'Batería Gel 12V', available_stock: 0 });
    render(
      <InventoryCatalogWorkspace
        products={[outProduct]}
        totalProducts={1}
        totalPages={1}
        currentPage={1}
        isLoading={false}
        search=""
        onSearchChange={vi.fn()}
        onWarehouseChange={vi.fn()}
        tracking="all"
        onTrackingChange={vi.fn()}
        stock="all"
        onStockChange={vi.fn()}
        status="all"
        onStatusChange={vi.fn()}
        onPageChange={vi.fn()}
        warehouses={[]}
        priceLists={mockPriceLists}
        activeRate={mockActiveRate}
        onNewProduct={vi.fn()}
      />,
    );

    expect(screen.getByText('Agotado')).toBeInTheDocument();
  });

  it('permite filtrar por categorías mediante chips horizontales', () => {
    const onCategoryChange = vi.fn();
    render(
      <InventoryCatalogWorkspace
        products={[makeProduct()]}
        totalProducts={1}
        totalPages={1}
        currentPage={1}
        isLoading={false}
        search=""
        onSearchChange={vi.fn()}
        categoryId={undefined}
        onCategoryChange={onCategoryChange}
        onWarehouseChange={vi.fn()}
        tracking="all"
        onTrackingChange={vi.fn()}
        stock="all"
        onStockChange={vi.fn()}
        status="all"
        onStatusChange={vi.fn()}
        onPageChange={vi.fn()}
        warehouses={[]}
        priceLists={mockPriceLists}
        activeRate={mockActiveRate}
        onNewProduct={vi.fn()}
      />,
    );

    // Click en el chip de "Cauchos y Tripas"
    const categoryBtn = screen.getByRole('button', { name: 'Cauchos y Tripas' });
    fireEvent.click(categoryBtn);

    expect(onCategoryChange).toHaveBeenCalledWith(1);
  });

  it('abre el modal de gestión rápida e inspección al hacer clic en la tarjeta', () => {
    const product = makeProduct();
    render(
      <InventoryCatalogWorkspace
        products={[product]}
        totalProducts={1}
        totalPages={1}
        currentPage={1}
        isLoading={false}
        search=""
        onSearchChange={vi.fn()}
        onWarehouseChange={vi.fn()}
        tracking="all"
        onTrackingChange={vi.fn()}
        stock="all"
        onStockChange={vi.fn()}
        status="all"
        onStatusChange={vi.fn()}
        onPageChange={vi.fn()}
        warehouses={[]}
        priceLists={mockPriceLists}
        activeRate={mockActiveRate}
        onNewProduct={vi.fn()}
      />,
    );

    // Clic en la tarjeta
    const card = screen.getByTestId('catalog-card-101');
    fireEvent.click(card);

    // Se abre el modal con las tarifas y almacenes
    expect(screen.getByText('Precios de Venta')).toBeInTheDocument();
    expect(screen.getByText('Existencias por Almacén')).toBeInTheDocument();
    expect(screen.getByText('Almacén Principal')).toBeInTheDocument();
  });

  it('abre el diálogo de edición al hacer clic en el botón de edición rápida', () => {
    const product = makeProduct();
    render(
      <InventoryCatalogWorkspace
        products={[product]}
        totalProducts={1}
        totalPages={1}
        currentPage={1}
        isLoading={false}
        search=""
        onSearchChange={vi.fn()}
        onWarehouseChange={vi.fn()}
        tracking="all"
        onTrackingChange={vi.fn()}
        stock="all"
        onStockChange={vi.fn()}
        status="all"
        onStatusChange={vi.fn()}
        onPageChange={vi.fn()}
        warehouses={[]}
        priceLists={mockPriceLists}
        activeRate={mockActiveRate}
        onNewProduct={vi.fn()}
      />,
    );

    const editBtn = screen.getByTestId('catalog-edit-btn-101');
    fireEvent.click(editBtn);

    expect(screen.getByTestId('mock-edit-dialog')).toBeInTheDocument();
  });
});
