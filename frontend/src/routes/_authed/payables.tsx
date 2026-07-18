import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { PayablesManager } from '@/features/payables/PayablesManager';

export const Route = createFileRoute('/_authed/payables')({
  component: PayablesPage,
});

function PayablesPage() {
  return (
    <PageLayout
      title="Cuentas por pagar"
      description="Audita saldos pendientes y registra pagos a proveedores."
    >
      <PayablesManager />
    </PageLayout>
  );
}
