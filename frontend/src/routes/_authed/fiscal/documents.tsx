import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import {
  FiscalDocumentsManager,
  type FiscalDocumentsSearch,
} from '@/features/fiscal-documents/FiscalDocumentsManager';

export const Route = createFileRoute('/_authed/fiscal/documents')({
  validateSearch: (search: Record<string, unknown>): FiscalDocumentsSearch => ({
    sale_id: toNumber(search.sale_id),
    status: search.status === 'preview' ? 'preview' : undefined,
    date_from: typeof search.date_from === 'string' ? search.date_from : undefined,
    date_to: typeof search.date_to === 'string' ? search.date_to : undefined,
    page: toNumber(search.page),
    per_page: toNumber(search.per_page),
  }),
  component: FiscalDocumentsPage,
});

function FiscalDocumentsPage() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();

  return (
    <PageLayout
      title="Documentos internos"
      description="Consulta snapshots pre-fiscales de ventas confirmadas. No son documentos fiscales emitidos."
    >
      <FiscalDocumentsManager
        search={search}
        onSearchChange={(next) => {
          void navigate({ search: cleanSearch(next) });
        }}
      />
    </PageLayout>
  );
}

function toNumber(value: unknown): number | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function cleanSearch(search: FiscalDocumentsSearch): FiscalDocumentsSearch {
  const next: FiscalDocumentsSearch = {};
  for (const [key, value] of Object.entries(search)) {
    if (value !== undefined && value !== '') {
      (next as Record<string, unknown>)[key] = value;
    }
  }
  return next;
}
