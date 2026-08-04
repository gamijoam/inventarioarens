import { useState, type ReactNode } from 'react';
import { AlertTriangle, ChevronDown, ChevronRight, RefreshCw, Search } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';

import {
  useCashSessions as useCashSessionsReport,
  type CashSessions,
  type ReportFilters,
} from '@/features/reports/api';
import { useCashSessionDetail, useReviewCashSession, type CashRegisterSessionDetail } from './api';
import { cashMovementMethodLabel, cashMovementTypeLabel } from '@/features/reports/movementLabels';

type CommandCenterProps = {
  branches: Array<{ id: number; name: string; code: string }>;
  registers: Array<{ id: number; name: string; code?: string | null; branch_id?: number | null }>;
};

const today = new Date().toISOString().slice(0, 10);

export function CashRegisterCommandCenter({ branches, registers }: CommandCenterProps) {
  const canView = useCan(PERMISSIONS.REPORTS_VIEW) || useCan(PERMISSIONS.REPORTS_CASH_VIEW);
  const [filters, setFilters] = useState<ReportFilters>({ date: today, status: 'all', limit: 100 });
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const report = useCashSessionsReport(filters, canView);

  if (!canView) return null;

  function updateFilter<K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) {
    setFilters((current) => ({ ...current, [key]: value }));
  }

  function clearFilters() {
    setFilters({ date: today, status: 'all', limit: 100 });
  }

  const data = report.data;

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <CardTitle className="flex items-center gap-2">
              <Search className="text-primary size-4" /> Centro de control
            </CardTitle>
            <p className="text-text-muted mt-1 text-sm">
              Supervisa turnos, diferencias y actividad de todas las cajas.
            </p>
          </div>
          <Button
            size="sm"
            variant="outline"
            onClick={() => void report.refetch()}
            disabled={report.isFetching}
          >
            <RefreshCw className={report.isFetching ? 'size-4 animate-spin' : 'size-4'} />{' '}
            Actualizar
          </Button>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <Field label="Fecha">
              <Input
                type="date"
                value={filters.date ?? ''}
                onChange={(event) => updateFilter('date', event.target.value || undefined)}
              />
            </Field>
            <Field label="Estado">
              <Select
                value={filters.status ?? 'all'}
                onChange={(event) => updateFilter('status', event.target.value)}
              >
                <option value="all">Todos los turnos</option>
                <option value="open">Abiertos</option>
                <option value="closed">Cerrados</option>
              </Select>
            </Field>
            <Field label="Revisión">
              <Select
                value={filters.review_status ?? ''}
                onChange={(event) => updateFilter('review_status', event.target.value || undefined)}
              >
                <option value="">Todos</option>
                <option value="pending">Pendientes</option>
                <option value="approved">Aprobados</option>
                <option value="rejected">Rechazados</option>
              </Select>
            </Field>
            <Field label="Sucursal">
              <Select
                value={String(filters.branch_id ?? '')}
                onChange={(event) =>
                  updateFilter(
                    'branch_id',
                    event.target.value ? Number(event.target.value) : undefined,
                  )
                }
              >
                <option value="">Todas las sucursales</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {branch.code} - {branch.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Caja física">
              <Select
                value={String(filters.cash_register_id ?? '')}
                onChange={(event) =>
                  updateFilter(
                    'cash_register_id',
                    event.target.value ? Number(event.target.value) : undefined,
                  )
                }
              >
                <option value="">Todas las cajas</option>
                {registers.map((register) => (
                  <option key={register.id} value={register.id}>
                    {register.code ?? register.id} - {register.name}
                  </option>
                ))}
              </Select>
            </Field>
            <div className="flex items-end">
              <Button variant="ghost" className="w-full" onClick={clearFilters}>
                Limpiar filtros
              </Button>
            </div>
          </div>

          {report.isLoading ? (
            <CommandCenterLoading />
          ) : report.isError ? (
            <CommandCenterError />
          ) : data ? (
            <CommandCenterContent data={data} expandedId={expandedId} onExpand={setExpandedId} />
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}

function CommandCenterContent({
  data,
  expandedId,
  onExpand,
}: {
  data: CashSessions;
  expandedId: number | null;
  onExpand: (id: number | null) => void;
}) {
  const detail = useCashSessionDetail(expandedId);
  const review = useReviewCashSession();
  const canReview = useCan(PERMISSIONS.CASH_REGISTER_REVIEW);
  const [reviewNotes, setReviewNotes] = useState('');
  const difference = data.summary.difference_base_amount;
  const attentionCount = data.rows.filter(
    (row) => row.status === 'open' || Math.abs(row.difference_base_amount ?? 0) > 0.009,
  ).length;

  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <Metric
          label="Turnos abiertos"
          value={String(data.summary.open_count)}
          tone={data.summary.open_count ? 'warning' : 'default'}
        />
        <Metric label="Turnos cerrados" value={String(data.summary.closed_count)} />
        <Metric label="Esperado USD" value={formatMoney(data.summary.expected_base_amount)} />
        <Metric
          label="Esperado VES"
          value={formatMoney({
            amount: String(data.summary.expected_local_amount),
            currency: 'VES',
          })}
        />
        <Metric
          label="Diferencia USD"
          value={formatMoney(difference)}
          tone={Math.abs(difference) > 0.009 ? 'danger' : 'success'}
        />
      </div>

      <div className="border-border bg-bg/40 flex flex-wrap items-center justify-between gap-2 rounded border px-3 py-2 text-sm">
        <span className="text-text-muted">
          {data.rows.length} turnos encontrados · período {data.period.from} al {data.period.to}
        </span>
        <Badge variant={attentionCount ? 'warning' : 'success'}>
          {attentionCount ? `${attentionCount} requieren atención` : 'Sin alertas visibles'}
        </Badge>
      </div>

      <div className="border-border overflow-x-auto rounded border">
        <table className="w-full min-w-[920px] text-left text-sm">
          <thead className="bg-bg/70 text-text-muted text-xs tracking-wide uppercase">
            <tr>
              <th className="w-8 px-3 py-3" />
              <th className="px-3 py-3">Caja / sucursal</th>
              <th className="px-3 py-3">Cajero</th>
              <th className="px-3 py-3">Turno</th>
              <th className="px-3 py-3 text-right">Esperado USD</th>
              <th className="px-3 py-3 text-right">Contado USD</th>
              <th className="px-3 py-3 text-right">Diferencia</th>
              <th className="px-3 py-3">Actividad</th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {data.rows.map((row) => {
              const expanded = expandedId === row.id;
              const rowDifference = row.difference_cash_usd ?? row.difference_base_amount ?? 0;
              return (
                <tr key={row.id} className="hover:bg-bg/40 align-top">
                  <td colSpan={8} className="p-0">
                    <button
                      type="button"
                      className="grid w-full grid-cols-[32px_1.4fr_1fr_1fr_1fr_1fr_1fr_1fr] items-center text-left"
                      onClick={() => onExpand(expanded ? null : row.id)}
                    >
                      <span className="text-text-muted px-3 py-3">
                        {expanded ? (
                          <ChevronDown className="size-4" />
                        ) : (
                          <ChevronRight className="size-4" />
                        )}
                      </span>
                      <span className="px-3 py-3">
                        <strong className="text-text-primary block">
                          {row.cash_register_name ?? 'Caja sin nombre'}
                        </strong>
                        <span className="text-text-muted text-xs">
                          {row.branch_name ?? 'Sin sucursal'}
                        </span>
                      </span>
                      <span className="px-3 py-3">{row.cashier_name ?? 'Sin cajero'}</span>
                      <span className="px-3 py-3">
                        <Badge variant={row.status === 'open' ? 'warning' : 'success'}>
                          {row.status === 'open' ? 'Abierto' : 'Cerrado'}
                        </Badge>
                      </span>
                      <span className="px-3 py-3 text-right font-medium">
                        {formatMoney(row.expected_cash_usd ?? row.expected_base_amount)}
                      </span>
                      <span className="px-3 py-3 text-right">
                        {formatMoney(row.counted_cash_usd ?? row.counted_base_amount)}
                      </span>
                      <span
                        className={`px-3 py-3 text-right font-semibold ${Math.abs(rowDifference) > 0.009 ? 'text-danger' : 'text-success'}`}
                      >
                        {formatMoney(row.difference_cash_usd ?? row.difference_base_amount)}
                      </span>
                      <span className="text-text-muted px-3 py-3 text-xs">
                        {row.closed_at
                          ? formatDateTime(row.closed_at)
                          : `Abierto ${formatDateTime(row.opened_at)}`}
                      </span>
                    </button>
                    {expanded && (
                      <SessionBreakdown
                        row={row}
                        detail={detail.data}
                        loading={detail.isLoading}
                        reviewNotes={reviewNotes}
                        reviewPending={review.isPending}
                        onReviewNotes={setReviewNotes}
                        canReview={canReview}
                        onReview={(status) =>
                          review.mutate(
                            {
                              sessionId: row.id,
                              status,
                              review_notes: reviewNotes.trim() || undefined,
                            },
                            {
                              onSuccess: () => {
                                toast.success(
                                  status === 'approved' ? 'Cierre aprobado.' : 'Cierre rechazado.',
                                );
                                setReviewNotes('');
                              },
                              onError: () => toast.error('No se pudo actualizar la revisión.'),
                            },
                          )
                        }
                      />
                    )}
                  </td>
                </tr>
              );
            })}
            {!data.rows.length && (
              <tr>
                <td colSpan={8} className="text-text-muted px-4 py-10 text-center">
                  No hay turnos para los filtros seleccionados.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

function SessionBreakdown({
  row,
  detail,
  loading,
  reviewNotes,
  reviewPending,
  canReview,
  onReviewNotes,
  onReview,
}: {
  row: CashSessions['rows'][number];
  detail: CashRegisterSessionDetail | undefined;
  loading: boolean;
  reviewNotes: string;
  reviewPending: boolean;
  canReview: boolean;
  onReviewNotes: (value: string) => void;
  onReview: (status: 'approved' | 'rejected') => void;
}) {
  return (
    <div className="border-border bg-bg/30 grid gap-4 border-t px-10 py-4 lg:grid-cols-[1fr_1fr]">
      <div>
        <p className="text-text-muted mb-2 text-xs font-semibold tracking-wide uppercase">
          Resumen del turno #{row.id}
        </p>
        <div className="grid gap-2 text-sm sm:grid-cols-2">
          <Detail label="Apertura USD" value={formatMoney(row.opening_base_amount)} />
          <Detail label="Esperado físico USD" value={formatMoney(row.expected_cash_usd ?? row.expected_base_amount)} />
          <Detail label="Contado físico USD" value={formatMoney(row.counted_cash_usd ?? row.counted_base_amount)} />
          <Detail label="Diferencia física USD" value={formatMoney(row.difference_cash_usd ?? row.difference_base_amount)} />
          <Detail
            label="Apertura VES"
            value={formatMoney({ amount: String(row.opening_local_amount), currency: 'VES' })}
          />
          <Detail
            label="Contado físico VES"
            value={formatMoney(
                row.counted_cash_ves === null || row.counted_cash_ves === undefined
                  ? null
                : { amount: String(row.counted_cash_ves), currency: 'VES' },
            )}
          />
          <Detail label="Esperado físico VES" value={formatMoney({ amount: String(row.expected_cash_ves ?? row.expected_local_amount), currency: 'VES' })} />
          <Detail label="Diferencia física VES" value={formatMoney({ amount: String(row.difference_cash_ves ?? row.difference_local_amount), currency: 'VES' })} />
          <Detail
            label="Movimientos"
            value={
              loading
                ? 'Cargando...'
                : String(detail?.summary.movement_count ?? row.movements.length)
            }
          />
          <Detail
            label="Ventas POS"
            value={loading ? 'Cargando...' : `${detail?.summary.pos_paid_order_count ?? 0} pagadas`}
          />
          <Detail
            label="Cobros CxC"
            value={formatMoney(detail?.summary.receivable_collections_base_amount)}
          />
          <Detail
            label="Pagos CxP"
            value={formatMoney(detail?.summary.payable_payments_base_amount)}
          />
          <Detail
            label="Movimientos manuales"
            value={String(detail?.summary.manual_movement_count ?? 0)}
          />
        </div>
      </div>
      <div>
        <div className="mb-2 flex items-center justify-between gap-2">
          <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
            Métodos de pago
          </p>
          <Badge
            variant={
              row.review_status === 'approved'
                ? 'success'
                : row.review_status === 'rejected'
                  ? 'danger'
                  : 'warning'
            }
          >
            {row.review_status === 'approved'
              ? 'Aprobado'
              : row.review_status === 'rejected'
                ? 'Rechazado'
                : 'Pendiente de revisión'}
          </Badge>
        </div>
        {detail?.payment_breakdown.length ? (
          <div className="space-y-1 text-sm">
            {detail.payment_breakdown.map((payment) => (
              <div
                key={`${payment.method}-${payment.currency}`}
                className="flex justify-between gap-3"
              >
                <span className="text-text-muted">
                  {payment.name} · {payment.payments_count}
                </span>
                <strong>{formatMoney(payment.amount_base)}</strong>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-text-muted text-sm">No hay pagos POS capturados.</p>
        )}
        <p className="text-text-muted mt-4 mb-2 text-xs font-semibold tracking-wide uppercase">
          Actividad reciente
        </p>
        {row.movements.length ? (
          <div className="space-y-1 text-sm">
            {row.movements
              .slice(-5)
              .reverse()
              .map((movement) => (
                <div key={movement.id} className="flex justify-between gap-3">
                  <span className="text-text-muted">
                    {cashMovementTypeLabel(movement.type)} · {cashMovementMethodLabel(movement.method)}
                  </span>
                  <strong>{formatMoney(movement.amount_base)}</strong>
                </div>
              ))}
          </div>
        ) : (
          <p className="text-text-muted text-sm">No hay movimientos registrados.</p>
        )}
        {Math.abs(row.difference_base_amount ?? 0) > 0.009 && (
          <p className="border-warning/40 bg-warning/10 text-warning mt-3 flex items-center gap-2 rounded border p-2 text-xs">
            <AlertTriangle className="size-4 shrink-0" /> Requiere revisión del responsable.
          </p>
        )}
        {canReview && row.status === 'closed' && row.review_status !== 'approved' && (
          <div className="border-border mt-4 space-y-2 border-t pt-3">
            <Input
              value={reviewNotes}
              onChange={(event) => onReviewNotes(event.target.value)}
              placeholder="Nota de revisión (obligatoria al rechazar)"
            />
            <div className="flex flex-wrap gap-2">
              <Button size="sm" disabled={reviewPending} onClick={() => onReview('approved')}>
                Aprobar cierre
              </Button>
              <Button
                size="sm"
                variant="danger"
                disabled={reviewPending || !reviewNotes.trim()}
                onClick={() => onReview('rejected')}
              >
                Rechazar cierre
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function Metric({
  label,
  value,
  tone = 'default',
}: {
  label: string;
  value: string;
  tone?: 'default' | 'warning' | 'danger' | 'success';
}) {
  return (
    <div className="border-border bg-surface/70 rounded border p-3">
      <p className="text-text-muted text-xs tracking-wide uppercase">{label}</p>
      <p
        className={`mt-1 text-lg font-semibold ${tone === 'danger' ? 'text-danger' : tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}
      >
        {value}
      </p>
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="space-y-1">
      <span className="text-text-muted block text-xs font-medium">{label}</span>
      {children}
    </label>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-text-muted text-xs">{label}</p>
      <p className="text-text-primary font-medium">{value}</p>
    </div>
  );
}

function CommandCenterLoading() {
  return (
    <div className="border-border text-text-muted rounded border p-6 text-sm">
      Cargando supervisión de cajas...
    </div>
  );
}

function CommandCenterError() {
  return (
    <div className="border-danger/40 bg-danger/5 text-danger rounded border p-6 text-sm">
      No se pudo cargar la supervisión de cajas. Actualiza para intentar nuevamente.
    </div>
  );
}
