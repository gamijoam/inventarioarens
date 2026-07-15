/**
 * Pagina /transfers: gestion de traslados (InventoryTransfers).
 * FASE T3+T4 entrega: listado con filtros + dialogs de crear, recibir,
 * asignar transportista, descargar guia. El detalle completo (/transfers/$id)
 * vive en la ruta $transferId.tsx.
 */
import { useState } from 'react';
import { createFileRoute, useNavigate } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { TransfersManager } from '@/features/transfers/TransfersManager';
import { TransferReceiveDialog } from '@/features/transfers/components/TransferReceiveDialog';

export const Route = createFileRoute('/_authed/transfers')({
  component: TransfersPage,
});

function TransfersPage() {
  const [receivingId, setReceivingId] = useState<number | null>(null);
  const navigate = useNavigate();

  return (
    <PageLayout
      title="Traslados"
      description="Movimiento de stock entre almacenes. Flujo: crear borrador -> preparar -> despachar -> recibir -> CxP."
    >
      <TransfersManager
        onReceive={(id) => setReceivingId(id)}
        onNew={() => navigate({ to: '/inventory/admin' })}
      />

      {receivingId !== null && (
        <TransferReceiveDialog
          transferId={receivingId}
          open={receivingId !== null}
          onOpenChange={(open) => { if (!open) setReceivingId(null); }}
          onReceived={() => navigate({ to: '/transfers' })}
        />
      )}
    </PageLayout>
  );
}
