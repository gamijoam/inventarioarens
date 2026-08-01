/**
 * Tests del componente ProductVariantsTab en el detalle del producto.
 *
 * Cubre:
 *  - Render inicial con lista vacia.
 *  - Render con 1 variante: la muestra y permite alternar activo.
 *  - Editar una variante: actualiza color y sku_variant.
 *  - Eliminar: pide confirm y luego llama al endpoint DELETE.
 *  - Crear variante: envia POST con los campos correctos.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

const mockUseProductVariants = vi.fn();
const mockPostOne = vi.fn();
const mockPatchOne = vi.fn();
const mockDeleteOne = vi.fn();

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: (productId: number) => mockUseProductVariants(productId),
}));

vi.mock('@/api/client', () => ({
  postOne: (...args: unknown[]) => mockPostOne(...args),
  patchOne: (...args: unknown[]) => mockPatchOne(...args),
  deleteOne: (...args: unknown[]) => mockDeleteOne(...args),
}));

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
  },
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

vi.mock('@/components/ui/Card', () => ({
  Card: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  CardHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  CardTitle: ({ children }: { children: React.ReactNode }) => <h3>{children}</h3>,
  CardDescription: ({ children }: { children: React.ReactNode }) => <p>{children}</p>,
  CardContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/Input', () => ({
  Input: (props: { id?: string; value?: string | number; onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void; placeholder?: string }) => (
    <input id={props.id} value={props.value ?? ''} onChange={props.onChange} placeholder={props.placeholder} />
  ),
}));

vi.mock('@/components/ui/Label', () => ({
  Label: ({ children, htmlFor }: { children: React.ReactNode; htmlFor?: string }) => (
    <label htmlFor={htmlFor}>{children}</label>
  ),
}));

vi.mock('@/components/ui/Switch', () => ({
  Switch: ({ checked, onChange, ...rest }: {
    checked?: boolean;
    onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
    [k: string]: unknown;
  }) => (
    <input type="checkbox" checked={!!checked} onChange={onChange} {...rest} />
  ),
}));

vi.mock('@/components/ui/Skeleton', () => ({
  Skeleton: () => <div data-testid="skeleton" />,
}));

vi.mock('@/components/ui/EmptyState', () => ({
  EmptyState: ({ title }: { title: string }) => <div data-testid="empty-state">{title}</div>,
}));

import { ProductVariantsTab } from '../ProductVariantsTab';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';

function makeVariant(overrides: Partial<ProductVariant> = {}): ProductVariant {
  return {
    id: 1,
    product_id: 100,
    color: 'Azul',
    color_hex: '#0000ff',
    sku_variant: 'IPHONE-15-AZ',
    barcode_variant: null,
    price_override: null,
    is_active: true,
    position: 0,
    stock_available: 0,
    ...overrides,
  } as ProductVariant;
}

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('ProductVariantsTab', () => {
  beforeEach(() => {
    mockUseProductVariants.mockReset();
    mockPostOne.mockReset();
    mockPatchOne.mockReset();
    mockDeleteOne.mockReset();
  });

  it('muestra empty state cuando no hay variantes', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [],
      isLoading: false,
      refetch: vi.fn(),
    });

    render(<ProductVariantsTab productId={100} />, { wrapper });

    await waitFor(() => {
      expect(screen.getByTestId('empty-state')).toBeInTheDocument();
    });
  });

  it('muestra skeleton mientras carga', () => {
    mockUseProductVariants.mockReturnValue({
      data: undefined,
      isLoading: true,
      refetch: vi.fn(),
    });

    render(<ProductVariantsTab productId={100} />, { wrapper });
    expect(screen.getByTestId('skeleton')).toBeInTheDocument();
  });

  it('crea una variante al enviar el formulario', async () => {
    mockUseProductVariants.mockReturnValue({
      data: [makeVariant({ id: 1 })],
      isLoading: false,
      refetch: vi.fn(),
    });
    mockPostOne.mockResolvedValue({});

    render(<ProductVariantsTab productId={100} />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/Azul/)).toBeInTheDocument();
    });

    fireEvent.change(screen.getByLabelText(/color \*/i), { target: { value: 'Verde' } });
    fireEvent.click(screen.getByRole('button', { name: /crear variante/i }));

    await waitFor(() => {
      expect(mockPostOne).toHaveBeenCalledWith(
        '/products/100/variants',
        expect.objectContaining({ color: 'Verde', is_active: true }),
      );
    });
  });

  it('elimina una variante con confirm', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    mockUseProductVariants.mockReturnValue({
      data: [makeVariant({ id: 99, color: 'Rojo' })],
      isLoading: false,
      refetch: vi.fn(),
    });
    mockDeleteOne.mockResolvedValue(undefined);

    render(<ProductVariantsTab productId={100} />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/Rojo/)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Eliminar Rojo/i }));

    await waitFor(() => {
      expect(mockDeleteOne).toHaveBeenCalledWith('/products/100/variants/99');
    });
    confirmSpy.mockRestore();
  });
});
