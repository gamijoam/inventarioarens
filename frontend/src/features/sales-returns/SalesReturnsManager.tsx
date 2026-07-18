import { useState } from 'react';
import { ChevronDown, FileText, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';

import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { useCashSessions, useCurrentExchangeRatesForPos } from '@/features/pos/api';
import { activeUsdVesRate } from '@/features/receivables/currentBalance';
import { cn } from '@/lib/cn';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  useApproveSalesReturn,
  useCancelSalesReturn,
  useProcessSalesReturn,
  useRejectSalesReturn,
  useSalesReturns,
  type ProcessSalesReturnPayload,
  type SalesReturn,
  type SalesReturnStatus,
} from './api';

const STATUS_LABELS: Record<string, string> = {
  requested: 'Solicitada',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  processed: 'Procesada',
  cancelled: 'Cancelada',
};

const CONDITION_LABELS: Record<string, string> = {
  sellable: 'Vendible',
  damaged: 'Dañado',
};

const RECEIVABLE_LABELS: Record<string, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  overdue: 'Vencida',
  paid: 'Pagada',
};

type StatusFilter = 'open' | 'all' | SalesReturnStatus;

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

function formatMoney(value: number | null | undefined, currency = '$'): string {
  const n = Number(value ?? 0);
  return `${currency}${n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function customerLabel(item: { sale?: { customer?: { name?: string; document_number?: string | null } | null } | null }): string {
  const customer = item.sale?.customer;
  if (!customer?.name) return 'Consumidor Final';
  return customer.document_number ? `${customer.name} - ${customer.document_number}` : customer.name;
}

function statusVariant(status: string): 'default' | 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'processed') return 'success';
  if (status === 'rejected' || status === 'cancelled') return 'danger';
  if (status === 'approved') return 'info';
  return 'warning';
}

function returnedUnits(item: SalesReturn): number {
  return (item.items ?? []).reduce((sum, line) => sum + Number(line.quantity ?? 0), 0);
}

function refundBaseAmount(item: SalesReturn): number {
  return (item.items ?? []).reduce((sum, line) => sum + Number(line.refundable_base_amount ?? 0), 0);
}

function receivableBalance(item: SalesReturn): number {
  return Number(item.sale?.receivable?.balance_base_amount ?? 0);
}

function suggestedMode(item: SalesReturn, canRefund: boolean): 'none' | 'cash' | 'receivable' {
  if (!canRefund) return 'none';
  const receivable = item.sale?.receivable;
  if (receivable && Number(receivable.balance_base_amount ?? 0) > 0) return 'receivable';
  return 'cash';
}

function receivableLabel(item: SalesReturn): string {
  const receivable = item.sale?.receivable;
  if (!receivable) return 'Sin CxC';
  const balance = Number(receivable.balance_base_amount ?? 0);
  if (balance <= 0) return 'Pagada';
  return RECEIVABLE_LABELS[receivable.status ?? ''] ?? 'Con saldo';
}

export function SalesReturnsManager() {
  const canView = useCan(PERMISSIONS.SALES_RETURNS_VIEW);
  const canReview = useCan(PERMISSIONS.SALES_RETURNS_REVIEW);
  const canProcess = useCan(PERMISSIONS.SALES_RETURNS_PROCESS);
  const canRefund = useCan(PERMISSIONS.SALES_RETURNS_REFUND);
  const canCancel = useCan(PERMISSIONS.SALES_RETURNS_CANCEL);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [status, setStatus] = useState<StatusFilter>('open');
  const returns = useSalesReturns({ enabled: canView });
  const allData = returns.data?.data ?? [];
  const data = allData.filter((item) => {
    if (status === 'all') return true;
    if (status === 'open') return ['requested', 'approved'].includes(item.status);
    return item.status === status;
  });
  const processedCount = allData.filter((item) => item.status === 'processed').length;
  const openCount = allData.filter((item) => ['requested', 'approved'].includes(item.status)).length;
  const totalUnits = data.reduce((sum, item) => sum + returnedUnits(item), 0);

  if (!canView) {
    return <PermissionDenied permission={PERMISSIONS.SALES_RETURNS_VIEW} message="No tienes permiso para ver devoluciones de venta." />;
  }

  if (returns.isLoading && !returns.data) return <Skeleton className="h-64 w-full" />;

  if (returns.isError) {
    return (
      <EmptyState
        title="No se pudieron cargar devoluciones"
        description="Intenta actualizar el listado."
        action={<Button onClick={() => void returns.refetch()}>Reintentar</Button>}
      />
    );
  }

  if (allData.length === 0) {
    return (
      <EmptyState
        icon={<RotateCcw className="size-8" />}
        title="Sin devoluciones"
        description="Las solicitudes creadas desde ventas aparecerán aquí para revisión y proceso."
      />
    );
  }

  return (
    <div className="space-y-3">
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile label="Visibles" value={String(data.length)} />
        <InfoTile label="Abiertas" value={String(openCount)} />
        <InfoTile label="Procesadas" value={String(processedCount)} />
        <InfoTile label="Unidades visibles" value={String(totalUnits)} />
      </div>

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
          <div>
            <h3 className="font-semibold">Bandeja de devoluciones</h3>
            <p className="text-sm text-text-muted">Solicita, aprueba y procesa devoluciones sin afectar stock antes de tiempo.</p>
          </div>
          <Select className="w-48" value={status} onChange={(event) => setStatus(event.target.value as StatusFilter)}>
            <option value="open">Abiertas</option>
            <option value="requested">Solicitadas</option>
            <option value="approved">Aprobadas</option>
            <option value="processed">Procesadas</option>
            <option value="rejected">Rechazadas</option>
            <option value="cancelled">Canceladas</option>
            <option value="all">Todas</option>
          </Select>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left text-xs uppercase text-text-muted">
              <tr>
                <th className="w-8 px-2 py-2" />
                <th className="px-3 py-2">Devolución</th>
                <th className="px-3 py-2">Venta</th>
                <th className="px-3 py-2">Cliente</th>
                <th className="px-3 py-2">Estado</th>
                <th className="px-3 py-2">Items</th>
                <th className="px-3 py-2">Fecha</th>
                <th className="px-3 py-2">Motivo</th>
              </tr>
            </thead>
            <tbody>
              {data.map((item) => (
                <ReturnRows
                  key={item.id}
                  item={item}
                  expanded={expandedId === item.id}
                  canReview={canReview}
                  canProcess={canProcess}
                  canRefund={canRefund}
                  canCancel={canCancel}
                  onToggle={() => setExpandedId((current) => (current === item.id ? null : item.id))}
                />
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

function ReturnRows({
  item,
  expanded,
  canReview,
  canProcess,
  canRefund,
  canCancel,
  onToggle,
}: {
  item: SalesReturn;
  expanded: boolean;
  canReview: boolean;
  canProcess: boolean;
  canRefund: boolean;
  canCancel: boolean;
  onToggle: () => void;
}) {
  return (
    <>
      <tr className="cursor-pointer border-b border-border hover:bg-bg/50" onClick={onToggle}>
        <td className="px-2 py-2 text-text-muted">
          <ChevronDown className={cn('size-4 transition-transform', expanded ? '' : '-rotate-90')} />
        </td>
        <td className="px-3 py-2 font-medium">#{item.id}</td>
        <td className="px-3 py-2">#{item.sale_id}</td>
        <td className="px-3 py-2">{customerLabel(item)}</td>
        <td className="px-3 py-2">
          <Badge variant={statusVariant(item.status)}>{STATUS_LABELS[item.status] ?? item.status}</Badge>
        </td>
        <td className="px-3 py-2">{item.items?.length ?? '-'}</td>
        <td className="px-3 py-2 text-text-muted">{formatDate(item.processed_at ?? item.reviewed_at ?? item.created_at)}</td>
        <td className="px-3 py-2 text-text-muted">
          <FileText className="mr-1 inline size-3.5" />
          {item.reason ?? item.rejection_reason ?? item.cancellation_reason ?? '-'}
        </td>
      </tr>
      {expanded && (
        <tr className="border-b border-border bg-bg/20">
          <td colSpan={8} className="px-4 py-4">
            <ReturnDetail item={item} canReview={canReview} canProcess={canProcess} canRefund={canRefund} canCancel={canCancel} />
          </td>
        </tr>
      )}
    </>
  );
}

function ReturnDetail({ item, canReview, canProcess, canRefund, canCancel }: { item: SalesReturn; canReview: boolean; canProcess: boolean; canRefund: boolean; canCancel: boolean }) {
  const approve = useApproveSalesReturn();
  const reject = useRejectSalesReturn();
  const cancel = useCancelSalesReturn();
  const process = useProcessSalesReturn();
  const [rejectReason, setRejectReason] = useState('');
  const [cancelReason, setCancelReason] = useState('');
  const suggestedRefund = refundBaseAmount(item);
  const receivable = item.sale?.receivable ?? null;
  const balanceBase = receivableBalance(item);
  const balanceLocal = Number(receivable?.balance_local_amount ?? 0);
  const coversDebt = Math.min(suggestedRefund, balanceBase);
  const potentialCashRefund = Math.max(suggestedRefund - balanceBase, 0);
  const recommendedMode = suggestedMode(item, canRefund);
  const [processForm, setProcessForm] = useState<ProcessSalesReturnPayload>({
    refund_mode: recommendedMode,
    refund_currency: 'USD',
    refund_amount: suggestedRefund,
    refund_method: 'cash',
  });
  const { data: sessions = [] } = useCashSessions();
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const activeRate = activeUsdVesRate(rates);
  const activeSession = sessions[0] ?? null;
  const needsCashSession = processForm.refund_mode === 'cash';
  const hasVisibleActions =
    (item.status === 'requested' && canReview) ||
    (['requested', 'approved'].includes(item.status) && canCancel) ||
    (item.status === 'approved' && canProcess);

  async function handleApprove() {
    await approve.mutateAsync(item.id);
    toast.success('Devolución aprobada.');
  }

  async function handleReject() {
    if (!rejectReason.trim()) return toast.error('Indica el motivo de rechazo.');
    await reject.mutateAsync({ id: item.id, reason: rejectReason.trim() });
    toast.success('Devolución rechazada.');
  }

  async function handleCancel() {
    if (!cancelReason.trim()) return toast.error('Indica el motivo de cancelación.');
    await cancel.mutateAsync({ id: item.id, reason: cancelReason.trim() });
    toast.success('Devolución cancelada.');
  }

  async function handleProcess() {
    if (needsCashSession && !activeSession) return toast.error('Abre una caja antes de reembolsar desde caja.');
    if (processForm.refund_mode === 'cash' && Number(processForm.refund_amount ?? 0) <= 0) return toast.error('Indica el monto a reembolsar.');

    const payload: ProcessSalesReturnPayload = {
      ...processForm,
      refund_amount: processForm.refund_mode === 'cash' ? Number(processForm.refund_amount ?? suggestedRefund) : null,
      refund_cash_register_session_id: processForm.refund_mode === 'cash' ? activeSession?.id : null,
      refund_exchange_rate_type_id: processForm.refund_mode === 'cash' && processForm.refund_currency === 'VES' ? activeRate?.exchange_rate_type_id ?? null : null,
    };

    await process.mutateAsync({ id: item.id, payload });
    toast.success('Devolución procesada.');
  }

  return (
    <div className="grid gap-3 lg:grid-cols-[1fr_380px]">
      <section className="rounded border border-border bg-surface">
        <div className="border-b border-border px-3 py-2 font-semibold">Items de la solicitud</div>
        <div className="divide-y divide-border">
          {(item.items ?? []).length === 0 ? (
            <p className="p-3 text-sm text-text-muted">Sin líneas cargadas.</p>
          ) : item.items?.map((line) => (
            <div key={line.id} className="grid gap-2 p-3 md:grid-cols-[1fr_120px_120px] md:items-center">
              <div>
                <p className="font-medium">{line.product?.name ?? `Producto #${line.product_id}`}</p>
                <p className="text-xs text-text-muted">{line.product?.sku ?? '-'} · {line.warehouse?.name ?? `Almacén #${line.warehouse_id ?? '-'}`}</p>
                {line.reason && <p className="mt-1 text-xs text-text-muted">Motivo: {line.reason}</p>}
                {(line.product_unit_ids ?? []).length > 0 && (
                  <p className="mt-1 text-xs text-text-muted">Unidades: {line.product_unit_ids?.join(', ')}</p>
                )}
              </div>
              <div className="text-sm">
                <span className="text-text-muted">Cantidad</span>
                <p className="font-semibold tabular-nums">{Number(line.quantity).toLocaleString('es-VE')}</p>
              </div>
              <Badge variant={line.condition === 'damaged' ? 'warning' : 'success'}>
                {CONDITION_LABELS[line.condition] ?? line.condition}
              </Badge>
            </div>
          ))}
        </div>
      </section>

      <section className="space-y-3 rounded border border-border bg-surface p-3">
        <div>
          <h3 className="font-semibold">Acciones</h3>
          <p className="text-xs text-text-muted">
            {item.status === 'requested' && 'Solicitada: aún no mueve stock ni finanzas.'}
            {item.status === 'approved' && 'Aprobada: lista para procesar stock, Kardex y finanzas.'}
            {item.status === 'processed' && 'Procesada: ya aplicada a stock/Kardex/CxC.'}
            {['rejected', 'cancelled'].includes(item.status) && 'Cerrada sin aplicar stock ni finanzas.'}
          </p>
        </div>

        <dl className="space-y-2 text-sm">
          <Metric label="Creada por" value={item.created_by_name ?? '-'} />
          <Metric label="Revisada por" value={item.reviewed_by_name ?? '-'} />
          <Metric label="Procesada por" value={item.processed_by_name ?? '-'} />
          {item.refund_amount_base > 0 && <Metric label="Reembolso" value={`${formatMoney(item.refund_amount_base)} · ${item.refund_method ?? 'caja'}`} />}
        </dl>

        <div className="rounded border border-border bg-bg px-3 py-2 text-sm">
          <div className="flex items-center justify-between gap-3">
            <span className="text-text-muted">Valor devolución</span>
            <strong>{formatMoney(suggestedRefund)}</strong>
          </div>
          <div className="mt-1 flex items-center justify-between gap-3">
            <span className="text-text-muted">CxC venta</span>
            <strong>{receivableLabel(item)}</strong>
          </div>
          {receivable && (
            <div className="mt-1 flex items-center justify-between gap-3">
              <span className="text-text-muted">Saldo pendiente</span>
              <strong>{formatMoney(balanceBase)} · Bs {balanceLocal.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong>
            </div>
          )}
          <p className="mt-2 rounded bg-surface px-2 py-1 text-xs text-text-muted">
            {recommendedMode === 'receivable' && `Recomendado: aplicar contra CxC. Cubriría ${formatMoney(coversDebt)} de la deuda.`}
            {recommendedMode === 'cash' && 'Recomendado: reembolsar desde caja porque no hay deuda pendiente.'}
            {recommendedMode === 'none' && 'Sin permiso de reembolso: sólo puedes procesar la devolución operativa.'}
          </p>
          {potentialCashRefund > 0 && (
            <p className="mt-2 rounded border border-warning/40 bg-warning/10 px-2 py-1 text-xs text-warning">
              La devolución supera la deuda: cubre {formatMoney(coversDebt)} y quedaría una diferencia reembolsable de {formatMoney(potentialCashRefund)}.
            </p>
          )}
        </div>

        {!hasVisibleActions && ['requested', 'approved'].includes(item.status) && (
          <div className="rounded border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning">
            No tienes permisos para operar esta solicitud. Requiere revisar/procesar devoluciones según el estado actual.
          </div>
        )}

        {item.status === 'requested' && canReview && (
          <div className="space-y-2">
            <Button className="w-full" loading={approve.isPending} onClick={() => void handleApprove()}>Aprobar</Button>
            <Input value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} placeholder="Motivo de rechazo" />
            <Button className="w-full" variant="outline" loading={reject.isPending} onClick={() => void handleReject()}>Rechazar</Button>
          </div>
        )}

        {['requested', 'approved'].includes(item.status) && canCancel && (
          <div className="space-y-2">
            <Input value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} placeholder="Motivo de cancelación" />
            <Button className="w-full" variant="danger" loading={cancel.isPending} onClick={() => void handleCancel()}>Cancelar solicitud</Button>
          </div>
        )}

        {item.status === 'approved' && canProcess && (
          <div className="space-y-3 border-t border-border pt-3">
            <div className="space-y-1">
              <Label>Finanzas</Label>
              <Select value={processForm.refund_mode ?? 'none'} onChange={(event) => setProcessForm((current) => ({ ...current, refund_mode: event.target.value as 'none' | 'cash' | 'receivable' }))}>
                <option value="none">Sólo procesar devolución</option>
                {canRefund && <option value="receivable" disabled={!receivable || balanceBase <= 0}>Aplicar contra CxC</option>}
                {canRefund && <option value="cash">Reembolsar desde caja</option>}
              </Select>
            </div>
            {processForm.refund_mode === 'none' && (
              <p className="rounded border border-border bg-bg px-3 py-2 text-xs text-text-muted">
                Procesa stock/Kardex. Si la venta tiene CxC, el ajuste normal de la devolución se aplicará al saldo pendiente.
              </p>
            )}
            {processForm.refund_mode === 'receivable' && (
              <p className="rounded border border-border bg-bg px-3 py-2 text-xs text-text-muted">
                Al procesar, la devolución cubrirá hasta {formatMoney(coversDebt)} de la deuda. No saldrá dinero de caja.
              </p>
            )}
            {processForm.refund_mode === 'cash' && (
              <div className="grid gap-2">
                <Select value={processForm.refund_currency ?? 'USD'} onChange={(event) => setProcessForm((current) => ({ ...current, refund_currency: event.target.value as 'USD' | 'VES' }))}>
                  <option value="USD">USD</option>
                  <option value="VES">VES</option>
                </Select>
                <Input type="number" min="0" value={processForm.refund_amount ?? suggestedRefund} onChange={(event) => setProcessForm((current) => ({ ...current, refund_amount: Number(event.target.value || 0) }))} />
                {processForm.refund_currency === 'VES' && (
                  <p className="text-xs text-text-muted">
                    {activeRate ? `Se usará ${activeRate.exchange_rate_type_code} @ ${Number(activeRate.rate).toLocaleString('es-VE')}.` : 'Sin tasa activa USD/VES.'}
                  </p>
                )}
                <Select value={processForm.refund_method ?? 'cash'} onChange={(event) => setProcessForm((current) => ({ ...current, refund_method: event.target.value }))}>
                  <option value="cash">Efectivo</option>
                  <option value="card">Tarjeta</option>
                  <option value="mobile_payment">Pago móvil</option>
                  <option value="transfer">Transferencia</option>
                  <option value="zelle">Zelle</option>
                  <option value="other">Otro</option>
                </Select>
                <p className="text-xs text-text-muted">{activeSession ? `Caja: ${activeSession.cash_register?.name ?? activeSession.id}` : 'No hay caja abierta para reembolso.'}</p>
                <Input value={processForm.refund_reference ?? ''} onChange={(event) => setProcessForm((current) => ({ ...current, refund_reference: event.target.value }))} placeholder="Referencia" />
              </div>
            )}
            <Textarea value={processForm.process_notes ?? ''} onChange={(event) => setProcessForm((current) => ({ ...current, process_notes: event.target.value }))} placeholder="Notas de proceso" />
            <Button className="w-full" loading={process.isPending} onClick={() => void handleProcess()}>Procesar devolución</Button>
          </div>
        )}
      </section>
    </div>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase text-text-muted">{label}</dt>
      <dd className="font-medium text-text-primary">{value}</dd>
    </div>
  );
}

function InfoTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded border border-border bg-surface px-3 py-2">
      <div className="text-xs uppercase text-text-muted">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}
