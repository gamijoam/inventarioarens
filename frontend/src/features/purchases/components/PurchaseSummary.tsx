/**
 * PurchaseSummary: card visual con el resumen completo de un PurchaseOrder.
 * Muestra:
 * - Stepper del estado (Borrador → Recibido parcial → Recibido)
 * - Totales (USD + VES) y progreso de recepcion
 * - Info del supplier, documento, fechas, tasa de cambio
 *
 * Patron visual consistente con el resto del modulo: cards, badges,
 * formato de moneda via formatMoney (es-VE).
 */
import { Building2, Calendar, CreditCard, FileText, Hash, TrendingUp } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Card, CardContent } from '@/components/ui/Card';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { formatRelative } from '@/lib/format';
import {
  PURCHASE_STATUS_LABELS,
  PURCHASE_PAYABLE_STATUS_LABELS,
  type Purchase,
  type PurchasePayableStatus,
  type PurchaseStatus,
} from '@/features/purchases/schemas';

function statusVariant(status: PurchaseStatus): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'draft':
      return 'default';
    case 'partially_received':
      return 'warning';
    case 'received':
      return 'success';
    case 'cancelled':
      return 'default';
  }
}

function payableVariant(
  status?: PurchasePayableStatus,
): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'pending':
    case 'overdue':
      return 'warning';
    case 'partial':
      return 'info';
    case 'paid':
      return 'success';
    default:
      return 'default';
  }
}

interface PurchaseSummaryProps {
  purchase: Purchase;
  /** Lista expandida de items (vienen del detalle). Opcional: si no viene, no se muestra la lista. */
  showItems?: boolean;
}

export function PurchaseSummary({ purchase, showItems = false }: PurchaseSummaryProps) {
  const totalBase = Number(purchase.total_base_amount ?? 0);
  const totalLocal = Number(purchase.total_local_amount ?? 0);
  const receivedBase = Number(purchase.received_base_amount ?? 0);
  const progress = totalBase > 0 ? Math.min(100, Math.round((receivedBase / totalBase) * 100)) : 0;
  const supplierName = (purchase.supplier as { name?: string } | null | undefined)?.name;
  const items = Array.isArray(purchase.items) ? purchase.items : [];
  const payable = purchase.account_payable ?? null;
  const payableStatus = payable?.status;
  const payableBalanceBase = Number(payable?.balance_base_amount ?? 0);
  const payableBalanceLocal = Number(payable?.balance_local_amount ?? 0);

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        {/* Header: estado + documento + fechas */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <Hash className="text-text-muted size-4" />
              <code className="bg-bg rounded px-1.5 py-0.5 text-sm font-semibold">
                {purchase.document_number ?? `#${purchase.id}`}
              </code>
              <Badge variant={statusVariant(purchase.status)} className="text-xs">
                Compra: {PURCHASE_STATUS_LABELS[purchase.status]}
              </Badge>
              <Badge variant={payableVariant(payableStatus)} className="text-xs">
                CxP: {payableStatus ? PURCHASE_PAYABLE_STATUS_LABELS[payableStatus] : 'Sin CxP'}
              </Badge>
            </div>
            <div className="text-text-muted flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
              {purchase.issued_at && (
                <span className="inline-flex items-center gap-1">
                  <Calendar className="size-3" /> Emitida: {purchase.issued_at}
                </span>
              )}
              {purchase.due_date && (
                <span className="inline-flex items-center gap-1">
                  <Calendar className="size-3" /> Vence: {purchase.due_date}
                </span>
              )}
              {purchase.created_at && <span>Creada {formatRelative(purchase.created_at)}</span>}
            </div>
          </div>

          {/* Totales grandes */}
          <div className="flex items-end gap-4">
            <div className="text-right">
              <div className="text-text-muted text-xs tracking-wide uppercase">Total USD</div>
              <div className="text-2xl font-bold tabular-nums">{formatMoney(totalBase)}</div>
            </div>
            {totalLocal > 0 && totalLocal !== totalBase && (
              <div className="text-right">
                <div className="text-text-muted text-xs tracking-wide uppercase">Total VES</div>
                <div className="text-text-muted text-2xl font-bold tabular-nums">
                  {formatMoney(totalLocal)}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Stepper del estado */}
        <StatusStepper status={purchase.status} />

        {/* Barra de progreso de recepcion */}
        {(purchase.status === 'partially_received' || purchase.status === 'received') && (
          <div className="space-y-1.5">
            <div className="flex items-center justify-between text-xs">
              <span className="text-text-muted">Progreso de recepcion</span>
              <span className="font-semibold tabular-nums">
                {formatMoney(receivedBase)} / {formatMoney(totalBase)} ({progress}%)
              </span>
            </div>
            <div className="bg-bg h-2 w-full overflow-hidden rounded-full">
              <div
                className={cn(
                  'h-full transition-all',
                  purchase.status === 'received' ? 'bg-success' : 'bg-warning',
                )}
                style={{ width: `${progress}%` }}
                role="progressbar"
                aria-valuenow={progress}
                aria-valuemin={0}
                aria-valuemax={100}
              />
            </div>
          </div>
        )}

        {/* Info del supplier + tasa de cambio */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <InfoRow icon={<Building2 className="size-4" />} label="Proveedor">
            {supplierName ?? <span className="text-text-muted/60">Sin proveedor</span>}
          </InfoRow>
          <InfoRow icon={<TrendingUp className="size-4" />} label="Tasa de cambio">
            {purchase.purchase_currency}
            {purchase.exchange_rate_type_code && (
              <span className="text-text-muted ml-1">({purchase.exchange_rate_type_code})</span>
            )}
            {purchase.exchange_rate != null && (
              <span className="text-text-muted ml-1 font-mono">
                {Number(purchase.exchange_rate).toFixed(4)}
              </span>
            )}
          </InfoRow>
        </div>

        {payable && (
          <div className="border-border bg-bg/30 grid grid-cols-1 gap-3 rounded-md border p-3 sm:grid-cols-3">
            <InfoRow icon={<CreditCard className="size-4" />} label="Cuenta por pagar">
              {payable.document_number ?? `CxP #${payable.id}`}
            </InfoRow>
            <div className="border-border bg-surface rounded-md border px-3 py-2">
              <div className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">
                Saldo pendiente
              </div>
              <div className="mt-0.5 text-sm font-semibold tabular-nums">
                {formatMoney(payableBalanceBase)}
              </div>
              {payableBalanceLocal > 0 && (
                <div className="text-text-muted text-xs tabular-nums">
                  {formatMoney({ amount: String(payableBalanceLocal), currency: 'VES' })}
                </div>
              )}
            </div>
            <div className="border-border bg-surface rounded-md border px-3 py-2">
              <div className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">
                Vencimiento
              </div>
              <div className="mt-0.5 text-sm">
                {payable.due_date ?? purchase.due_date ?? 'Sin vencimiento'}
              </div>
            </div>
          </div>
        )}

        {/* Lista de items (opcional) */}
        {showItems && items.length > 0 && (
          <div className="border-border space-y-2 border-t pt-3">
            <div className="text-text-secondary flex items-center gap-1 text-xs font-semibold tracking-wide uppercase">
              <FileText className="size-3.5" /> Items ({items.length})
            </div>
            <ul className="divide-border border-border bg-bg/30 divide-y rounded-md border">
              {items.map((it) => {
                const qty = Number(it.quantity ?? 0);
                const rec = Number(it.received_quantity ?? 0);
                const cost = it.unit_cost != null ? Number(it.unit_cost) : null;
                const baseCost = it.base_unit_cost != null ? Number(it.base_unit_cost) : null;
                const totalCost = it.total_cost != null ? Number(it.total_cost) : null;
                const baseTotalCost =
                  it.base_total_cost != null ? Number(it.base_total_cost) : null;
                const product = it.product as { name?: string; sku?: string } | null | undefined;
                const warehouse = it.warehouse as { code?: string } | null | undefined;
                return (
                  <li
                    key={it.id}
                    className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium">
                        {product?.name ?? `Producto #${it.product_id}`}
                      </div>
                      <div className="text-text-muted flex items-center gap-1.5 text-xs">
                        {product?.sku && (
                          <code className="bg-bg rounded px-1 py-0.5">{product.sku}</code>
                        )}
                        <span>|</span>
                        <span>{warehouse?.code ?? `Almacen #${it.warehouse_id}`}</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-3 text-right">
                      <div className="tabular-nums">
                        <div className="font-medium">
                          {rec.toFixed(2)} / {qty.toFixed(2)}
                        </div>
                        <div className="text-text-muted text-[10px] tracking-wide uppercase">
                          recibido / pedido
                        </div>
                      </div>
                      {(cost != null || baseCost != null) && (
                        <div className="text-text-muted tabular-nums">
                          <div>{formatMoney(cost ?? baseCost)}</div>
                          {purchase.purchase_currency === 'VES' && baseCost != null && (
                            <div className="text-[10px]">{formatMoney(baseCost)} base</div>
                          )}
                          <div className="text-[10px] tracking-wide uppercase">c/u</div>
                        </div>
                      )}
                      {(totalCost != null || baseTotalCost != null) && (
                        <div className="text-text-muted tabular-nums">
                          <div className="text-text-primary font-medium">
                            {formatMoney(totalCost ?? baseTotalCost)}
                          </div>
                          {purchase.purchase_currency === 'VES' && baseTotalCost != null && (
                            <div className="text-[10px]">{formatMoney(baseTotalCost)} base</div>
                          )}
                          <div className="text-[10px] tracking-wide uppercase">total</div>
                        </div>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function InfoRow({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="border-border bg-bg/30 flex items-start gap-2 rounded-md border px-3 py-2">
      <span className="text-text-muted mt-0.5">{icon}</span>
      <div className="min-w-0 flex-1">
        <div className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">
          {label}
        </div>
        <div className="mt-0.5 truncate text-sm">{children}</div>
      </div>
    </div>
  );
}

/**
 * StatusStepper: indicador visual del estado del PO con 3 etapas:
 * Borrador → Parcial → Recibido. Cancelado se renderiza aparte.
 */
function StatusStepper({ status }: { status: PurchaseStatus }) {
  if (status === 'cancelled') {
    return (
      <div className="border-danger/40 bg-danger/10 text-danger flex items-center justify-center rounded-md border px-3 py-2 text-sm font-medium">
        Compra cancelada
      </div>
    );
  }

  const steps: { key: PurchaseStatus; label: string }[] = [
    { key: 'draft', label: 'Borrador' },
    { key: 'partially_received', label: 'Parcial' },
    { key: 'received', label: 'Recibido' },
  ];

  const activeIdx = status === 'draft' ? 0 : status === 'partially_received' ? 1 : 2;

  return (
    <div className="flex items-center gap-2" role="status" aria-label="Estado de la compra">
      {steps.map((step, idx) => {
        const done = idx < activeIdx;
        const current = idx === activeIdx;
        return (
          <div key={step.key} className="flex flex-1 items-center gap-2">
            <div
              className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                done && 'bg-success text-success-foreground',
                current && 'bg-primary text-primary-foreground',
                !done && !current && 'border-border bg-bg text-text-muted border',
              )}
              aria-current={current ? 'step' : undefined}
            >
              {done ? '\u2713' : idx + 1}
            </div>
            <span
              className={cn(
                'text-xs font-medium',
                current ? 'text-text-primary' : 'text-text-muted',
              )}
            >
              {step.label}
            </span>
            {idx < steps.length - 1 && (
              <div className={cn('h-px flex-1', done ? 'bg-success' : 'bg-border')} />
            )}
          </div>
        );
      })}
    </div>
  );
}
