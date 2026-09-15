import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CustomizeInventoryColumnsDialog } from '../CustomizeInventoryColumnsDialog';
import {
  DEFAULT_INVENTORY_TABLE_COLUMNS,
  PRODUCT_AND_STOCK_COLUMNS,
  BARCODE_COUNTER_COLUMNS,
} from '../../inventoryColumnsConfig';

describe('<CustomizeInventoryColumnsDialog>', () => {
  it('renderiza título, presets rápidos y contador de columnas visibles', () => {
    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={() => {}}
        onReset={() => {}}
      />,
    );

    expect(screen.getByText('Personalizar columnas del inventario')).toBeInTheDocument();
    expect(screen.getByText(/columnas visibles/i)).toBeInTheDocument();
    expect(screen.getByText('Solo Producto y Stock')).toBeInTheDocument();
    expect(screen.getByText('Mostrador / Código')).toBeInTheDocument();
    expect(screen.getByText('Estándar')).toBeInTheDocument();
    expect(screen.getByText('Completo')).toBeInTheDocument();
  });

  it('mantiene el campo Nombre del producto como obligatorio y deshabilitado para desmarcar', () => {
    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={() => {}}
        onReset={() => {}}
      />,
    );

    const nameCheckbox = screen.getByTestId('checkbox-col-name');
    expect(nameCheckbox).toBeDisabled();
    expect(screen.getByText('Obligatorio')).toBeInTheDocument();
  });

  it('permite seleccionar el preset "Solo Producto y Stock" con un solo clic', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={onChange}
        onReset={() => {}}
      />,
    );

    const presetBtn = screen.getByTestId('preset-btn-product-stock');
    await user.click(presetBtn);

    expect(onChange).toHaveBeenCalledWith(PRODUCT_AND_STOCK_COLUMNS);
  });

  it('permite seleccionar el preset "Mostrador / Código" para ocultar SKU y mostrar Código', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={onChange}
        onReset={() => {}}
      />,
    );

    const presetBtn = screen.getByTestId('preset-btn-barcode-counter');
    await user.click(presetBtn);

    expect(onChange).toHaveBeenCalledWith(BARCODE_COUNTER_COLUMNS);
  });

  it('permite alternar individualmente una columna (ej. barcode)', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={onChange}
        onReset={() => {}}
      />,
    );

    const barcodeCheckbox = screen.getByTestId('checkbox-col-barcode');
    expect(barcodeCheckbox).not.toBeDisabled();
    await user.click(barcodeCheckbox);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        barcode: true,
      }),
    );
  });

  it('permite alternar la columna de Precio costo', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={onChange}
        onReset={() => {}}
      />,
    );

    expect(screen.getByText('Precio costo')).toBeInTheDocument();
    const costCheckbox = screen.getByTestId('checkbox-col-cost_price');
    expect(costCheckbox).not.toBeDisabled();
    await user.click(costCheckbox);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        cost_price: true,
      }),
    );
  });

  it('llama onReset al presionar Restablecer predeterminados', async () => {
    const user = userEvent.setup();
    const onReset = vi.fn();

    render(
      <CustomizeInventoryColumnsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={DEFAULT_INVENTORY_TABLE_COLUMNS}
        onChange={() => {}}
        onReset={onReset}
      />,
    );

    const resetBtn = screen.getByTestId('reset-columns-defaults-btn');
    await user.click(resetBtn);

    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
