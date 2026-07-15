/**
 * TransferDispatchDialog: dialog para despachar mercancia de un traslado
 * (POST /api/inventory-transfers/{id}/dispatch).
 *
 * Solo valido para validation_mode = 'logistics'. El traslado debe estar
 * en status 'prepared' o 'prepared_with_differences'.
 */
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { useDispatchTransfer, useTransfer } from '@/features/transfers/api';
import type { Transfer } from '@/features/transfers/schemas';

interface TransferDispatchDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onDispatched?: (transfer: Transfer) => void;
}

export function TransferDispatchDialog({
  transferId,
  open,
  onOpenChange,
  onDispatched,
}: TransferDispatchDialogProps) {
  const { data: transfer } = useTransfer(transferId);
  const dispatch = useDispatchTransfer();
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open) setNotes('');
  }, [open]);

  if (!transfer) return null;

  if (transfer.validation_mode !== 'logistics') {
    return (
      <ModalShell open={open} onClose={() => onOpenChange(false)} title="Despachar">
        <p className="text-sm text-text-muted">Este traslado esta en modo simple y no requiere despacho manual.</p>
      </ModalShell>
    );
  }

  const canDispatch = transfer.status === 'prepared' || transfer.status === 'prepared_with_differences';

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const result = await dispatch.mutateAsync({
        id: transferId,
        values: { dispatched_at: null, notes: notes.trim() || null },
      });
      toast.success('Traslado despachado. En transito.');
      onDispatched?.(result);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al despachar.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ModalShell open={open} onClose={() => onOpenChange(false)} title={`Despachar — ${transfer.document_number ?? '#' + transfer.id}`}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-text-muted">
          Confirma el despacho. El traslado pasa a <strong>DESPACHADO</strong> y queda en transito
          hasta que el destino lo reciba.
        </p>
        {!canDispatch && (
          <p className="text-xs text-warning">
            El traslado esta en estado "{transfer.status}". Solo se puede despachar desde PREPARADO o PREPARADO_CON_DIFERENCIAS.
          </p>
        )}
        <div className="space-y-1.5">
          <Label htmlFor="disp-notes">Notas (opcional)</Label>
          <Input
            id="disp-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Ej: sale en camio ABC-123, conductor Juan"
            maxLength={1000}
          />
        </div>
        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" loading={submitting} disabled={!canDispatch}>
            Confirmar despacho
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