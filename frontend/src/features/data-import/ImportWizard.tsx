import { useEffect, useState } from 'react';
import { toast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';

import {
  downloadImportFile,
  ENTITY_LABELS,
  SUPPORTED_ENTITIES,
  supportsCurrentData,
  type SupportedEntity,
  templateUrl,
  useCreateDataImportSession,
  useDataImportSession,
  useRunImportEntity,
  useUploadImportFile,
  type RunSummary,
} from './api';
import { ImportPreviewTable } from './ImportPreviewTable';
import { ImportRunResult } from './ImportRunResult';

interface StepState {
  step: 'select' | 'upload' | 'preview' | 'running' | 'done';
  entity: SupportedEntity | null;
  sessionId: number | null;
  uploadedFileName: string | null;
  uploadedFile: File | null;
  result: RunSummary | null;
}

const initial: StepState = {
  step: 'select',
  entity: null,
  sessionId: null,
  uploadedFileName: null,
  uploadedFile: null,
  result: null,
};

export function ImportWizard() {
  const [state, setState] = useState<StepState>(initial);

  const createSession = useCreateDataImportSession();
  const upload = useUploadImportFile();
  const run = useRunImportEntity();
  const runningSessionQuery = useDataImportSession(state.sessionId ?? 0, state.step === 'running');

  useEffect(() => {
    if (state.step !== 'running' || !state.entity || !runningSessionQuery.data) return;

    const entity = runningSessionQuery.data.entities?.find((item) => item.entity === state.entity);
    if (!entity || (entity.status !== 'completed' && entity.status !== 'failed')) return;

    setState((current) => ({
      ...current,
      step: 'done',
      result: {
        total: entity.total_rows,
        ok: entity.succeeded_rows,
        skipped: entity.skipped_rows,
        failed: entity.failed_rows,
        status: entity.status,
        error_summary: entity.error_summary as RunSummary['error_summary'],
      },
    }));
  }, [runningSessionQuery.data, state.entity, state.step]);

  function pickEntity(entity: SupportedEntity) {
    setState({ ...initial, entity, step: 'upload' });
  }

  async function downloadTemplate() {
    if (!state.entity) return;
    try {
      const simpleProducts = state.entity === 'products';
      await downloadImportFile(
        templateUrl(state.entity, false, simpleProducts),
        simpleProducts ? 'plantilla_productos_sencilla.csv' : `plantilla_${state.entity}.csv`,
      );
    } catch {
      toast.error('No se pudo descargar la plantilla.');
    }
  }

  async function downloadCurrentData() {
    if (!state.entity || !supportsCurrentData(state.entity)) return;
    try {
      const simpleProducts = state.entity === 'products';
      await downloadImportFile(
        templateUrl(state.entity, true, simpleProducts),
        simpleProducts ? 'productos_para_actualizar.csv' : `datos_${state.entity}.csv`,
      );
      toast.success('Descargado. Edita el archivo y vuelve a subirlo.');
    } catch {
      toast.error('No se pudieron descargar los datos actuales.');
    }
  }

  async function handleFile(file: File) {
    try {
      const session =
        state.sessionId ?? (await createSession.mutateAsync({ meta: { entity: state.entity } })).id;
      if (!state.sessionId) {
        setState((s) => ({ ...s, sessionId: session }));
      }
      await upload.mutateAsync({ file, sessionId: session, entity: state.entity ?? 'branches' });
      setState((s) => ({
        ...s,
        sessionId: session,
        step: 'preview',
        uploadedFileName: file.name,
        uploadedFile: file,
      }));
      toast.success('Archivo subido correctamente.');
    } catch (err) {
      toast.error((err as Error).message);
    }
  }

  async function handleRun() {
    setState((s) => ({ ...s, step: 'running' }));
    try {
      const result = await run.mutateAsync({
        sessionId: state.sessionId ?? 0,
        entity: state.entity ?? 'branches',
      });
      setState((s) => ({ ...s, step: 'running', sessionId: result.session.id }));
      toast.success(
        'Importacion iniciada. Puedes dejar esta ventana abierta para ver el progreso.',
      );
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
          <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <div>
              <h2 className="text-base font-semibold text-blue-950">
                Agregar o actualizar productos
              </h2>
              <p className="mt-1 text-sm text-blue-900">
                Sube una hoja de Excel. El código o código de barras permite reconocer los productos
                existentes.
              </p>
            </div>
            <Button className="mt-3" onClick={() => pickEntity('products')}>
              Subir productos
            </Button>
          </div>
          <details className="rounded-lg border p-3">
            <summary className="cursor-pointer text-sm font-medium">
              Cargar otro tipo de dato
            </summary>
            <div className="mt-3">
              <label className="mb-1 block text-sm font-medium">Selecciona el tipo de dato</label>
              <Select value="" onChange={(e) => pickEntity(e.target.value as SupportedEntity)}>
                <option value="">Selecciona...</option>
                {SUPPORTED_ENTITIES.filter((entity) => entity !== 'products').map((entity) => (
                  <option key={entity} value={entity}>
                    {ENTITY_LABELS[entity]}
                  </option>
                ))}
              </Select>
            </div>
          </details>
        </div>
      )}

      {state.step !== 'select' && state.entity && (
        <div className="rounded-md border bg-white p-4">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <div>
              <strong>{ENTITY_LABELS[state.entity]}</strong>
            </div>
            <div className="flex gap-2">
              <Button onClick={downloadTemplate} variant="secondary" size="sm">
                {state.entity === 'products' ? 'Plantilla sencilla' : 'Descargar plantilla'}
              </Button>
              {supportsCurrentData(state.entity) && (
                <Button onClick={downloadCurrentData} variant="secondary" size="sm">
                  {state.entity === 'products'
                    ? 'Productos actuales para editar'
                    : 'Descargar datos actuales'}
                </Button>
              )}
              <Button onClick={resetWizard} variant="ghost" size="sm">
                Cambiar tipo
              </Button>
            </div>
          </div>

          {state.step === 'upload' && (
            <div className="space-y-3">
              {state.entity === 'products' ? (
                <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950">
                  <p className="font-semibold">
                    Agrega o actualiza productos desde una hoja sencilla.
                  </p>
                  <p className="mt-1">
                    Usa el código del producto para identificarlo. Si ya existe, se actualiza; si es
                    nuevo, se agrega. Deja vacíos los campos que no quieras cambiar. Esta hoja no
                    modifica existencias.
                  </p>
                  <p className="mt-1 text-xs">
                    Puedes subir el archivo Excel tal como lo guardaste (.xlsx o .xls).
                  </p>
                </div>
              ) : (
                <p className="text-sm text-gray-600">
                  Sube aquí el CSV completado con la plantilla descargada.
                </p>
              )}
              <FileDropzone
                onFile={handleFile}
                fileName={state.uploadedFileName}
                loading={upload.isPending || createSession.isPending}
                accept={
                  state.entity === 'products'
                    ? '.csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                    : '.csv,text/csv'
                }
              />
            </div>
          )}

          {state.step === 'preview' && (
            <ImportPreviewTable
              fileName={state.uploadedFileName}
              entity={state.entity}
              file={state.uploadedFile}
            />
          )}

          {(state.step === 'preview' || state.step === 'running') && (
            <div className="mt-4 flex gap-3">
              <Button
                onClick={handleRun}
                loading={run.isPending}
                disabled={state.step === 'running'}
              >
                {state.step === 'running'
                  ? 'Importando...'
                  : state.entity === 'products'
                    ? 'Aplicar cambios a productos'
                    : 'Importar datos'}
              </Button>
              <Button onClick={resetWizard} variant="ghost" disabled={state.step === 'running'}>
                Cancelar
              </Button>
            </div>
          )}

          {state.step === 'done' && state.result && (
            <ImportRunResult
              result={state.result}
              sessionId={state.sessionId ?? 0}
              onReset={resetWizard}
            />
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
  accept: string;
}

function FileDropzone({ onFile, fileName, loading, accept }: FileDropzoneProps) {
  const [dragOver, setDragOver] = useState(false);
  const supportsSpreadsheet = accept.includes('.xlsx');

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
        accept={accept}
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) onFile(file);
        }}
      />
      <p className="text-sm font-medium">
        {loading
          ? 'Subiendo...'
          : fileName
            ? `Archivo: ${fileName}`
            : `Selecciona o arrastra aquí tu archivo ${supportsSpreadsheet ? 'Excel o CSV' : 'CSV'}`}
      </p>
      <p className="mt-1 text-xs text-gray-500">
        {supportsSpreadsheet ? 'Admite Excel (.xlsx, .xls) y CSV.' : 'Admite archivos CSV.'}
      </p>
    </label>
  );
}
