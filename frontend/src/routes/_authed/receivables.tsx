import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { ReceivablesManager } from '@/features/receivables/ReceivablesManager';

export const Route = createFileRoute('/_authed/receivables')({
  component: ReceivablesPage,
});

function ReceivablesPage() {
  return (
    <PageLayout
      title="Cuentas por cobrar"
      description="Audita saldos pendientes y registra cobros con caja abierta."
    >
      <ReceivablesManager />
    </PageLayout>
  );
}
