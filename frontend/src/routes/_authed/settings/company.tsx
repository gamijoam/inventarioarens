/**
 * Ruta /settings/company - Informacion legal/fiscal de la empresa
 * (razon social, RIF, domicilio fiscal, contacto) y en que documentos
 * se refleja (ticket de venta, guias, reporte Z).
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { CompanySettingsPanel } from '@/features/company-settings/CompanySettingsPanel';

export const Route = createFileRoute('/_authed/settings/company')({
  component: CompanySettingsPage,
});

function CompanySettingsPage() {
  return (
    <PageLayout title="Información de la empresa">
      <CompanySettingsPanel />
    </PageLayout>
  );
}
