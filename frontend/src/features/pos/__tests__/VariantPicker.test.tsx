/**
 * Tests del componente VariantPicker para el POS.
 *
 * Cubre:
 *  - Render con lista vacia: muestra empty state.
 *  - Render con 1 variante: no muestra modal real, el padre decide.
 *  - Render con 2+ variantes: lista todas y permite seleccionar.
 *  - Submit emite el variant + quantity al callback onSelect.
 *  - Stock filter: el submit se deshabilita si quantity > stock_available.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

const mockUseProductVariants = vi.fn();

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: (productId: number, warehouseId?: number | null) =>
    mockUseProductVariants(productId, warehouseId),
}));

vi.mock('@/lib/cn', () => ({
  cn: (...args: unknown[]) => args.filter(Boolean).join(' '),
}));

vi.mock('@/components/ui/Button', () => ({
  Button: ({ children, onClick, disabled, type, ...rest }: {
    children: React.ReactNode;
    onClick?: () => void;
    disabled?: boolean;
    type?: 'button' | 'submit';
    [k: string]: unknown;
  }) => (
    <button type={type ?? 'button'} disabled={disabled} onClick={onClick} {...rest}>
      {children}
    </button>
  ),
}));

vi.mock('@/components/ui/Dialog', () => ({
  Dialog: ({ children, open }: { children: React.ReactNode; open: boolean }) =>
    open ? <div data-testid="dialog-root">{children}</div> : null,
  DialogContent: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="dialog-content">{children}</div>
  ),
  DialogHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  DialogTitle: ({ children }: { children: React.ReactNode }) => <h2>{children}</h2>,
  DialogFooter: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/Label', () => ({
  Label: ({ children, htmlFor }: { children: React.ReactNode; htmlFor?: string }) => (
    <label htmlFor={htmlFor}>{children}</label>
  ),
}));

import { VariantPicker } from '../VariantPicker';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';

function makeVariant(overrides: Partial<ProductVariant> = {}): ProductVariant {
  return {
    id: 1,
    product_id: 100,
    color: 'Azul',
    color_hex: '#0000ff',
    sku_variant: null,
    barcode_variant: null,
    price_override: null,
    is_active: true,
    position: 0,
    stock_available: 5,
    ...overrides,
  } as ProductVariant;
}

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('POS VariantPicker', () => {
  beforeEach(() => {
    mockUseProductVariants.mockReset();
  });

  it('muestra empty state cuando no hay variantes', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [],
      isLoading: false,
    });

    render(
      <VariantPicker
        productId={1}
        productName="iPhone"
        warehouseId={null}
        open
        onClose={() => undefined}
        onSelect={() => undefined}
      />,
      { wrapper },
    );

    await waitFor(() => {
      expect(screen.getByText(/no tiene variantes activas/i)).toBeInTheDocument();
    });
  });

  it('lista las variantes activas y emite onSelect al confirmar', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [
        makeVariant({ id: 1, color: 'Azul', position: 0, stock_available: 5 }),
        makeVariant({ id: 2, color: 'Negro', position: 1, stock_available: 2 }),
      ],
      isLoading: false,
    });

    const onSelect = vi.fn();
    render(
      <VariantPicker
        productId={1}
        productName="iPhone"
        warehouseId={1}
        open
        onClose={() => undefined}
        onSelect={onSelect}
      />,
      { wrapper },
    );

    // Esperar a que se renderice la lista.
    await waitFor(() => {
      expect(screen.getByTestId('variant-option-1')).toBeInTheDocument();
      expect(screen.getByTestId('variant-option-2')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId('variant-option-2'));
    fireEvent.click(screen.getByRole('button', { name: /agregar al carrito/i }));

    expect(onSelect).toHaveBeenCalledWith({
      variant: expect.objectContaining({ id: 2, color: 'Negro' }),
      quantity: 1,
    });
  });

  it('deshabilita el submit cuando la cantidad supera el stock', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [
        makeVariant({ id: 1, color: 'Azul', position: 0, stock_available: 2 }),
      ],
      isLoading: false,
    });

    render(
      <VariantPicker
        productId={1}
        productName="iPhone"
        warehouseId={1}
        open
        onClose={() => undefined}
        onSelect={() => undefined}
      />,
      { wrapper },
    );

    await waitFor(() => {
      expect(screen.getByTestId('variant-option-1')).toBeInTheDocument();
    });

    const quantityInput = screen.getByLabelText(/cantidad/i);
    fireEvent.change(quantityInput, { target: { value: '5' } });

    await waitFor(() => {
      expect(
        screen.getByRole('button', { name: /agregar al carrito/i }),
      ).toBeDisabled();
    });
  });

  it('respeta quantity inicial del padre', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [makeVariant({ id: 1, color: 'Azul', position: 0, stock_available: 5 })],
      isLoading: false,
    });

    const onSelect = vi.fn();
    render(
      <VariantPicker
        productId={1}
        productName="iPhone"
        warehouseId={1}
        open
        initialQuantity={3}
        onClose={() => undefined}
        onSelect={onSelect}
      />,
      { wrapper },
    );

    await waitFor(() => {
      expect(screen.getByLabelText(/cantidad/i)).toHaveValue(3);
    });

    fireEvent.click(screen.getByTestId('variant-option-1'));
    fireEvent.click(screen.getByRole('button', { name: /agregar al carrito/i }));

    expect(onSelect).toHaveBeenCalledWith({
      variant: expect.objectContaining({ id: 1 }),
      quantity: 3,
    });
  });
});
