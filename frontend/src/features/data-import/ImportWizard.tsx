import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';

import {
  downloadImportFile,
  ENTITY_LABELS,
  SUPPORTED_ENTITIES,
  type SupportedEntity,
  templateUrl,
  useCreateDataImportSession,
  useRunImportEntity,
  useUploadImportFile,
} from './api';
import { ImportPreviewTable } from './ImportPreviewTable';
import { ImportRunResult } from './ImportRunResult';

interface StepState {
  step: 'select' | 'upload' | 'preview' | 'running' | 'done';
  entity: SupportedEntity | null;
  sessionId: number | null;
  previewRows: Record<string, string | null>[];
  uploadedFileName: string | null;
}

const initial: StepState = {
  step: 'select',
  entity: null,
  sessionId: null,
  previewRows: [],
  uploadedFileName: null,
};

export function ImportWizard() {
  const [state, setState] = useState<StepState>(initial);

  const createSession = useCreateDataImportSession();
  const upload = useUploadImportFile(state.sessionId ?? 0, state.entity ?? 'branches');
  const run = useRunImportEntity(state.sessionId ?? 0, state.entity ?? 'branches');

  function pickEntity(entity: SupportedEntity) {
    setState({ ...initial, entity });
  }

  async function downloadTemplate() {
    if (!state.entity) return;
    try {
      await downloadImportFile(templateUrl(state.entity), `plantilla_${state.entity}.csv`);
    } catch {
      toast.error('No se pudo descargar la plantilla.');
    }
  }

  async function handleFile(file: File) {
    try {
      const session = state.sessionId ?? (await createSession.mutateAsync({ meta: { entity: state.entity } })).id;
      if (!state.sessionId) {
        setState((s) => ({ ...s, sessionId: session }));
      }
      await upload.mutateAsync({ file });
      setState((s) => ({
        ...s,
        sessionId: session,
        step: 'preview',
        uploadedFileName: file.name,
      }));
      toast.success('Archivo subido correctamente.');
    } catch (err) {
      toast.error((err as Error).message);
    }
  }

  async function handleRun() {
    setState((s) => ({ ...s, step: 'running' }));
    try {
      const result = await run.mutateAsync();
      setState((s) => ({ ...s, step: 'done', previewRows: [], uploadedFileName: null }));
      const { ok, skipped, failed } = result.summary;
      if (failed === 0) {
        toast.success(`Import listo: ${ok} creadas, ${skipped} omitidas.`);
      } else {
        toast.warning(`Import con errores: ${ok} OK, ${skipped} skip, ${failed} fail.`);
      }
    } catch (err) {
      toast.error((err as Error).message);
      setState((s) => ({ ...s, step: 'preview' }));
    }
  }

  function resetWizard() {
    setState(initial);
  }

  return (
    <div className="space-y-6">
      {state.step === 'select' && (
        <div className="space-y-4">
          <div>
            <label className="mb-1 block text-sm font-medium">Tipo de dato a importar</label>
            <Select
              value={state.entity ?? ''}
              onChange={(e) => pickEntity(e.target.value as SupportedEntity)}
            >
              <option value="">Selecciona...</option>
              {SUPPORTED_ENTITIES.map((e) => (
                <option key={e} value={e}>
                  {ENTITY_LABELS[e]}
                </option>
              ))}
            </Select>
          </div>
          {state.entity && (
            <div className="flex gap-3">
              <Button onClick={downloadTemplate} variant="secondary">
                Descargar plantilla
              </Button>
              <Button onClick={resetWizard} variant="ghost">
                Cambiar tipo
              </Button>
            </div>
          )}
        </div>
      )}

      {state.step !== 'select' && state.entity && (
        <div className="rounded-md border bg-white p-4">
          <div className="mb-3 text-sm text-gray-600">
            Tipo: <strong>{ENTITY_LABELS[state.entity]}</strong>
          </div>

          {state.step === 'upload' && (
            <FileDropzone
              onFile={handleFile}
              fileName={state.uploadedFileName}
              loading={upload.isPending || createSession.isPending}
            />
          )}

          {state.step === 'preview' && (
            <ImportPreviewTable fileName={state.uploadedFileName} entity={state.entity} />
          )}

          {(state.step === 'preview' || state.step === 'running') && (
            <div className="mt-4 flex gap-3">
              <Button onClick={handleRun} loading={run.isPending} disabled={state.step === 'running'}>
                {state.step === 'running' ? 'Importando...' : 'Ejecutar importacion'}
              </Button>
              <Button onClick={resetWizard} variant="ghost" disabled={state.step === 'running'}>
                Cancelar
              </Button>
            </div>
          )}

          {state.step === 'done' && run.data && (
            <ImportRunResult result={run.data.summary} sessionId={state.sessionId ?? 0} onReset={resetWizard} />
          )}
        </div>
      )}
    </div>
  );
}

interface FileDropzoneProps {
  onFile: (f: File) => void;
  fileName: string | null;
  loading: boolean;
}

function FileDropzone({ onFile, fileName, loading }: FileDropzoneProps) {
  const [dragOver, setDragOver] = useState(false);

  return (
    <label
      className={`flex flex-col items-center justify-center rounded-md border-2 border-dashed p-8 text-center transition-colors ${
        dragOver ? 'border-blue-500 bg-blue-50' : 'border-gray-300'
      } ${loading ? 'opacity-50' : 'cursor-pointer hover:bg-gray-50'}`}
      onDragOver={(e) => {
        e.preventDefault();
        setDragOver(true);
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files[0];
        if (file) onFile(file);
      }}
    >
      <input
        type="file"
        accept=".csv,text/csv"
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) onFile(file);
        }}
      />
      <p className="text-sm font-medium">
        {loading ? 'Subiendo...' : fileName ? `Archivo: ${fileName}` : 'Arrastra tu CSV aqui o haz click para seleccionar'}
      </p>
      <p className="mt-1 text-xs text-gray-500">Tamano maximo: 5 MB. Hasta 5.000 filas.</p>
    </label>
  );
}
