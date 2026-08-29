import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { FiscalTaxRatesManager } from '@/features/fiscal-identity/FiscalTaxRatesManager';

export const Route = createFileRoute('/_authed/settings/fiscal')({
  component: FiscalSettingsPage,
});

function FiscalSettingsPage() {
  return (
    <PageLayout
      title="Configuración fiscal"
      description="Administra las alícuotas y tratamientos que se pueden asignar a tus productos."
    >
      <FiscalTaxRatesManager />
    </PageLayout>
  );
}
