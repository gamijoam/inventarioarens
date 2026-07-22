import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { DataImportPage } from '@/features/data-import/DataImportPage';

export const Route = createFileRoute('/_authed/import/')({
  component: ImportPage,
});

function ImportPage() {
  return (
    <PageLayout
      title="Importar datos"
      description="Carga catalogos desde un CSV. Plantillas dinamicas con valores reales del tenant, validacion por fila y reporte descargable."
    >
      <DataImportPage />
    </PageLayout>
  );
}
