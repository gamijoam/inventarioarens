import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { SimpleModeSettingsPanel } from '@/features/company-settings/SimpleModeSettingsPanel';

export const Route = createFileRoute('/_authed/settings/simple-mode')({
  component: SimpleModeSettingsPage,
});

function SimpleModeSettingsPage() {
  return (
    <PageLayout title="Configuración de Modo Fácil">
      <SimpleModeSettingsPanel />
    </PageLayout>
  );
}
