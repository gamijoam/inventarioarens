import { useMemo, useState } from 'react';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CreditCard,
  Loader2,
  Search,
  Wallet,
  X,
} from 'lucide-react';
import { toast } from 'sonner';
import { Link } from '@tanstack/react-router';

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
import {
  useCashSessions,
  useCurrentExchangeRatesForPos,
  usePaymentMethods,
  type CurrentExchangeRate,
} from '@/features/pos/api';
import { usePayable, usePayables, usePayPayable, type PayableListFilters } from './api';
import { activeUsdVesRate, currentLocalBalance, numberLabel, rateLabel } from './currentBalance';
import {
  PAYABLE_STATUS_LABELS,
  type Payable,
  type PayableStatus,
  type PayPayableValues,
} from './schemas';

const STATUS_OPTIONS: { value: PayableListFilters['status']; label: string }[] = [
  { value: 'open', label: 'Abiertas' },
  { value: 'all', label: 'Todas' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'partial', label: 'Parciales' },
  { value: 'overdue', label: 'Vencidas' },
  { value: 'paid', label: 'Pagadas' },
];

function statusVariant(status: PayableStatus): 'warning' | 'success' | 'danger' | 'info' {
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

function supplierLabel(payable: Payable): string {
  if (!payable.supplier) return 'Sin proveedor';
  const doc = payable.supplier.document_number ? ` - ${payable.supplier.document_number}` : '';
  return `${payable.supplier.name}${doc}`;
}

function isCashMethod(method: string | null | undefined): boolean {
  return (method ?? '').toLowerCase() === 'cash';
}

export function PayablesManager() {
  const [filters, setFilters] = useState<PayableListFilters>({
    status: 'open',
    page: 1,
    limit: 25,
  });
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [paying, setPaying] = useState<Payable | null>(null);
  const { data, isLoading, isError, refetch } = usePayables(filters);
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const activeRate = activeUsdVesRate(rates);
  const canPay = useCan(PERMISSIONS.ACCOUNTS_PAYABLE_PAY);
  const payables = data?.data ?? [];
  const meta = data?.meta;
  const totals = useMemo(() => summarize(payables), [payables]);
  const currentLocalOpen = activeRate ? totals.balance * activeRate.rate : null;

  function updateFilters(patch: Partial<PayableListFilters>) {
    setFilters((current) => ({ ...current, ...patch, page: 1 }));
  }

  if (isLoading && !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-3">
      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 pt-4">
          <div className="min-w-[240px] flex-1">
            <Label htmlFor="payables-search" className="text-text-muted text-xs">
              Buscar
            </Label>
            <div className="relative mt-1">
              <Search className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
              <Input
                id="payables-search"
                value={filters.search ?? ''}
                onChange={(event) => updateFilters({ search: event.target.value })}
                placeholder="Proveedor, documento, compra o CxP"
                className="pl-9"
              />
            </div>
          </div>
          <div className="w-44">
            <Label htmlFor="payables-status" className="text-text-muted text-xs">
              Estado
            </Label>
            <Select
              id="payables-status"
              value={filters.status ?? 'open'}
              onChange={(event) =>
                updateFilters({ status: event.target.value as PayableListFilters['status'] })
              }
              className="mt-1"
            >
              {STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-40">
            <Label htmlFor="payable-due-from" className="text-text-muted text-xs">
              Vence desde
            </Label>
            <Input
              id="payable-due-from"
              type="date"
              value={filters.due_from ?? ''}
              onChange={(event) => updateFilters({ due_from: event.target.value || undefined })}
              className="mt-1"
            />
          </div>
          <div className="w-40">
            <Label htmlFor="payable-due-to" className="text-text-muted text-xs">
              Vence hasta
            </Label>
            <Input
              id="payable-due-to"
              type="date"
              value={filters.due_to ?? ''}
              onChange={(event) => updateFilters({ due_to: event.target.value || undefined })}
              className="mt-1"
            />
          </div>
          <Button variant="secondary" onClick={() => void refetch()}>
            Actualizar
          </Button>
        </CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-5">
        <InfoTile
          label="Saldo abierto"
          value={money(totals.balance)}
          helper={
            currentLocalOpen === null
              ? 'Sin tasa activa USD/VES'
              : `${money(currentLocalOpen, 'Bs ')} hoy`
          }
        />
        <InfoTile label="Vencidas" value={String(totals.overdue)} />
        <InfoTile label="Pendientes" value={String(totals.pending)} />
        <InfoTile label="Parciales" value={String(totals.partial)} />
        <InfoTile label="Pagadas" value={String(totals.paid)} />
      </div>

      {isError ? (
        <EmptyState
          title="No se pudo cargar CxP"
          description="Revisa la conexion e intenta actualizar."
          action={<Button onClick={() => void refetch()}>Reintentar</Button>}
        />
      ) : payables.length === 0 ? (
        <EmptyState
          icon={<Wallet className="size-8" />}
          title="Sin cuentas por pagar abiertas"
          description="Las compras recibidas con saldo a proveedor apareceran aqui."
        />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="table-dense w-full">
              <thead className="border-border bg-bg/60 border-b text-left">
                <tr>
                  <th className="w-8 px-2 py-2" />
                  <th className="text-text-secondary px-3 py-2 font-semibold uppercase">
                    Documento
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold uppercase">
                    Proveedor
                  </th>
                  <th className="text-text-secondary px-3 py-2 font-semibold uppercase">Estado</th>
                  <th className="text-text-secondary px-3 py-2 font-semibold uppercase">Vence</th>
                  <th className="text-text-secondary px-3 py-2 text-right font-semibold uppercase">
                    Original
                  </th>
                  <th className="text-text-secondary px-3 py-2 text-right font-semibold uppercase">
                    Pagado
                  </th>
                  <th className="text-text-secondary px-3 py-2 text-right font-semibold uppercase">
                    Saldo
                  </th>
                  <th className="text-text-secondary px-3 py-2 text-right font-semibold uppercase">
                    Accion
                  </th>
                </tr>
              </thead>
              <tbody>
                {payables.map((payable) => (
                  <PayableRow
                    key={payable.id}
                    payable={payable}
                    activeRate={activeRate}
                    expanded={expandedId === payable.id}
                    canPay={canPay}
                    onToggle={() =>
                      setExpandedId((current) => (current === payable.id ? null : payable.id))
                    }
                    onPay={() => setPaying(payable)}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {meta && (
            <div className="border-border text-text-muted flex items-center justify-between border-t px-4 py-3 text-sm">
              <span>
                Pagina {meta.current_page} de {meta.last_page} - {meta.total} cuentas
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page <= 1}
                  leftIcon={<ChevronLeft className="size-4" />}
                  onClick={() =>
                    setFilters((f) => ({ ...f, page: Math.max(1, (f.page ?? 1) - 1) }))
                  }
                >
                  Anterior
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page >= meta.last_page}
                  rightIcon={<ChevronRight className="size-4" />}
                  onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
                >
                  Siguiente
                </Button>
              </div>
            </div>
          )}
        </Card>
      )}

      {paying && (
        <PayPanel payable={paying} activeRate={activeRate} onClose={() => setPaying(null)} />
      )}
    </div>
  );
}

function PayableRow({
  payable,
  activeRate,
  expanded,
  canPay,
  onToggle,
  onPay,
}: {
  payable: Payable;
  activeRate: CurrentExchangeRate | null;
  expanded: boolean;
  canPay: boolean;
  onToggle: () => void;
  onPay: () => void;
}) {
  const localBalance = currentLocalBalance(payable, activeRate);

  return (
    <>
      <tr className="border-border hover:bg-bg/50 cursor-pointer border-b" onClick={onToggle}>
        <td className="text-text-muted px-2 py-2">
          <ChevronDown className={`size-4 transition-transform ${expanded ? '' : '-rotate-90'}`} />
        </td>
        <td className="px-3 py-2 font-medium">{payable.document_number ?? `CxP #${payable.id}`}</td>
        <td className="px-3 py-2">{supplierLabel(payable)}</td>
        <td className="px-3 py-2">
          <Badge variant={statusVariant(payable.status)}>
            {PAYABLE_STATUS_LABELS[payable.status]}
          </Badge>
        </td>
        <td className="text-text-muted px-3 py-2">{dateLabel(payable.due_date)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{money(payable.original_base_amount)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{money(payable.paid_base_amount)}</td>
        <td className="px-3 py-2 text-right font-semibold tabular-nums">
          <div>{money(payable.balance_base_amount)}</div>
          <div className="text-text-muted text-xs font-normal">
            {localBalance === null
              ? 'Sin tasa activa USD/VES'
              : `${money(localBalance, 'Bs ')} hoy`}
          </div>
        </td>
        <td className="px-3 py-2 text-right">
          <Button
            size="sm"
            variant="secondary"
            disabled={!canPay || payable.balance_base_amount <= 0}
            onClick={(event) => {
              event.stopPropagation();
              onPay();
            }}
          >
            Pagar
          </Button>
        </td>
      </tr>
      {expanded && (
        <tr className="border-border bg-bg/20 border-b">
          <td colSpan={9} className="px-4 py-4">
            <PayableDetail payableId={payable.id} payable={payable} activeRate={activeRate} />
          </td>
        </tr>
      )}
    </>
  );
}

function PayableDetail({
  payableId,
  payable,
  activeRate,
}: {
  payableId: number;
  payable: Payable;
  activeRate: CurrentExchangeRate | null;
}) {
  const { data, isLoading } = usePayable(payableId);
  const current = data ?? payable;
  const items = current.purchase_order?.items ?? [];
  const payments = current.payments ?? [];
  const localBalance = currentLocalBalance(current, activeRate);

  if (isLoading && !data) return <Skeleton className="h-36 w-full" />;

  return (
    <div className="space-y-4" onClick={(event) => event.stopPropagation()}>
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile
          label="Compra"
          value={current.purchase_order_id ? `#${current.purchase_order_id}` : '-'}
        />
        <InfoTile label="Proveedor" value={supplierLabel(current)} />
        <InfoTile label="Saldo USD" value={money(current.balance_base_amount)} />
        <InfoTile
          label="Equivalente VES hoy"
          value={
            localBalance === null
              ? 'Sin tasa activa USD/VES'
              : `${money(localBalance, 'Bs ')} - ${rateLabel(activeRate)}`
          }
        />
      </div>
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile label="Original" value={money(current.original_base_amount)} />
        <InfoTile label="Devuelto" value={money(current.returned_base_amount)} />
        <InfoTile label="Ajustado" value={money(current.adjusted_base_amount)} />
        <InfoTile label="Pagado" value={money(current.paid_base_amount)} />
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <section className="border-border bg-surface rounded border p-3">
          <div className="flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">Items comprados</h3>
            {current.purchase_order_id && (
              <Link to="/purchases" className="text-primary text-xs hover:underline">
                Ver compras
              </Link>
            )}
          </div>
          <div className="mt-2 max-h-48 overflow-auto">
            {items.length === 0 ? (
              <p className="text-text-muted text-sm">Sin items cargados.</p>
            ) : (
              items.map((item) => (
                <div
                  key={item.id}
                  className="border-border flex items-center justify-between border-b py-2 text-sm last:border-0"
                >
                  <div>
                    <p className="font-medium">{item.product?.name ?? 'Producto'}</p>
                    <p className="text-text-muted text-xs">{item.product?.sku ?? '-'}</p>
                  </div>
                  <div className="text-right">
                    <p>{item.received_quantity} recibidas</p>
                    <p className="text-text-muted text-xs">{money(item.base_total_cost)}</p>
                  </div>
                </div>
              ))
            )}
          </div>
        </section>
        <section className="border-border bg-surface rounded border p-3">
          <h3 className="text-sm font-semibold">Historial de pagos</h3>
          <div className="mt-2 max-h-48 overflow-auto">
            {payments.length === 0 ? (
              <p className="text-text-muted text-sm">Sin pagos registrados.</p>
            ) : (
              payments.map((payment) => (
                <div
                  key={payment.id}
                  className="border-border flex items-center justify-between border-b py-2 text-sm last:border-0"
                >
                  <div>
                    <p className="font-medium">{payment.method ?? 'Pago'}</p>
                    <p className="text-text-muted text-xs">
                      {payment.reference ?? dateLabel(payment.paid_at)}
                    </p>
                    {payment.exchange_rate > 0 && (
                      <p className="text-text-muted text-xs">
                        {payment.exchange_rate_type_code ?? 'Tasa'} @{' '}
                        {numberLabel(payment.exchange_rate)}
                      </p>
                    )}
                  </div>
                  <div className="text-right">
                    <p>{money(payment.amount, payment.payment_currency === 'VES' ? 'Bs ' : '$')}</p>
                    <p className="text-text-muted text-xs">Base {money(payment.amount_base)}</p>
                  </div>
                </div>
              ))
            )}
          </div>
        </section>
      </div>
    </div>
  );
}

function PayPanel({
  payable,
  activeRate,
  onClose,
}: {
  payable: Payable;
  activeRate: CurrentExchangeRate | null;
  onClose: () => void;
}) {
  const pay = usePayPayable();
  const { data: sessions = [] } = useCashSessions();
  const { data: methods = [] } = usePaymentMethods();
  const canMoveCash =
    useCan(PERMISSIONS.CASH_REGISTER_MOVE) || useCan(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const activeSession = sessions.find((session) => session.status === 'open') ?? null;
  const activeMethods = methods.filter((method) => method.is_active !== false);
  const defaultMethod =
    activeMethods.find((method) => method.method === 'transfer') ?? activeMethods[0];
  const rateValue = activeRate?.rate && activeRate.rate > 0 ? activeRate.rate : null;
  const balanceBase = payable.balance_base_amount;
  const balanceLocal = rateValue ? balanceBase * rateValue : payable.balance_local_amount;
  const [form, setForm] = useState<PayPayableValues>({
    payment_currency: 'USD',
    amount: balanceBase,
    cash_register_session_id: null,
    exchange_rate_type_id: null,
    exchange_rate: null,
    method: defaultMethod?.method ?? 'transfer',
    reference: '',
    notes: '',
    paid_at: '',
  });
  const amountBase =
    form.payment_currency === 'VES' && rateValue ? form.amount / rateValue : form.amount;
  const amountLocal =
    form.payment_currency === 'VES' ? form.amount : rateValue ? form.amount * rateValue : 0;
  const remainingBase = Math.max(0, balanceBase - amountBase);
  const remainingLocal = rateValue
    ? remainingBase * rateValue
    : Math.max(0, payable.balance_local_amount - amountLocal);
  const isOverpaying = amountBase > balanceBase + 0.0001;
  const isCash = isCashMethod(form.method);
  const cashBlocked = isCash && (!activeSession || !canMoveCash);

  function patch(next: Partial<PayPayableValues>) {
    setForm((current) => ({ ...current, ...next }));
  }

  function setCurrency(currency: 'USD' | 'VES') {
    patch({
      payment_currency: currency,
      amount: currency === 'VES' && rateValue ? balanceBase * rateValue : balanceBase,
    });
  }

  async function submit() {
    if (isCash && !activeSession)
      return toast.error('Debes tener una caja abierta para pagar en efectivo.');
    if (isCash && !canMoveCash) return toast.error('No tienes permiso para movimientos de caja.');
    if (isOverpaying) return toast.error('El pago supera el saldo pendiente.');
    const payload: PayPayableValues = {
      ...form,
      cash_register_session_id: isCash ? (activeSession?.id ?? null) : null,
      exchange_rate_type_id:
        form.payment_currency === 'VES'
          ? (activeRate?.exchange_rate_type_id ?? null)
          : (form.exchange_rate_type_id ?? null),
      exchange_rate:
        form.payment_currency === 'VES' ? (activeRate?.rate ?? null) : (form.exchange_rate ?? null),
      paid_at: form.paid_at || null,
      reference: form.reference?.trim() || null,
      notes: form.notes?.trim() || null,
    };
    await pay.mutateAsync({ id: payable.id, values: payload });
    toast.success('Pago a proveedor registrado.');
    onClose();
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30">
      <div className="border-border bg-surface h-full w-full max-w-lg overflow-auto border-l p-4 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-semibold">Registrar pago a proveedor</h2>
          <Button size="icon-sm" variant="ghost" onClick={onClose}>
            <X className="size-4" />
          </Button>
        </div>
        <div className="space-y-4">
          <div className="border-border bg-bg/50 rounded border p-4">
            <p className="text-text-muted text-sm">Saldo pendiente</p>
            <p className="mt-1 text-4xl font-bold">{money(payable.balance_base_amount)}</p>
            <p className="text-text-muted mt-1 text-sm">
              {rateValue
                ? `${money(balanceLocal, 'Bs ')} con ${activeRate?.exchange_rate_type_code ?? 'tasa'} @ ${numberLabel(rateValue)}`
                : 'Sin tasa USD/VES activa'}
            </p>
            <p className="text-text-muted mt-1 text-sm">{supplierLabel(payable)}</p>
          </div>
          {cashBlocked && (
            <p className="border-warning bg-warning/10 text-warning rounded border p-3 text-sm">
              {isCash && !activeSession
                ? 'Abre una caja antes de pagar en efectivo.'
                : 'Necesitas permiso de movimientos de caja para pagar en efectivo.'}
            </p>
          )}
          <div className="grid grid-cols-2 gap-2">
            <Select
              value={form.payment_currency}
              onChange={(event) => setCurrency(event.target.value as 'USD' | 'VES')}
            >
              <option value="USD">USD</option>
              <option value="VES">VES</option>
            </Select>
            <Select
              value={form.method ?? ''}
              onChange={(event) => patch({ method: event.target.value })}
            >
              {activeMethods.length === 0 ? (
                <option value="transfer">Transferencia</option>
              ) : (
                activeMethods.map((method) => (
                  <option key={method.id} value={method.method ?? method.code ?? method.name}>
                    {method.name}
                  </option>
                ))
              )}
            </Select>
          </div>
          <Input
            type="number"
            min="0"
            value={form.amount}
            onChange={(event) => patch({ amount: Number(event.target.value || 0) })}
            placeholder="Monto"
          />
          <div className="border-border bg-bg/40 rounded border p-3 text-sm">
            {rateValue ? (
              <div className="space-y-1">
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Pagas</span>
                  <strong>
                    {form.payment_currency === 'VES'
                      ? money(form.amount, 'Bs ')
                      : money(form.amount)}
                  </strong>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Equivale a</span>
                  <strong>
                    {form.payment_currency === 'VES'
                      ? money(amountBase)
                      : money(amountLocal, 'Bs ')}
                  </strong>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-text-muted">Saldo quedara</span>
                  <strong>
                    {money(remainingBase)} - {money(remainingLocal, 'Bs ')}
                  </strong>
                </div>
                <p className="text-text-muted text-xs">
                  {activeRate?.exchange_rate_type_code ?? 'Tasa'} @ {numberLabel(rateValue)}
                </p>
              </div>
            ) : form.payment_currency === 'VES' ? (
              <p className="text-warning">
                Configura una tasa activa USD/VES antes de pagar en bolivares.
              </p>
            ) : (
              <p className="text-text-muted">
                El pago en USD no requiere tasa. Configura una tasa USD/VES para ver equivalentes en
                bolivares.
              </p>
            )}
          </div>
          {isOverpaying && (
            <p className="border-warning bg-warning/10 text-warning rounded border p-3 text-sm">
              El monto supera el saldo pendiente. En esta fase no se registra saldo a favor.
            </p>
          )}
          <Input
            value={form.reference ?? ''}
            onChange={(event) => patch({ reference: event.target.value })}
            placeholder="Referencia"
          />
          <Input
            type="date"
            value={form.paid_at ?? ''}
            onChange={(event) => patch({ paid_at: event.target.value })}
          />
          <Textarea
            value={form.notes ?? ''}
            onChange={(event) => patch({ notes: event.target.value })}
            placeholder="Notas"
            rows={3}
          />
          <Button
            className="w-full"
            disabled={
              pay.isPending ||
              isOverpaying ||
              cashBlocked ||
              (form.payment_currency === 'VES' && !rateValue)
            }
            onClick={() => void submit()}
          >
            {pay.isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <CreditCard className="size-4" />
            )}
            Registrar pago
          </Button>
        </div>
      </div>
    </div>
  );
}

function InfoTile({ label, value, helper }: { label: string; value: string; helper?: string }) {
  return (
    <div className="border-border bg-surface rounded border px-3 py-2">
      <div className="text-text-muted text-xs uppercase">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
      {helper && <div className="text-text-muted mt-0.5 text-xs">{helper}</div>}
    </div>
  );
}

function summarize(payables: Payable[]) {
  return payables.reduce(
    (acc, item) => {
      acc.balance += item.balance_base_amount;
      acc.pending += item.status === 'pending' ? 1 : 0;
      acc.partial += item.status === 'partial' ? 1 : 0;
      acc.paid += item.status === 'paid' ? 1 : 0;
      acc.overdue += item.status === 'overdue' ? 1 : 0;
      return acc;
    },
    { balance: 0, pending: 0, partial: 0, paid: 0, overdue: 0 },
  );
}
