import { createFileRoute } from '@tanstack/react-router';
import { Barcode } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { LabelsPanel } from '@/features/labels/LabelsPanel';

interface LabelsSearch {
  bulk_adjustment_id?: number;
}

export const Route = createFileRoute('/_authed/inventory/labels')({
  validateSearch: (search: Record<string, unknown>): LabelsSearch => {
    const id = Number(search.bulk_adjustment_id);
    return Number.isInteger(id) && id > 0 ? { bulk_adjustment_id: id } : {};
  },
  component: LabelsPage,
});

function LabelsPage() {
  const { bulk_adjustment_id: bulkAdjustmentId } = Route.useSearch();

  return (
    <PageLayout
      title="Etiquetas de precio"
      description="Etiquetas de anaquel con precio en USD y Bs a la tasa vigente y codigo de barras, listas para imprimir."
      icon={<Barcode className="size-6" />}
    >
      <LabelsPanel key={bulkAdjustmentId ?? 'manual'} initialBulkAdjustmentId={bulkAdjustmentId} />
    </PageLayout>
  );
}
