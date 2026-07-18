import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PurchaseSummary } from './PurchaseSummary';
import type { Purchase } from '@/features/purchases/schemas';

function makePurchase(overrides: Partial<Purchase> = {}): Purchase {
  return {
    id: 1,
    supplier_id: 5,
    status: 'received',
    document_number: 'PO-2026-001',
    issued_at: '2026-07-15',
    due_date: '2026-07-30',
    purchase_currency: 'USD',
    exchange_rate_type_id: null,
    exchange_rate_type_code: null,
    exchange_rate: null,
    total_base_amount: '1000.0000',
    total_local_amount: '36500.0000',
    received_base_amount: '1000.0000',
    received_local_amount: '36500.0000',
    items_count: 2,
    created_by: 1,
    received_at: '2026-07-15T10:00:00.000000Z',
    cancelled_at: null,
    created_at: '2026-07-14T10:00:00.000000Z',
    updated_at: '2026-07-15T10:00:00.000000Z',
    supplier: { id: 5, name: 'Distribuidora XYZ' },
    account_payable: null,
    items: [
      {
        id: 10,
        purchase_order_id: 1,
        warehouse_id: 3,
        product_id: 77,
        quantity: 10,
        received_quantity: 10,
        serial_units: null,
        stock_movement_id: 82,
        unit_cost: '100.0000',
        total_cost: '1000.0000',
        base_unit_cost: '100.0000',
        base_total_cost: '1000.0000',
        product: { id: 77, name: 'CEPILLO DENTAL', sku: 'cepillo' },
        warehouse: { id: 3, code: 'ALMACEN2', name: 'Almacen 2' },
      },
    ],
    ...overrides,
  };
}

describe('PurchaseSummary', () => {
  it('muestra el numero de documento y badge de estado', () => {
    render(<PurchaseSummary purchase={makePurchase({ status: 'draft' })} />);
    expect(screen.getByText('PO-2026-001')).toBeTruthy();
    // "Borrador" aparece en el badge Y en el stepper.
    expect(screen.getAllByText('Borrador').length).toBeGreaterThanOrEqual(1);
  });

  it('muestra los 3 pasos del stepper en estado received', () => {
    render(<PurchaseSummary purchase={makePurchase({ status: 'received' })} />);
    // Cada label aparece al menos una vez.
    expect(screen.getAllByText('Borrador').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Parcial').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Recibido').length).toBeGreaterThanOrEqual(1);
  });

  it('muestra banner rojo para cancelled', () => {
    render(<PurchaseSummary purchase={makePurchase({ status: 'cancelled' })} />);
    expect(screen.getByText('Compra cancelada')).toBeTruthy();
  });

  it('muestra el total USD formateado', () => {
    render(<PurchaseSummary purchase={makePurchase({ total_base_amount: '1234.5600' })} />);
    // formatMoney produce "1.234,56" en es-VE. Aparece 2 veces (Total + recibido).
    expect(screen.getAllByText(/1\.234,56/).length).toBeGreaterThanOrEqual(1);
  });

  it('muestra el total VES cuando difiere del USD', () => {
    render(<PurchaseSummary purchase={makePurchase()} />);
    // 36500 formateado
    expect(screen.getAllByText(/36\.500/).length).toBeGreaterThanOrEqual(1);
  });

  it('muestra 100% de progreso en estado received', () => {
    render(
      <PurchaseSummary
        purchase={makePurchase({
          status: 'received',
          total_base_amount: '1000.0000',
          received_base_amount: '1000.0000',
        })}
      />,
    );
    // El progressbar tiene aria-valuenow=100
    const progressbar = screen.getByRole('progressbar');
    expect(progressbar.getAttribute('aria-valuenow')).toBe('100');
  });

  it('muestra el porcentaje correcto en partially_received', () => {
    render(
      <PurchaseSummary
        purchase={makePurchase({
          status: 'partially_received',
          total_base_amount: '1000.0000',
          received_base_amount: '500.0000',
        })}
      />,
    );
    const progressbar = screen.getByRole('progressbar');
    expect(progressbar.getAttribute('aria-valuenow')).toBe('50');
  });

  it('muestra el nombre del proveedor', () => {
    render(<PurchaseSummary purchase={makePurchase()} />);
    expect(screen.getByText('Distribuidora XYZ')).toBeTruthy();
  });

  it('muestra "Sin proveedor" cuando no hay supplier', () => {
    render(<PurchaseSummary purchase={makePurchase({ supplier: null, supplier_id: null })} />);
    expect(screen.getByText('Sin proveedor')).toBeTruthy();
  });

  it('muestra la lista de items cuando showItems=true', () => {
    render(<PurchaseSummary purchase={makePurchase()} showItems />);
    expect(screen.getByText('CEPILLO DENTAL')).toBeTruthy();
    expect(screen.getByText('ALMACEN2')).toBeTruthy();
  });

  it('NO muestra la lista de items por defecto', () => {
    render(<PurchaseSummary purchase={makePurchase()} />);
    expect(screen.queryByText('CEPILLO DENTAL')).toBeNull();
  });

  it('muestra las fechas de emision y vencimiento', () => {
    render(<PurchaseSummary purchase={makePurchase()} />);
    expect(screen.getByText(/Emitida: 2026-07-15/)).toBeTruthy();
    expect(screen.getByText(/Vence: 2026-07-30/)).toBeTruthy();
  });

  it('muestra el estado financiero de CxP y saldo pendiente', () => {
    render(
      <PurchaseSummary
        purchase={makePurchase({
          account_payable: {
            id: 20,
            status: 'partial',
            document_number: 'PO-2026-001',
            original_base_amount: '1000.0000',
            paid_base_amount: '250.0000',
            balance_base_amount: '750.0000',
            balance_local_amount: '750000.0000',
            due_date: '2026-07-30',
            is_open: true,
          },
        })}
      />,
    );

    expect(screen.getByText('CxP: Parcial')).toBeTruthy();
    expect(screen.getByText('Saldo pendiente')).toBeTruthy();
    expect(screen.getByText(/\$750,00/)).toBeTruthy();
  });
});
