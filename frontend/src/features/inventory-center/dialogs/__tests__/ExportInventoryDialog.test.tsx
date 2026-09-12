import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ExportInventoryDialog } from '../ExportInventoryDialog';

import type { InventoryFilters } from '../../schemas';

describe('<ExportInventoryDialog>', () => {
  const dummyFilters: InventoryFilters = {
    search: '',
    tracking_type: 'all',
    stock_status: 'all',
    active_status: 'all',
    page: 1,
    per_page: 25,
    with_prices: 1,
  };

  beforeEach(() => {
    window.localStorage.clear();
  });

  it('renderiza título, descripción, presets y botón de descarga', () => {
    render(
      <ExportInventoryDialog
        open={true}
        onOpenChange={() => {}}
        filters={dummyFilters}
        exportProducts={{ exportCsv: vi.fn(), isExporting: false }}
      />,
    );

    expect(screen.getByText('Exportar inventario a CSV')).toBeInTheDocument();
    expect(screen.getByText('Solo Nombres')).toBeInTheDocument();
    expect(screen.getByText('Nombre y SKU')).toBeInTheDocument();
    expect(screen.getByText('Nombre y Código de barras')).toBeInTheDocument();
    expect(screen.getByText('Nombre y Stock')).toBeInTheDocument();
    expect(screen.getByText('Estándar')).toBeInTheDocument();
    expect(screen.getByText('Todos los campos')).toBeInTheDocument();
    expect(screen.getByTestId('confirm-export-csv')).toBeInTheDocument();
  });

  it('permite aplicar el preset "Solo Nombres" y llamar a exportCsv con ["name"]', async () => {
    const user = userEvent.setup();
    const exportCsv = vi.fn().mockResolvedValue(undefined);
    const onOpenChange = vi.fn();

    render(
      <ExportInventoryDialog
        open={true}
        onOpenChange={onOpenChange}
        filters={dummyFilters}
        exportProducts={{ exportCsv, isExporting: false }}
      />,
    );

    // Clic en preset "Solo Nombres"
    await user.click(screen.getByTestId('preset-export-only_names'));

    // Clic en botón "Descargar CSV"
    await user.click(screen.getByTestId('confirm-export-csv'));

    expect(exportCsv).toHaveBeenCalledWith(dummyFilters, ['name']);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('permite aplicar el preset "Nombre y SKU" y descargar con ["name", "sku"]', async () => {
    const user = userEvent.setup();
    const exportCsv = vi.fn().mockResolvedValue(undefined);

    render(
      <ExportInventoryDialog
        open={true}
        onOpenChange={() => {}}
        filters={dummyFilters}
        exportProducts={{ exportCsv, isExporting: false }}
      />,
    );

    await user.click(screen.getByTestId('preset-export-name_and_sku'));
    await user.click(screen.getByTestId('confirm-export-csv'));

    expect(exportCsv).toHaveBeenCalledWith(dummyFilters, ['name', 'sku']);
  });

  it('permite aplicar el preset "Nombre y Código de barras" y descargar con ["name", "barcode"]', async () => {
    const user = userEvent.setup();
    const exportCsv = vi.fn().mockResolvedValue(undefined);

    render(
      <ExportInventoryDialog
        open={true}
        onOpenChange={() => {}}
        filters={dummyFilters}
        exportProducts={{ exportCsv, isExporting: false }}
      />,
    );

    await user.click(screen.getByTestId('preset-export-name_and_barcode'));
    await user.click(screen.getByTestId('confirm-export-csv'));

    expect(exportCsv).toHaveBeenCalledWith(dummyFilters, ['name', 'barcode']);
  });

  it('permite marcar o desmarcar columnas individuales', async () => {
    const user = userEvent.setup();
    const exportCsv = vi.fn().mockResolvedValue(undefined);

    render(
      <ExportInventoryDialog
        open={true}
        onOpenChange={() => {}}
        filters={dummyFilters}
        exportProducts={{ exportCsv, isExporting: false }}
      />,
    );

    // Empezamos con "Solo Nombres"
    await user.click(screen.getByTestId('preset-export-only_names'));

    // Marcamos "Código de barras"
    await user.click(screen.getByTestId('export-column-barcode'));

    // Descargamos
    await user.click(screen.getByTestId('confirm-export-csv'));

    expect(exportCsv).toHaveBeenCalledWith(dummyFilters, ['name', 'barcode']);
  });
});
