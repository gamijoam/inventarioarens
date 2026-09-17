import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchTicketPreview = vi.fn<
  (orderId: number) => Promise<{ html: string; paperWidthMm: number }>
>();

vi.mock('@/features/printing/api', () => ({
  fetchTicketPreview: (orderId: number) => mockFetchTicketPreview(orderId),
}));

import { TicketPreviewDialog } from '../TicketPreviewDialog';
import type { PosOrder } from '../api';

const order = { id: 42, status: 'paid' } as unknown as PosOrder;

function renderDialog(props: Partial<Parameters<typeof TicketPreviewDialog>[0]> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <TicketPreviewDialog
        order={order}
        onClose={vi.fn()}
        canThermalPrint={false}
        canDigital={false}
        busy={false}
        onPrintThermal={vi.fn()}
        onDownloadPdf={vi.fn()}
        {...props}
      />
    </QueryClientProvider>,
  );
}

describe('TicketPreviewDialog', () => {
  beforeEach(() => {
    mockFetchTicketPreview.mockReset();
    mockFetchTicketPreview.mockResolvedValue({
      html: '<html><body>Ticket POS #42</body></html>',
      paperWidthMm: 80,
    });
  });

  it('muestra el ticket en un iframe con el HTML del backend', async () => {
    renderDialog();

    expect(screen.getByTestId('ticket-preview-dialog')).toBeInTheDocument();
    const frame = await screen.findByTestId('ticket-preview-frame');
    expect(frame).toHaveAttribute('srcdoc', '<html><body>Ticket POS #42</body></html>');
    expect(mockFetchTicketPreview).toHaveBeenCalledWith(42);
  });

  it('oculta Imprimir si no hay impresora termica y muestra Descargar PDF con permiso', async () => {
    renderDialog({ canThermalPrint: false, canDigital: true });

    await screen.findByTestId('ticket-preview-frame');
    expect(screen.queryByTestId('ticket-preview-print')).not.toBeInTheDocument();
    expect(screen.getByTestId('ticket-preview-download')).toBeInTheDocument();
  });

  it('dispara la impresion y la descarga manual', async () => {
    const onPrintThermal = vi.fn();
    const onDownloadPdf = vi.fn();
    renderDialog({ canThermalPrint: true, canDigital: true, onPrintThermal, onDownloadPdf });

    await screen.findByTestId('ticket-preview-frame');
    fireEvent.click(screen.getByTestId('ticket-preview-print'));
    fireEvent.click(screen.getByTestId('ticket-preview-download'));

    expect(onPrintThermal).toHaveBeenCalledTimes(1);
    expect(onDownloadPdf).toHaveBeenCalledTimes(1);
  });

  it('no renderiza nada sin orden', () => {
    renderDialog({ order: null });

    expect(screen.queryByTestId('ticket-preview-dialog')).not.toBeInTheDocument();
    expect(mockFetchTicketPreview).not.toHaveBeenCalled();
  });
});
