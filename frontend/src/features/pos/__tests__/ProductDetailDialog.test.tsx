/**
 * Tests del ProductDetailDialog del POS (se abre con el icono "i").
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ProductDetailDialog } from '../ProductDetailDialog';
import type { Product } from '@/features/inventory-center/schemas';

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: () => ({
    data: [
      { id: 1, product_id: 100, color: 'Rojo', color_hex: '#ff0000', sku_variant: 'ROJ', stock_available: 3, position: 0, is_active: true },
      { id: 2, product_id: 100, color: 'Azul', color_hex: '#0000ff', sku_variant: 'AZU', stock_available: 0, position: 1, is_active: true },
    ],
    isLoading: false,
  }),
}));

vi.mock('@/features/inventory-center/components/ProductImage', () => ({
  ProductImage: ({ alt }: { alt?: string }) => <div data-testid="product-image">{alt}</div>,
}));

function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 100,
    tenant_id: 1,
    name: 'LAVADORA AIWA DOBLE TINA 16 KG AWHTTX1601',
    sku: 'LAVADORA16',
    barcode: null,
    description: 'Lavadora doble tina de 16 kg.',
    long_description: '<p>Descripción larga del producto</p>',
    image_url: null,
    tracking_type: 'quantity',
    unit_of_measure: 'unit',
    track_stock: true,
    brand: { id: 1, name: 'AIWA', slug: 'aiwa' },
    categories: [{ id: 1, name: 'Linea Blanca', slug: 'linea-blanca', full_path: 'Linea Blanca' }],
    tags: [{ id: 1, name: 'Oferta', slug: 'oferta', color: '#00ff00' }],
    base_price: 200,
    available_stock: 5,
    min_stock: 0,
    is_active: true,
    ...overrides,
  } as Product;
}

describe('ProductDetailDialog', () => {
  beforeEach(() => vi.clearAllMocks());

  it('muestra nombre, descripcion, categoria, tags, marca y precio', () => {
    const onClose = vi.fn();
    render(
      <ProductDetailDialog
        product={makeProduct()}
        warehouseId={1}
        priceListName="DETAL"
        onClose={onClose}
        onAdd={vi.fn()}
      />,
    );

    expect(
      screen.getAllByText('LAVADORA AIWA DOBLE TINA 16 KG AWHTTX1601').length,
    ).toBeGreaterThan(0);
    expect(screen.getByText('Lavadora doble tina de 16 kg.')).toBeInTheDocument();
    expect(screen.getByText('Descripción larga del producto')).toBeInTheDocument();
    expect(screen.getByText('Linea Blanca')).toBeInTheDocument();
    expect(screen.getByText('Oferta')).toBeInTheDocument();
    expect(screen.getByText('AIWA')).toBeInTheDocument();
    expect(screen.getByText(/\$200\.00/)).toBeInTheDocument();
  });

  it('muestra las variantes con stock', () => {
    render(
      <ProductDetailDialog
        product={makeProduct()}
        warehouseId={1}
        priceListName="DETAL"
        onClose={vi.fn()}
        onAdd={vi.fn()}
      />,
    );

    expect(screen.getByTestId('detail-variants')).toBeInTheDocument();
    expect(screen.getByText('Rojo')).toBeInTheDocument();
    expect(screen.getByText('Azul')).toBeInTheDocument();
  });

  it('llama onAdd al hacer click en Agregar al ticket', async () => {
    const onAdd = vi.fn();
    render(
      <ProductDetailDialog
        product={makeProduct()}
        warehouseId={1}
        priceListName="DETAL"
        onClose={vi.fn()}
        onAdd={onAdd}
      />,
    );

    fireEvent.click(screen.getByTestId('detail-add'));
    await waitFor(() => expect(onAdd).toHaveBeenCalledTimes(1));
  });

  it('llama onClose al hacer click en Cerrar', () => {
    const onClose = vi.fn();
    render(
      <ProductDetailDialog
        product={makeProduct()}
        warehouseId={1}
        priceListName="DETAL"
        onClose={onClose}
        onAdd={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTestId('detail-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
