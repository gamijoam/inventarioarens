/**
 * RejectInventoryTransferRequestDialog: dialog para que la empresa destino
 * rechaze una solicitud. Solo pide notas opcionales.
 */
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { useRejectTransferRequest } from '@/features/inventory-transfer-requests/api';
import type { TransferRequest } from '../schemas';

interface RejectInventoryTransferRequestDialogProps {
  request: TransferRequest;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onRejected?: (id: number) => void;
}

export function RejectInventoryTransferRequestDialog({
  request,
  open,
  onOpenChange,
  onRejected,
}: RejectInventoryTransferRequestDialogProps) {
  const reject = useRejectTransferRequest();
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    setNotes('');
  }, [open]);

  if (!open) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const rejected = await reject.mutateAsync({
        id: request.id,
        values: { response_notes: notes.trim() ? notes.trim() : null },
      });
      toast.success('Solicitud rechazada.');
      onRejected?.(rejected.id);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al rechazar la solicitud.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={() => onOpenChange(false)}
      role="dialog"
      aria-modal="true"
      aria-labelledby="reject-req-title"
    >
      <div
        className="w-full max-w-md rounded-lg border border-border bg-surface p-5"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="reject-req-title" className="text-lg font-semibold">
          Rechazar solicitud {request.document_number ?? '#' + request.id}
        </h2>
        <p className="mt-1 text-sm text-text-muted">
          La solicitud quedara en estado <code>rejected</code>. Indica opcionalmente el motivo.
        </p>
        <form onSubmit={handleSubmit} className="mt-4 space-y-3">
          <div>
            <Label htmlFor="rej-notes">Notas (opcional)</Label>
            <textarea
              id="rej-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              maxLength={1000}
              rows={3}
              className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" variant="danger" loading={submitting}>
              Confirmar rechazo
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
