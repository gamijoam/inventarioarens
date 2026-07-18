import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { ReportsManager } from '@/features/reports/ReportsManager';

export const Route = createFileRoute('/_authed/reports')({
  component: ReportsPage,
});

function ReportsPage() {
  return (
    <PageLayout
      title="Reportes"
      description="Centro ejecutivo para inventario, movimientos, finanzas, caja y POS."
    >
      <ReportsManager />
    </PageLayout>
  );
}
