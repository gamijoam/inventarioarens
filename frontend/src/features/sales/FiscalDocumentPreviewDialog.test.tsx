import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import type { FiscalDocumentPreview } from '@/features/fiscal-documents/api';
import type { Sale } from './schemas';

const { getFiscalDocumentPreview, getFiscalDocumentPreviews, mutateAsync, toastError } = vi.hoisted(
  () => ({
    getFiscalDocumentPreview: vi.fn(),
    getFiscalDocumentPreviews: vi.fn(),
    mutateAsync: vi.fn(),
    toastError: vi.fn(),
  }),
);

vi.mock('@/features/fiscal-documents/api', () => ({
  getFiscalDocumentPreview,
  getFiscalDocumentPreviews,
  useCreateFiscalDocumentPreview: () => ({ mutateAsync, isPending: false }),
}));
vi.mock('sonner', () => ({
  toast: { error: toastError },
}));

import { FiscalDocumentPreviewDialog } from './FiscalDocumentPreviewDialog';

function makeSale(): Sale {
  return {
    id: 15,
    status: 'confirmed',
    total_base_amount: 116,
    total_local_amount: 6960,
    receivable: null,
  };
}

function makePreview(): FiscalDocumentPreview {
  return {
    id: 10,
    tenant_id: 1,
    sale_id: 15,
    document_type: 'internal_preview',
    document_mode: 'internal_preview',
    status: 'preview',
    officially_issued: false,
    company_snapshot: { legal_name: 'Arens Comercial', tax_id: 'J-12345678-9' },
    branch_snapshot: { name: 'Sucursal Centro' },
    customer_snapshot: { name: 'Cliente Demo', fiscal_name: 'Cliente Demo Fiscal' },
    totals_snapshot: {
      total_base_amount: 116,
      total_local_amount: 6960,
      fiscal_taxable_base_amount: 100,
      fiscal_taxable_local_amount: 6000,
      fiscal_exempt_base_amount: 0,
      fiscal_exempt_local_amount: 0,
      fiscal_exonerated_base_amount: 0,
      fiscal_exonerated_local_amount: 0,
      fiscal_non_taxable_base_amount: 0,
      fiscal_non_taxable_local_amount: 0,
      fiscal_tax_base_amount: 16,
      fiscal_tax_local_amount: 960,
    },
    snapshot_at: '2026-08-29T12:00:00.000000Z',
    items: [
      {
        id: 1,
        sale_item_id: 2,
        quantity: 1,
        sale_currency: 'USD',
        unit_price: 116,
        total_amount: 116,
        base_unit_price: 116,
        base_total_amount: 116,
        local_total_amount: 6960,
        product_snapshot: { name: 'Producto Demo', sku: 'SKU-1' },
        warehouse_snapshot: null,
        commercial_snapshot: null,
        fiscal_snapshot: { tax_code: 'general', tax_rate: 16 },
      },
    ],
  };
}

describe('FiscalDocumentPreviewDialog', () => {
  beforeEach(() => {
    getFiscalDocumentPreview.mockReset();
    getFiscalDocumentPreviews.mockReset();
    mutateAsync.mockReset();
    toastError.mockReset();
    getFiscalDocumentPreviews.mockResolvedValue({ data: [] });
    mutateAsync.mockResolvedValue(makePreview());
  });

  it('creates and displays an explicitly internal preview without issuing it', async () => {
    render(<FiscalDocumentPreviewDialog sale={makeSale()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Vista previa interna' }));

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith(15));
    expect(await screen.findByText('Arens Comercial')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Vista previa interna' })).toBeInTheDocument();
    expect(
      screen.getByText(
        'Documento interno para revisión comercial. No constituye emisión fiscal oficial.',
      ),
    ).toBeInTheDocument();
    expect(screen.getByText('Interno · No emitido')).toBeInTheDocument();
    expect(screen.getByText('$100,00')).toBeInTheDocument();
    expect(screen.queryByText(/número de control/i)).not.toBeInTheDocument();
  });

  it('shows the API error without opening an issuance flow', async () => {
    mutateAsync.mockRejectedValue(new Error('Venta no confirmada'));
    render(<FiscalDocumentPreviewDialog sale={makeSale()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Vista previa interna' }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('Venta no confirmada'));
    expect(screen.queryByText('Arens Comercial')).not.toBeInTheDocument();
  });

  it('reopens the persisted preview instead of creating another one', async () => {
    getFiscalDocumentPreviews.mockResolvedValue({ data: [{ id: 10 }] });
    getFiscalDocumentPreview.mockResolvedValue(makePreview());
    render(<FiscalDocumentPreviewDialog sale={makeSale()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Vista previa interna' }));

    await waitFor(() => expect(getFiscalDocumentPreview).toHaveBeenCalledWith(10));
    expect(mutateAsync).not.toHaveBeenCalled();
    expect(await screen.findByText('Arens Comercial')).toBeInTheDocument();
  });

  it('offers commercial printing without changing the preview state', async () => {
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
    render(<FiscalDocumentPreviewDialog sale={makeSale()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Vista previa interna' }));
    await screen.findByText('Arens Comercial');
    fireEvent.click(screen.getByRole('button', { name: 'Imprimir vista comercial' }));

    expect(print).toHaveBeenCalledOnce();
    print.mockRestore();
  });
});
