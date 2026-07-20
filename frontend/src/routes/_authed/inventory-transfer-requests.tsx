/**
 * Pagina /inventory-transfer-requests: bandeja con tabs (Enviadas,
 * Recibidas, Pendientes, Completadas, Rechazadas/Canceladas) para
 * solicitudes de stock entre empresas del grupo.
 *
 * Dialogs disponibles:
 *   - Crear nueva solicitud (FAB Nueva solicitud).
 *   - Aceptar solicitud recibida.
 *   - Rechazar solicitud recibida.
 *   - Cancelar solicitud enviada (boton inline en la fila).
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { InventoryTransferRequestsManager } from '@/features/inventory-transfer-requests/InventoryTransferRequestsManager';
import { CreateInventoryTransferRequestDialog } from '@/features/inventory-transfer-requests/components/CreateInventoryTransferRequestDialog';
import { AcceptInventoryTransferRequestDialog } from '@/features/inventory-transfer-requests/components/AcceptInventoryTransferRequestDialog';
import { RejectInventoryTransferRequestDialog } from '@/features/inventory-transfer-requests/components/RejectInventoryTransferRequestDialog';
import type { TransferRequest } from '@/features/inventory-transfer-requests/schemas';

export const Route = createFileRoute('/_authed/inventory-transfer-requests')({
  component: InventoryTransferRequestsPage,
});

function InventoryTransferRequestsPage() {
  const [creating, setCreating] = useState(false);
  const [accepting, setAccepting] = useState<TransferRequest | null>(null);
  const [rejecting, setRejecting] = useState<TransferRequest | null>(null);

  return (
    <PageLayout
      title="Solicitudes inter-empresa"
      description="Pedidos de stock entre empresas hermanas del grupo. La empresa destino debe aceptar para que se materialice el movimiento."
    >
      <InventoryTransferRequestsManager
        onCreate={() => setCreating(true)}
        onAccept={(r) => setAccepting(r)}
        onReject={(r) => setRejecting(r)}
      />

      {creating && (
        <CreateInventoryTransferRequestDialog
          open={creating}
          onOpenChange={(o) => { if (!o) setCreating(false); }}
        />
      )}

      {accepting && (
        <AcceptInventoryTransferRequestDialog
          request={accepting}
          open={accepting !== null}
          onOpenChange={(o) => { if (!o) setAccepting(null); }}
        />
      )}

      {rejecting && (
        <RejectInventoryTransferRequestDialog
          request={rejecting}
          open={rejecting !== null}
          onOpenChange={(o) => { if (!o) setRejecting(null); }}
        />
      )}
    </PageLayout>
  );
}
