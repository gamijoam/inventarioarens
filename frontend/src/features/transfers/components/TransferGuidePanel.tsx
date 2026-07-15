/**
 * TransferGuidePanel: tab que muestra la guia de traslado con botones
 * para descargar PDF o ver HTML. La guia solo esta disponible si el
 * transfer esta en un estado post-creacion (prepared, dispatched,
 * completed).
 */
import { Download, ExternalLink } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  TRANSFER_STATUS_LABELS,
  type Transfer,
} from '@/features/transfers/schemas';
import { useTransferApiBaseUrl } from '@/lib/apiBaseUrl';

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

  const pdfUrl = `${apiBaseUrl}/inventory-transfers/${transfer.id}/guide.pdf`;
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
        <a href={pdfUrl} target="_blank" rel="noopener noreferrer" download>
          <Button leftIcon={<Download className="size-4" />}>
            Descargar PDF
          </Button>
        </a>
        <a href={htmlUrl} target="_blank" rel="noopener noreferrer">
          <Button variant="outline" leftIcon={<ExternalLink className="size-4" />}>
            Ver HTML
          </Button>
        </a>
      </CardContent>
    </Card>
  );
}
