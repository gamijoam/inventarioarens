/**
 * Pagina /transfers: gestion de traslados (InventoryTransfers).
 * FASE T3+T4 entrega: listado con filtros + dialogs de crear, recibir,
 * asignar transportista, descargar guia. El detalle completo (/transfers/$id)
 * vive en la ruta $transferId.tsx (child route).
 *
 * Esta ruta actua como PARENT LAYOUT para el detalle (TanStack Router:
 * /transfers/$transferId es child de /transfers). Por eso usa <Outlet />.
 */
import { useState } from 'react';
import { createFileRoute, Outlet, useLocation, useNavigate } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { TransfersManager } from '@/features/transfers/TransfersManager';
import { TransferCreateDialog } from '@/features/transfers/components/TransferCreateDialog';
import { TransferReceiveDialog } from '@/features/transfers/components/TransferReceiveDialog';

export const Route = createFileRoute('/_authed/transfers')({
  component: TransfersPage,
});

function TransfersPage() {
  const [receivingId, setReceivingId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  // Cuando la URL es /transfers/$transferId, este componente es el parent
  // layout y debe renderizar <Outlet /> en lugar del listado.
  const childMatch = /^\/transfers\/\d+$/.exec(location.pathname);
  const isChildRouteActive = childMatch !== null;

  if (isChildRouteActive) {
    // Renderizamos SOLO el child route (el detalle), sin el listado.
    return <Outlet />;
  }

  return (
    <PageLayout
      title="Traslados"
      description="Movimiento de stock entre almacenes. Flujo: crear borrador -> preparar -> despachar -> recibir -> CxP."
    >
      <TransfersManager
        onReceive={(id) => setReceivingId(id)}
        onNew={() => setCreating(true)}
      />

      {creating && (
        <TransferCreateDialog
          open={creating}
          onOpenChange={(open) => { if (!open) setCreating(false); }}
          onCreated={(id) => navigate({ to: '/transfers/$transferId', params: { transferId: String(id) } })}
        />
      )}

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
