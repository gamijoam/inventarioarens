import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { WarrantiesManager } from '@/features/warranties/WarrantiesManager';

export const Route = createFileRoute('/_authed/warranties')({
  component: WarrantiesPage,
});

function WarrantiesPage() {
  return (
    <PageLayout
      title="Garantías"
      description="Gestiona casos recibidos, revisión, resolución y entrega al cliente."
    >
      <WarrantiesManager />
    </PageLayout>
  );
}
