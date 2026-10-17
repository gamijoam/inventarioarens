/**
 * Ruta /settings/company - Informacion legal/fiscal de la empresa
 * (razon social, RIF, domicilio fiscal, contacto) y descargas locales.
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { CompanySettingsPanel } from '@/features/company-settings/CompanySettingsPanel';

export const Route = createFileRoute('/_authed/settings/company')({
  component: CompanySettingsPage,
});

function CompanySettingsPage() {
  return (
    <PageLayout
      title="Información de la empresa"
      description="Configuración general, datos fiscales y descargas para la instalación local."
    >
      <CompanySettingsPanel />
    </PageLayout>
  );
}
