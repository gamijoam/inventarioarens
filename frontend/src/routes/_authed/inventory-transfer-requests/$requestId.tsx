/**
 * Pagina /inventory-transfer-requests/$requestId: detalle de una solicitud
 * inter-empresa. Patron consistente con /transfers/$transferId.
 *
 * Tabs:
 *   - General: info origen/destino + response_notes + notas.
 *   - Items: tabla con IMEIs/seriales visibles cuando el item es serialized.
 *   - Timeline: cronologia de eventos (created / accepted / rejected / cancelled).
 *
 * Acciones contextuales segun el rol del user:
 *   - canRespond: Aceptar / Rechazar (status=requested + soy destino).
 *   - canCancel: Cancelar (status=requested + soy origen).
 */
import { useState } from 'react';
import { Link, createFileRoute, useNavigate } from '@tanstack/react-router';
import { ArrowLeft, Building2, CheckCircle2, XCircle, PackageX } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/Tabs';
import {
  useAcceptTransferRequest,
  useCancelTransferRequest,
  useTransferRequest,
} from '@/features/inventory-transfer-requests/api';
import { AcceptInventoryTransferRequestDialog } from '@/features/inventory-transfer-requests/components/AcceptInventoryTransferRequestDialog';
import { RejectInventoryTransferRequestDialog } from '@/features/inventory-transfer-requests/components/RejectInventoryTransferRequestDialog';
import {
  TRANSFER_REQUEST_STATUS_LABELS,
  type TransferRequest,
  type TransferRequestStatus,
} from '@/features/inventory-transfer-requests/schemas';
import { useSessionStore } from '@/stores/session';
import { TransferRequestTimeline } from '@/features/inventory-transfer-requests/TransferRequestTimeline';

export const Route = createFileRoute('/_authed/inventory-transfer-requests/$requestId')({
  component: TransferRequestDetailPage,
});

function statusVariant(status: TransferRequestStatus): 'info' | 'warning' | 'success' | 'danger' | 'default' {
  switch (status) {
    case 'requested':
      return 'info';
    case 'completed':
      return 'success';
    case 'rejected':
      return 'danger';
    case 'cancelled':
      return 'default';
  }
}

function TransferRequestDetailPage() {
  // Route.useParams() requiere contexto de router; en tests lo mockeamos.
  const { requestId } = Route.useParams();
  return <TransferRequestDetailInner id={parseInt(requestId, 10)} />;
}

/**
 * Componente interno testeable que recibe el id como prop en vez de leerlo
 * del contexto de router. Esto permite testearlo sin necesidad de montar
 * un MemoryRouter de TanStack.
 */
export function TransferRequestDetailInner({ id }: { id: number }) {
  const navigate = useNavigate();
  const { data: request, isLoading, isError } = useTransferRequest(id);
  const currentTenantId = useSessionStore((s) => s.tenant?.id);

  const accept = useAcceptTransferRequest();
  const cancel = useCancelTransferRequest();

  const [activeTab, setActiveTab] = useState('general');
  const [acceptOpen, setAcceptOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);

  if (isLoading) {
    return (
      <PageLayout title="Cargando solicitud...">
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </PageLayout>
    );
  }

  if (isError || !request) {
    return (
      <PageLayout title="Solicitud no encontrada">
        <EmptyState
          title="No se encontro la solicitud"
          description="La solicitud puede haber sido eliminada o no tienes permiso para verla."
          action={
            <Button variant="outline" onClick={() => navigate({ to: '/inventory-transfer-requests' })}>
              <ArrowLeft className="size-4" /> Volver a la bandeja
            </Button>
          }
        />
      </PageLayout>
    );
  }

  // Acciones disponibles segun el rol:
  //  - canRespond: status=requested + soy el destino.
  //  - canCancel:  status=requested + soy el origen.
  // request es no-null aqui porque `isError || !request` retorno arriba.
  const isDestination = currentTenantId === request!.destination_tenant_id;
  const isOrigin = currentTenantId === request!.origin_tenant_id;
  const canRespond = request.status === 'requested' && isDestination;
  const canCancel = request.status === 'requested' && isOrigin;
  const accepting = accept.isPending;
  const cancelling = cancel.isPending;

  function doCancel() {
    if (!confirm(`Cancelar la solicitud ${request!.document_number ?? '#' + request!.id}?`)) return;
    cancel.mutate(request!.id, {
      onSuccess: () => {
        // Forzar refetch de la lista y del detalle.
        navigate({ to: '/inventory-transfer-requests' });
      },
    });
  }

  return (
    <PageLayout
      title={`Solicitud ${request.document_number ?? '#' + request.id}`}
      description={`${request.origin_tenant?.slug ?? 'T#' + request.origin_tenant_id} → ${request.destination_tenant?.slug ?? 'T#' + request.destination_tenant_id}`}
      breadcrumb={
        <Link
          to="/inventory-transfer-requests"
          className="inline-flex items-center gap-1 text-xs text-text-muted hover:text-primary"
        >
          <ArrowLeft className="size-3" /> Solicitudes inter-empresa
        </Link>
      }
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={statusVariant(request.status)}>
            {TRANSFER_REQUEST_STATUS_LABELS[request.status]}
          </Badge>
          {canRespond && (
            <>
              <Button
                size="sm"
                leftIcon={<CheckCircle2 className="size-4" />}
                onClick={() => setAcceptOpen(true)}
                loading={accepting}
                data-testid="detail-accept-btn"
              >
                Aceptar
              </Button>
              <Button
                size="sm"
                variant="outline"
                leftIcon={<XCircle className="size-4 text-danger" />}
                onClick={() => setRejectOpen(true)}
                data-testid="detail-reject-btn"
              >
                Rechazar
              </Button>
            </>
          )}
          {canCancel && (
            <Button
              size="sm"
              variant="ghost"
              leftIcon={<PackageX className="size-4 text-text-muted" />}
              onClick={doCancel}
              loading={cancelling}
              data-testid="detail-cancel-btn"
            >
              Cancelar
            </Button>
          )}
        </div>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="items">Items ({request.items?.length ?? 0})</TabsTrigger>
          <TabsTrigger value="timeline">Timeline</TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="space-y-4">
          <GeneralTab request={request} />
        </TabsContent>

        <TabsContent value="items" className="space-y-4">
          <ItemsTab request={request} />
        </TabsContent>

        <TabsContent value="timeline" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Cronologia de la solicitud</CardTitle>
              <CardDescription>
                Eventos registrados en orden cronologico: solicitud, aceptacion,
                rechazo o cancelacion.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <TransferRequestTimeline request={request} />
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {acceptOpen && (
        <AcceptInventoryTransferRequestDialog
          request={request}
          open={acceptOpen}
          onOpenChange={(o) => { if (!o) setAcceptOpen(false); }}
          onAccepted={() => {
            setAcceptOpen(false);
            // Refetch del detalle + bandeja.
            navigate({ to: '/inventory-transfer-requests' });
          }}
        />
      )}

      {rejectOpen && (
        <RejectInventoryTransferRequestDialog
          request={request}
          open={rejectOpen}
          onOpenChange={(o) => { if (!o) setRejectOpen(false); }}
          onRejected={() => {
            setRejectOpen(false);
            navigate({ to: '/inventory-transfer-requests' });
          }}
        />
      )}
    </PageLayout>
  );
}

function GeneralTab({ request }: { request: TransferRequest }) {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Building2 className="size-4" /> Origen
          </CardTitle>
          <CardDescription>Empresa que envia la solicitud.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Nombre</div>
            <div className="font-medium">{request.origin_tenant?.name ?? `Tenant #${request.origin_tenant_id}`}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Slug</div>
            <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{request.origin_tenant?.slug ?? '-'}</code>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Building2 className="size-4" /> Destino
          </CardTitle>
          <CardDescription>Empresa que debe responder.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Nombre</div>
            <div className="font-medium">{request.destination_tenant?.name ?? `Tenant #${request.destination_tenant_id}`}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Slug</div>
            <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{request.destination_tenant?.slug ?? '-'}</code>
          </div>
        </CardContent>
      </Card>

      <Card className="md:col-span-2">
        <CardHeader>
          <CardTitle>Detalles de la solicitud</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Motivo</div>
            <div>{request.reason ?? <span className="italic text-text-muted">sin motivo</span>}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Referencia</div>
            <div>{request.reference ?? <span className="italic text-text-muted">sin referencia</span>}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Notas del solicitante</div>
            <div>{request.notes ?? <span className="italic text-text-muted">sin notas</span>}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Notas de respuesta</div>
            <div>{request.response_notes ?? <span className="italic text-text-muted">aun sin responder</span>}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Solicitada</div>
            <div>{request.requested_at ? new Date(request.requested_at).toLocaleString() : '-'}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Respondida / Completada</div>
            <div>{request.responded_at ? new Date(request.responded_at).toLocaleString() : '-'}</div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

interface ItemRow {
  id: number;
  origin_product_id: number;
  origin_product?: { id: number; name: string; sku?: string | null; barcode?: string | null; tracking_type?: 'quantity' | 'serialized' } | null;
  destination_product_id?: number | null;
  destination_product?: { id: number; name: string; sku?: string | null; tracking_type?: 'quantity' | 'serialized' } | null;
  quantity: number;
  serial_units?: Array<string | { serial_type?: string; serial_number: string }> | null;
}

function ItemsTab({ request }: { request: TransferRequest }) {
  const items: ItemRow[] = (request.items ?? []) as ItemRow[];
  if (items.length === 0) {
    return <EmptyState title="Sin items" description="Esta solicitud no tiene items asociados." />;
  }
  return (
    <div className="space-y-4">
      {items.map((it) => {
        const origin = it.origin_product;
        const destination = it.destination_product;
        const tracking = origin?.tracking_type;
        const serialList = Array.isArray(it.serial_units) ? it.serial_units : [];
        const serialNumbers = serialList.map((s) =>
          typeof s === 'string' ? s : s.serial_number,
        );
        return (
          <Card key={it.id} data-testid={`detail-item-${it.id}`}>
            <CardHeader>
              <CardTitle className="text-base">
                {origin?.name ?? `Producto #${it.origin_product_id}`}
              </CardTitle>
              <CardDescription>
                {origin?.sku && (
                  <code className="mr-2 rounded bg-bg px-1.5 py-0.5 text-xs">{origin.sku}</code>
                )}
                <span className="text-text-muted">
                  Cantidad: <strong>{Number(it.quantity).toLocaleString()}</strong>
                </span>
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              {destination ? (
                <div className="rounded border border-border bg-bg/30 p-3">
                  <div className="text-xs uppercase tracking-wide text-text-muted">Producto destino mapeado</div>
                  <div className="mt-1 font-medium">{destination.name}</div>
                  {destination.sku && (
                    <code className="mt-1 inline-block rounded bg-bg px-1.5 py-0.5 text-xs">
                      {destination.sku}
                    </code>
                  )}
                </div>
              ) : (
                <div className="rounded border border-dashed border-border bg-bg/30 p-3 text-text-muted">
                  Producto destino <strong>no mapeado todavia</strong>. El destino debe aceptar y elegir el producto correspondiente.
                </div>
              )}

              {tracking === 'serialized' && (
                <div data-testid={`detail-item-imeis-${it.id}`}>
                  <div className="text-xs uppercase tracking-wide text-text-muted">
                    IMEIs / seriales que llegaran a tu stock ({serialNumbers.length} / {Number(it.quantity).toLocaleString()})
                  </div>
                  {serialNumbers.length === 0 ? (
                    <p className="mt-1 rounded border border-warning/30 bg-warning/10 p-2 text-xs text-warning">
                      La solicitud no incluye IMEIs/seriales. Si aceptas sin ellos, las unidades
                      quedaran sin identificar en tu stock.
                    </p>
                  ) : (
                    <ul className="mt-1 flex flex-wrap gap-1.5">
                      {serialNumbers.map((sn, idx) => (
                        <li
                          key={`${it.id}-sn-${idx}`}
                          className="inline-flex items-center rounded bg-primary/10 px-2 py-0.5 font-mono text-[11px] text-primary"
                          data-testid={`detail-imei-${it.id}-${idx}`}
                        >
                          {sn}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
