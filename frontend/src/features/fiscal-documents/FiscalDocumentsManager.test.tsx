import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import type { FiscalDocumentPreview } from './api';

const { mockUseFiscalDocumentPreviews } = vi.hoisted(() => ({
  mockUseFiscalDocumentPreviews: vi.fn(),
}));

vi.mock('./api', () => ({
  useFiscalDocumentPreviews: mockUseFiscalDocumentPreviews,
}));
vi.mock('@/permissions/useCan', () => ({
  useCanAny: () => true,
}));
vi.mock('@/features/sales/FiscalDocumentPreviewDialog', () => ({
  FiscalDocumentPreviewViewerDialog: ({
    preview,
    open,
  }: {
    preview: FiscalDocumentPreview | null;
    open: boolean;
  }) => (open ? <div data-testid="selected-preview">Preview #{preview?.id}</div> : null),
}));

import { FiscalDocumentsManager } from './FiscalDocumentsManager';

function makePreview(): FiscalDocumentPreview {
  return {
    id: 10,
    tenant_id: 1,
    sale_id: 20,
    document_type: 'internal_preview',
    document_mode: 'internal_preview',
    status: 'preview',
    officially_issued: false,
    company_snapshot: { legal_name: 'Empresa Demo' },
    branch_snapshot: null,
    customer_snapshot: { name: 'Cliente Demo', fiscal_name: 'Cliente Fiscal' },
    totals_snapshot: {
      total_base_amount: 116,
      total_local_amount: 116,
      fiscal_taxable_base_amount: 100,
      fiscal_taxable_local_amount: 100,
      fiscal_exempt_base_amount: 0,
      fiscal_exempt_local_amount: 0,
      fiscal_exonerated_base_amount: 0,
      fiscal_exonerated_local_amount: 0,
      fiscal_non_taxable_base_amount: 0,
      fiscal_non_taxable_local_amount: 0,
      fiscal_tax_base_amount: 16,
      fiscal_tax_local_amount: 16,
    },
    snapshot_at: '2026-08-29T12:00:00.000000Z',
    items: [],
  };
}

describe('FiscalDocumentsManager', () => {
  beforeEach(() => {
    mockUseFiscalDocumentPreviews.mockReturnValue({
      data: {
        data: [makePreview()],
        meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      },
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    });
  });

  it('lists persisted previews and opens the selected snapshot', () => {
    render(<FiscalDocumentsManager search={{}} onSearchChange={vi.fn()} />);

    expect(screen.getByText('#20')).toBeInTheDocument();
    expect(screen.getByText('Cliente Fiscal')).toBeInTheDocument();
    expect(screen.getByText('Interno · No emitido')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Abrir' }));

    expect(screen.getByTestId('selected-preview')).toHaveTextContent('Preview #10');
  });

  it('updates the sale filter and resets the page', () => {
    const onSearchChange = vi.fn();
    render(<FiscalDocumentsManager search={{ page: 3 }} onSearchChange={onSearchChange} />);

    fireEvent.change(screen.getByLabelText('Venta'), { target: { value: '42' } });

    expect(onSearchChange).toHaveBeenCalledWith({ sale_id: 42, page: 1 });
  });
});
