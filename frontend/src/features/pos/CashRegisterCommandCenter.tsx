import { useMemo, useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronRight, FileText, RefreshCw, Search } from 'lucide-react';
import { toast } from '@/lib/toast';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { ReportZDialog } from '@/features/cash-register/ReportZDialog';
import {
  type CashRegisterSession,
  type CashRegisterSessionDetail,
  useCashSessionDetail,
  useCashSessionsPage,
  useReviewCashSession,
} from './api';

type SessionStatusFilter = 'all' | 'open' | 'closed' | 'cancelled';

const emptySessions: CashRegisterSession[] = [];

interface CashRegisterCommandCenterProps {
  branches: { id: number; name: string; code?: string | null }[];
  registers: { id: number; name: string; code?: string | null; branch_id?: number | null }[];
  loadingOptions?: boolean;
}

export function CashRegisterCommandCenter({
  branches,
  registers,
  loadingOptions = false,
}: CashRegisterCommandCenterProps) {
  const canView = useCan(PERMISSIONS.CASH_REGISTER_VIEW);
  const canReview = useCan(PERMISSIONS.CASH_REGISTER_REVIEW);
  const [status, setStatus] = useState<SessionStatusFilter>('all');
  const [branchId, setBranchId] = useState('');
  const [registerId, setRegisterId] = useState('');
  const [reviewStatus, setReviewStatus] = useState('all');
  const [search, setSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [reviewNotes, setReviewNotes] = useState('');
  const [reportSessionId, setReportSessionId] = useState<number | null>(null);
  const perPage = 50;
  const sessionsQuery = useCashSessionsPage(
    {
      page,
      perPage,
      status: status === 'all' ? undefined : status,
      branchId: branchId ? Number(branchId) : undefined,
      registerId: registerId ? Number(registerId) : undefined,
    },
    { enabled: canView },
  );
  const sessions = sessionsQuery.data?.data ?? emptySessions;
  const meta = sessionsQuery.data?.meta;
  const detail = useCashSessionDetail(expandedId);
  const review = useReviewCashSession();

  const filteredSessions = useMemo(() => {
    const query = normalize(search);
    return sessions.filter((session) => {
      const searchMatch =
        !query ||
        normalize(String(session.id)).includes(query) ||
        normalize(session.cash_register?.name ?? '').includes(query) ||
        normalize(session.branch?.name ?? '').includes(query) ||
        normalize(session.cashier?.name ?? '').includes(query);
      const reviewMatch =
        reviewStatus === 'all' ||
        (session.status === 'closed' &&
          (session.review_status ?? 'pending') === reviewStatus);
      const openedDate = session.opened_at?.slice(0, 10) ?? '';
      const closedDate = session.closed_at?.slice(0, 10) ?? openedDate;
      const dateMatch =
        (!dateFrom || closedDate >= dateFrom) &&
        (!dateTo || closedDate <= dateTo);
      return searchMatch && reviewMatch && dateMatch;
    });
  }, [dateFrom, dateTo, reviewStatus, search, sessions]);

  function changeFilter<T>(setter: (value: T) => void, value: T): void {
    setter(value);
    setPage(1);
    setExpandedId(null);
    setReviewNotes('');
  }

  function toggleSession(sessionId: number): void {
    setExpandedId((current) => (current === sessionId ? null : sessionId));
    setReviewNotes('');
  }

  function clearFilters(): void {
    setStatus('all');
    setBranchId('');
    setRegisterId('');
    setReviewStatus('all');
    setSearch('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
    setExpandedId(null);
    setReviewNotes('');
  }

  function submitReview(session: CashRegisterSession, nextStatus: 'approved' | 'rejected'): void {
    review.mutate(
      {
        sessionId: session.id,
        status: nextStatus,
        review_notes: reviewNotes.trim() || undefined,
      },
      {
        onSuccess: () => {
          toast.success(nextStatus === 'approved' ? 'Cierre aprobado.' : 'Cierre rechazado.');
          setReviewNotes('');
          void sessionsQuery.refetch();
        },
        onError: () => toast.error('No se pudo actualizar la revisión.'),
      },
    );
  }

  if (!canView) {
    return (
      <div className="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm text-warning" role="status">
        No tienes permiso para consultar los turnos de caja.
      </div>
    );
  }

  const total = meta?.total ?? sessions.length;
  const firstResult = total === 0 ? 0 : (page - 1) * perPage + 1;
  const lastResult = Math.min(page * perPage, total);
  const lastPage = meta?.last_page ?? 1;

  return (
    <Card>
      <CardHeader className="gap-3 pb-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <CardTitle>Turnos</CardTitle>
          <CardDescription>
            Revisa turnos abiertos y cierres desde una sola lista.
          </CardDescription>
        </div>
        <Button
          variant="outline"
          className="min-h-11 w-full sm:w-auto"
          onClick={() => void sessionsQuery.refetch()}
          disabled={sessionsQuery.isFetching}
        >
          <RefreshCw className={sessionsQuery.isFetching ? 'size-4 animate-spin' : 'size-4'} />
          Actualizar
        </Button>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
          <label className="relative sm:col-span-2">
            <span className="sr-only">Buscar turnos de esta página</span>
            <Search className="pointer-events-none absolute top-3 left-3 size-4 text-text-muted" aria-hidden="true" />
            <Input
              aria-label="Buscar turnos de esta página"
              className="min-h-11 pl-9"
              value={search}
              onChange={(event) => changeFilter(setSearch, event.target.value)}
              placeholder="Buscar caja, sucursal, cajero o número"
            />
          </label>
          <Field label="Estado">
            <Select
              aria-label="Estado de los turnos"
              className="min-h-11"
              value={status}
              onChange={(event) => changeFilter(setStatus, event.target.value as SessionStatusFilter)}
            >
              <option value="all">Todos los turnos</option>
              <option value="open">Abiertos</option>
              <option value="closed">Cerrados</option>
              <option value="cancelled">Cancelados</option>
            </Select>
          </Field>
          <Field label="Revisión">
            <Select
              aria-label="Estado de revisión"
              className="min-h-11"
              value={reviewStatus}
              onChange={(event) => changeFilter(setReviewStatus, event.target.value)}
            >
              <option value="all">Todas</option>
              <option value="pending">Pendientes</option>
              <option value="approved">Aprobadas</option>
              <option value="rejected">Rechazadas</option>
            </Select>
          </Field>
          <Field label="Sucursal">
            <Select
              aria-label="Sucursal"
              className="min-h-11"
              value={branchId}
              onChange={(event) => changeFilter(setBranchId, event.target.value)}
              disabled={loadingOptions}
            >
              <option value="">Todas las sucursales</option>
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.code ? branch.code + ' · ' : ''}{branch.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Caja física">
            <Select
              aria-label="Caja física"
              className="min-h-11"
              value={registerId}
              onChange={(event) => changeFilter(setRegisterId, event.target.value)}
              disabled={loadingOptions}
            >
              <option value="">Todas las cajas</option>
              {registers.map((register) => (
                <option key={register.id} value={register.id}>
                  {register.code ? register.code + ' · ' : ''}{register.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Desde">
            <Input
              aria-label="Desde"
              className="min-h-11"
              type="date"
              value={dateFrom}
              onChange={(event) => changeFilter(setDateFrom, event.target.value)}
            />
          </Field>
          <Field label="Hasta">
            <Input
              aria-label="Hasta"
              className="min-h-11"
              type="date"
              value={dateTo}
              onChange={(event) => changeFilter(setDateTo, event.target.value)}
            />
          </Field>
        </div>

        <p className="text-xs text-text-muted">
          Estado, sucursal y caja consultan el historial; búsqueda, revisión y fechas filtran la página actual.
        </p>

        <div className="flex flex-col gap-2 rounded border border-border bg-bg/40 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
          <span className="text-text-muted">
            {filteredSessions.length} de {sessions.length} turnos en esta página · {firstResult}-{lastResult} de {total} en el historial
          </span>
          <div className="flex gap-2">
            <Button
              variant="ghost"
              className="min-h-11 flex-1 sm:flex-none"
              onClick={clearFilters}
              disabled={page === 1 && status === 'all' && !branchId && !registerId && reviewStatus === 'all' && !search && !dateFrom && !dateTo}
            >
              Limpiar filtros
            </Button>
            <Button
              variant="outline"
              className="min-h-11"
              onClick={() => { setPage((current) => Math.max(1, current - 1)); setExpandedId(null); setReviewNotes(''); }}
              disabled={page <= 1 || sessionsQuery.isFetching}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              className="min-h-11"
              onClick={() => { setPage((current) => Math.min(lastPage, current + 1)); setExpandedId(null); setReviewNotes(''); }}
              disabled={page >= lastPage || sessionsQuery.isFetching}
            >
              Siguiente
            </Button>
          </div>
        </div>

        {sessionsQuery.isLoading ? (
          <div className="rounded border border-border p-5 text-sm text-text-muted" role="status">
            Cargando turnos...
          </div>
        ) : sessionsQuery.isError ? (
          <div className="flex flex-col gap-3 rounded border border-danger/40 bg-danger/5 p-4 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert">
            <span>No se pudieron cargar los turnos. Intenta nuevamente.</span>
            <Button variant="outline" className="min-h-11" onClick={() => void sessionsQuery.refetch()}>
              Reintentar
            </Button>
          </div>
        ) : filteredSessions.length === 0 ? (
          <div className="rounded border border-dashed border-border p-6 text-center text-sm text-text-muted">
            {sessions.length === 0 ? 'No hay turnos registrados.' : 'No hay turnos que coincidan con los filtros de esta página.'}
          </div>
        ) : (
          <div className="space-y-2">
            {filteredSessions.map((session) => (
              <SessionCard
                key={session.id}
                session={session}
                expanded={expandedId === session.id}
                canReview={canReview}
                detail={expandedId === session.id ? detail.data : undefined}
                detailLoading={expandedId === session.id && detail.isLoading}
                detailError={expandedId === session.id && detail.isError}
                reviewNotes={reviewNotes}
                reviewPending={review.isPending}
                onToggle={() => toggleSession(session.id)}
                onReviewNotes={setReviewNotes}
                onReview={(nextStatus) => submitReview(session, nextStatus)}
                onReport={() => setReportSessionId(session.id)}
              />
            ))}
          </div>
        )}
      </CardContent>

      {reportSessionId !== null && (
        <ReportZDialog
          sessionId={reportSessionId}
          open
          onOpenChange={(open) => { if (!open) setReportSessionId(null); }}
        />
      )}
    </Card>
  );
}

function SessionCard({
  session,
  expanded,
  canReview,
  detail,
  detailLoading,
  detailError,
  reviewNotes,
  reviewPending,
  onToggle,
  onReviewNotes,
  onReview,
  onReport,
}: {
  session: CashRegisterSession;
  expanded: boolean;
  canReview: boolean;
  detail: CashRegisterSessionDetail | undefined;
  detailLoading: boolean;
  detailError: boolean;
  reviewNotes: string;
  reviewPending: boolean;
  onToggle: () => void;
  onReviewNotes: (value: string) => void;
  onReview: (status: 'approved' | 'rejected') => void;
  onReport: () => void;
}) {
  const differenceUsd = session.difference_cash_usd ?? session.difference_base_amount;
  const differenceVes = session.difference_cash_ves ?? session.difference_local_amount;
  const expectedUsd = session.expected_cash_usd ?? session.expected_base_amount;
  const open = session.status === 'open';
  const canceled = session.status === 'cancelled';

  return (
    <article className="overflow-hidden rounded-lg border border-border bg-surface">
      <button
        type="button"
        className="grid min-h-11 w-full min-w-0 gap-3 p-3 text-left transition-colors hover:bg-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary sm:grid-cols-[minmax(0,1fr)_auto]"
        aria-label={'Turno #' + session.id + ', ' + (session.cash_register?.name ?? 'Caja física')}
        aria-expanded={expanded}
        onClick={onToggle}
      >
        <span className="flex min-w-0 items-start gap-3">
          <span className="mt-1 shrink-0 text-text-muted" aria-hidden="true">
            {expanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
          </span>
          <span className="min-w-0 flex-1">
            <span className="flex flex-wrap items-center gap-2">
              <strong className="break-words text-text-primary">
                {session.cash_register?.name ?? 'Caja física'}
              </strong>
              <Badge variant={open ? 'warning' : canceled ? 'default' : 'success'}>
                {open ? 'Abierto' : canceled ? 'Cancelado' : 'Cerrado'}
              </Badge>
              {!open && (
                <Badge variant={reviewVariant(session.review_status)}>
                  {reviewLabel(session.review_status)}
                </Badge>
              )}
            </span>
            <span className="mt-1 block break-words text-xs text-text-muted">
              {session.branch?.name ?? 'Sin sucursal'} · {session.cashier?.name ?? 'Sin cajero'} · Turno #{session.id}
            </span>
          </span>
        </span>
        <span className="grid grid-cols-2 gap-x-4 gap-y-1 text-left text-xs sm:min-w-56 sm:text-right">
          <span className="text-text-muted">{open ? 'Esperado USD' : 'Diferencia USD'}</span>
          <strong className={differenceUsd != null && Math.abs(Number(differenceUsd)) >= 0.01 ? 'text-danger' : 'text-text-primary'}>
            {open ? moneyUsd(expectedUsd) : optionalUsd(differenceUsd)}
          </strong>
          {!open && (
            <>
              <span className="text-text-muted">Diferencia Bs</span>
              <strong className={differenceVes != null && Math.abs(Number(differenceVes)) >= 0.01 ? 'text-danger' : 'text-text-primary'}>
                {optionalVes(differenceVes)}
              </strong>
            </>
          )}
        </span>
      </button>

      {expanded && (
        <div className="border-t border-border bg-bg/30 p-3 sm:p-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <div>
              <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-text-muted">
                Resumen del turno #{session.id}
              </p>
              {detailLoading ? (
                <p className="text-sm text-text-muted" role="status">Cargando el detalle...</p>
              ) : detailError ? (
                <p className="text-sm text-danger" role="alert">No se pudo cargar el detalle del turno.</p>
              ) : (
                <>
                  <dl className="grid grid-cols-2 gap-x-3 gap-y-3 text-sm">
                    <Detail label="Fondo USD" value={moneyUsd(session.opening_base_amount)} />
                    <Detail label="Fondo Bs" value={moneyVes(session.opening_local_amount)} />
                    <Detail label="Esperado USD" value={optionalUsd(expectedUsd)} />
                    <Detail label="Esperado Bs" value={optionalVes(session.expected_cash_ves ?? session.expected_local_amount)} />
                    <Detail label="Contado USD" value={optionalUsd(session.counted_cash_usd ?? session.counted_base_amount)} />
                    <Detail label="Contado Bs" value={optionalVes(session.counted_cash_ves ?? session.counted_local_amount)} />
                    <Detail label="Diferencia USD" value={optionalUsd(differenceUsd)} />
                    <Detail label="Diferencia Bs" value={optionalVes(differenceVes)} />
                    <Detail label="Apertura" value={formatDate(session.opened_at)} />
                    <Detail label="Cierre" value={formatDate(session.closed_at)} />
                    <Detail label="Movimientos" value={String(detail?.summary.movement_count ?? session.movements?.length ?? 0)} />
                    <Detail label="Ventas POS" value={detail ? String(detail.summary.pos_paid_order_count) : '—'} />
                  </dl>
                  {detail?.summary && (
                    <div className="mt-3 grid grid-cols-2 gap-3 border-t border-border pt-3 text-sm">
                      <Detail label="Cobros CxC" value={moneyUsd(detail.summary.receivable_collections_base_amount)} />
                      <Detail label="Pagos CxP" value={moneyUsd(detail.summary.payable_payments_base_amount)} />
                    </div>
                  )}
                </>
              )}
            </div>
            <div className="space-y-3">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">Actividad reciente</p>
                {session.movements?.length ? (
                  <ul className="space-y-1 text-sm">
                    {session.movements.slice(-5).reverse().map((movement) => (
                      <li key={movement.id} className="flex min-w-0 justify-between gap-3">
                        <span className="min-w-0 break-words text-text-muted">
                          {movementTypeLabel(movement.type)} · {movement.notes ?? movementMethodLabel(movement.method)}
                        </span>
                        <strong className="shrink-0">{moneyUsd(movement.amount_base ?? movement.amount)}</strong>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-text-muted">No hay movimientos registrados.</p>
                )}
              </div>
              {session.closing_notes && (
                <p className="rounded border border-border p-2 text-sm text-text-muted">
                  Nota de cierre: {session.closing_notes}
                </p>
              )}
              {session.review_notes && (
                <p className="rounded border border-border p-2 text-sm text-text-muted">
                  Nota de revisión: {session.review_notes}
                </p>
              )}
              {Math.abs(Number(differenceUsd ?? 0)) >= 0.01 && (
                <p className="flex items-center gap-2 rounded border border-warning/40 bg-warning/10 p-2 text-xs text-warning">
                  <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                  El cierre presenta una diferencia que requiere revisión.
                </p>
              )}
              {canReview && session.status === 'closed' && session.review_status !== 'approved' && (
                <div className="space-y-2 border-t border-border pt-3">
                  <label className="block space-y-1">
                    <span className="text-xs font-medium text-text-muted">Nota de revisión</span>
                    <Input
                      aria-label="Nota de revisión"
                      className="min-h-11"
                      value={reviewNotes}
                      onChange={(event) => onReviewNotes(event.target.value)}
                      placeholder="Obligatoria al rechazar"
                    />
                  </label>
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <Button className="min-h-11 flex-1" disabled={reviewPending} onClick={() => onReview('approved')}>
                      Aprobar cierre
                    </Button>
                    <Button className="min-h-11 flex-1" variant="danger" disabled={reviewPending || !reviewNotes.trim()} onClick={() => onReview('rejected')}>
                      Rechazar cierre
                    </Button>
                  </div>
                </div>
              )}
              {session.status === 'closed' && (
                <div className="border-t border-border pt-3">
                  <Button variant="outline" className="min-h-11 w-full sm:w-auto" onClick={onReport}>
                    <FileText className="size-4" /> Reporte Z
                  </Button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </article>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1">
      <span className="text-xs font-medium text-text-muted">{label}</span>
      {children}
    </label>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-text-muted">{label}</dt>
      <dd className="break-words font-medium text-text-primary">{value}</dd>
    </div>
  );
}

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function reviewLabel(status?: string): string {
  if (status === 'approved') return 'Aprobado';
  if (status === 'rejected') return 'Rechazado';
  return 'Pendiente de revisión';
}

function reviewVariant(status?: string): 'success' | 'danger' | 'warning' {
  if (status === 'approved') return 'success';
  if (status === 'rejected') return 'danger';
  return 'warning';
}

function movementTypeLabel(type: string): string {
  if (type === 'inflow') return 'Entrada';
  if (type === 'outflow') return 'Salida';
  if (type === 'adjustment') return 'Ajuste';
  if (type === 'pos_payment') return 'Pago POS';
  return type;
}

function movementMethodLabel(method?: string | null): string {
  if (method === 'cash') return 'Efectivo';
  if (method === 'card') return 'Tarjeta';
  if (method === 'mobile_payment') return 'Pago móvil';
  if (method === 'transfer') return 'Transferencia';
  return method ?? 'Movimiento';
}

function formatDate(value?: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-VE');
}

function moneyUsd(value: number | string | null | undefined): string {
  return '$' + Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function moneyVes(value: number | string | null | undefined): string {
  return 'Bs ' + Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function optionalUsd(value: number | string | null | undefined): string {
  return value == null ? '—' : moneyUsd(value);
}

function optionalVes(value: number | string | null | undefined): string {
  return value == null ? '—' : moneyVes(value);
}
