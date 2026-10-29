import { useEffect, useState } from 'react';

interface PreviewRow {
  headers: string[];
  rows: Record<string, string | null>[];
}

interface Props {
  fileName: string | null;
  entity: string;
  file: File | null;
}

function parseCsvPreview(text: string, maxRows = 10): PreviewRow {
  const lines = text.split(/\r?\n/).filter((l) => l.length > 0);
  if (lines.length === 0) {
    return { headers: [], rows: [] };
  }
  const firstLine = (lines[0] ?? '').replace(/^\uFEFF/, '');
  const headers = firstLine.split(',').map((h) => h.trim());
  const rows = lines.slice(1, maxRows + 1).map((line) => {
    const cells = parseCsvLine(line);
    const obj: Record<string, string | null> = {};
    headers.forEach((h, i) => {
      obj[h] = cells[i] ?? null;
    });
    return obj;
  });
  return { headers, rows };
}

function parseCsvLine(line: string): string[] {
  const out: string[] = [];
  let cur = '';
  let inQuote = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (c === '"') {
      if (inQuote && line[i + 1] === '"') {
        cur += '"';
        i++;
      } else {
        inQuote = !inQuote;
      }
    } else if (c === ',' && !inQuote) {
      out.push(cur.trim());
      cur = '';
    } else {
      cur += c;
    }
  }
  out.push(cur.trim());
  return out;
}

function readFileAsText(file: File): Promise<string> {
  if (typeof file.text === 'function') return file.text();

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(reader.error ?? new Error('No se pudo leer el archivo.'));
    reader.readAsText(file);
  });
}

export function ImportPreviewTable({ fileName, entity, file }: Props) {
  const [preview, setPreview] = useState<PreviewRow | null>(null);
  const [reading, setReading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    if (!file) {
      setPreview(null);
      return;
    }

    if (/\.xlsx?$/i.test(file.name)) {
      setPreview(null);
      setReading(false);
      return;
    }

    setReading(true);
    void readFileAsText(file)
      .then((text) => {
        if (!cancelled) setPreview(parseCsvPreview(text));
      })
      .catch(() => {
        if (!cancelled) setPreview({ headers: [], rows: [] });
      })
      .finally(() => {
        if (!cancelled) setReading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [file]);

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-gray-700">
          <strong>{fileName ?? 'archivo'}</strong> listo.{' '}
          {file && /\.xlsx?$/i.test(file.name)
            ? 'La hoja se comprobará al iniciar la carga.'
            : 'Revisa las primeras filas antes de importar.'}
        </p>
      </div>

      {reading && <p className="text-xs text-gray-500">Leyendo las primeras filas…</p>}

      {file && entity === 'products' && /\.xlsx?$/i.test(file.name) && (
        <p className="rounded-md bg-blue-50 p-2 text-xs text-blue-800">
          Archivo Excel listo. El sistema revisará los encabezados y los códigos al aplicar los
          cambios.
        </p>
      )}

      {preview && (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-xs">
            <thead className="bg-gray-50">
              <tr>
                {preview.headers.map((h) => (
                  <th key={h} className="px-2 py-1 text-left font-medium text-gray-700">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {preview.rows.map((row, i) => (
                <tr key={i} className="border-t">
                  {preview.headers.map((h) => (
                    <td key={h} className="px-2 py-1">
                      {row[h] ?? ''}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!preview && (
        <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-700">
          {entity === 'products'
            ? 'El código identifica el producto: los códigos existentes se actualizarán y los nuevos se agregarán. Las filas con error aparecerán en el reporte.'
            : 'Las filas se procesarán una por una y los errores aparecerán en el reporte descargable.'}
        </p>
      )}

      {entity !== 'products' && <p className="text-xs text-gray-500">Tipo de dato: {entity}</p>}
    </div>
  );
}
