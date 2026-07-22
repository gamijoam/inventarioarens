import { useState } from 'react';

interface PreviewRow {
  headers: string[];
  rows: Record<string, string | null>[];
}

interface Props {
  fileName: string | null;
  entity: string;
}

function parseCsvPreview(text: string, maxRows = 10): PreviewRow {
  const lines = text.split(/\r?\n/).filter((l) => l.length > 0);
  if (lines.length === 0) {
    return { headers: [], rows: [] };
  }
  const firstLine = lines[0] ?? '';
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

export function ImportPreviewTable({ fileName, entity }: Props) {
  const [preview, setPreview] = useState<PreviewRow | null>(null);

  async function readFile(file: File) {
    const text = await file.text();
    setPreview(parseCsvPreview(text));
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-700">
          <strong>{fileName ?? 'archivo'}</strong> listo. Revisa las primeras filas antes de importar.
        </p>
        <input
          type="file"
          accept=".csv"
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void readFile(f);
          }}
          className="text-xs"
        />
      </div>

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
          Tip: cuando ejecutes la importacion, las filas se procesaran una por una.
          Los duplicados se omitiran y los errores se reportaran en el CSV final.
        </p>
      )}

      <p className="text-xs text-gray-500">Entidad destino: <code>{entity}</code></p>
    </div>
  );
}
