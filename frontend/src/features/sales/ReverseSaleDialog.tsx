import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import type { CashRegisterSession } from '@/features/pos/api';
import { useReversePosSale, type ReversePosSalePayload } from './api';
import type { Sale } from './schemas';

interface ReverseSaleDialogProps {
  sale: Sale;
  activeSession: CashRegisterSession | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

function isSameCalendarDay(value: string | null | undefined): boolean {
  if (!value) return false;
  const date = new Date(value);
  const now = new Date();
  return (
    date.getFullYear() === now.getFullYear() &&
    date.getMonth() === now.getMonth() &&
    date.getDate() === now.getDate()
  );
}

export function ReverseSaleDialog({
  sale,
  activeSession,
  open,
  onOpenChange,
}: ReverseSaleDialogProps) {
  const paidAt = sale.pos_order?.paid_at ?? sale.confirmed_at ?? sale.created_at;
  const sameDay = isSameCalendarDay(paidAt);
  const [reason, setReason] = useState('');
  const [type, setType] = useState<ReversePosSalePayload['type']>(sameDay ? 'void' : 'reversal');
  const reverse = useReversePosSale();
  const reasonIsValid = reason.trim().length >= 5;
  const canSubmit = Boolean(sale.pos_order?.id && activeSession?.id && reasonIsValid);

  async function submit(): Promise<void> {
    if (!sale.pos_order?.id || !activeSession?.id) return;
    if (!reasonIsValid) {
      toast.error('El motivo debe tener al menos 5 caracteres.');
      return;
    }

    try {
      await reverse.mutateAsync({
        posOrderId: sale.pos_order.id,
        payload: {
          type,
          reason: reason.trim(),
          cash_register_session_id: activeSession.id,
        },
      });
      toast.success(type === 'void' ? 'Venta anulada.' : 'Venta revertida.');
      setReason('');
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo revertir la venta.');
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{sameDay ? 'Anular venta POS' : 'Revertir venta POS'}</DialogTitle>
          <DialogDescription>
            {sameDay
              ? 'La anulación aplica a ventas pagadas el día de hoy.'
              : 'Esta venta es anterior y debe procesarse como reversal.'}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <Label htmlFor="reverse-sale-type">Tipo de operación</Label>
            <select
              id="reverse-sale-type"
              aria-label="Tipo de operación"
              className="border-border bg-surface mt-1 h-9 w-full rounded border px-3 text-sm"
              value={type}
              onChange={(event) => setType(event.target.value as ReversePosSalePayload['type'])}
              disabled
            >
              <option value="void">Anulación (void)</option>
              <option value="reversal">Reversal</option>
            </select>
          </div>

          <div>
            <Label htmlFor="reverse-sale-reason">Motivo</Label>
            <Input
              id="reverse-sale-reason"
              aria-label="Motivo"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Describe por qué se revierte la venta"
              maxLength={2000}
              className="mt-1"
            />
            {!reasonIsValid && reason.length > 0 && (
              <p className="text-danger mt-1 text-xs">
                El motivo debe tener al menos 5 caracteres.
              </p>
            )}
          </div>

          {!activeSession && (
            <p className="border-warning/30 bg-warning/10 text-warning rounded border p-2 text-sm">
              Debes abrir una caja para procesar esta operación.
            </p>
          )}
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={reverse.isPending}
          >
            Cancelar
          </Button>
          <Button
            variant="danger"
            loading={reverse.isPending}
            disabled={!canSubmit}
            onClick={() => void submit()}
          >
            Confirmar {sameDay ? 'anulación' : 'reversión'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
