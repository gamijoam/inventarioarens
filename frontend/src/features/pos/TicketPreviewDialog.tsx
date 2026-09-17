/**
 * TicketPreviewDialog — Previsualizacion centrada del ticket POS.
 *
 * Se abre al completar una venta (F10), reemplazando la descarga automatica
 * del PDF y la apertura del panel lateral de recibo. Renderiza el mismo HTML
 * que se envia a la impresora termica (endpoint de preview sin PrintJob), de
 * modo que "lo que se ve es lo que se imprime". El PDF y la impresion quedan
 * como acciones manuales.
 */
import { useQuery } from '@tanstack/react-query';
import { Download, Loader2, Printer, X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent } from '@/components/ui/Dialog';
import { fetchTicketPreview } from '@/features/printing/api';

import type { PosOrder } from './api';

interface TicketPreviewDialogProps {
  order: PosOrder | null;
  onClose: () => void;
  canThermalPrint: boolean;
  canDigital: boolean;
  busy: boolean;
  onPrintThermal: () => void;
  onDownloadPdf: () => void;
}

/** Escala del preview: agranda el ticket sin tocar la plantilla de impresion. */
const PREVIEW_ZOOM = 1.5;
const MM_TO_PX = 3.7795;

function withPreviewZoom(html: string, zoom: number): string {
  const style = `<style>html { zoom: ${zoom}; }</style>`;

  return html.includes('</head>') ? html.replace('</head>', `${style}</head>`) : `${style}${html}`;
}

export function TicketPreviewDialog({
  order,
  onClose,
  canThermalPrint,
  canDigital,
  busy,
  onPrintThermal,
  onDownloadPdf,
}: TicketPreviewDialogProps) {
  const open = order !== null;
  const { data, isLoading, isError } = useQuery({
    queryKey: ['pos-ticket-preview', order?.id ?? null],
    queryFn: () => fetchTicketPreview(order!.id),
    enabled: open,
  });

  const paperWidthMm = data?.paperWidthMm ?? 80;
  const paperWidthPx = Math.round(paperWidthMm * MM_TO_PX);
  const frameWidth = Math.round(paperWidthPx * PREVIEW_ZOOM);

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent
        className="fixed top-1/2 left-1/2 z-50 flex h-[92vh] max-h-[92vh] w-[94vw] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-2xl border border-border bg-surface p-0 shadow-2xl"
        data-testid="ticket-preview-dialog"
      >
        <div className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5">
          <div>
            <p className="text-xs font-semibold tracking-wide text-success uppercase">
              Venta completada
            </p>
            <p className="text-lg font-bold text-text-primary">Ticket #{order?.id}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl p-2 text-text-muted transition-colors hover:bg-bg hover:text-text-primary"
            aria-label="Cerrar previsualizacion"
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="flex min-h-0 flex-1 justify-center overflow-auto bg-bg/40 p-4">
          {isLoading ? (
            <div className="flex flex-col items-center justify-center gap-2 text-text-muted">
              <Loader2 className="size-6 animate-spin text-primary" />
              <span className="text-sm">Generando previsualizacion...</span>
            </div>
          ) : isError || !data ? (
            <p className="self-center text-sm text-text-muted">
              No se pudo cargar la previsualizacion del ticket.
            </p>
          ) : (
            <iframe
              title={`Ticket #${order?.id}`}
              srcDoc={withPreviewZoom(data.html, PREVIEW_ZOOM)}
              data-testid="ticket-preview-frame"
              style={{ width: frameWidth }}
              className="h-full rounded-lg border border-border bg-white shadow-sm"
            />
          )}
        </div>

        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3">
          {canThermalPrint && (
            <Button onClick={onPrintThermal} disabled={busy} data-testid="ticket-preview-print">
              {busy ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />}
              Imprimir
            </Button>
          )}
          {canDigital && (
            <Button
              variant="outline"
              onClick={onDownloadPdf}
              disabled={busy}
              data-testid="ticket-preview-download"
            >
              <Download className="size-4" /> Descargar PDF
            </Button>
          )}
          <Button variant="outline" onClick={onClose} data-testid="ticket-preview-close">
            Cerrar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
