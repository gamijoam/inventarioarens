/**
 * Tests del QuotationPickerDialog: lista cotizaciones emitidas y permite
 * convertirlas a orden POS pendiente desde el terminal.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const convertMutation = vi.fn();
const quotations = [
  {
    id: 1,
    sequence: 1,
    document_number: 'COT-000001',
    customer_name: 'Cliente A',
    status: 'issued',
    total_base_amount: 100,
    valid_until: '2026-09-30',
  },
  {
    id: 2,
    sequence: 2,
    document_number: 'COT-000002',
    customer_name: null,
    status: 'converted',
    total_base_amount: 50,
    valid_until: null,
  },
];

vi.mock('./api', () => ({
  useQuotations: () => ({ data: quotations, isLoading: false }),
  useConvertQuotation: () => ({ mutateAsync: convertMutation }),
  openQuotationPdf: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('@/permissions/useCan', () => ({
  useCan: () => true,
}));
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { QuotationPickerDialog } from './QuotationPickerDialog';

describe('QuotationPickerDialog', () => {
  beforeEach(() => {
    convertMutation.mockReset();
    convertMutation.mockResolvedValue({
      quotation: { document_number: 'COT-000001' },
      pos_order: { id: 55 },
    });
  });

  it('lista las cotizaciones y convierte una emitida a orden POS', async () => {
    const onConverted = vi.fn();
    const onOpenChange = vi.fn();
    render(
      <QuotationPickerDialog open onOpenChange={onOpenChange} onConverted={onConverted} />,
    );

    expect(screen.getByText('COT-000001')).toBeInTheDocument();
    expect(screen.getByText('COT-000002')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('quotation-picker-convert-1'));

    await waitFor(() => {
      expect(convertMutation).toHaveBeenCalledWith(1);
    });
    expect(onConverted).toHaveBeenCalledWith(55);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('no muestra boton convertir en cotizaciones ya convertidas', () => {
    render(<QuotationPickerDialog open onOpenChange={vi.fn()} />);
    expect(screen.queryByTestId('quotation-picker-convert-2')).not.toBeInTheDocument();
  });
});
