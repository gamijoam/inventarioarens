import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ProductSearchDetailModal } from '../ProductSearchDetailModal';
import type { PriceList, Product } from '@/features/inventory-center/schemas';

const mockProducts: Product[] = [
  {
    id: 1,
    tenant_id: 1,
    name: 'ACEITE 20W50 MINERAL MOTUL 1L',
    sku: 'MOT-20W50',
    barcode: '085051008489',
    description: 'Aceite mineral para motor 4T 20W50',
    long_description: '<p>Aceite de alto rendimiento para motores a cuatro tiempos.</p>',
    unit_of_measure: 'unit',
    track_stock: true,
    tracking_type: 'quantity',
    brand: { id: 10, name: 'MOTUL', slug: 'motul' },
    categories: [{ id: 5, name: 'Lubricantes', slug: 'lubricantes', full_path: 'Lubricantes' }],
    base_price: 10.34,
    prices: [
      {
        id: 101,
        price_list_id: 1,
        price_list: { id: 1, name: 'Precio 1 (Detal)', code: 'P1', is_default: true, is_active: true },
        price: 10.34,
        currency: 'USD',
        is_active: true,
      },
      {
        id: 102,
        price_list_id: 2,
        price_list: { id: 2, name: 'Precio 2 (Mayor)', code: 'P2', is_default: false, is_active: true },
        price: 8.5,
        currency: 'USD',
        is_active: true,
      },
      {
        id: 103,
        price_list_id: 3,
        price_list: { id: 3, name: 'Precio 3 (Especial)', code: 'P3', is_default: false, is_active: true },
        price: 7.9,
        currency: 'USD',
        is_active: true,
      },
    ],
    available_stock: 45,
    min_stock: 10,
    max_stock: 100,
    reorder_quantity: 20,
    average_cost: 3.52,
    last_purchase_cost: 3.52,
    is_active: true,
  } as unknown as Product,
  {
    id: 2,
    tenant_id: 1,
    name: 'FILTRO DE ACEITE HF138',
    sku: 'HF138',
    barcode: '742680012345',
    description: 'Filtro de aceite premium',
    unit_of_measure: 'unit',
    track_stock: true,
    tracking_type: 'quantity',
    base_price: 5.0,
    available_stock: 12,
    min_stock: 5,
    is_active: true,
  } as unknown as Product,
];

const mockWarehouses = [
  { id: 1, code: 'ALM-01', name: 'Almacén Principal' },
  { id: 2, code: 'ALM-02', name: 'Almacén Secundario' },
];

const mockPriceLists: PriceList[] = [
  { id: 1, name: 'Precio 1 (Detal)', code: 'P1', is_default: true, is_active: true, sort_order: 1 },
  { id: 2, name: 'Precio 2 (Mayor)', code: 'P2', is_default: false, is_active: true, sort_order: 2 },
  { id: 3, name: 'Precio 3 (Especial)', code: 'P3', is_default: false, is_active: true, sort_order: 3 },
] as PriceList[];

vi.mock('@/features/inventory-center/api', () => ({
  useProducts: () => ({
    data: {
      data: mockProducts,
      meta: {
        current_page: 1,
        last_page: 2,
        per_page: 10,
        total: 15,
      },
    },
    isLoading: false,
  }),
  useProductStockByWarehouse: () => ({
    data: [
      {
        warehouse_id: 1,
        warehouse_name: 'Almacén Principal',
        warehouse_code: 'ALM-01',
        branch_name: 'Sede Centro',
        available: 30,
        reserved: 5,
        damaged: 0,
      },
      {
        warehouse_id: 2,
        warehouse_name: 'Almacén Secundario',
        warehouse_code: 'ALM-02',
        branch_name: 'Sede Norte',
        available: 15,
        reserved: 0,
        damaged: 0,
      },
    ],
    isLoading: false,
  }),
  useProductSerials: () => ({
    data: [
      {
        id: 501,
        product_id: 1,
        serial_number: 'SN-MOT-001',
        serial_type: 'imei',
        warehouse_name: 'Almacén Principal',
        status: 'available',
      },
    ],
    isLoading: false,
  }),
}));

vi.mock('@/features/inventory-center/components/ProductImage', () => ({
  ProductImage: ({ alt }: { alt?: string }) => <div data-testid="product-image">{alt}</div>,
}));

describe('ProductSearchDetailModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
  });

  it('renderiza correctamente el modal con título, buscador, atajos F2..F8 y tabla de resultados', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
        activeRate={{ rate: 70, name: 'BCV' }}
      />,
    );

    // Título y buscador
    expect(screen.getByText('Búsqueda y Detalle de Productos')).toBeInTheDocument();
    expect(screen.getByTestId('search-modal-input')).toBeInTheDocument();

    // Atajos de pie de ventana
    expect(screen.getByText('F2')).toBeInTheDocument();
    expect(screen.getByText('F3')).toBeInTheDocument();
    expect(screen.getByText('F4')).toBeInTheDocument();
    expect(screen.getByText('F5')).toBeInTheDocument();
    expect(screen.getByText('F6')).toBeInTheDocument();
    expect(screen.getByText('F7')).toBeInTheDocument();
    expect(screen.getByText('F8')).toBeInTheDocument();

    // Botones de acción
    expect(screen.getByTestId('search-modal-cancel-btn')).toBeInTheDocument();
    expect(screen.getByTestId('search-modal-accept-btn')).toBeInTheDocument();

    // Filas en la tabla izquierda
    expect(screen.getByText('MOT-20W50')).toBeInTheDocument();
    expect(screen.getAllByText('ACEITE 20W50 MINERAL MOTUL 1L').length).toBeGreaterThan(0);
    expect(screen.getByText('HF138')).toBeInTheDocument();

    // Categoría visible en cabecera y en lista de productos
    expect(screen.getByText('Categoría: Lubricantes')).toBeInTheDocument();
    expect(screen.getAllByText(/Lubricantes/).length).toBeGreaterThan(0);
  });

  it('muestra la pestaña de Precios con comparativa Precio 1, 2 y 3 con impuesto en USD y VES', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
        activeRate={{ rate: 70, name: 'BCV' }}
      />,
    );

    // Encabezados de tabla de precios
    expect(screen.getByText('USD c/Imp')).toBeInTheDocument();
    expect(screen.getByText('VES c/Imp')).toBeInTheDocument();

    // Filas de precios
    expect(screen.getByText('Precio 1 (Detal)')).toBeInTheDocument();
    expect(screen.getByText('Precio 2 (Mayor)')).toBeInTheDocument();
    expect(screen.getByText('Precio 3 (Especial)')).toBeInTheDocument();

    // El precio base del sistema ya incluye el 16% de IVA: 10.34 USD con impuesto
    expect(screen.getAllByText('$10.34').length).toBeGreaterThan(0);
    // Base imponible desglosada (10.34 / 1.16 = 8.91 USD neto)
    expect(screen.getAllByText('$8.91').length).toBeGreaterThan(0);
    // IVA 16% desglosado (10.34 - 8.91 = 1.43 USD)
    expect(screen.getAllByText('$1.43').length).toBeGreaterThan(0);
  });

  it('permite cambiar entre pestañas (Existencia, Datos, Seriales) usando atajos F6, F8 y F7', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
      />,
    );

    const modal = screen.getByTestId('product-search-detail-modal');

    // Cambiar a Existencia via atajo F6
    fireEvent.keyDown(modal, { key: 'F6' });
    expect(screen.getByText('Sede Centro')).toBeInTheDocument();
    expect(screen.getByText('Sede Norte')).toBeInTheDocument();

    // Cambiar a Datos via atajo F8
    fireEvent.keyDown(modal, { key: 'F8' });
    expect(screen.getByText('Descripción Corta')).toBeInTheDocument();
    expect(screen.getByText('Aceite mineral para motor 4T 20W50')).toBeInTheDocument();
    expect(screen.getByText('Costos y Rentabilidad')).toBeInTheDocument();

    // Cambiar a Seriales via atajo F7
    fireEvent.keyDown(modal, { key: 'F7' });
    expect(screen.getByText('SN-MOT-001')).toBeInTheDocument();
  });

  it('llama a onSelect al presionar Enter o F2, o hacer clic en Aceptar', () => {
    const onSelect = vi.fn();
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={onSelect}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTestId('search-modal-accept-btn'));
    expect(onSelect).toHaveBeenCalledWith(mockProducts[0]);
  });

  it('llama a onClose al presionar Esc o hacer clic en Salir', () => {
    const onClose = vi.fn();
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={onClose}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTestId('search-modal-cancel-btn'));
    expect(onClose).toHaveBeenCalled();
  });

  it('permite navegar con flechas del teclado para seleccionar otro producto', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
      />,
    );

    const modal = screen.getByTestId('product-search-detail-modal');

    // Flecha abajo para seleccionar el segundo producto
    fireEvent.keyDown(modal, { key: 'ArrowDown' });

    // En la columna derecha debe actualizarse al segundo producto
    expect(screen.getAllByText('FILTRO DE ACEITE HF138').length).toBeGreaterThan(0);
  });

  it('agrega el producto con Enter incluso tras hacer clic en pestañas de la derecha (existencia o precios)', () => {
    const onSelect = vi.fn();
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={onSelect}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
      />,
    );

    // Simular clic en la pestaña Existencia
    const existenciaTab = screen.getByText('[F6] Existencia');
    fireEvent.click(existenciaTab);

    // Presionar Enter en la pestaña enfocada
    fireEvent.keyDown(existenciaTab, { key: 'Enter' });

    // Debe llamar a onSelect con el producto seleccionado
    expect(onSelect).toHaveBeenCalledWith(mockProducts[0]);
  });

  it('colapsa y expande el panel de detalle con el botón', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
      />,
    );

    // Por defecto el detalle del producto seleccionado es visible.
    expect(screen.getByText('Categoría: Lubricantes')).toBeInTheDocument();

    // Al colapsar, el detalle desaparece y el listado sigue visible.
    fireEvent.click(screen.getByTestId('search-modal-detail-toggle'));
    expect(screen.queryByText('Categoría: Lubricantes')).not.toBeInTheDocument();
    expect(screen.getByTestId('search-modal-row-1')).toBeInTheDocument();

    // Al volver a pulsar, el detalle reaparece.
    fireEvent.click(screen.getByTestId('search-modal-detail-toggle'));
    expect(screen.getByText('Categoría: Lubricantes')).toBeInTheDocument();
  });

  it('recuerda en localStorage que el panel de detalle fue colapsado', () => {
    window.localStorage.setItem('pos.f3.detailOpen', 'false');

    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
      />,
    );

    expect(screen.queryByText('Categoría: Lubricantes')).not.toBeInTheDocument();
    expect(screen.getByTestId('search-modal-row-1')).toBeInTheDocument();
  });

  it('abre el detalle al seleccionar una fila si estaba colapsado', () => {
    window.localStorage.setItem('pos.f3.detailOpen', 'false');

    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
      />,
    );

    expect(screen.queryByText('[F5] Precios')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('search-modal-row-2'));

    expect(screen.getByText('[F5] Precios')).toBeInTheDocument();
  });

  it('no muestra el selector de empaque ni la tarjeta de stock/precio base', () => {
    render(
      <ProductSearchDetailModal
        open={true}
        onClose={vi.fn()}
        onSelect={vi.fn()}
        warehouses={mockWarehouses}
        warehouseId={1}
        onWarehouseChange={vi.fn()}
        priceLists={mockPriceLists}
        activeRate={{ rate: 70, name: 'BCV' }}
      />,
    );

    // El selector de empaque (x1 / x6 / x12) ya no existe.
    expect(screen.queryByText('Empaque:')).not.toBeInTheDocument();
    expect(screen.queryByText('x1')).not.toBeInTheDocument();
    expect(screen.queryByText('x6')).not.toBeInTheDocument();
    expect(screen.queryByText('x12')).not.toBeInTheDocument();

    // La tarjeta redundante de stock y precio base fue eliminada.
    expect(screen.queryByText('Precio base')).not.toBeInTheDocument();

    // La tabla comparativa sigue disponible con sus columnas clave.
    expect(screen.getByText('USD c/Imp')).toBeInTheDocument();
    expect(screen.getByText('VES c/Imp')).toBeInTheDocument();
  });
});
