import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { LabelsSelection, ProductLabelsResponse } from '../schemas';
import { LabelsPanel } from '../LabelsPanel';

const useProductLabels = vi.fn<(selection: LabelsSelection | null) => { data?: ProductLabelsResponse; isFetching: boolean }>();

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof LabelsApiModule>()),
  useProductLabels: (selection: LabelsSelection | null) => useProductLabels(selection),
}));

vi.mock('@/features/inventory-center/api', () => ({
  usePriceLists: () => ({ data: [{ id: 2, name: 'Mayor', code: 'MAYOR' }] }),
}));

vi.mock('@/features/purchases/components/ProductAutocomplete', () => ({
  ProductAutocomplete: ({ onChange }: { onChange: (id: number | null, product?: { id: number; name: string; sku: string | null; barcode: string | null }) => void }) => (
    <button type="button" onClick={() => onChange(7, { id: 7, name: 'Harina 1kg', sku: 'HARINA', barcode: null })}>
      elegir producto
    </button>
  ),
}));

import type * as LabelsApiModule from '../api';

const response: ProductLabelsResponse = {
  data: {
    labels: [
      {
        product_id: 7,
        name: 'Harina 1kg',
        sku: 'HARINA',
        barcode: '7590002',
        unit_of_measure: 'unit',
        price_usd: 2.5,
        price_ves: 250,
        sale_currency: 'USD',
        price_list_name: null,
        exchange_rate: 100,
        exchange_rate_type_code: 'BCV',
        packagings: [],
      },
    ],
    skipped: [{ product_id: 8, name: 'Sin precio', reason: 'El producto no tiene precio base configurado.' }],
    generated_at: null,
  },
};

describe('LabelsPanel', () => {
  beforeEach(() => {
    useProductLabels.mockReset();
    useProductLabels.mockReturnValue({ data: undefined, isFetching: false });
  });

  it('abre directo con los productos de un ajuste masivo', () => {
    useProductLabels.mockReturnValue({ data: response, isFetching: false });
    render(<LabelsPanel initialBulkAdjustmentId={9} />);

    expect(useProductLabels).toHaveBeenLastCalledWith({ bulkAdjustmentId: 9, priceListId: null });
    expect(screen.getByText('Harina 1kg')).toBeInTheDocument();
    expect(screen.getByText(/Sin precio/)).toBeInTheDocument();
    expect(screen.getByText(/no tiene precio base/)).toBeInTheDocument();
  });

  it('genera etiquetas de los productos que cambiaron de precio desde una fecha', async () => {
    const user = userEvent.setup();
    render(<LabelsPanel />);

    await user.selectOptions(screen.getByLabelText('Que productos'), 'changed');
    await user.clear(screen.getByLabelText('Cambiaron desde'));
    await user.type(screen.getByLabelText('Cambiaron desde'), '2026-11-01');
    await user.selectOptions(screen.getByLabelText('Lista de precio'), '2');
    await user.click(screen.getByRole('button', { name: /Generar/ }));

    expect(useProductLabels).toHaveBeenLastCalledWith({ changedSince: '2026-11-01', priceListId: 2 });
  });

  it('arma la lista de productos elegidos y permite quitarlos', async () => {
    const user = userEvent.setup();
    render(<LabelsPanel />);

    expect(screen.getByRole('button', { name: /Generar/ })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'elegir producto' }));
    await user.click(screen.getByRole('button', { name: 'elegir producto' }));
    expect(screen.getAllByText('Harina 1kg')).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: /Generar/ }));
    expect(useProductLabels).toHaveBeenLastCalledWith({ productIds: [7], priceListId: null });

    await user.click(screen.getByRole('button', { name: 'Quitar Harina 1kg' }));
    expect(screen.getByRole('button', { name: /Generar/ })).toBeDisabled();
  });

  it('imprime con el dialogo del navegador', async () => {
    const user = userEvent.setup();
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
    useProductLabels.mockReturnValue({ data: response, isFetching: false });
    render(<LabelsPanel initialBulkAdjustmentId={9} />);

    await user.click(screen.getByRole('button', { name: /Imprimir/ }));

    expect(print).toHaveBeenCalled();
  });
});
