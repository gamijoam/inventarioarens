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
import { EditPurchaseInvoiceDialog } from '@/features/purchases/components/EditPurchaseInvoiceDialog';
import { ReceiveDialog } from '@/features/purchases/components/ReceiveDialog';
import type { Purchase } from '@/features/purchases/schemas';

export const Route = createFileRoute('/_authed/purchases')({
  component: PurchasesPage,
});

function PurchasesPage() {
  const [creating, setCreating] = useState(false);
  const [receivingId, setReceivingId] = useState<number | null>(null);
  const [editingDraft, setEditingDraft] = useState<Purchase | null>(null);
  const [editingInvoice, setEditingInvoice] = useState<Purchase | null>(null);

  function handleEdit(purchase: Purchase) {
    if (purchase.status === 'draft') {
      setEditingDraft(purchase);
    } else if (purchase.status === 'received' || purchase.status === 'partially_received') {
      setEditingInvoice(purchase);
    }
  }

  return (
    <PageLayout
      title="Compras"
      description="Gestion de ordenes de compra. El flujo es: crear borrador -> recibir mercancia -> pagar CxP."
    >
      <PurchasesManager
        onNew={() => {
          setEditingDraft(null);
          setCreating(true);
        }}
        onReceive={(id) => setReceivingId(id)}
        onEdit={handleEdit}
      />
      <PurchaseFormDialog
        open={creating || Boolean(editingDraft)}
        onOpenChange={(open) => {
          if (!open) {
            setCreating(false);
            setEditingDraft(null);
          }
        }}
        purchase={editingDraft}
        onCreated={(id) => {
          if (!editingDraft) {
            // Auto-abrir el dialog de Recibir para ofrecer el siguiente paso solo en nuevas compras.
            setReceivingId(id);
          }
        }}
      />
      <EditPurchaseInvoiceDialog
        open={Boolean(editingInvoice)}
        onOpenChange={(open) => {
          if (!open) setEditingInvoice(null);
        }}
        purchase={editingInvoice}
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
