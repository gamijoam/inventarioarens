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

export const Route = createFileRoute('/_authed/purchases')({
  component: PurchasesPage,
});

function PurchasesPage() {
  const [creating, setCreating] = useState(false);

  return (
    <PageLayout
      title="Compras"
      description="Gestion de ordenes de compra. El flujo es: crear borrador -> recibir mercancia -> pagar CxP."
    >
      <PurchasesManager onNew={() => setCreating(true)} />
      <PurchaseFormDialog
        open={creating}
        onOpenChange={setCreating}
        onCreated={(id) => {
          // FASE 3 abrira automaticamente el dialog de "Recibir".
          // Por ahora solo cerramos el form y la lista se refresca.
          void id;
        }}
      />
    </PageLayout>
  );
}
