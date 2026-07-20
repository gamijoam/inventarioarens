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
  TRANSFER_REQUEST_TAB_LABELS,
  type TransferRequest,
  type TransferRequestStatus,
  type TransferRequestTab,
} from '@/features/inventory-transfer-requests/schemas';
import { useSessionStore } from '@/stores/session';
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
  const refetchInterval: number | false =
    tab === 'received' || tab === 'pending' ? 5000 : false;
  // useTransferRequests ahora retorna una forma aplanada: { data, meta, isLoading }.
  const { data: requests, isLoading: isLoadingLocal } = useTransferRequests(undefined, { refetchInterval });
  const cancel = useCancelTransferRequest();
  // Lectura no-reactiva del tenant actual: el componente se re-renderiza
  // cuando cambian los datos de la query, que es suficiente para que el
  // filtro se actualice si el usuario cambia de empresa.
  const currentTenantId = currentTenantIdProp ?? useSessionStore.getState().tenant?.id;

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return requests.filter((r) => {
      // Filtrar por tab.
      const isMine = (r.origin_tenant_id === currentTenantId);
      const isTheirs = (r.destination_tenant_id === currentTenantId);
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
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
            aria-hidden="true"
          />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por documento, motivo, referencia..."
            className="pl-8"
          />
        </div>
        <Button
          size="sm"
          leftIcon={<Plus className="size-4" />}
          onClick={onCreate}
          className="ml-auto"
        >
          Nueva solicitud
        </Button>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as TransferRequestTab)}>
        <TabsList>
          {TRANSFER_REQUEST_TAB_LABELS_SAFE.map((t) => (
            <TabsTrigger key={t.value} value={t.value}>{t.label}</TabsTrigger>
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
                  : 'No hay solicitudes en esta categoria.'
            }
          />
        ) : (
          <div className="mt-3 rounded-lg border border-border bg-surface">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Documento</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Direccion</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Items</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => {
                  const isMine = r.origin_tenant_id === currentTenantId;
                  const canRespond = !isMine && r.status === 'requested';
                  const canCancel = isMine && r.status === 'requested';
                  return (
                    <tr
                      key={r.id}
                      className="cursor-pointer border-b border-border last:border-b-0 transition-colors hover:bg-bg/40"
                      data-testid={`row-${r.id}`}
                      onClick={() =>
                        navigate({
                          to: '/inventory-transfer-requests/$requestId',
                          params: { requestId: String(r.id) },
                        })
                      }
                    >
                      <td className="px-3 py-2 font-medium">
                        <code className="rounded bg-bg px-1.5 py-0.5 text-xs">
                          {r.document_number ?? `#${r.id}`}
                        </code>
                        {r.reason && (
                          <div className="mt-0.5 text-xs text-text-muted">{r.reason}</div>
                        )}
                      </td>
                      <td className="px-3 py-2 text-text-muted">
                        <div className="flex items-center gap-1 text-xs">
                          <span>{r.origin_tenant?.slug ?? `T#${r.origin_tenant_id}`}</span>
                          <ArrowRight className="size-3" />
                          <span>{r.destination_tenant?.slug ?? `T#${r.destination_tenant_id}`}</span>
                        </div>
                        <div className="text-[10px] uppercase tracking-wide">
                          {isMine ? 'salida' : 'entrada'}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-text-muted tabular-nums">{r.items?.length ?? 0}</td>
                      <td className="px-3 py-2">
                        <Badge variant={statusVariant(r.status)}>
                          {TRANSFER_REQUEST_STATUS_LABELS[r.status]}
                        </Badge>
                      </td>
                      <td className="px-3 py-2 text-text-muted">
                        {r.requested_at ? new Date(r.requested_at).toLocaleDateString() : '-'}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <div className="flex justify-end gap-1">
                          {canRespond && onAccept && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => onAccept(r)}
                              aria-label={`Aceptar solicitud ${r.document_number ?? r.id}`}
                              title="Aceptar"
                              data-testid={`accept-${r.id}`}
                            >
                              <CheckCircle2 className="size-4 text-success" />
                            </Button>
                          )}
                          {canRespond && onReject && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => onReject(r)}
                              aria-label={`Rechazar solicitud ${r.document_number ?? r.id}`}
                              title="Rechazar"
                            >
                              <XCircle className="size-4 text-danger" />
                            </Button>
                          )}
                          {canCancel && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={() => cancelMine(r)}
                              aria-label={`Cancelar solicitud ${r.document_number ?? r.id}`}
                              title="Cancelar"
                            >
                              <XCircle className="size-4 text-text-muted" />
                            </Button>
                          )}
                          {!canRespond && !canCancel && (
                            <span className="text-xs text-text-muted">
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

const TRANSFER_REQUEST_TAB_LABELS_SAFE = (Object.keys(TRANSFER_REQUEST_TAB_LABELS) as TransferRequestTab[]).map(
  (value) => ({ value, label: TRANSFER_REQUEST_TAB_LABELS[value] }),
);
