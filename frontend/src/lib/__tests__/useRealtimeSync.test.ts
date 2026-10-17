import { describe, expect, it, vi } from 'vitest';
import { handleRealtimeEvent } from '../useRealtimeSync';
import { rememberLocalTransferAction } from '../intercompanyTransferToasts';

const toastMocks = vi.hoisted(() => ({
  info: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
  dismiss: vi.fn(),
}));

vi.mock('sonner', () => ({
  toast: toastMocks,
}));

describe('useRealtimeSync event handler', () => {
  it('invalida queries de tasa de cambio cuando recibe rate.updated', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = { invalidateQueries: invalidateQueriesMock } as any;

    handleRealtimeEvent(queryClientMock, 'rate.updated', { currency: 'USD', rate: 45.5 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos', 'current-rates'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos', 'bootstrap'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['catalog', 'exchange-rates'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['products'] })
    );
  });

  it('invalida queries de órdenes POS cuando recibe pos.order.paid o pending', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = { invalidateQueries: invalidateQueriesMock } as any;

    handleRealtimeEvent(queryClientMock, 'pos.order.paid', { order_id: 123 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['pos', 'orders'] })
    );
  });

  it('invalida sesiones de caja cuando recibe cash_register.closed', () => {
    const queryClientMock = { invalidateQueries: vi.fn() } as any;

    handleRealtimeEvent(queryClientMock, 'cash_register.closed', { session_id: 45 });

    expect(queryClientMock.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['cash-registers'] })
    );
  });

  it('invalida campana e historial sin mostrar un segundo toast por el alias de notificación', () => {
    const queryClientMock = { invalidateQueries: vi.fn() } as any;

    handleRealtimeEvent(queryClientMock, 'intercompany.notification', { document_number: 'ITR-01' });

    expect(queryClientMock.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['inventory-transfer-notifications'] })
    );
    expect(queryClientMock.invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['inventory-transfer-requests'] })
    );
    expect(toastMocks.info).not.toHaveBeenCalled();
  });

  it('deduplica canales de empresa y grupo y personaliza el aviso para ambos lados', () => {
    const queryClientMock = { invalidateQueries: vi.fn() } as any;
    const payload = {
      id: 811,
      inventory_transfer_request_id: 70,
      event_type: 'created',
      flow_type: 'stock_request',
      tenant_id: 22,
      origin_tenant_id: 11,
      destination_tenant_id: 22,
      origin_tenant_name: 'Empresa Origen',
      destination_tenant_name: 'Empresa Destino',
      document_number: 'ITR-70',
      sender_tenant_id: 22,
      receiver_tenant_id: 11,
      sender_tenant_name: 'Empresa Destino',
      receiver_tenant_name: 'Empresa Origen',
    };
    const openRequest = vi.fn();

    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.created', payload, {
      tenantId: 22,
      onOpenRequest: openRequest,
    });
    // El mismo evento puede llegar otra vez por el canal del grupo.
    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.created', payload, {
      tenantId: 22,
      onOpenRequest: openRequest,
    });
    // El alias actualiza datos, pero no crea otro toast.
    handleRealtimeEvent(queryClientMock, 'intercompany.notification', payload, {
      tenantId: 22,
      onOpenRequest: openRequest,
    });

    expect(toastMocks.info).toHaveBeenCalledTimes(1);
    const destinationToast = toastMocks.info.mock.calls[0]!;
    expect(destinationToast[0]).toBe('Nueva solicitud de stock');
    expect(destinationToast[1].description).toContain('Empresa Origen solicita mercancía');
    expect(destinationToast[1].action.label).toBe('Ver');
    destinationToast[1].action.onClick();
    expect(openRequest).toHaveBeenCalledWith('70');

    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.created', payload, {
      tenantId: 11,
      onOpenRequest: openRequest,
    });

    expect(toastMocks.info).toHaveBeenCalledTimes(2);
    expect(toastMocks.info.mock.calls[1]![0]).toBe('Solicitud de stock enviada');
    expect(toastMocks.info.mock.calls[1]![1].description).toContain('Enviaste una solicitud de stock a Empresa Destino');
  });

  it('suprime el eco local y mantiene el aviso para la otra empresa', () => {
    const queryClientMock = { invalidateQueries: vi.fn() } as any;
    const payload = {
      id: 920,
      inventory_transfer_request_id: 91,
      event_type: 'accepted',
      flow_type: 'stock_request',
      origin_tenant_id: 11,
      destination_tenant_id: 22,
      origin_tenant_name: 'Empresa Origen',
      destination_tenant_name: 'Empresa Destino',
      document_number: 'ITR-91',
    };

    rememberLocalTransferAction(91, 'accepted', 22);
    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.accepted', payload, { tenantId: 22 });
    expect(toastMocks.success).not.toHaveBeenCalled();
    expect(toastMocks.dismiss).toHaveBeenCalled();

    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.accepted', payload, { tenantId: 11 });
    expect(toastMocks.success).toHaveBeenCalledTimes(1);
    expect(toastMocks.success.mock.calls[0]![1].description).toContain('Empresa Destino aceptó tu solicitud de stock');
  });

  it('conserva intactas las notas escritas por el usuario en la respuesta interempresa', () => {
    const queryClientMock = { invalidateQueries: vi.fn() } as any;
    const payload = {
      id: 940,
      inventory_transfer_request_id: 94,
      event_type: 'rejected',
      flow_type: 'stock_request',
      origin_tenant_id: 11,
      destination_tenant_id: 22,
      origin_tenant_name: 'Empresa Origen',
      destination_tenant_name: 'Empresa Destino',
      document_number: 'ITR-94',
      response_notes: 'Please check lot REF-42 before dispatch.',
    };

    handleRealtimeEvent(queryClientMock, 'inventory-transfer-requests.rejected', payload, { tenantId: 11 });

    expect(toastMocks.warning.mock.calls[0]![1].description)
      .toContain('Motivo: Please check lot REF-42 before dispatch.');
  });

  it('invalida traslados internos cuando recibe inventory-transfer.created', () => {
    const invalidateQueriesMock = vi.fn();
    const queryClientMock = { invalidateQueries: invalidateQueriesMock } as any;

    handleRealtimeEvent(queryClientMock, 'inventory-transfer.created', { id: 10 });

    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['transfers'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['inventory-transfers'] })
    );
    expect(invalidateQueriesMock).toHaveBeenCalledWith(
      expect.objectContaining({ queryKey: ['products'] })
    );
  });
});
