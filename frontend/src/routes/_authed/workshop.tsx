import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { WorkshopManager } from '@/features/workshop/WorkshopManager';

export const Route = createFileRoute('/_authed/workshop')({
  component: WorkshopPage,
});

function WorkshopPage() {
  return (
    <PageLayout
      title="Taller"
      description="Órdenes de servicio: garantías y reparaciones con piezas del inventario y comisiones de técnico."
    >
      <WorkshopManager />
    </PageLayout>
  );
}