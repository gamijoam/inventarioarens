import { createFileRoute, Link } from '@tanstack/react-router';
import { useState } from 'react';
import { toast } from 'sonner';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import {
  downloadImportFile,
  ENTITY_LABELS,
  reportUrl,
  useDataImportSession,
  useImportEntityRows,
  type SupportedEntity,
} from '@/features/data-import/api';

export const Route = createFileRoute('/_authed/import/sessions/$sessionId')({
  component: ImportSessionDetailPage,
});

function ImportSessionDetailPage() {
  const { sessionId } = Route.useParams();
  const id = Number(sessionId);
  const { data: session, isLoading } = useDataImportSession(id);
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    if (!session) return;
    setDownloading(true);
    try {
      await downloadImportFile(reportUrl(session.id), `import-report-${session.id}.csv`);
    } catch {
      toast.error('No se pudo descargar el reporte.');
    } finally {
      setDownloading(false);
    }
  }

  if (isLoading) {
    return (
      <PageLayout title="Sesion de importacion">
        <p className="text-sm text-gray-500">Cargando...</p>
      </PageLayout>
    );
  }

  if (!session) {
    return (
      <PageLayout title="Sesion de importacion">
        <p className="text-sm text-gray-500">Sesion no encontrada.</p>
      </PageLayout>
    );
  }

  return (
    <PageLayout
      title={`Sesion #${session.id}`}
      description="Detalle de la importacion por entidad."
      actions={
        <Button onClick={handleDownload} loading={downloading}>
          Descargar reporte CSV
        </Button>
      }
    >
      <div className="space-y-4">
        <Card>
          <div className="grid grid-cols-4 gap-4 text-center">
            <Stat label="Total" value={session.total_rows} />
            <Stat label="Creadas" value={session.succeeded_rows} color="text-green-700" />
            <Stat label="Omitidas" value={session.skipped_rows} color="text-yellow-700" />
            <Stat label="Fallidas" value={session.failed_rows} color="text-red-700" />
          </div>
        </Card>

        {(session.entities ?? []).map((ent) => (
          <EntityPanel
            key={ent.id}
            sessionId={session.id}
            entity={ent.entity as SupportedEntity}
          />
        ))}

        <Link to="/import" className="text-sm text-blue-600 hover:underline">
          ← Volver al wizard
        </Link>
      </div>
    </PageLayout>
  );
}

function Stat({ label, value, color }: { label: string; value: number; color?: string }) {
  return (
    <div>
      <p className={`text-2xl font-semibold ${color ?? ''}`}>{value}</p>
      <p className="text-xs text-gray-500">{label}</p>
    </div>
  );
}

function EntityPanel({ sessionId, entity }: { sessionId: number; entity: SupportedEntity }) {
  const { data: rows } = useImportEntityRows(sessionId, entity);

  return (
    <Card>
      <h3 className="mb-2 text-sm font-semibold text-gray-700">
        {ENTITY_LABELS[entity] ?? entity} ({rows?.length ?? 0} filas en preview)
      </h3>
      {rows && rows.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b text-left text-gray-500">
                <th className="px-2 py-1">Fila</th>
                <th className="px-2 py-1">Estado</th>
                <th className="px-2 py-1">Clave</th>
                <th className="px-2 py-1">ID</th>
              </tr>
            </thead>
            <tbody>
              {rows.slice(0, 50).map((r: unknown, i: number) => {
                const row = r as {
                  row_number: number;
                  status: string;
                  natural_key: string | null;
                  resulting_id: number | null;
                };
                return (
                  <tr key={i} className="border-b">
                    <td className="px-2 py-1">{row.row_number}</td>
                    <td className="px-2 py-1">
                      <span
                        className={`rounded px-2 py-0.5 ${
                          row.status === 'ok'
                            ? 'bg-green-100 text-green-800'
                            : row.status === 'skipped'
                              ? 'bg-yellow-100 text-yellow-800'
                              : 'bg-red-100 text-red-800'
                        }`}
                      >
                        {row.status}
                      </span>
                    </td>
                    <td className="px-2 py-1 font-mono">{row.natural_key ?? '-'}</td>
                    <td className="px-2 py-1">{row.resulting_id ?? '-'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {rows.length > 50 && (
            <p className="mt-2 text-xs text-gray-500">
              Mostrando primeras 50 filas. Descarga el reporte CSV para ver el detalle completo.
            </p>
          )}
        </div>
      ) : (
        <p className="text-xs text-gray-500">Aun no se procesaron filas para esta entidad.</p>
      )}
    </Card>
  );
}
