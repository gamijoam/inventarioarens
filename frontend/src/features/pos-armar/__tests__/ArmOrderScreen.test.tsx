import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  holdMutate: vi.fn(),
  signOut: vi.fn(),
  createCustomer: vi.fn(),
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

vi.mock('@/components/layout/PosShell', () => ({
  PosShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/permissions/useCan', () => ({
  useCan: () => true,
}));

vi.mock('@/features/pos/api', () => ({
  useBootstrapRefsForPos: () => ({
    refs: {
      warehouses: mocks.bootstrapWarehouses,
    },
  }),
  useWarehousesForPos: () => ({
    data: mocks.fallbackWarehouses,
    isLoading: false,
    isError: false,
  }),
  useHoldOrder: () => ({ isPending: false, mutateAsync: mocks.holdMutate }),
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
    ];
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

  it('agrega el producto con el primer toque tactil en tablet', () => {
    render(<ArmOrderScreen />);

    const touchStart = new Event('pointerdown', { bubbles: true });
    Object.defineProperty(touchStart, 'pointerType', { value: 'touch' });
    fireEvent(screen.getByTestId('product-41'), touchStart);

    const ticket = screen.getByRole('complementary');
    expect(within(ticket).getByText('1 x $12.50')).toBeInTheDocument();
    expect(within(ticket).getByText('$12.50')).toBeInTheDocument();
  });

  it('muestra stock real y bloquea productos agotados o cantidades mayores a existencias', () => {
    render(<ArmOrderScreen />);

    expect(screen.getByText('Stock 2')).toBeInTheDocument();
    expect(screen.getByText('Agotado')).toBeInTheDocument();
    expect(screen.getByTestId('product-42')).toBeDisabled();

    fireEvent.click(screen.getByTestId('product-41'));
    fireEvent.click(screen.getByTestId('product-41'));
    fireEvent.click(screen.getByTestId('product-41'));

    expect(within(screen.getByRole('complementary')).getByText('2 x $12.50')).toBeInTheDocument();
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
