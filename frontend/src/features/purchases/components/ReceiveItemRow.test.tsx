import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import { ReceiveItemRow, receiveItemError, type ReceiveItemRowValue } from './ReceiveItemRow';
import { ReceivePurchaseItemSchema } from '../schemas';

function makeValue(overrides: Partial<ReceiveItemRowValue> = {}): ReceiveItemRowValue {
  return {
    purchase_item_id: 10,
    product_id: 5,
    product_name: 'Telefono Demo',
    product_sku: 'TEL-001',
    product_tracking_type: 'serialized',
    warehouse_code: 'MAIN',
    ordered_quantity: 2,
    received_quantity: 0,
    receiving_quantity: 2,
    unit_cost: 100,
    serial_units: [
      { serial_type: 'imei', serial_number: '' },
      { serial_type: 'imei', serial_number: '' },
    ],
    ...overrides,
  };
}

describe('ReceiveItemRow', () => {
  it('permite capturar IMEIs al recibir productos serializados', () => {
    const onChange = vi.fn();
    render(<ReceiveItemRow value={makeValue()} onChange={onChange} />);

    expect(screen.getByText('IMEIs / seriales de esta recepcion')).toBeTruthy();
    const inputs = screen.getAllByPlaceholderText('Escanear o escribir IMEI y Enter');

    fireEvent.change(inputs[0]!, { target: { value: '860001000000001' } });

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        serial_units: [
          { serial_type: 'imei', serial_number: '860001000000001' },
          { serial_type: 'imei', serial_number: '' },
        ],
      }),
    );
  });

  it('no muestra captura de IMEI para productos de cantidad', () => {
    render(
      <ReceiveItemRow
        value={makeValue({
          product_tracking_type: 'quantity',
          serial_units: [],
        })}
        onChange={vi.fn()}
      />,
    );

    expect(screen.queryByText('IMEIs / seriales de esta recepcion')).toBeNull();
  });

  it('identifica la variante de color que se esta recibiendo', () => {
    render(
      <ReceiveItemRow
        value={makeValue({
          product_tracking_type: 'quantity',
          product_variant_name: 'Verde',
          product_variant_sku: 'TEL-VERDE',
          serial_units: [],
        })}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByText('Variante: Verde (TEL-VERDE)')).toBeInTheDocument();
  });
});

function row(overrides: Partial<ReceiveItemRowValue> = {}): ReceiveItemRowValue {
  return {
    purchase_item_id: 7,
    product_id: 10,
    product_name: 'Leche completa',
    warehouse_code: 'ALM',
    ordered_quantity: 12,
    received_quantity: 0,
    receiving_quantity: 12,
    unit_cost: 1.5,
    serial_units: [],
    ...overrides,
  };
}

describe('ReceiveItemRow con vencimiento', () => {
  it('pide fecha y lote solo en productos que controlan vencimiento', () => {
    const { rerender } = render(<ReceiveItemRow value={row({ tracks_expiration: true })} onChange={vi.fn()} />);
    expect(screen.getByLabelText(/Vence el/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Lote/i)).toBeInTheDocument();

    rerender(<ReceiveItemRow value={row({ tracks_expiration: false })} onChange={vi.fn()} />);
    expect(screen.queryByLabelText(/Vence el/i)).not.toBeInTheDocument();
  });

  it('propaga la fecha y el lote escritos', () => {
    const onChange = vi.fn();
    render(<ReceiveItemRow value={row({ tracks_expiration: true })} onChange={onChange} />);

    fireEvent.change(screen.getByLabelText(/Vence el/i), { target: { value: '2026-12-31' } });
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({ expires_on: '2026-12-31' }));

    fireEvent.change(screen.getByLabelText(/Lote/i), { target: { value: 'L-55' } });
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({ lot_code: 'L-55' }));
  });

  it('valida que un producto con vencimiento tenga fecha', () => {
    expect(receiveItemError(row({ tracks_expiration: true }))).toMatch(/fecha de vencimiento/i);
    expect(receiveItemError(row({ tracks_expiration: true, expires_on: '2026-12-31' }))).toBeNull();
    expect(receiveItemError(row({ tracks_expiration: false }))).toBeNull();
    expect(receiveItemError(row({ receiving_quantity: 13 }))).toMatch(/Excede el pendiente/);
  });

  it('el payload de recepcion conserva lote y fecha', () => {
    expect(
      ReceivePurchaseItemSchema.parse({ purchase_item_id: 7, quantity: 12, lot_code: 'L-55', expires_on: '2026-12-31' }),
    ).toMatchObject({ lot_code: 'L-55', expires_on: '2026-12-31' });
  });
});
