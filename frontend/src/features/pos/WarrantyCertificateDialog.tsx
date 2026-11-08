import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Loader2, Printer, ShieldCheck, X } from 'lucide-react';
import { toast } from '@/lib/toast';

import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent } from '@/components/ui/Dialog';
import { downloadWarrantyCertificatePdf, fetchWarrantyCertificate } from '@/features/printing/api';

interface WarrantyCertificateDialogProps {
  orderId: number | null;
  open: boolean;
  onClose: () => void;
}

export function WarrantyCertificateDialog({
  orderId,
  open,
  onClose,
}: WarrantyCertificateDialogProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const [downloading, setDownloading] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['pos-warranty-certificate', orderId],
    queryFn: () => (orderId ? fetchWarrantyCertificate(orderId) : null),
    enabled: open && orderId !== null,
  });

  const handlePrint = () => {
    const iframe = iframeRef.current;
    if (!iframe || !iframe.contentWindow) {
      toast.error('No se pudo inicializar la vista de impresión.');
      return;
    }
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
  };

  const handleDownload = async () => {
    if (!orderId) return;
    setDownloading(true);
    try {
      await downloadWarrantyCertificatePdf(orderId);
      toast.success('Certificado descargado correctamente.');
    } catch {
      toast.error('No se pudo descargar el certificado PDF.');
    } finally {
      setDownloading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent
        className="fixed top-1/2 left-1/2 z-50 flex h-[94vh] max-h-[94vh] w-[95vw] max-w-4xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-2xl border border-border bg-surface p-0 shadow-2xl"
        data-testid="warranty-certificate-dialog"
      >
        {/* Header */}
        <div className="flex shrink-0 items-center justify-between border-b border-border px-6 py-4 bg-surface">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <ShieldCheck className="size-6" />
            </div>
            <div>
              <p className="text-xs font-bold tracking-wide text-primary uppercase">
                Garantía Oficial · Formato Carta / A4
              </p>
              <h2 className="text-lg font-extrabold text-text-primary">
                Certificado de Garantía #{orderId}
              </h2>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="rounded-xl p-2 text-text-muted transition-colors hover:bg-bg hover:text-text-primary"
            aria-label="Cerrar certificado"
          >
            <X className="size-5" />
          </button>
        </div>

        {/* Content / Preview */}
        <div className="flex min-h-0 flex-1 justify-center overflow-auto bg-slate-900/5 dark:bg-black/30 p-4 sm:p-6">
          {isLoading ? (
            <div className="flex flex-col items-center justify-center gap-2 text-text-muted">
              <Loader2 className="size-7 animate-spin text-primary" />
              <span className="text-sm font-medium">Generando certificado oficial...</span>
            </div>
          ) : isError || !data ? (
            <div className="flex flex-col items-center justify-center text-center p-6">
              <p className="text-sm font-medium text-destructive">
                No se pudo cargar la vista previa del certificado de garantía.
              </p>
            </div>
          ) : !data.has_warranty ? (
            <div className="flex flex-col items-center justify-center text-center p-6 text-text-muted">
              <ShieldCheck className="size-10 text-text-muted mb-2 opacity-50" />
              <p className="text-base font-semibold text-text-primary">Sin equipos amparados</p>
              <p className="text-sm max-w-sm mt-1">
                Esta venta no contiene productos con póliza de garantía ni números de serie/IMEI asignados.
              </p>
            </div>
          ) : (
            <div className="relative w-full max-w-[820px] rounded-xl border border-slate-300 dark:border-slate-700 bg-white shadow-xl overflow-hidden min-h-[600px]">
              <iframe
                ref={iframeRef}
                title={`Certificado de Garantía #${orderId}`}
                srcDoc={data.html}
                data-testid="warranty-certificate-frame"
                className="w-full h-full min-h-[720px] border-0 bg-white"
              />
            </div>
          )}
        </div>

        {/* Action Footer */}
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border px-6 py-3.5 bg-surface">
          <span className="text-xs text-text-muted">
            Impresora estándar de oficina (HP, Epson, Canon o Guardar como PDF)
          </span>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={handleDownload}
              disabled={isLoading || !data?.has_warranty || downloading}
              data-testid="warranty-cert-download-btn"
            >
              {downloading ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Download className="size-4" />
              )}
              Descargar PDF
            </Button>

            <Button
              onClick={handlePrint}
              disabled={isLoading || !data?.has_warranty}
              data-testid="warranty-cert-print-btn"
              className="bg-primary hover:bg-primary/90 text-white font-semibold"
            >
              <Printer className="size-4" />
              Imprimir Certificado (Navegador)
            </Button>

            <Button variant="ghost" onClick={onClose} data-testid="warranty-cert-close-btn">
              Cerrar
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
