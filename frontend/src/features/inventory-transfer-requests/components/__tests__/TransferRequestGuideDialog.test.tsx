import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { TransferRequest } from '../../schemas';

const { prepare, receive } = vi.hoisted(() => ({
  prepare: vi.fn(),
  receive: vi.fn(),
}));

vi.mock('../../api', () => ({
  usePrepareTransferRequest: () => ({ mutateAsync: prepare, isPending: false }),
  useReceiveTransferRequest: () => ({ mutateAsync: receive, isPending: false }),
}));

vi.mock('@/features/users/api', () => ({
  useUsers: () => ({ data: { data: [] } }),
}));

vi.mock('@/features/inventory-center/api', () => ({
  useAvailableProductUnits: () => ({
    data: [
      {
        id: 901,
        product_id: 50,
        warehouse_id: 10,
        serial_type: 'imei',
        serial_number: '51531351681331',
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

import { TransferRequestGuideDialog } from '../TransferRequestGuideDialog';

function shipmentOffer(status: TransferRequest['status']): TransferRequest {
  return {
    id: 40,
    document_number: 'TREQ-5-000002',
    origin_tenant_id: 5,
    destination_tenant_id: 6,
    sender_tenant_id: 5,
    receiver_tenant_id: 6,
    initiated_by_tenant_id: 5,
    flow_type: 'shipment_offer',
    from_warehouse_id: 10,
    destination_warehouse_id: 20,
    status,
    logistics_mode: true,
    items: [
      {
        id: 401,
        origin_product_id: 50,
        destination_product_id: 60,
        quantity: 1,
        origin_product: {
          id: 50,
          name: 'IPHONE 20',
          sku: 'IPHONE-20',
          tracking_type: 'serialized',
        },
      },
    ],
    guide: {
      id: 7,
      inventory_transfer_request_id: 40,
      status: status === 'accepted' ? 'draft' : 'delivered',
      transport_mode: 'simple',
      items: [
        {
          id: 8,
          guide_id: 7,
          inventory_transfer_request_item_id: 401,
          prepared_quantity: 1,
          received_quantity: 0,
          prepared_serial_units: [
            { serial_type: 'imei', serial_number: '51531351681331' },
          ],
        },
      ],
    },
  } as TransferRequest;
}

describe('TransferRequestGuideDialog', () => {
  beforeEach(() => {
    prepare.mockReset();
    receive.mockReset();
    prepare.mockResolvedValue({});
    receive.mockResolvedValue({});
  });

  it('el remitente prepara una propuesta y registra los IMEIs que saldran', async () => {
    const user = userEvent.setup();
    render(
      <TransferRequestGuideDialog
        request={shipmentOffer('accepted')}
        mode="prepare"
        open
        onOpenChange={() => undefined}
      />,
    );

    expect(screen.getByText(/mercancía que ofreciste/i)).toBeInTheDocument();
    expect(screen.getByText(/Ofrecido: 1/i)).toBeInTheDocument();

    expect(screen.getByText('51531351681331')).toBeInTheDocument();
    expect(screen.getByText(/0 \/ 1 IMEIs seleccionados/i)).toBeInTheDocument();

    await user.click(screen.getByTestId('guide-imei-401-item-901'));

    expect(screen.getByText(/1 \/ 1 IMEIs seleccionados/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Confirmar preparación/i }));

    await waitFor(() =>
      expect(prepare).toHaveBeenCalledWith(
        expect.objectContaining({
          id: 40,
          items: [
            expect.objectContaining({
              request_item_id: 401,
              prepared_quantity: 1,
              prepared_serial_units: [
                { serial_type: 'imei', serial_number: '51531351681331' },
              ],
            }),
          ],
        }),
      ),
    );
  });

  it('el receptor verifica los IMEIs despachados sin tratarlos como stock propio de salida', async () => {
    const user = userEvent.setup();
    render(
      <TransferRequestGuideDialog
        request={shipmentOffer('delivered')}
        mode="receive"
        open
        onOpenChange={() => undefined}
      />,
    );

    expect(screen.getByText(/Compara lo recibido con la guía despachada/i)).toBeInTheDocument();
    expect(screen.getByText(/Despachado: 1/i)).toBeInTheDocument();

    await user.type(
      screen.getByPlaceholderText(/IMEIs\/seriales recibidos/i),
      '51531351681331',
    );
    await user.click(screen.getByRole('button', { name: /Confirmar recepción/i }));

    await waitFor(() =>
      expect(receive).toHaveBeenCalledWith({
        id: 40,
        items: [
          expect.objectContaining({
            request_item_id: 401,
            received_quantity: 1,
            received_serial_units: [
              { serial_type: 'imei', serial_number: '51531351681331' },
            ],
          }),
        ],
      }),
    );
  });
});
