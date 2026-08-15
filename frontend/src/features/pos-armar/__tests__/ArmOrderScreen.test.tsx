import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  holdMutate: vi.fn(),
  signOut: vi.fn(),
  navigate: vi.fn(),
  createCustomer: vi.fn(),
  getProductVariants: vi.fn(),
  quoteProductForPos: vi.fn<(productId: number, priceListId: number) => Promise<unknown>>(),
  bootstrapWarehouses: [
    { id: 7, name: 'Principal', code: 'MAIN', status: 'active' },
    { id: 8, name: 'Deposito', code: 'DEP', status: 'active' },
  ],
  fallbackWarehouses: [] as {
    id: number;
    name: string;
    code: string;
    status?: string;
    is_active?: boolean;
  }[],
  bootstrapPriceLists: [] as Record<string, unknown>[],
  productQuery: vi.fn(),
  productResult: {
    isLoading: false,
    isError: false,
    data: { data: [] as Record<string, unknown>[] },
  },
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({ signOut: mocks.signOut }),
}));

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => mocks.navigate,
}));

vi.mock('@/components/layout/PosShell', () => ({
  PosShell: ({ children, onExit }: { children: React.ReactNode; onExit?: () => void }) => (
    <div>
      <button type="button" onClick={onExit}>
        Salir del POS
      </button>
      {children}
    </div>
  ),
}));

vi.mock('@/permissions/useCan', () => ({
  useCan: () => true,
}));

vi.mock('@/features/pos/api', () => ({
  useBootstrapRefsForPos: () => ({
    refs: {
      warehouses: mocks.bootstrapWarehouses,
    },
    data: {
      price_lists: mocks.bootstrapPriceLists,
    },
  }),
  useWarehousesForPos: () => ({
    data: mocks.fallbackWarehouses,
    isLoading: false,
    isError: false,
  }),
  useHoldOrder: () => ({ isPending: false, mutateAsync: mocks.holdMutate }),
  quoteProductForPos: (productId: number, priceListId: number) =>
    mocks.quoteProductForPos(productId, priceListId),
  useCustomers: (search: string) => ({
    isLoading: false,
    data:
      search.length >= 2
        ? [
            {
              id: 9,
              name: 'Gabriel Perez',
              document_type: 'V',
              document_number: '27144475',
              phone: '04140000000',
            },
          ]
        : [],
  }),
  useCreateCustomerForPos: () => ({
    isPending: false,
    mutateAsync: mocks.createCustomer,
  }),
  usePosProductsDebounced: (...args: unknown[]) => {
    mocks.productQuery(...args);
    return mocks.productResult;
  },
}));

vi.mock('@/features/inventory-center/variantApi', () => ({
  getProductVariants: (...args: unknown[]) =>
    mocks.getProductVariants(...args) as unknown as Promise<unknown>,
}));

vi.mock('@/features/pos/VariantPicker', () => ({
  VariantPicker: ({
    open,
    onClose,
    onSelect,
  }: {
    open: boolean;
    onClose: () => void;
    onSelect: (value: { variant: Record<string, unknown>; quantity: number }) => void;
  }) =>
    open ? (
      <div data-testid="variant-picker">
        <button type="button" onClick={onClose}>
          Cancelar variante
        </button>
        <button
          type="button"
          onClick={() =>
            onSelect({
              variant: {
                id: 501,
                color: 'Rojo',
                price_override: null,
                stock_available: 3,
              },
              quantity: 1,
            })
          }
        >
          Seleccionar variante
        </button>
      </div>
    ) : null,
}));

vi.mock('../OnScreenKeyboard', () => ({
  OnScreenKeyboard: () => <div data-testid="keyboard" />,
}));

import { ArmOrderScreen } from '../ArmOrderScreen';

describe('<ArmOrderScreen>', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.holdMutate.mockResolvedValue({ id: 101 });
    mocks.bootstrapWarehouses.splice(
      0,
      mocks.bootstrapWarehouses.length,
      { id: 7, name: 'Principal', code: 'MAIN', status: 'active' },
      { id: 8, name: 'Deposito', code: 'DEP', status: 'active' },
    );
    mocks.fallbackWarehouses.splice(0);
    mocks.bootstrapPriceLists.splice(0);
    mocks.quoteProductForPos.mockReset();
    mocks.productResult.data.data = [
      {
        id: 41,
        tenant_id: 1,
        name: 'Adaptador USB-C',
        sku: 'CAT-000516',
        barcode: null,
        base_price: 12.5,
        available_stock: 2,
        tracking_type: 'quantity',
      },
      {
        id: 42,
        tenant_id: 1,
        name: 'Producto agotado',
        sku: 'SIN-STOCK',
        barcode: null,
        base_price: 5,
        available_stock: 0,
        tracking_type: 'quantity',
      },
      {
        id: 43,
        tenant_id: 1,
        name: 'Accesorio con variantes',
        sku: 'ACC-001',
        barcode: null,
        base_price: 8,
        available_stock: 0,
        variants_count: 2,
        tracking_type: 'quantity',
      },
    ];
    mocks.getProductVariants.mockResolvedValue([
      { id: 501, color: 'Rojo', price_override: null, stock_available: 3 },
    ]);
  });

  it('sale del POS y navega a login sin dejar el layout en cargando sesion', async () => {
    mocks.signOut.mockResolvedValue(undefined);
    render(<ArmOrderScreen />);

    fireEvent.click(screen.getByRole('button', { name: 'Salir del POS' }));

    await waitFor(() => expect(mocks.signOut).toHaveBeenCalledOnce());
    expect(mocks.navigate).toHaveBeenCalledWith({ to: '/login' });
  });

  it('usa el listado alternativo y selecciona un almacen cuando bootstrap llega vacio', async () => {
    mocks.bootstrapWarehouses.splice(0);
    mocks.fallbackWarehouses.push({
      id: 12,
      name: 'Almacen tablet',
      code: 'TABLET',
      status: 'active',
    });

    render(<ArmOrderScreen />);

    expect(screen.getByLabelText('Almacen de salida')).toHaveValue('12');
    await waitFor(() =>
      expect(mocks.productQuery).toHaveBeenLastCalledWith(
        '',
        12,
        expect.objectContaining({ enabled: false }),
      ),
    );
  });

  it('agrega el producto con el primer toque tactil en tablet', async () => {
    render(<ArmOrderScreen />);

    const touchStart = new Event('pointerdown', { bubbles: true });
    Object.defineProperty(touchStart, 'pointerType', { value: 'touch' });
    fireEvent(screen.getByTestId('product-41'), touchStart);

    const ticket = screen.getByRole('complementary');
    expect(await within(ticket).findByText('1 x $12.50')).toBeInTheDocument();
    expect(within(ticket).getByText('$12.50')).toBeInTheDocument();
  });

  it('muestra stock real y bloquea productos agotados o cantidades mayores a existencias', async () => {
    render(<ArmOrderScreen />);

    expect(screen.getByText('Stock 2')).toBeInTheDocument();
    expect(screen.getByText('Agotado')).toBeInTheDocument();
    expect(screen.getByTestId('product-42')).toBeDisabled();

    fireEvent.click(screen.getByTestId('product-41'));
    fireEvent.click(screen.getByTestId('product-41'));
    fireEvent.click(screen.getByTestId('product-41'));

    expect(
      await within(screen.getByRole('complementary')).findByText('2 x $12.50'),
    ).toBeInTheDocument();
  });

  it('permite elegir una variante con stock aunque el producto padre tenga stock cero', async () => {
    render(<ArmOrderScreen />);

    fireEvent.click(screen.getByTestId('product-43'));
    expect(await screen.findByTestId('variant-picker')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Seleccionar variante' }));

    expect(await within(screen.getByRole('complementary')).findByText(/Rojo/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /enviar a la cajera/i }));

    await waitFor(() =>
      expect(mocks.holdMutate).toHaveBeenCalledWith(
        expect.objectContaining({
          items: [
            expect.objectContaining({ product_id: 43, product_variant_id: 501, quantity: 1 }),
          ],
        }),
      ),
    );
  });

  it('cotiza y conserva la lista seleccionada al enviar la orden a caja', async () => {
    mocks.bootstrapPriceLists.push({
      id: 1,
      code: 'MAYOR',
      name: 'PRECIO MAYOR',
      is_active: true,
      is_default: false,
      payment_method_ids: [11],
    });
    mocks.quoteProductForPos.mockResolvedValue({
      product_id: 41,
      price_list_id: 1,
      price_list_name: 'PRECIO MAYOR',
      price_source: 'price_list',
      markup_percentage: null,
      base_price_usd: 15,
      sale_currency: 'USD',
      sale_price: 15,
      price_usd: 15,
      price_ves: null,
      exchange_rate_type_id: null,
      exchange_rate_type_code: null,
      exchange_rate_type_name: null,
      exchange_rate_id: null,
      exchange_rate: null,
      exchange_rate_effective_at: null,
    });

    render(<ArmOrderScreen />);

    fireEvent.change(screen.getByLabelText('Lista de precio'), { target: { value: '1' } });
    fireEvent.click(screen.getByTestId('product-41'));

    expect(await screen.findByText('1 x $15.00')).toBeInTheDocument();
    expect(mocks.quoteProductForPos).toHaveBeenCalledWith(41, 1);

    fireEvent.click(screen.getByRole('button', { name: /enviar a la cajera/i }));

    await waitFor(() =>
      expect(mocks.holdMutate).toHaveBeenCalledWith(
        expect.objectContaining({
          items: [
            expect.objectContaining({
              product_id: 41,
              price_list_id: 1,
              price_source: 'price_list',
            }),
          ],
        }),
      ),
    );
  });

  it('busca un cliente por cedula, lo asigna y lo envia con la orden', async () => {
    render(<ArmOrderScreen />);

    fireEvent.click(screen.getByRole('button', { name: 'Seleccionar cliente' }));
    fireEvent.change(screen.getByPlaceholderText('Nombre, cedula o telefono'), {
      target: { value: '27144475' },
    });
    fireEvent.click(screen.getByRole('button', { name: /gabriel perez/i }));
    expect(screen.getByText('Gabriel Perez')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('product-41'));
    await screen.findByText('1 x $12.50');
    fireEvent.click(screen.getByRole('button', { name: /enviar a la cajera/i }));

    await waitFor(() =>
      expect(mocks.holdMutate).toHaveBeenCalledWith(
        expect.objectContaining({ customer_id: 9, customer_name: 'Gabriel Perez' }),
      ),
    );
    expect(screen.getByText('Consumidor Final')).toBeInTheDocument();
  });

  it('permite crear y asignar un cliente desde la tablet', async () => {
    mocks.createCustomer.mockResolvedValue({
      id: 15,
      name: 'Maria Lopez',
      document_type: 'V',
      document_number: '12345678',
    });
    render(<ArmOrderScreen />);

    fireEvent.click(screen.getByRole('button', { name: 'Seleccionar cliente' }));
    fireEvent.click(screen.getByRole('button', { name: /crear cliente/i }));
    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Maria Lopez' } });
    fireEvent.change(screen.getByLabelText('Cedula o documento'), {
      target: { value: '12345678' },
    });
    fireEvent.click(screen.getByRole('button', { name: /guardar y asignar/i }));

    expect(mocks.createCustomer).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Maria Lopez', document_number: '12345678' }),
    );
    expect(await screen.findByText('Maria Lopez')).toBeInTheDocument();
  });
});
