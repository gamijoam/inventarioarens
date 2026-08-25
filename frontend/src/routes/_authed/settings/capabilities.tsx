import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { TenantCapabilitiesPanel } from '@/features/tenant-capabilities/TenantCapabilitiesPanel';

export const Route = createFileRoute('/_authed/settings/capabilities')({
  component: TenantCapabilitiesPage,
});

function TenantCapabilitiesPage() {
  return (
    <PageLayout
      title="Capacidades y modulos"
      description="Configura los modulos contratados y disponibles para la empresa activa."
    >
      <TenantCapabilitiesPanel />
    </PageLayout>
  );
}
