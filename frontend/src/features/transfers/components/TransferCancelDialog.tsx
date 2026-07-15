/**
 * TransferCancelDialog: dialog para cancelar un traslado
 * (POST /api/inventory-transfers/{id}/cancel).
 *
 * Solo valido en status requested/prepared/prepared_with_differences.
 * El backend rechaza con 422 si se intenta cancelar en otros estados.
 */
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { useCancelTransfer, useTransfer } from '@/features/transfers/api';
import type { Transfer } from '@/features/transfers/schemas';

interface TransferCancelDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCancelled?: (transfer: Transfer) => void;
}

export function TransferCancelDialog({
  transferId,
  open,
  onOpenChange,
  onCancelled,
}: TransferCancelDialogProps) {
  const { data: transfer } = useTransfer(transferId);
  const cancel = useCancelTransfer();
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open) setReason('');
  }, [open]);

  if (!transfer) return null;

  const cancellable = ['requested', 'prepared', 'prepared_with_differences'].includes(transfer.status);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (reason.trim().length < 5) {
      toast.error('El motivo debe tener al menos 5 caracteres.');
      return;
    }
    setSubmitting(true);
    try {
      const result = await cancel.mutateAsync({
        id: transferId,
        values: { cancelled_at: null, cancellation_reason: reason.trim() },
      });
      toast.success('Traslado cancelado.');
      onCancelled?.(result);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al cancelar.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ModalShell open={open} onClose={() => onOpenChange(false)} title={`Cancelar traslado — ${transfer.document_number ?? '#' + transfer.id}`}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-text-muted">
          El traslado quedara en estado <strong>cancelado</strong> y el stock reservado se libera.
          Esta accion no se puede deshacer.
        </p>
        {!cancellable && (
          <p className="text-xs text-warning">
            Este traslado esta en estado "{transfer.status}". Solo se puede cancelar desde SOLICITADO, PREPARADO o PREPARADO_CON_DIFERENCIAS.
          </p>
        )}
        <div className="space-y-1.5">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Motivo <span className="text-danger">*</span>
          </label>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            minLength={5}
            maxLength={1000}
            required
            rows={3}
            className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
            placeholder="Describe brevemente por que se cancela (min. 5 chars)"
          />
        </div>
        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Volver
          </Button>
          <Button type="submit" variant="danger" loading={submitting} disabled={!cancellable}>
            Confirmar cancelacion
          </Button>
        </div>
      </form>
    </ModalShell>
  );
}

function ModalShell({
  open,
  onClose,
  title,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
}) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-md rounded-lg border border-border bg-surface max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 flex items-center justify-between border-b border-border bg-surface px-5 py-3">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-text-muted hover:bg-bg hover:text-text-primary" aria-label="Cerrar">
            <X className="size-4" />
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}