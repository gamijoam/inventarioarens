import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ProductAutocomplete, type ProductAutocompleteOption } from './ProductAutocomplete';

const { mockUseProductsForPurchase } = vi.hoisted(() => ({
  mockUseProductsForPurchase: vi.fn(),
}));

vi.mock('@/features/purchases/api', () => ({
  useProductsForPurchase: mockUseProductsForPurchase,
}));

describe('ProductAutocomplete', () => {
  beforeEach(() => {
    mockUseProductsForPurchase.mockReturnValue({
      data: [
        {
          id: 20,
          name: 'IPHONE 20',
          sku: 'IPHONE-20',
          barcode: null,
          tracking_type: 'serialized',
        },
      ],
    });
  });

  it('muestra y permite seleccionar un producto serializado', async () => {
    const onChange = vi.fn();
    render(<ProductAutocomplete value={null} onChange={onChange} />);

    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    const option = await screen.findByRole('option', { name: /IPHONE 20/i });
    expect(option).toHaveTextContent('Serializado');

    fireEvent.click(option);
    expect(onChange).toHaveBeenCalledWith(
      20,
      expect.objectContaining({ name: 'IPHONE 20', tracking_type: 'serialized' }),
    );
  });

  it('conserva visualmente el producto seleccionado en el formulario', async () => {
    function ControlledAutocomplete() {
      const [value, setValue] = useState<number | null>(null);
      const [product, setProduct] = useState<{
        id: number;
        name: string;
        sku: string | null;
        barcode: string | null;
        tracking_type?: string;
      } | null>(null);

      return (
        <ProductAutocomplete
          value={value}
          selectedProduct={product}
          onChange={(nextValue, nextProduct) => {
            setValue(nextValue);
            setProduct(nextProduct ?? null);
          }}
        />
      );
    }

    render(<ControlledAutocomplete />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));
    fireEvent.click(await screen.findByRole('option', { name: /IPHONE 20/i }));

    expect(screen.getByText('IPHONE 20')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Quitar IPHONE 20' })).toBeInTheDocument();
    expect(
      screen.queryByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'),
    ).not.toBeInTheDocument();
  });

  it('mantiene los resultados dentro de un area desplazable', () => {
    mockUseProductsForPurchase.mockReturnValue({
      data: Array.from({ length: 40 }, (_, index) => ({
        id: index + 1,
        name: `PRODUCTO ${index + 1}`,
        sku: `SKU-${index + 1}`,
        barcode: null,
        tracking_type: 'quantity',
      })),
    });

    render(<ProductAutocomplete value={null} onChange={vi.fn()} />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    const results = screen.getByTestId('purchase-product-results');
    expect(results.querySelector('.overflow-y-auto')).toBeInTheDocument();
  });

  it('ofrece crear el producto cuando no hay resultados', async () => {
    mockUseProductsForPurchase.mockReturnValue({ data: [], isFetching: false });
    const onProductNotFound = vi.fn();

    render(
      <ProductAutocomplete
        value={null}
        onChange={vi.fn()}
        onProductNotFound={onProductNotFound}
      />,
    );

    const input = screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...');
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: 'REPUESTO NUEVO' } });

    fireEvent.click(await screen.findByText('Crear producto con este nombre'));

    expect(onProductNotFound).toHaveBeenCalledWith('REPUESTO NUEVO');
  });

  it('indica si el producto esta activo o inactivo', async () => {
    mockUseProductsForPurchase.mockReturnValue({
      data: [
        { id: 1, name: 'PRODUCTO ACTIVO', sku: 'ACT-1', barcode: null, tracking_type: 'quantity', is_active: true },
        { id: 2, name: 'PRODUCTO INACTIVO', sku: 'INA-1', barcode: null, tracking_type: 'quantity', is_active: false },
      ],
      isFetching: false,
    });

    render(<ProductAutocomplete value={null} onChange={vi.fn()} />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    expect(await screen.findByText('Activo')).toBeInTheDocument();
    expect(screen.getByText('Inactivo')).toBeInTheDocument();
  });

  it('muestra el stock y permite activar/desactivar el producto', async () => {
    mockUseProductsForPurchase.mockReturnValue({
      data: [
        {
          id: 30,
          name: 'ACEITE 20W50',
          sku: 'ACE-20',
          barcode: null,
          tracking_type: 'quantity',
          is_active: true,
          available_stock: 7,
        },
      ],
      isFetching: false,
    });
    const onToggleActive = vi.fn();

    function Controlled() {
      const [value, setValue] = useState<number | null>(null);
      const [product, setProduct] = useState<ProductAutocompleteOption | null>(null);

      return (
        <ProductAutocomplete
          value={value}
          selectedProduct={product}
          onToggleActive={onToggleActive}
          onChange={(nextValue, nextProduct) => {
            setValue(nextValue);
            setProduct(nextProduct ?? null);
          }}
        />
      );
    }

    render(<Controlled />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    expect(await screen.findByText(/Stock: 7/)).toBeInTheDocument();

    fireEvent.click(await screen.findByRole('option', { name: /ACEITE 20W50/i }));

    const toggle = await screen.findByTestId('product-toggle-active-30');
    expect(toggle).toHaveTextContent('Desactivar');

    fireEvent.click(toggle);
    expect(onToggleActive).toHaveBeenCalledWith(30, false);
  });

  it('muestra completo el nombre de productos con nombres largos', async () => {
    const longName =
      'ACEITE SINTETICO DE ALTO RENDIMIENTO PARA MOTOR 4T 20W50 MARCA MOTUL 1 LITRO';

    mockUseProductsForPurchase.mockReturnValue({
      data: [
        {
          id: 99,
          name: longName,
          sku: 'LONG-1',
          barcode: null,
          tracking_type: 'quantity',
          is_active: true,
          available_stock: 3,
        },
      ],
      isFetching: false,
    });

    render(<ProductAutocomplete value={null} onChange={vi.fn()} />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    const name = await screen.findByText(longName);
    expect(name.className).not.toContain('truncate');
    expect(name.className).toContain('break-words');
  });
});
