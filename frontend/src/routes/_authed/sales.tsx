import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { SalesManager } from '@/features/sales/SalesManager';

export const Route = createFileRoute('/_authed/sales')({
  component: SalesPage,
});

function SalesPage() {
  return (
    <PageLayout
      title="Ventas"
      description="Historial administrativo de ventas, POS, borradores y cancelaciones."
    >
      <SalesManager />
    </PageLayout>
  );
}
