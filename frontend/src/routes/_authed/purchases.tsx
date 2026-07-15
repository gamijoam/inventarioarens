/**
 * Pagina /purchases: gestion de compras a proveedores.
 * FASE 1: listado + filtros + cancelar. FASE 2: dialog de crear.
 * FASE 3: dialog de recibir mercancia.
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { PurchasesManager } from '@/features/purchases/PurchasesManager';
import { PurchaseFormDialog } from '@/features/purchases/components/PurchaseFormDialog';
import { ReceiveDialog } from '@/features/purchases/components/ReceiveDialog';

export const Route = createFileRoute('/_authed/purchases')({
  component: PurchasesPage,
});

function PurchasesPage() {
  const [creating, setCreating] = useState(false);
  const [receivingId, setReceivingId] = useState<number | null>(null);

  return (
    <PageLayout
      title="Compras"
      description="Gestion de ordenes de compra. El flujo es: crear borrador -> recibir mercancia -> pagar CxP."
    >
      <PurchasesManager
        onNew={() => setCreating(true)}
        onReceive={(id) => setReceivingId(id)}
      />
      <PurchaseFormDialog
        open={creating}
        onOpenChange={setCreating}
        onCreated={(id) => {
          // Auto-abrir el dialog de Recibir para ofrecer el siguiente paso.
          setReceivingId(id);
        }}
      />
      <ReceiveDialog
        open={receivingId !== null}
        onOpenChange={(open) => {
          if (!open) setReceivingId(null);
        }}
        purchaseId={receivingId}
      />
    </PageLayout>
  );
}
