import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import { ReceiveItemRow, type ReceiveItemRowValue } from './ReceiveItemRow';

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
});
