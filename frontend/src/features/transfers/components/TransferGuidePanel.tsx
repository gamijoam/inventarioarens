/**
 * TransferGuidePanel: tab que muestra la guia de traslado con botones
 * para descargar PDF o ver HTML. La guia solo esta disponible si el
 * transfer esta en un estado post-creacion (prepared, dispatched,
 * completed).
 *
 * La descarga del PDF se hace via fetch + blob para evitar problemas con
 * el proxy de Vite (que en algunos casos convierte el Content-Type y
 * el navegador interpreta la respuesta como JSON). Ademas, el nombre
 * del archivo viene del header Content-Disposition del backend para
 * evitar ambiguedades.
 */
import { useState } from 'react';
import { Download, ExternalLink, Loader2 } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  TRANSFER_STATUS_LABELS,
  type Transfer,
} from '@/features/transfers/schemas';
import { useTransferApiBaseUrl } from '@/lib/apiBaseUrl';
import { api } from '@/api/client';
import { toast } from 'sonner';

const GUIDE_AVAILABLE_STATUSES = new Set([
  'prepared',
  'prepared_with_differences',
  'dispatched',
  'completed',
  'completed_with_differences',
]);

export function TransferGuidePanel({ transfer }: { transfer: Transfer }) {
  const apiBaseUrl = useTransferApiBaseUrl();
  const isAvailable = GUIDE_AVAILABLE_STATUSES.has(transfer.status);
  const [downloading, setDownloading] = useState(false);

  async function downloadPdf() {
    setDownloading(true);
    try {
      const url = `${apiBaseUrl}/inventory-transfers/${transfer.id}/guide.pdf`;
      const response = await api.get(url, {
        responseType: 'blob',
        withCredentials: true,
      });
      const blob = response.data as Blob;
      // Extraer filename del header Content-Disposition si esta disponible.
      const dispo = (response.headers?.['content-disposition'] as string | undefined) ?? '';
      const match = /filename="?([^"]+)"?/.exec(dispo);
      const filename = match?.[1] ?? `guia-${transfer.document_number ?? transfer.id}.pdf`;
      const downloadUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = downloadUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(downloadUrl);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al descargar la guia.');
    } finally {
      setDownloading(false);
    }
  }

  if (!isAvailable) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Guia de traslado</CardTitle>
          <CardDescription>
            La guia se genera cuando el traslado pasa por preparar, despachar o recibir.
            Estado actual: <strong>{TRANSFER_STATUS_LABELS[transfer.status]}</strong>.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <EmptyState
            title="Guia no disponible"
            description="Avanza el traslado a 'preparado' para poder generar la guia de despacho."
          />
        </CardContent>
      </Card>
    );
  }

  const htmlUrl = `${apiBaseUrl}/inventory-transfers/${transfer.id}/guide.html`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Guia de traslado</CardTitle>
        <CardDescription>
          Documento imprimible con el detalle del traslado, lista de items
          y firmas. Estado: <strong>{TRANSFER_STATUS_LABELS[transfer.status]}</strong>.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Button
          leftIcon={downloading ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
          onClick={downloadPdf}
          loading={downloading}
          data-testid="guide-download-pdf"
        >
          Descargar PDF
        </Button>
        <a href={htmlUrl} target="_blank" rel="noopener noreferrer">
          <Button variant="outline" leftIcon={<ExternalLink className="size-4" />}>
            Ver HTML
          </Button>
        </a>
      </CardContent>
    </Card>
  );
}
