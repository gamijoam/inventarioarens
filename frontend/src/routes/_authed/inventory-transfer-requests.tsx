/**
 * Pagina /inventory-transfer-requests: bandeja con tabs (Enviadas,
 * Recibidas, Pendientes, Completadas, Rechazadas/Canceladas) para
 * solicitudes de stock entre empresas del grupo.
 *
 * Tambien actua como PARENT LAYOUT para la ruta hija $requestId
 * (detalle de la solicitud). Cuando la URL es /inventory-transfer-requests/$id,
 * este componente renderiza <Outlet /> en vez de la bandeja.
 *
 * Dialogs disponibles (solo cuando NO estamos en sub-ruta):
 *   - Crear nueva solicitud (FAB Nueva solicitud).
 *   - Aceptar solicitud recibida (boton inline en la fila).
 *   - Rechazar solicitud recibida (boton inline en la fila).
 *   - Cancelar solicitud enviada (boton inline en la fila).
 */
import { useEffect, useState } from 'react';
import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { InventoryTransferRequestsManager } from '@/features/inventory-transfer-requests/InventoryTransferRequestsManager';
import { markTransferRequestsAsSeen } from '@/features/inventory-transfer-requests/api';
import { useSessionStore } from '@/stores/session';
import { useQueryClient } from '@tanstack/react-query';
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
  const location = useLocation();
  const currentTenantId = useSessionStore((s) => s.tenant?.id);
  const qc = useQueryClient();

  // Cuando el user entra a esta pagina, marcamos las solicitudes
  // pendientes como "vistas" y forzamos re-fetch del contador del sidebar.
  // Asi el badge rojo se limpia hasta que llegue una nueva solicitud
  // despues del lastSeenAt. Tambien cubre el caso del usuario que
  // entra directamente (sin venir del push) - el sidebar tendria el
  // badge con los pendientes sin ver, pero al entrar a la lista se
  // resetea (porque ahora el user los vio).
  useEffect(() => {
    if (typeof currentTenantId === 'number' && currentTenantId > 0) {
      markTransferRequestsAsSeen(currentTenantId);
      // Forzar re-fetch para que el badge se limpie de inmediato (sin
      // esperar al proximo poll de 30s).
      void qc.invalidateQueries({ queryKey: ['inventory-transfer-requests', 'unread-count'] });
    }
  }, [currentTenantId, qc, location.pathname]);

  // Cuando la URL es /inventory-transfer-requests/$id, este componente es
  // el parent layout y debe renderizar <Outlet /> en lugar de la bandeja.
  const childMatch = /^\/inventory-transfer-requests\/\d+$/.exec(location.pathname);
  if (childMatch) {
    return <Outlet />;
  }

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
