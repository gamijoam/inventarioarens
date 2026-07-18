import { beforeEach, describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { QuickActionsBar } from './QuickActionsBar';
import type { Purchase } from '@/features/purchases/schemas';
import { useSessionStore } from '@/stores/session';

function makePurchase(
  status: Purchase['status'],
  payable: Partial<NonNullable<Purchase['account_payable']>> | null = {
    id: 9,
    status: 'pending',
    balance_base_amount: '100.0000',
    is_open: true,
  },
): Purchase {
  return {
    id: 1,
    supplier_id: 5,
    status,
    document_number: 'PO-2026-001',
    purchase_currency: 'USD',
    total_base_amount: '1000.0000',
    received_base_amount: status === 'received' ? '1000.0000' : '0.0000',
    items_count: 1,
    account_payable: payable,
    ...(status === 'cancelled' ? { cancelled_at: '2026-07-15T10:00:00.000000Z' } : {}),
    ...(status === 'received' ? { received_at: '2026-07-15T10:00:00.000000Z' } : {}),
  } as unknown as Purchase;
}

function wrapperFactory() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function setPermissions(permissions: string[]) {
  useSessionStore.setState({ permissions: new Set(permissions) });
}

describe('QuickActionsBar', () => {
  beforeEach(() => {
    setPermissions(['purchases.create', 'purchases.approve', 'accounts_payable.view']);
  });

  it('muestra Recibir + Cancelar en estado draft', () => {
    render(<QuickActionsBar purchase={makePurchase('draft')} onReceive={vi.fn()} />, {
      wrapper: wrapperFactory(),
    });
    expect(screen.getByTestId('purchase-receive-1')).toBeTruthy();
    expect(screen.getByTestId('purchase-cancel-1')).toBeTruthy();
  });

  it('muestra "Recibir lo que falta" en partially_received', () => {
    render(<QuickActionsBar purchase={makePurchase('partially_received')} onReceive={vi.fn()} />, {
      wrapper: wrapperFactory(),
    });
    expect(screen.getByText('Recibir lo que falta')).toBeTruthy();
    // No muestra cancelar en partial (ya no aplica)
    expect(screen.queryByTestId('purchase-cancel-1')).toBeNull();
  });

  it('muestra Pagar CxP cuando se pasa onPayPayable (received o partial)', () => {
    const { rerender } = render(
      <QuickActionsBar
        purchase={makePurchase('received')}
        onReceive={vi.fn()}
        onPayPayable={vi.fn()}
      />,
      { wrapper: wrapperFactory() },
    );
    expect(screen.getByTestId('purchase-pay-1')).toBeTruthy();

    rerender(
      <QuickActionsBar
        purchase={makePurchase('partially_received')}
        onReceive={vi.fn()}
        onPayPayable={vi.fn()}
      />,
    );
    expect(screen.getByTestId('purchase-pay-1')).toBeTruthy();
  });

  it('NO muestra acciones en cancelled', () => {
    const { container } = render(<QuickActionsBar purchase={makePurchase('cancelled')} />, {
      wrapper: wrapperFactory(),
    });
    // Renderiza null (no retorna nada visible)
    expect(container.querySelector('[data-testid="purchase-receive-1"]')).toBeNull();
    expect(container.querySelector('[data-testid="purchase-cancel-1"]')).toBeNull();
    expect(container.querySelector('[data-testid="purchase-pay-1"]')).toBeNull();
  });

  it('NO muestra Recibir cuando onReceive no esta definido', () => {
    render(<QuickActionsBar purchase={makePurchase('draft')} />, { wrapper: wrapperFactory() });
    expect(screen.queryByTestId('purchase-receive-1')).toBeNull();
  });

  it('click en Recibir llama a onReceive', () => {
    const onReceive = vi.fn();
    render(<QuickActionsBar purchase={makePurchase('draft')} onReceive={onReceive} />, {
      wrapper: wrapperFactory(),
    });
    fireEvent.click(screen.getByTestId('purchase-receive-1'));
    expect(onReceive).toHaveBeenCalledTimes(1);
  });

  it('click en Cancelar abre el ConfirmDialog', () => {
    render(<QuickActionsBar purchase={makePurchase('draft')} onReceive={vi.fn()} />, {
      wrapper: wrapperFactory(),
    });
    fireEvent.click(screen.getByTestId('purchase-cancel-1'));
    // El dialog de confirmacion aparece
    expect(screen.getByText('Cancelar compra "PO-2026-001"')).toBeTruthy();
  });

  it('oculta acciones cuando faltan permisos', () => {
    setPermissions([]);
    render(
      <QuickActionsBar
        purchase={makePurchase('received')}
        onReceive={vi.fn()}
        onPayPayable={vi.fn()}
      />,
      { wrapper: wrapperFactory() },
    );
    expect(screen.queryByTestId('purchase-receive-1')).toBeNull();
    expect(screen.queryByTestId('purchase-pay-1')).toBeNull();
  });

  it('oculta Pagar CxP si la cuenta ya esta pagada o no existe', () => {
    const { rerender } = render(
      <QuickActionsBar
        purchase={makePurchase('received', {
          id: 9,
          status: 'paid',
          balance_base_amount: '0.0000',
          is_open: false,
        })}
        onPayPayable={vi.fn()}
      />,
      { wrapper: wrapperFactory() },
    );
    expect(screen.queryByTestId('purchase-pay-1')).toBeNull();

    rerender(<QuickActionsBar purchase={makePurchase('received', null)} onPayPayable={vi.fn()} />);
    expect(screen.queryByTestId('purchase-pay-1')).toBeNull();
  });
});
