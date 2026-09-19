import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import { PurchaseItemRow, type PurchaseItemRowValue } from './PurchaseItemRow';

// Mock inventory hooks
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
      base_price: 1.50,
      last_purchase_cost: 1.00,
      average_cost: 1.00,
      profit_margin: 50.0,
      pricing_mode: 'manual',
    },
    quantity: 10,
    unit_cost: '2.00',
    serial_units: [],
    ...overrides,
  };
}

describe('PurchaseItemRow', () => {
  it('muestra referencias de ultimo costo, PVP actual y margen al seleccionar un producto', () => {
    const value = makeItemValue({ unit_cost: '1.00' });
    render(
      <PurchaseItemRow
        value={value}
        onChange={vi.fn()}
        onRemove={vi.fn()}
        canRemove={false}
        index={0}
        collapsed={false}
        onToggleCollapse={vi.fn()}
      />,
    );

    expect(screen.getByText('Último costo:')).toBeTruthy();
    expect(screen.getByText('$1,00')).toBeTruthy();
    expect(screen.getByText('PVP actual:')).toBeTruthy();
    expect(screen.getByText('$1,50')).toBeTruthy();
    expect(screen.getAllByText(/50\.0%/i).length).toBeGreaterThanOrEqual(1);
  });

  it('detecta y alerta aumento de costo y venta a perdida cuando costo sube de $1 a $2', () => {
    const value = makeItemValue({
      unit_cost: '2.00', // Costo subio a $2.00, PVP actual es $1.50
    });

    render(
      <PurchaseItemRow
        value={value}
        onChange={vi.fn()}
        onRemove={vi.fn()}
        canRemove={false}
        index={0}
        collapsed={false}
        onToggleCollapse={vi.fn()}
      />,
    );

    // Debe mostrar que el costo aumentó +100%
    expect(screen.getByText(/Costo aumentó \+100\.0%/i)).toBeTruthy();

    // Debe alertar venta a pérdida con el PVP actual
    expect(screen.getByText(/¡Venta a pérdida con PVP actual/i)).toBeTruthy();

    // Debe mostrar PVP sugerido para mantener margen del 50% ($2.00 * 1.50 = $3.00)
    expect(screen.getByText(/PVP sugerido/i)).toBeTruthy();
    expect(screen.getByText(/\$3\.00 USD/i)).toBeTruthy();
  });

  it('permite activar la actualizacion de PVP al recibir la compra', () => {
    const onChange = vi.fn();
    const value = makeItemValue({
      unit_cost: '2.00',
      update_sale_price: false,
    });

    render(
      <PurchaseItemRow
        value={value}
        onChange={onChange}
        onRemove={vi.fn()}
        canRemove={false}
        index={0}
        collapsed={false}
        onToggleCollapse={vi.fn()}
      />,
    );

    const checkbox = screen.getByLabelText(/Actualizar PVP al recibir:/i);
    expect(checkbox).toBeTruthy();

    fireEvent.click(checkbox);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        update_sale_price: true,
        new_sale_price: '3',
      }),
    );
  });

  it('permite editar o crear producto sin salir de la compra', () => {
    const onEditProduct = vi.fn();
    const onCreateProduct = vi.fn();

    render(
      <PurchaseItemRow
        value={makeItemValue()}
        onChange={vi.fn()}
        onRemove={vi.fn()}
        canRemove={false}
        index={0}
        collapsed={false}
        onToggleCollapse={vi.fn()}
        onEditProduct={onEditProduct}
        onCreateProduct={onCreateProduct}
      />,
    );

    fireEvent.click(screen.getByTestId('purchase-item-edit-product-0'));
    expect(onEditProduct).toHaveBeenCalledWith(10);

    fireEvent.click(screen.getByTestId('purchase-item-create-product-0'));
    expect(onCreateProduct).toHaveBeenCalled();
  });

  it('permite eliminar la linea con un boton con nombre', () => {
    const onRemove = vi.fn();

    render(
      <PurchaseItemRow
        value={makeItemValue()}
        onChange={vi.fn()}
        onRemove={onRemove}
        canRemove
        index={0}
        collapsed={false}
        onToggleCollapse={vi.fn()}
      />,
    );

    expect(screen.getByText('Eliminar')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('purchase-item-remove-0'));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });
});
