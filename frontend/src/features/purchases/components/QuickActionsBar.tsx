/**
 * QuickActionsBar: barra de acciones contextuales para un Purchase.
 * Muestra solo los botones relevantes segun el estado:
 * - draft: Recibir (primary), Cancelar (danger)
 * - partially_received: Recibir lo que falta, Pagar CxP
 * - received: Pagar CxP, Imprimir
 * - cancelled: ninguna accion
 */
import { useState } from 'react';
import { CreditCard, Package, Printer, XCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useCancelPurchase } from '@/features/purchases/api';
import type { Purchase } from '@/features/purchases/schemas';

interface QuickActionsBarProps {
  purchase: Purchase;
  onReceive?: () => void;
  onPayPayable?: () => void;
  onPrint?: () => void;
}

export function QuickActionsBar({
  purchase,
  onReceive,
  onPayPayable,
  onPrint,
}: QuickActionsBarProps) {
  const cancel = useCancelPurchase();
  const [confirmingCancel, setConfirmingCancel] = useState(false);

  if (purchase.status === 'cancelled') {
    return null;
  }

  const showReceive = purchase.status === 'draft' || purchase.status === 'partially_received';
  const showPay = purchase.status === 'received' || purchase.status === 'partially_received';

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        {showReceive && onReceive && (
          <Button
            size="sm"
            leftIcon={<Package className="size-4" />}
            onClick={onReceive}
            data-testid={`purchase-receive-${purchase.id}`}
          >
            {purchase.status === 'partially_received' ? 'Recibir lo que falta' : 'Recibir mercancia'}
          </Button>
        )}
        {purchase.status === 'draft' && (
          <Button
            size="sm"
            variant="outline"
            leftIcon={<XCircle className="size-4" />}
            onClick={() => setConfirmingCancel(true)}
            data-testid={`purchase-cancel-${purchase.id}`}
          >
            Cancelar
          </Button>
        )}
        {showPay && onPayPayable && (
          <Button
            size="sm"
            variant="outline"
            leftIcon={<CreditCard className="size-4" />}
            onClick={onPayPayable}
            data-testid={`purchase-pay-${purchase.id}`}
          >
            Pagar CxP
          </Button>
        )}
        {onPrint && (
          <Button
            size="sm"
            variant="ghost"
            leftIcon={<Printer className="size-4" />}
            onClick={onPrint}
            data-testid={`purchase-print-${purchase.id}`}
          >
            Imprimir
          </Button>
        )}
      </div>

      {confirmingCancel && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setConfirmingCancel(false); }}
          title={`Cancelar compra "${purchase.document_number ?? '#' + purchase.id}"`}
          description="La compra quedara en estado cancelado. No se puede deshacer."
          confirmLabel="Cancelar compra"
          variant="danger"
          loading={cancel.isPending}
          onConfirm={async () => {
            try {
              await cancel.mutateAsync(purchase.id);
              setConfirmingCancel(false);
              toast.success('Compra cancelada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al cancelar.');
            }
          }}
        />
      )}
    </>
  );
}