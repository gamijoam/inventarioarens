/**
 * Ruta /settings/company - Informacion legal/fiscal de la empresa
 * (razon social, RIF, domicilio fiscal, contacto) y en que documentos
 * se refleja (ticket de venta, guias, reporte Z).
 */
import { createFileRoute } from '@tanstack/react-router';
import { Download, Laptop } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { useSessionStore } from '@/stores/session';

import { PageLayout } from '@/components/layout/PageLayout';
import { CompanySettingsPanel } from '@/features/company-settings/CompanySettingsPanel';

export const Route = createFileRoute('/_authed/settings/company')({
  component: CompanySettingsPage,
});

function CompanySettingsPage() {
  const tenant = useSessionStore((state) => state.tenant);
  const slug = tenant?.slug ?? '';

  return (
    <PageLayout
      title="Información de la empresa"
      description="Configuración general, datos fiscales y descargas de instalación para Windows."
      actions={
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => window.open(slug ? `/api/offline/database?slug=${slug}` : '/api/offline/database', '_blank')}
            data-testid="header-download-sqlite"
          >
            <Download className="size-4" />
            Descargar SQLite ({tenant?.name ?? 'Empresa'})
          </Button>
          <Button
            size="sm"
            className="gap-2 bg-primary text-white hover:bg-primary/90 shadow-sm"
            onClick={() => window.open('/downloads/BalanzaPro-Setup.exe', '_blank')}
            data-testid="header-download-setup"
          >
            <Laptop className="size-4" />
            Descargar BalanzaPro-Setup.exe
          </Button>
        </div>
      }
    >
      <CompanySettingsPanel />
    </PageLayout>
  );
}

