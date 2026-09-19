import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import { PurchaseItemTableRow } from './PurchaseItemTableRow';
import type { PurchaseItemRowValue } from './PurchaseItemRow';

vi.mock('@/features/inventory-center/api', () => ({
  useWarehouses: () => ({ data: [{ id: 1, code: 'PRINCIPAL', name: 'Almacen Principal' }] }),
}));

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: () => ({ data: [], isLoading: false }),
}));

vi.mock('@/features/purchases/api', () => ({
  useProductsForPurchase: () => ({ data: [], isFetching: false }),
}));

function makeItemValue(overrides: Partial<PurchaseItemRowValue> = {}): PurchaseItemRowValue {
  return {
    warehouse_id: 1,
    product_id: 10,
    product_variant_id: null,
    product_info: {
      id: 10,
      name: 'Repuesto Cable USB',
      sku: 'CAB-001',
      barcode: '7591234567890',
      unit_of_measure: 'unidad',
      base_price: 1.5,
      last_purchase_cost: 1.0,
      average_cost: 1.0,
      profit_margin: 50.0,
      pricing_mode: 'manual',
    },
    quantity: 10,
    unit_cost: '2.00',
    serial_units: [],
    ...overrides,
  };
}

function renderRow(props: Partial<Parameters<typeof PurchaseItemTableRow>[0]> = {}) {
  return render(
    <table>
      <tbody>
        <PurchaseItemTableRow
          value={makeItemValue()}
          onChange={vi.fn()}
          onRemove={vi.fn()}
          canRemove
          index={0}
          isExpanded={false}
          onToggleExpand={vi.fn()}
          {...props}
        />
      </tbody>
    </table>,
  );
}

describe('PurchaseItemTableRow', () => {
  it('muestra acciones con nombre y permite editar, crear, detalle y eliminar', () => {
    const onEditProduct = vi.fn();
    const onCreateProduct = vi.fn();
    const onRemove = vi.fn();
    const onToggleExpand = vi.fn();

    renderRow({ onEditProduct, onCreateProduct, onRemove, onToggleExpand });

    expect(screen.getByText('Editar')).toBeInTheDocument();
    expect(screen.getByText('Nuevo')).toBeInTheDocument();
    expect(screen.getByText('Detalle')).toBeInTheDocument();
    expect(screen.getByText('Eliminar')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('purchase-table-edit-product-0'));
    expect(onEditProduct).toHaveBeenCalledWith(10);

    fireEvent.click(screen.getByTestId('purchase-table-create-product-0'));
    expect(onCreateProduct).toHaveBeenCalled();

    fireEvent.click(screen.getByTestId('purchase-table-detail-0'));
    expect(onToggleExpand).toHaveBeenCalledWith(0);

    fireEvent.click(screen.getByTestId('purchase-table-remove-0'));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });

  it('oculta Editar cuando la linea no tiene producto', () => {
    renderRow({
      value: makeItemValue({ product_id: null, product_info: null }),
      onCreateProduct: vi.fn(),
    });

    expect(screen.queryByTestId('purchase-table-edit-product-0')).not.toBeInTheDocument();
    expect(screen.getByText('Nuevo')).toBeInTheDocument();
    expect(screen.getByText('Eliminar')).toBeInTheDocument();
  });
});
