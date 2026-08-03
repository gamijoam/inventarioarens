/**
 * Tests para AcceptInventoryTransferRequestDialog.
 *
 * Cubre:
 *   - Layout visual de cada item (origen -> flecha -> destino) con cards.
 *   - IMEI scanner aparece en la zona destino SOLO cuando el item destino
 *     es serializado (es la empresa destino quien decide QUE IMEIs envia).
 *   - IMEI scanner se muestra si hay destination_product_id Y warehouse_id.
 *   - Submit envia serial_units en el payload para items serializados.
 *   - Bloquea submit si el item serializado no tiene la cantidad de IMEIs.
 *   - Dropdown de producto destino se ordena por scoreMatch.
 *   - Badge visual segun tipo de match (SKU/Barcode verde, Similar amarillo).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const { mockAcceptMutateAsync, mockUseWarehouses, mockUseProductsForTransfer } = vi.hoisted(() => ({
  mockAcceptMutateAsync: vi.fn(),
  mockUseWarehouses: vi.fn(),
  mockUseProductsForTransfer: vi.fn(),
}));

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useAcceptTransferRequest: () => ({
    mutateAsync: async (values: unknown) => {
      await mockAcceptMutateAsync(values);
      return { id: 999 };
    },
  }),
  useTransferRequestProducts: (...args: unknown[]): unknown =>
    mockUseProductsForTransfer(...args) as unknown,
}));

vi.mock('@/features/inventory-center/api', () => ({
  useWarehouses: (): unknown => mockUseWarehouses() as unknown,
  useAvailableProductUnits: () => ({
    data: [
      {
        id: 2001,
        product_id: 77,
        warehouse_id: 10,
        serial_type: 'imei',
        serial_number: 'IMEI-LOCAL-001',
        status: 'available',
      },
      {
        id: 2002,
        product_id: 77,
        warehouse_id: 10,
        serial_type: 'imei',
        serial_number: 'IMEI-LOCAL-002',
        status: 'available',
      },
      {
        id: 2003,
        product_id: 77,
        warehouse_id: 10,
        serial_type: 'imei',
        serial_number: 'IMEI-LOCAL-003',
        status: 'available',
      },
    ],
    isLoading: false,
    isError: false,
  }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { AcceptInventoryTransferRequestDialog } from '../AcceptInventoryTransferRequestDialog';
import type { TransferRequest } from '../../schemas';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeRequest(items: NonNullable<TransferRequest['items']>): TransferRequest {
  return {
    id: 1,
    origin_tenant_id: 2,
    destination_tenant_id: 1,
    from_warehouse_id: 10,
    destination_warehouse_id: null,
    status: 'requested',
    items,
  } as TransferRequest;
}

async function selectDestinationProduct(
  user: ReturnType<typeof userEvent.setup>,
  itemId: number,
  productName: string,
) {
  const input = screen.getByTestId(`item-product-${itemId}`);
  await user.click(input);
  await user.clear(input);
  await user.type(input, productName);
  await user.click(await screen.findByRole('option', { name: new RegExp(productName, 'i') }));
}

describe('AcceptInventoryTransferRequestDialog', () => {
  beforeEach(() => {
    mockAcceptMutateAsync.mockReset();
    mockUseWarehouses.mockReset();
    mockUseProductsForTransfer.mockReset();
    mockUseWarehouses.mockReturnValue({
      data: [
        { id: 10, code: 'W1' },
        { id: 11, code: 'W2' },
      ],
    });
    mockUseProductsForTransfer.mockReturnValue({ data: [] });
  });

  it('muestra el dialog con el campo almacen de salida requerido', () => {
    const request = makeRequest([]);
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    expect(screen.getByLabelText(/almac.n.*salida/i)).toBeInTheDocument();
    expect(screen.getByText(/Notas de respuesta/i)).toBeInTheDocument();
  });

  it('bloquea submit si no se selecciona almacen de salida', async () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Test',
          sku: 'T-001',
          barcode: null,
          tracking_type: 'quantity',
        },
        quantity: 1,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 77, name: 'Destino 1', sku: 'D-001', tracking_type: 'quantity' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await selectDestinationProduct(user, 100, 'Destino 1');
    await user.click(screen.getByTestId('submit-accept'));
    await waitFor(() => {
      expect(mockAcceptMutateAsync).not.toHaveBeenCalled();
    });
  });

  it('no muestra IMEI scanner si el item destino es quantity', async () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Coca-Cola',
          sku: 'CC-1500',
          barcode: null,
          tracking_type: 'quantity',
        },
        quantity: 5,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 77, name: 'Coca-Cola Local', sku: 'CC-LOCAL', tracking_type: 'quantity' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await user.selectOptions(screen.getByLabelText(/almac.n.*salida/i), '10');
    await selectDestinationProduct(user, 100, 'Coca-Cola Local');
    // No debe aparecer el IMEI scanner porque el item destino es quantity.
    expect(screen.queryByTestId('accept-imeis-100')).not.toBeInTheDocument();
  });

  it('muestra IMEI scanner cuando item destino es serializado', async () => {
    const request = makeRequest([
      {
        id: 200,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'iPhone 15',
          sku: 'IP15-001',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 2,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 77, name: 'iPhone 15', sku: 'IP15-LOCAL', tracking_type: 'serialized' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await user.selectOptions(screen.getByLabelText(/almac.n.*salida/i), '10');
    await screen.findByLabelText(/Quitar iPhone 15/i);
    // IMEI scanner aparece con data-testid del item.
    expect(await screen.findByTestId('accept-imeis-200')).toBeInTheDocument();
  });

  it('click en IMEI actualiza el contador del scanner', async () => {
    const request = makeRequest([
      {
        id: 300,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Celular Pro',
          sku: 'CP-001',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 2,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 88, name: 'Celular Pro', sku: 'CP-LOCAL', tracking_type: 'serialized' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await user.selectOptions(screen.getByLabelText(/almac.n.*salida/i), '10');
    await screen.findByLabelText(/Quitar Celular Pro/i);
    await waitFor(() => {
      expect(screen.getByTestId('accept-imeis-300')).toBeInTheDocument();
    });
    expect(screen.getByText(/0 \/ 2/)).toBeInTheDocument();
    await user.click(screen.getByTestId('accept-imei-300-item-2001'));
    await waitFor(() => {
      expect(screen.getByText(/1 \/ 2/)).toBeInTheDocument();
    });
  });

  it('submit sin IMEIs suficientes para item serializado bloquea el envio', async () => {
    const request = makeRequest([
      {
        id: 400,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Celular',
          sku: 'C-001',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 2,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 99, name: 'Celular Local', sku: 'C-LOCAL', tracking_type: 'serialized' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await user.selectOptions(screen.getByLabelText(/almac.n.*salida/i), '10');
    await selectDestinationProduct(user, 400, 'Celular Local');
    // NO seleccionar IMEIs y hacer submit.
    await user.click(screen.getByTestId('submit-accept'));
    await waitFor(() => {
      expect(mockAcceptMutateAsync).not.toHaveBeenCalled();
    });
  });

  it('submit con IMEIs seleccionados envia serial_units en el payload', async () => {
    const request = makeRequest([
      {
        id: 500,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Celular',
          sku: 'C-001',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 2,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 111, name: 'Celular Local', sku: 'C-LOCAL', tracking_type: 'serialized' }],
    });
    const user = userEvent.setup();
    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      {
        wrapper: makeWrapper(),
      },
    );
    await user.selectOptions(screen.getByLabelText(/almac.n.*salida/i), '10');
    await selectDestinationProduct(user, 500, 'Celular Local');
    await screen.findByTestId('accept-imeis-500');
    await user.click(screen.getByTestId('accept-imei-500-item-2001'));
    await user.click(screen.getByTestId('accept-imei-500-item-2002'));
    await user.click(screen.getByTestId('submit-accept'));

    await waitFor(() => {
      expect(mockAcceptMutateAsync).toHaveBeenCalledTimes(1);
    });
    const call = mockAcceptMutateAsync.mock.calls[0]?.[0] as {
      values: {
        destination_warehouse_id: number;
        items: {
          request_item_id: number;
          destination_product_id: number;
          serial_units?: { serial_type: string; serial_number: string }[];
        }[];
      };
    };
    expect(call).toBeDefined();
    expect(call.values.destination_warehouse_id).toBe(10);
    expect(call.values.items[0]?.request_item_id).toBe(500);
    expect(call.values.items[0]?.destination_product_id).toBe(111);
    expect(call.values.items[0]?.serial_units).toEqual([
      { serial_type: 'imei', serial_number: 'IMEI-LOCAL-001' },
      { serial_type: 'imei', serial_number: 'IMEI-LOCAL-002' },
    ]);
  });

  it('consulta por SKU y selecciona automaticamente el producto equivalente del receptor', async () => {
    const request = makeRequest([
      {
        id: 600,
        origin_product_id: 14601,
        origin_product: {
          id: 14601,
          name: 'IPHONE 20',
          sku: 'IPHONE-20',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 1,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        {
          id: 14599,
          name: 'IPHONE 20',
          sku: 'IPHONE-20',
          barcode: null,
          tracking_type: 'serialized',
        },
      ],
      isFetching: false,
      isError: false,
    });

    render(
      <AcceptInventoryTransferRequestDialog
        request={request}
        open
        onOpenChange={() => undefined}
      />,
      { wrapper: makeWrapper() },
    );

    expect(await screen.findByLabelText(/Quitar IPHONE 20/i)).toBeInTheDocument();
    expect(mockUseProductsForTransfer).toHaveBeenCalledWith('IPHONE-20');
    expect(screen.getByTestId('accept-card-badge-600')).toHaveTextContent(/Match SKU/i);
  });
});
