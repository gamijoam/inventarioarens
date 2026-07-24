import { createFileRoute } from '@tanstack/react-router';
import { ClipboardList } from 'lucide-react';
import { PageLayout } from '@/components/layout/PageLayout';
import { ManualMovementsManager } from '@/features/manual-movements/ManualMovementsManager';

export const Route = createFileRoute('/_authed/inventory/manual-movements')({
  component: ManualMovementsPage,
});

function ManualMovementsPage() {
  return (
    <PageLayout
      title="Movimientos manuales"
      description="Solicitudes de entradas, salidas y ajustes con aprobación y trazabilidad."
      icon={<ClipboardList className="size-6" />}
    >
      <ManualMovementsManager />
    </PageLayout>
  );
}
