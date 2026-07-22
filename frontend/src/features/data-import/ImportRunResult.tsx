import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { downloadImportFile, reportUrl, type RunSummary } from './api';

interface Props {
  result: RunSummary;
  sessionId: number;
  onReset: () => void;
}

export function ImportRunResult({ result, sessionId, onReset }: Props) {
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadImportFile(reportUrl(sessionId), `import-report-${sessionId}.csv`);
    } catch {
      toast.error('No se pudo descargar el reporte.');
    } finally {
      setDownloading(false);
    }
  }
  const totalOk = result.ok;
  const totalSkip = result.skipped;
  const totalFail = result.failed;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-3">
        <Card label="Total" value={result.total} color="bg-gray-100 text-gray-800" />
        <Card label="Creados" value={totalOk} color="bg-green-100 text-green-800" />
        <Card label="Omitidos" value={totalSkip} color="bg-yellow-100 text-yellow-800" />
        <Card label="Fallidos" value={totalFail} color="bg-red-100 text-red-800" />
      </div>

      {totalFail > 0 && result.error_summary && (
        <div className="rounded-md border border-red-200 bg-red-50 p-3">
          <p className="mb-2 text-sm font-medium text-red-800">
            {totalFail} fila(s) con errores. Descargá el reporte CSV para ver el detalle.
          </p>
          <ul className="space-y-1 text-xs text-red-700">
            {result.error_summary.slice(0, 5).map((err, i) => (
              <li key={i}>
                Fila {err.row} ({err.natural_key ?? 'sin clave'}):{' '}
                  {Object.entries(err.errors)
                  .map(([field, msg]) => `${field}: ${Array.isArray(msg) ? msg.join(', ') : String(msg)}`)
                  .join(' | ')}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex gap-3">
        <Button onClick={handleDownload} loading={downloading}>
          Descargar reporte CSV
        </Button>
        <Button onClick={onReset} variant="ghost">
          Importar otro archivo
        </Button>
      </div>
    </div>
  );
}

function Card({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className={`rounded-md ${color} p-3 text-center`}>
      <p className="text-2xl font-semibold">{value}</p>
      <p className="text-xs">{label}</p>
    </div>
  );
}
