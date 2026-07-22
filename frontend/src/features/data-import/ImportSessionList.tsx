import { Link } from '@tanstack/react-router';

import { Button } from '@/components/ui/Button';
import {
  useDataImportSessions,
  useDeleteDataImportSession,
} from './api';

export function ImportSessionList() {
  const { data: sessions, isLoading } = useDataImportSessions();
  const del = useDeleteDataImportSession();

  if (isLoading) {
    return <p className="text-sm text-gray-500">Cargando sesiones...</p>;
  }

  if (!sessions || sessions.length === 0) {
    return (
      <p className="rounded-md bg-gray-50 p-4 text-sm text-gray-600">
        Aun no has realizado ninguna importacion.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs uppercase text-gray-500">
            <th className="px-3 py-2">ID</th>
            <th className="px-3 py-2">Estado</th>
            <th className="px-3 py-2">Filas</th>
            <th className="px-3 py-2">Creadas</th>
            <th className="px-3 py-2">Omitidas</th>
            <th className="px-3 py-2">Fallidas</th>
            <th className="px-3 py-2">Fecha</th>
            <th className="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody>
          {sessions.map((s) => {
            return (
              <tr key={s.id} className="border-b hover:bg-gray-50">
                <td className="px-3 py-2 font-mono text-xs">#{s.id}</td>
                <td className="px-3 py-2">
                  <StatusBadge status={s.status} />
                </td>
                <td className="px-3 py-2">{s.total_rows}</td>
                <td className="px-3 py-2 text-green-700">{s.succeeded_rows}</td>
                <td className="px-3 py-2 text-yellow-700">{s.skipped_rows}</td>
                <td className="px-3 py-2 text-red-700">{s.failed_rows}</td>
                <td className="px-3 py-2 text-xs text-gray-500">{s.created_at ?? '-'}</td>
                <td className="px-3 py-2">
                  <div className="flex gap-2">
                    <Link
                      to={`/import/sessions/${s.id}` as '/'}
                      className="text-xs text-blue-600 hover:underline"
                    >
                      Ver detalle
                    </Link>
                    {s.status !== 'running' && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => del.mutate(s.id)}
                        loading={del.isPending}
                      >
                        Eliminar
                      </Button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const color: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    running: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    cancelled: 'bg-gray-100 text-gray-700',
  };
  const cls = color[status] ?? 'bg-gray-100 text-gray-700';
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${cls}`}>{status}</span>
  );
}
