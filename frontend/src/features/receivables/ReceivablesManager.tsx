import { useMemo, useState } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, CreditCard, Loader2, Search, Wallet, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useCashSessions, useCurrentExchangeRatesForPos, usePaymentMethods, type CurrentExchangeRate } from '@/features/pos/api';
import { useCollectReceivable, useReceivable, useReceivables, type ReceivableListFilters } from './api';
import { activeUsdVesRate, currentLocalBalance, numberLabel, rateLabel } from './currentBalance';
import { RECEIVABLE_STATUS_LABELS, type CollectReceivableValues, type Receivable, type ReceivableStatus } from './schemas';

const STATUS_OPTIONS: { value: ReceivableListFilters['status']; label: string }[] = [
  { value: 'open', label: 'Abiertas' },
  { value: 'all', label: 'Todas' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'partial', label: 'Parciales' },
  { value: 'overdue', label: 'Vencidas' },
  { value: 'paid', label: 'Pagadas' },
];

function statusVariant(status: ReceivableStatus): 'warning' | 'success' | 'danger' | 'info' {
  if (status === 'paid') return 'success';
  if (status === 'overdue') return 'danger';
  if (status === 'partial') return 'info';
  return 'warning';
}

function money(value: number | null | undefined, currency = '$'): string {
  const n = Number(value ?? 0);
  return `${currency}${n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function dateLabel(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('es-VE');
}

function customerLabel(receivable: Receivable): string {
  if (!receivable.customer) return 'Consumidor Final';
  const doc = receivable.customer.document_number ? ` - ${receivable.customer.document_number}` : '';
  return `${receivable.customer.name}${doc}`;
}

export function ReceivablesManager() {
  const [filters, setFilters] = useState<ReceivableListFilters>({ status: 'open', page: 1, limit: 25 });
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [collecting, setCollecting] = useState<Receivable | null>(null);
  const { data, isLoading, isError, refetch } = useReceivables(filters);
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const activeRate = activeUsdVesRate(rates);
  const canCollect = useCan(PERMISSIONS.ACCOUNTS_RECEIVABLE_COLLECT);
  const receivables = data?.data ?? [];
  const meta = data?.meta;
  const totals = useMemo(() => summarize(receivables), [receivables]);

  function updateFilters(patch: Partial<ReceivableListFilters>) {
    setFilters((current) => ({ ...current, ...patch, page: 1 }));
  }

  if (isLoading && !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-3">
      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 pt-4">
          <div className="min-w-[240px] flex-1">
            <Label htmlFor="receivables-search" className="text-xs text-text-muted">Buscar</Label>
            <div className="relative mt-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
              <Input
                id="receivables-search"
                value={filters.search ?? ''}
                onChange={(event) => updateFilters({ search: event.target.value })}
                placeholder="Cliente, documento, venta o CxC"
                className="pl-9"
              />
            </div>
          </div>
          <div className="w-44">
            <Label htmlFor="receivables-status" className="text-xs text-text-muted">Estado</Label>
            <Select
              id="receivables-status"
              value={filters.status ?? 'all'}
              onChange={(event) => updateFilters({ status: event.target.value as ReceivableListFilters['status'] })}
              className="mt-1"
            >
              {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </Select>
          </div>
          <div className="w-40">
            <Label htmlFor="due-from" className="text-xs text-text-muted">Vence desde</Label>
            <Input id="due-from" type="date" value={filters.due_from ?? ''} onChange={(event) => updateFilters({ due_from: event.target.value || undefined })} className="mt-1" />
          </div>
          <div className="w-40">
            <Label htmlFor="due-to" className="text-xs text-text-muted">Vence hasta</Label>
            <Input id="due-to" type="date" value={filters.due_to ?? ''} onChange={(event) => updateFilters({ due_to: event.target.value || undefined })} className="mt-1" />
          </div>
          <Button variant="secondary" onClick={() => void refetch()}>Actualizar</Button>
        </CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-5">
        <InfoTile label="Saldo abierto" value={money(totals.balance)} />
        <InfoTile label="Vencidas" value={String(totals.overdue)} />
        <InfoTile label="Pendientes" value={String(totals.pending)} />
        <InfoTile label="Parciales" value={String(totals.partial)} />
        <InfoTile label="Pagadas" value={String(totals.paid)} />
      </div>

      {isError ? (
        <EmptyState title="No se pudo cargar CxC" description="Revisa la conexión e intenta actualizar." action={<Button onClick={() => void refetch()}>Reintentar</Button>} />
      ) : receivables.length === 0 ? (
        <EmptyState icon={<Wallet className="size-8" />} title="Sin cuentas por cobrar abiertas" description="Las ventas a crédito o saldos pendientes aparecerán aquí." />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="w-8 px-2 py-2" />
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Documento</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Cliente</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Estado</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Vence</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Original</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Cobrado</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Saldo</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Acción</th>
                </tr>
              </thead>
              <tbody>
                {receivables.map((receivable) => (
                  <ReceivableRow
                    key={receivable.id}
                    receivable={receivable}
                    activeRate={activeRate}
                    expanded={expandedId === receivable.id}
                    canCollect={canCollect}
                    onToggle={() => setExpandedId((current) => current === receivable.id ? null : receivable.id)}
                    onCollect={() => setCollecting(receivable)}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {meta && (
            <div className="flex items-center justify-between border-t border-border px-4 py-3 text-sm text-text-muted">
              <span>Página {meta.current_page} de {meta.last_page} · {meta.total} cuentas</span>
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" disabled={meta.current_page <= 1} leftIcon={<ChevronLeft className="size-4" />} onClick={() => setFilters((f) => ({ ...f, page: Math.max(1, (f.page ?? 1) - 1) }))}>Anterior</Button>
                <Button size="sm" variant="secondary" disabled={meta.current_page >= meta.last_page} rightIcon={<ChevronRight className="size-4" />} onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}>Siguiente</Button>
              </div>
            </div>
          )}
        </Card>
      )}

      {collecting && <CollectPanel receivable={collecting} activeRate={activeRate} onClose={() => setCollecting(null)} />}
    </div>
  );
}

function ReceivableRow({ receivable, activeRate, expanded, canCollect, onToggle, onCollect }: { receivable: Receivable; activeRate: CurrentExchangeRate | null; expanded: boolean; canCollect: boolean; onToggle: () => void; onCollect: () => void }) {
  const localBalance = currentLocalBalance(receivable, activeRate);

  return (
    <>
      <tr className="cursor-pointer border-b border-border hover:bg-bg/50" onClick={onToggle}>
        <td className="px-2 py-2 text-text-muted"><ChevronDown className={`size-4 transition-transform ${expanded ? '' : '-rotate-90'}`} /></td>
        <td className="px-3 py-2 font-medium">{receivable.document_number ?? `CxC #${receivable.id}`}</td>
        <td className="px-3 py-2">{customerLabel(receivable)}</td>
        <td className="px-3 py-2"><Badge variant={statusVariant(receivable.status)}>{RECEIVABLE_STATUS_LABELS[receivable.status]}</Badge></td>
        <td className="px-3 py-2 text-text-muted">{dateLabel(receivable.due_date)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{money(receivable.original_base_amount)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{money(receivable.collected_base_amount)}</td>
        <td className="px-3 py-2 text-right font-semibold tabular-nums">
          <div>{money(receivable.balance_base_amount)}</div>
          <div className="text-xs font-normal text-text-muted">
            {localBalance === null ? 'Sin tasa activa USD/VES' : `${money(localBalance, 'Bs ')} hoy`}
          </div>
        </td>
        <td className="px-3 py-2 text-right">
          <Button
            size="sm"
            variant="secondary"
            disabled={!canCollect || receivable.balance_base_amount <= 0}
            onClick={(event) => {
              event.stopPropagation();
              onCollect();
            }}
          >
            Cobrar
          </Button>
        </td>
      </tr>
      {expanded && (
        <tr className="border-b border-border bg-bg/20">
          <td colSpan={9} className="px-4 py-4">
            <ReceivableDetail receivableId={receivable.id} receivable={receivable} activeRate={activeRate} />
          </td>
        </tr>
      )}
    </>
  );
}

function ReceivableDetail({ receivableId, receivable, activeRate }: { receivableId: number; receivable: Receivable; activeRate: CurrentExchangeRate | null }) {
  const { data, isLoading } = useReceivable(receivableId);
  const current = data ?? receivable;
  const items = current.sale?.items ?? [];
  const payments = current.payments ?? [];
  const localBalance = currentLocalBalance(current, activeRate);

  if (isLoading && !data) return <Skeleton className="h-36 w-full" />;

  return (
    <div className="space-y-4" onClick={(event) => event.stopPropagation()}>
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile label="Venta" value={`#${current.sale_id}`} />
        <InfoTile label="Cliente" value={customerLabel(current)} />
        <InfoTile label="Saldo USD" value={money(current.balance_base_amount)} />
        <InfoTile label="Equivalente VES hoy" value={localBalance === null ? 'Sin tasa activa USD/VES' : `${money(localBalance, 'Bs ')} · ${rateLabel(activeRate)}`} />
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <section className="rounded border border-border bg-surface p-3">
          <h3 className="text-sm font-semibold">Items vendidos</h3>
          <div className="mt-2 max-h-48 overflow-auto">
            {items.length === 0 ? <p className="text-sm text-text-muted">Sin items cargados.</p> : items.map((item) => (
              <div key={item.id} className="flex items-center justify-between border-b border-border py-2 text-sm last:border-0">
                <div>
                  <p className="font-medium">{item.product_name ?? 'Producto'}</p>
                  <p className="text-xs text-text-muted">{item.product_sku ?? item.warehouse_name ?? '-'}</p>
                </div>
                <div className="text-right">
                  <p>{item.quantity} und.</p>
                  <p className="text-xs text-text-muted">{money(item.total_base_amount)}</p>
                </div>
              </div>
            ))}
          </div>
        </section>
        <section className="rounded border border-border bg-surface p-3">
          <h3 className="text-sm font-semibold">Historial de cobros</h3>
          <div className="mt-2 max-h-48 overflow-auto">
            {payments.length === 0 ? <p className="text-sm text-text-muted">Sin cobros registrados.</p> : payments.map((payment) => (
              <div key={payment.id} className="flex items-center justify-between border-b border-border py-2 text-sm last:border-0">
                <div>
                  <p className="font-medium">{payment.method ?? 'Cobro'}</p>
                  <p className="text-xs text-text-muted">{payment.reference ?? dateLabel(payment.paid_at)}</p>
                </div>
                <div className="text-right">
                  <p>{money(payment.amount, payment.payment_currency === 'VES' ? 'Bs ' : '$')}</p>
                  <p className="text-xs text-text-muted">Base {money(payment.amount_base)}</p>
                </div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}

function CollectPanel({ receivable, activeRate, onClose }: { receivable: Receivable; activeRate: CurrentExchangeRate | null; onClose: () => void }) {
  const collect = useCollectReceivable();
  const { data: sessions = [] } = useCashSessions();
  const { data: methods = [] } = usePaymentMethods();
  const activeSession = sessions.find((session) => session.status === 'open') ?? null;
  const activeMethods = methods.filter((method) => method.is_active !== false);
  const defaultMethod = activeMethods[0];
  const rateValue = activeRate?.rate && activeRate.rate > 0 ? activeRate.rate : null;
  const balanceBase = receivable.balance_base_amount;
  const balanceLocal = rateValue ? balanceBase * rateValue : receivable.balance_local_amount;
  const [form, setForm] = useState<CollectReceivableValues>({
    payment_currency: 'USD',
    amount: balanceBase,
    cash_register_session_id: activeSession?.id ?? 0,
    exchange_rate_type_id: null,
    exchange_rate: null,
    method: defaultMethod?.method ?? 'cash',
    reference: '',
    notes: '',
    paid_at: '',
  });
  const amountBase = form.payment_currency === 'VES' && rateValue ? form.amount / rateValue : form.amount;
  const amountLocal = form.payment_currency === 'VES' ? form.amount : rateValue ? form.amount * rateValue : 0;
  const remainingBase = Math.max(0, balanceBase - amountBase);
  const remainingLocal = rateValue ? remainingBase * rateValue : Math.max(0, receivable.balance_local_amount - amountLocal);
  const isOverpaying = amountBase > balanceBase + 0.0001;

  function patch(next: Partial<CollectReceivableValues>) {
    setForm((current) => ({ ...current, ...next }));
  }

  function setCurrency(currency: 'USD' | 'VES') {
    patch({
      payment_currency: currency,
      amount: currency === 'VES' && rateValue ? balanceBase * rateValue : balanceBase,
    });
  }

  async function submit() {
    if (!activeSession) return toast.error('Debes tener una caja abierta para registrar cobros.');
    if (isOverpaying) return toast.error('El cobro supera el saldo pendiente.');
    const payload: CollectReceivableValues = {
      ...form,
      cash_register_session_id: activeSession.id,
      exchange_rate_type_id: form.payment_currency === 'VES' ? activeRate?.exchange_rate_type_id ?? null : form.exchange_rate_type_id ?? null,
      exchange_rate: form.payment_currency === 'VES' ? activeRate?.rate ?? null : form.exchange_rate ?? null,
      paid_at: form.paid_at || null,
      reference: form.reference?.trim() || null,
      notes: form.notes?.trim() || null,
    };
    await collect.mutateAsync({ id: receivable.id, values: payload });
    toast.success('Cobro registrado.');
    onClose();
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30">
      <div className="h-full w-full max-w-lg overflow-auto border-l border-border bg-surface p-4 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-semibold">Registrar cobro</h2>
          <Button size="icon-sm" variant="ghost" onClick={onClose}><X className="size-4" /></Button>
        </div>
        <div className="space-y-4">
          <div className="rounded border border-border bg-bg/50 p-4">
            <p className="text-sm text-text-muted">Saldo pendiente</p>
            <p className="mt-1 text-4xl font-bold">{money(receivable.balance_base_amount)}</p>
            <p className="mt-1 text-sm text-text-muted">
              {rateValue ? `${money(balanceLocal, 'Bs ')} con ${activeRate?.exchange_rate_type_code ?? 'tasa'} @ ${numberLabel(rateValue)}` : 'Sin tasa USD/VES activa'}
            </p>
            <p className="mt-1 text-sm text-text-muted">{customerLabel(receivable)}</p>
          </div>
          {!activeSession && (
            <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">Abre una caja antes de registrar cobros manuales.</p>
          )}
          <div className="grid grid-cols-2 gap-2">
            <Select value={form.payment_currency} onChange={(event) => setCurrency(event.target.value as 'USD' | 'VES')}>
              <option value="USD">USD</option>
              <option value="VES">VES</option>
            </Select>
            <Select value={form.method ?? ''} onChange={(event) => patch({ method: event.target.value })}>
              {activeMethods.length === 0 ? <option value="cash">Efectivo</option> : activeMethods.map((method) => <option key={method.id} value={method.method ?? method.code ?? method.name}>{method.name}</option>)}
            </Select>
          </div>
          <Input type="number" min="0" value={form.amount} onChange={(event) => patch({ amount: Number(event.target.value || 0) })} placeholder="Monto" />
          <div className="rounded border border-border bg-bg/40 p-3 text-sm">
            {rateValue ? (
              <div className="space-y-1">
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Cobras</span>
                  <strong>{form.payment_currency === 'VES' ? money(form.amount, 'Bs ') : money(form.amount)}</strong>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Equivale a</span>
                  <strong>{form.payment_currency === 'VES' ? money(amountBase) : money(amountLocal, 'Bs ')}</strong>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Saldo quedará</span>
                  <strong>{money(remainingBase)} · {money(remainingLocal, 'Bs ')}</strong>
                </div>
                <p className="text-xs text-text-muted">{activeRate?.exchange_rate_type_code ?? 'Tasa'} @ {numberLabel(rateValue)}</p>
              </div>
            ) : form.payment_currency === 'VES' ? (
              <p className="text-warning">Configura una tasa activa USD/VES antes de cobrar en bolívares.</p>
            ) : (
              <p className="text-text-muted">El cobro en USD no requiere tasa. Configura una tasa USD/VES para ver equivalentes en bolívares.</p>
            )}
          </div>
          {isOverpaying && (
            <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">El monto supera el saldo pendiente. En esta fase no se registra vuelto ni saldo a favor.</p>
          )}
          <Input value={form.reference ?? ''} onChange={(event) => patch({ reference: event.target.value })} placeholder="Referencia" />
          <Input type="date" value={form.paid_at ?? ''} onChange={(event) => patch({ paid_at: event.target.value })} />
          <Textarea value={form.notes ?? ''} onChange={(event) => patch({ notes: event.target.value })} placeholder="Notas" rows={3} />
          <Button className="w-full" disabled={!activeSession || collect.isPending || isOverpaying || (form.payment_currency === 'VES' && !rateValue)} onClick={() => void submit()}>
            {collect.isPending ? <Loader2 className="size-4 animate-spin" /> : <CreditCard className="size-4" />}
            Registrar cobro
          </Button>
        </div>
      </div>
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

function summarize(receivables: Receivable[]) {
  return receivables.reduce((acc, item) => {
    acc.balance += item.balance_base_amount;
    acc.pending += item.status === 'pending' ? 1 : 0;
    acc.partial += item.status === 'partial' ? 1 : 0;
    acc.paid += item.status === 'paid' ? 1 : 0;
    acc.overdue += item.status === 'overdue' ? 1 : 0;
    return acc;
  }, { balance: 0, pending: 0, partial: 0, paid: 0, overdue: 0 });
}
