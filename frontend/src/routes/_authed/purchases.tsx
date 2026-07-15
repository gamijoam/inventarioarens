/**
 * Pagina /purchases: gestion de compras a proveedores.
 * FASE 1 entrega el listado + filtros + accion cancelar. FASE 2-3
 * agregaran el dialog de crear (PurchaseFormDialog) y el de recibir
 * (ReceiveDialog) conectados via onNew prop.
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { PurchasesManager } from '@/features/purchases/PurchasesManager';

export const Route = createFileRoute('/_authed/purchases')({
  component: PurchasesPage,
});

function PurchasesPage() {
  // FASE 2: este estado abre PurchaseFormDialog. Por ahora es un
  // placeholder que se conectara cuando se implemente la fase 2.
  const [creating] = useState(false);

  return (
    <PageLayout
      title="Compras"
      description="Gestion de ordenes de compra. El flujo es: crear borrador -> recibir mercancia -> pagar CxP."
    >
      <PurchasesManager onNew={creating ? () => undefined : undefined} />
    </PageLayout>
  );
}
