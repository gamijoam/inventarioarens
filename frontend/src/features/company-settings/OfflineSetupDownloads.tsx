import { useEffect, useState } from 'react';
import { Download, HardDrive, Laptop } from 'lucide-react';

import { api } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { usePermissionContext } from '@/permissions/PermissionContext';
import { useSessionStore } from '@/stores/session';

type InstallerInfo = {
  available: boolean;
  name: string | null;
  version: string | null;
  size_bytes: number | null;
  built_at: string | null;
  url: string | null;
};

type SnapshotInfo = {
  id: string;
  status: 'queued' | 'building' | 'ready' | 'failed';
  message: string;
  file_name?: string | null;
  size_bytes?: number | null;
  sha256?: string | null;
};

function formatSize(bytes?: number | null): string {
  if (!bytes || bytes < 0) return '';
  if (bytes < 1_000_000) return `${Math.ceil(bytes / 1024)} KB`;
  return `${(bytes / 1_000_000).toFixed(1)} MB`;
}

export function OfflineSetupDownloads() {
  const { permissions } = usePermissionContext();
  const tenant = useSessionStore((state) => state.tenant);
  const canExport = permissions.has('settings.manage');
  const [installer, setInstaller] = useState<InstallerInfo | null>(null);
  const [installerLoading, setInstallerLoading] = useState(true);
  const [snapshot, setSnapshot] = useState<SnapshotInfo | null>(null);
  const [starting, setStarting] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!canExport) {
      setInstallerLoading(false);
      return;
    }

    let active = true;
    api.get<InstallerInfo>('/offline/installer-info')
      .then((response) => {
        if (active) setInstaller(response.data);
      })
      .catch(() => {
        if (active) setInstaller({ available: false, name: null, version: null, size_bytes: null, built_at: null, url: null });
      })
      .finally(() => {
        if (active) setInstallerLoading(false);
      });

    return () => {
      active = false;
    };
  }, [canExport, tenant?.slug]);

  useEffect(() => {
    if (!snapshot?.id || (snapshot.status !== 'queued' && snapshot.status !== 'building')) return;

    let active = true;
    const timer = window.setTimeout(() => {
      api.get<SnapshotInfo>(`/offline/snapshots/${snapshot.id}`)
        .then((response) => {
          if (active) setSnapshot(response.data);
        })
        .catch(() => {
          if (active) setSnapshot((current) => current ? {
            ...current,
            status: 'failed',
            message: 'No se pudo consultar el estado. Puedes generar otro paquete.',
          } : current);
        });
    }, 2000);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [snapshot]);

  if (!canExport) return null;

  async function createSnapshot(): Promise<void> {
    setStarting(true);
    setSnapshot(null);
    try {
      const response = await api.post<SnapshotInfo>('/offline/snapshots', {});
      setSnapshot(response.data);
    } catch {
      setSnapshot({
        id: '',
        status: 'failed',
        message: 'No se pudo iniciar la preparación del paquete.',
      });
    } finally {
      setStarting(false);
    }
  }

  async function downloadSnapshot(): Promise<void> {
    if (!snapshot?.id || snapshot.status !== 'ready') return;
    setDownloading(true);
    try {
      const response = await api.get<Blob>(`/offline/snapshots/${snapshot.id}/download`, {
        responseType: 'blob',
      });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = snapshot.file_name ?? `BalanzaPro-datos-${tenant?.slug ?? 'empresa'}.zip`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1500);
    } catch {
      // El interceptor central muestra el error HTTP o de red en español.
    } finally {
      setDownloading(false);
    }
  }

  const isPreparing = snapshot?.status === 'queued' || snapshot?.status === 'building';

  return (
    <Card data-testid="company-offline-downloads">
      <CardHeader>
        <div className="flex items-center gap-2">
          <Laptop className="size-5 text-primary" />
          <CardTitle>Instalación local</CardTitle>
        </div>
        <CardDescription>
          Instala BalanzaPro en la PC central y descarga los datos de {tenant?.name ?? 'esta empresa'} para usarlo sin conexión.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3 lg:grid-cols-2">
        <section className="space-y-3 rounded-lg border border-border bg-bg/40 p-4" aria-labelledby="offline-installer-title">
          <div className="flex items-center gap-2 text-sm font-medium" id="offline-installer-title">
            <Laptop className="size-4 text-primary" />
            Instalador para Windows
          </div>
          <p className="text-xs text-text-muted">
            Programa genérico para Windows de 64 bits. Instálalo una vez en la PC principal de la tienda.
          </p>
          {installer?.available ? (
            <>
              <p className="text-xs text-text-muted" data-testid="installer-details">
                Versión {installer.version ?? 'actual'} · {formatSize(installer.size_bytes)}
                {installer.built_at ? ` · ${new Date(installer.built_at).toLocaleDateString('es-VE')}` : ''}
              </p>
              <Button
                type="button"
                className="w-full gap-2"
                onClick={() => { window.location.assign(installer.url ?? '/downloads/BalanzaPro-Setup.exe'); }}
                data-testid="download-setup-btn"
              >
                <Download className="size-4" />
                Descargar BalanzaPro-Setup.exe
              </Button>
            </>
          ) : (
            <>
              <p className="text-xs text-amber-700 dark:text-amber-300" role="status" data-testid="installer-unavailable">
                {installerLoading ? 'Verificando el instalador…' : 'El instalador todavía no está disponible para descargar.'}
              </p>
              <Button type="button" variant="outline" className="w-full gap-2" disabled>
                <Download className="size-4" />
                Descarga no disponible
              </Button>
            </>
          )}
        </section>

        <section className="space-y-3 rounded-lg border border-border bg-bg/40 p-4" aria-labelledby="offline-data-title">
          <div className="flex items-center gap-2 text-sm font-medium" id="offline-data-title">
            <HardDrive className="size-4 text-primary" />
            Datos completos de la empresa
          </div>
          <p className="text-xs text-text-muted">
            El ZIP incluye la base de datos, configuración, imágenes y el historial. La importación automática se realiza únicamente al instalar por primera vez.
          </p>
          <Button
            type="button"
            variant="outline"
            className="w-full gap-2"
            onClick={createSnapshot}
            disabled={starting || isPreparing}
            loading={starting || isPreparing}
            data-testid="create-offline-package-btn"
          >
            <Download className="size-4" />
            {isPreparing ? 'Preparando paquete…' : snapshot?.status === 'ready' ? 'Preparar un paquete nuevo' : 'Preparar ZIP de esta empresa'}
          </Button>
          {snapshot && (
            <div className="space-y-2 text-xs" role="status" aria-live="polite" data-testid="offline-package-status">
              <p className={snapshot.status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-text-muted'}>
                {snapshot.message}
              </p>
              {snapshot.status === 'ready' && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-mono text-text-muted">
                    {formatSize(snapshot.size_bytes)}{snapshot.sha256 ? ` · SHA-256 ${snapshot.sha256.slice(0, 12)}…` : ''}
                  </span>
                  <Button type="button" size="sm" onClick={downloadSnapshot} loading={downloading} data-testid="download-offline-package-btn">
                    Descargar ZIP
                  </Button>
                </div>
              )}
            </div>
          )}
        </section>
      </CardContent>
    </Card>
  );
}
