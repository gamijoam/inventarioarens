/**
 * QuotationPickerDialog: lista las cotizaciones del vendedor y permite
 * convertirlas en orden POS pendiente sin salir del terminal (POS /pos/armar).
 */
import { useMemo, useState } from 'react';
import { Download, RefreshCw, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
import { useQuotations, useConvertQuotation, openQuotationPdf } from './api';

const STATUS_LABELS: Record<string, string> = {
  draft: 'Borrador',
  issued: 'Emitida',
  cancelled: 'Cancelada',
  converted: 'Convertida',
};

interface QuotationPickerDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConverted?: (posOrderId: number) => void;
}

export function QuotationPickerDialog(props: QuotationPickerDialogProps) {
  if (!props.open) return null;

  return <QuotationPickerDialogInner {...props} />;
}

function QuotationPickerDialogInner({ onOpenChange, onConverted }: QuotationPickerDialogProps) {
  const [status, setStatus] = useState('issued');
  const { data: quotations = [], isLoading } = useQuotations({ status, per_page: 100 });
  const convert = useConvertQuotation();
  const canConvert = useCan(PERMISSIONS.QUOTATIONS_CONVERT);
  const [convertingId, setConvertingId] = useState<number | null>(null);

  const issued = useMemo(() => quotations.filter((q) => q.status === 'issued'), [quotations]);

  async function handleConvert(id: number) {
    setConvertingId(id);
    try {
      const result = (await convert.mutateAsync(id)) as {
        pos_order?: { id?: number };
        quotation?: { document_number?: string };
      };
      toast.success(
        `Cotizacion ${result.quotation?.document_number ?? ''} convertida a venta. Quedo en espera para cobrar.`,
      );
      onOpenChange(false);
      if (result.pos_order?.id) onConverted?.(result.pos_order.id);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo convertir la cotizacion.');
    } finally {
      setConvertingId(null);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={() => onOpenChange(false)}
      role="dialog"
      aria-modal="true"
      aria-labelledby="quotation-picker-title"
    >
      <div
        className="border-border bg-surface max-h-[85vh] w-full max-w-3xl overflow-hidden rounded-2xl border shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="border-border bg-surface sticky top-0 z-10 flex items-center justify-between border-b px-5 py-3">
          <h2 id="quotation-picker-title" className="text-lg font-semibold">
            Cotizaciones
          </h2>
          <div className="flex items-center gap-2">
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="border-border-strong bg-surface rounded border px-2 py-1 text-sm"
              data-testid="quotation-picker-status"
            >
              <option value="issued">Emitidas</option>
              <option value="draft">Borrador</option>
              <option value="converted">Convertidas</option>
              <option value="cancelled">Canceladas</option>
            </select>
            <button
              type="button"
              onClick={() => onOpenChange(false)}
              className="text-text-muted hover:bg-bg hover:text-text-primary rounded p-1"
              aria-label="Cerrar"
            >
              <X className="size-4" />
            </button>
          </div>
        </div>

        <div className="overflow-y-auto p-4">
          {isLoading ? (
            <Spinner label="Cargando cotizaciones..." />
          ) : quotations.length === 0 ? (
            <EmptyState
              title="Sin cotizaciones"
              description="No hay cotizaciones en este estado. Crea una desde el carrito."
            />
          ) : (
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Numero</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Cliente</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Total</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Vence</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {quotations.map((q) => (
                  <tr key={q.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2 font-mono text-xs">{q.document_number}</td>
                    <td className="px-3 py-2">{q.customer_name ?? '—'}</td>
                    <td className="px-3 py-2">
                      <Badge variant={q.status === 'issued' ? 'success' : q.status === 'converted' ? 'warning' : q.status === 'cancelled' ? 'danger' : 'default'}>
                        {STATUS_LABELS[q.status] ?? q.status}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(q.total_base_amount)}</td>
                    <td className="px-3 py-2">{q.valid_until ?? '—'}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          type="button"
                          className="inline-flex items-center gap-1 rounded p-1.5 text-sm hover:bg-bg"
                          title="Abrir PDF"
                          data-testid={`quotation-picker-pdf-${q.id}`}
                          onClick={() => {
                            void openQuotationPdf(q.id).catch(() =>
                              toast.error('No se pudo abrir el PDF.'),
                            );
                          }}
                        >
                          <Download className="size-4" />
                        </button>
                        {q.status === 'issued' && canConvert && (
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={convertingId === q.id}
                            loading={convertingId === q.id}
                            onClick={() => handleConvert(q.id)}
                            data-testid={`quotation-picker-convert-${q.id}`}
                          >
                            <RefreshCw className="size-3.5" /> Convertir
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {issued.length > 0 && (
            <p className="text-text-muted mt-3 text-xs">
              Las cotizaciones emitidas se convierten en ordenes en espera para cobrar desde el
              terminal.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
