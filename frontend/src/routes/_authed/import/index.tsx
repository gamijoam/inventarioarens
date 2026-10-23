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
      description="Agrega o actualiza productos desde una hoja sencilla. También puedes cargar otros catálogos."
    >
      <DataImportPage />
    </PageLayout>
  );
}
