/**
 * InventoryTransferRequestsManager: bandeja con 5 tabs (Enviadas /
 * Recibidas / Pendientes / Completadas / Rechazadas-Canceladas) para
 * solicitudes de stock ENTRE empresas del grupo.
 *
 * - Enviadas: status = requested OR completed OR cancelled, donde soy origin.
 * - Recibidas: status = requested OR completed OR rejected, donde soy destination.
 * - Pendientes: status = requested (union de Enviadas+Recibidas pendientes).
 * - Completadas: status = completed.
 * - Rechazadas/Canceladas: status = rejected OR cancelled.
 *
 * Acciones rapidas por fila:
 *   - Recibidas+requested: Aceptar / Rechazar.
 *   - Enviadas+requested: Cancelar.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Search, Plus, Building2, Truck, ArrowRight, XCircle, CheckCircle2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import {
  useTransferRequests,
  useCancelTransferRequest,
} from '@/features/inventory-transfer-requests/api';
import {
  TRANSFER_REQUEST_STATUS_LABELS,
  TRANSFER_REQUEST_GUIDE_STATUS_LABELS,
  TRANSFER_REQUEST_TAB_LABELS,
  type TransferRequest,
  type TransferRequestStatus,
  type TransferRequestTab,
} from '@/features/inventory-transfer-requests/schemas';
import { useSessionStore } from '@/stores/session';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
function statusVariant(
  status: TransferRequestStatus,
): 'info' | 'warning' | 'success' | 'danger' | 'default' {
  switch (status) {
    case 'requested':
      return 'info';
    case 'accepted':
    case 'prepared':
      return 'warning';
    case 'dispatched':
    case 'delivered':
      return 'info';
    case 'completed':
      return 'success';
    case 'rejected':
      return 'danger';
    case 'cancelled':
      return 'default';
    default:
      return 'default';
  }
}

interface InventoryTransferRequestsManagerProps {
  onCreate?: () => void;
  onAccept?: (req: TransferRequest) => void;
  onReject?: (req: TransferRequest) => void;
  /**
   * Si se pasa, se usa para filtrar Enviadas/Recibidas. Si no, se lee del
   * session store via useSessionStore.getState() (lectura no-reactiva;
   * suficiente porque el query se re-fetcha al cambiar de tenant).
   */
  currentTenantId?: number;
}

export function InventoryTransferRequestsManager({
  onCreate,
  onAccept,
  onReject,
  currentTenantId: currentTenantIdProp,
}: InventoryTransferRequestsManagerProps = {}) {
  const navigate = useNavigate();
  const [tab, setTab] = useState<TransferRequestTab>('received');
  const [search, setSearch] = useState('');
  // Polling automatico solo en tabs "activas" (Received/Pending).
  // En tabs de archivo (Sent/Completed/Rejected) se desactiva para no
  // gastar requests del backend ni bateria del navegador.
  const refetchInterval: number | false = tab === 'received' || tab === 'pending' ? 5000 : false;
  // useTransferRequests ahora retorna una forma aplanada: { data, meta, isLoading }.
  const { data: requests, isLoading: isLoadingLocal } = useTransferRequests(undefined, {
    refetchInterval,
  });
  const cancel = useCancelTransferRequest();
  const canCreate = useCan(PERMISSIONS.INVENTORY_TRANSFER_REQUESTS_CREATE);
  const canRespond = useCan(PERMISSIONS.INVENTORY_TRANSFER_REQUESTS_RESPOND);
  const canCancelPermission = useCan(PERMISSIONS.INVENTORY_TRANSFER_REQUESTS_CANCEL);
  // Lectura no-reactiva del tenant actual: el componente se re-renderiza
  // cuando cambian los datos de la query, que es suficiente para que el
  // filtro se actualice si el usuario cambia de empresa.
  const currentTenantId = currentTenantIdProp ?? useSessionStore.getState().tenant?.id;

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return requests.filter((r) => {
      // Filtrar por tab.
      const isMine = r.origin_tenant_id === currentTenantId;
      const isTheirs = r.destination_tenant_id === currentTenantId;
      switch (tab) {
        case 'sent':
          if (!isMine) return false;
          break;
        case 'received':
          if (!isTheirs) return false;
          break;
        case 'pending':
          if (r.status !== 'requested') return false;
          break;
        case 'completed':
          if (r.status !== 'completed') return false;
          break;
        case 'rejected':
          if (r.status !== 'rejected' && r.status !== 'cancelled') return false;
          break;
      }
      if (!q) return true;
      return (
        (r.document_number ?? '').toLowerCase().includes(q) ||
        (r.reason ?? '').toLowerCase().includes(q) ||
        (r.reference ?? '').toLowerCase().includes(q)
      );
    });
  }, [requests, tab, search, currentTenantId]);

  function cancelMine(r: TransferRequest) {
    if (!confirm(`Cancelar la solicitud ${r.document_number ?? '#' + r.id}?`)) return;
    cancel.mutate(r.id, {
      onSuccess: () => toast.success('Solicitud cancelada.'),
      onError: (err) => toast.error(err instanceof Error ? err.message : 'Error al cancelar.'),
    });
  }

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative max-w-sm min-w-[200px] flex-1">
          <Search
            className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por documento, motivo, referencia..."
            className="pl-8"
          />
        </div>
        {canCreate && (
          <Button
            size="sm"
            leftIcon={<Plus className="size-4" />}
            onClick={onCreate}
            className="ml-auto"
          >
            Nueva solicitud
          </Button>
        )}
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as TransferRequestTab)}>
        <TabsList>
          {TRANSFER_REQUEST_TAB_LABELS_SAFE.map((t) => (
            <TabsTrigger key={t.value} value={t.value}>
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {isLoadingLocal ? (
          <Skeleton className="mt-3 h-32 w-full" />
        ) : filtered.length === 0 ? (
          <EmptyState
            className="mt-3"
            icon={<Building2 className="size-8" />}
            title="Sin solicitudes"
            description={
              tab === 'sent'
                ? 'No has enviado solicitudes a otras empresas.'
                : tab === 'received'
                  ? 'No tienes solicitudes pendientes de empresas hermanas.'
                : 'No hay solicitudes en esta categoría.'
            }
          />
        ) : (
          <div className="border-border bg-surface mt-3 rounded-lg border">
            <table className="table-dense w-full">
              <thead className="border-border bg-bg/60 border-b text-left">
                <tr>
                  <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                    Documento
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                    Dirección
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                    Items
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                    Estado
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                    Fecha
                  </th>
                  <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                    Acciones
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => {
                  const isMine = r.origin_tenant_id === currentTenantId;
                  const canRespondToRequest = canRespond && !isMine && r.status === 'requested';
                  const canCancelRequest = canCancelPermission && isMine && r.status === 'requested';
                  return (
                    <tr
                      key={r.id}
                      className="border-border hover:bg-bg/40 cursor-pointer border-b transition-colors last:border-b-0"
                      data-testid={`row-${r.id}`}
                      onClick={() =>
                        navigate({
                          to: '/inventory-transfer-requests/$requestId',
                          params: { requestId: String(r.id) },
                        })
                      }
                    >
                      <td className="px-3 py-2 font-medium">
                        <code className="bg-bg rounded px-1.5 py-0.5 text-xs">
                          {r.document_number ?? `#${r.id}`}
                        </code>
                        {r.reason && (
                          <div className="text-text-muted mt-0.5 text-xs">{r.reason}</div>
                        )}
                      </td>
                      <td className="text-text-muted px-3 py-2">
                        <div className="flex items-center gap-1 text-xs">
                          <span>{r.origin_tenant?.slug ?? `T#${r.origin_tenant_id}`}</span>
                          <ArrowRight className="size-3" />
                          <span>
                            {r.destination_tenant?.slug ?? `T#${r.destination_tenant_id}`}
                          </span>
                        </div>
                        <div className="text-[10px] tracking-wide uppercase">
                          {isMine ? 'salida' : 'entrada'}
                        </div>
                      </td>
                      <td className="text-text-muted px-3 py-2 tabular-nums">
                        {r.items?.length ?? 0}
                      </td>
                      <td className="px-3 py-2">
                        <Badge variant={statusVariant(r.status)}>
                          {r.logistics_mode && r.guide
                            ? `Guía: ${TRANSFER_REQUEST_GUIDE_STATUS_LABELS[r.guide.status]}`
                            : TRANSFER_REQUEST_STATUS_LABELS[r.status]}
                        </Badge>
                      </td>
                      <td className="text-text-muted px-3 py-2">
                        {r.requested_at ? new Date(r.requested_at).toLocaleDateString() : '-'}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <div className="flex justify-end gap-1">
                          {canRespondToRequest && onAccept && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => onAccept(r)}
                              aria-label={`Aceptar solicitud ${r.document_number ?? r.id}`}
                              title="Aceptar"
                              data-testid={`accept-${r.id}`}
                            >
                              <CheckCircle2 className="text-success size-4" />
                            </Button>
                          )}
                          {canRespondToRequest && onReject && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => onReject(r)}
                              aria-label={`Rechazar solicitud ${r.document_number ?? r.id}`}
                              title="Rechazar"
                            >
                              <XCircle className="text-danger size-4" />
                            </Button>
                          )}
                          {canCancelRequest && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => cancelMine(r)}
                              aria-label={`Cancelar solicitud ${r.document_number ?? r.id}`}
                              title="Cancelar"
                            >
                              <XCircle className="text-text-muted size-4" />
                            </Button>
                          )}
                          {!canRespondToRequest && !canCancelRequest && (
                            <span className="text-text-muted text-xs">
                              <Truck className="inline size-3" />
                            </span>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Tabs>
    </>
  );
}

const TRANSFER_REQUEST_TAB_LABELS_SAFE = (
  Object.keys(TRANSFER_REQUEST_TAB_LABELS) as TransferRequestTab[]
).map((value) => ({ value, label: TRANSFER_REQUEST_TAB_LABELS[value] }));
