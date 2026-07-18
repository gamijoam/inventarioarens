/**
 * PurchasesManager: listado + filtros + acciones rapidas para Compras.
 * Patron consistente con CustomersManager, SuppliersManager, etc.
 *
 * El boton "Nueva compra" abre el dialog de creacion (FASE 2). Las
 * acciones "Recibir" y "Cancelar" estan en el detalle (FASE 3).
 */
import { useState } from 'react';
import { Plus, Search, XCircle, ChevronDown, ChevronRight } from 'lucide-react';
import { useNavigate } from '@tanstack/react-router';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { usePurchases, type PurchaseListFilters } from '@/features/purchases/api';
import {
  PURCHASE_STATUS_LABELS,
  PURCHASE_PAYABLE_STATUS_LABELS,
  type Purchase,
  type PurchasePayableStatus,
  type PurchaseStatus,
} from '@/features/purchases/schemas';
import { PurchaseSummary } from './components/PurchaseSummary';
import { QuickActionsBar } from './components/QuickActionsBar';
import { usePurchase } from '@/features/purchases/api';

const STATUS_FILTER_OPTIONS: { value: PurchaseListFilters['status']; label: string }[] = [
  { value: 'all', label: 'Todos' },
  { value: 'draft', label: 'Borrador' },
  { value: 'partially_received', label: 'Recibido parcial' },
  { value: 'received', label: 'Recibido' },
  { value: 'cancelled', label: 'Cancelado' },
];

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

function formatMoney(value: number | string | null | undefined): string {
  if (value === null || value === undefined) return '-';
  const n = typeof value === 'string' ? Number(value) : value;
  if (!Number.isFinite(n)) return '-';
  return n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

interface PurchasesManagerProps {
  onNew?: () => void;
  onReceive?: (purchaseId: number) => void;
}

export function PurchasesManager({ onNew, onReceive }: PurchasesManagerProps = {}) {
  const [filters, setFilters] = useState<PurchaseListFilters>({
    search: '',
    status: 'all',
    date_from: undefined,
    date_to: undefined,
  });
  const { data: purchases = [], isLoading } = usePurchases(filters);
  const navigate = useNavigate();
  const [expandedId, setExpandedId] = useState<number | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  function toggleExpand(id: number) {
    setExpandedId((prev) => (prev === id ? null : id));
  }

  return (
    <>
      <div className="mb-3 flex flex-wrap items-end gap-2">
        <div className="relative max-w-sm min-w-[200px] flex-1">
          <Search className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            value={filters.search ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))}
            placeholder="Buscar por documento o proveedor..."
            className="pl-9"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="status-filter" className="text-text-muted text-xs">
            Estado
          </Label>
          <Select
            id="status-filter"
            value={filters.status ?? 'all'}
            onChange={(e) =>
              setFilters((f) => ({ ...f, status: e.target.value as PurchaseListFilters['status'] }))
            }
            className="w-44"
          >
            {STATUS_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="date-from" className="text-text-muted text-xs">
            Desde
          </Label>
          <Input
            id="date-from"
            type="date"
            value={filters.date_from ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value || undefined }))}
            className="w-40"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="date-to" className="text-text-muted text-xs">
            Hasta
          </Label>
          <Input
            id="date-to"
            type="date"
            value={filters.date_to ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value || undefined }))}
            className="w-40"
          />
        </div>
        <Button
          size="sm"
          leftIcon={<Plus className="size-4" />}
          onClick={onNew}
          className="ml-auto"
        >
          Nueva compra
        </Button>
      </div>

      {purchases.length === 0 ? (
        <EmptyState
          title={filters.search || filters.status !== 'all' ? 'Sin resultados' : 'Sin compras'}
          description={
            filters.search || filters.status !== 'all'
              ? 'Ninguna compra coincide con los filtros.'
              : 'Crea la primera compra para registrar mercancia y generar CxP.'
          }
        />
      ) : (
        <div className="border-border bg-surface rounded-lg border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>
                <th className="w-8 px-2 py-2" />
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Documento
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Fecha
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Proveedor
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Estado compra
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  CxP
                </th>
                <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                  Total (USD)
                </th>
                <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                  Recibido
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Items
                </th>
              </tr>
            </thead>
            <tbody>
              {purchases.map((p) => {
                const totalBase = Number(p.total_base_amount ?? 0);
                const receivedBase = Number(p.received_base_amount ?? 0);
                const progress =
                  totalBase > 0 ? Math.min(100, Math.round((receivedBase / totalBase) * 100)) : 0;
                const isExpanded = expandedId === p.id;
                return (
                  <Row
                    key={p.id}
                    purchase={p}
                    isExpanded={isExpanded}
                    onToggle={() => toggleExpand(p.id)}
                    progress={progress}
                    onReceive={onReceive ? () => onReceive(p.id) : undefined}
                    onCancel={() => undefined /* QuickActionsBar maneja su propio dialog */}
                    onPayPayable={() => navigate({ to: '/payables' })}
                  />
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

/**
 * Row: fila individual de la tabla con toggle de expansion. Cuando se
 * expande, carga el detalle del PO (con items) y muestra PurchaseSummary
 * + QuickActionsBar en una fila <tr> aparte con colspan.
 */
function Row({
  purchase,
  isExpanded,
  onToggle,
  progress,
  onReceive,
  onCancel,
  onPayPayable,
}: {
  purchase: Purchase;
  isExpanded: boolean;
  onToggle: () => void;
  progress: number;
  onReceive?: () => void;
  onCancel: (purchase: Purchase) => void;
  onPayPayable?: () => void;
}) {
  return (
    <>
      <tr
        className="border-border hover:bg-bg/50 cursor-pointer border-b"
        onClick={onToggle}
        data-testid={`purchase-row-${purchase.id}`}
      >
        <td className="text-text-muted px-2 py-2">
          {isExpanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
        </td>
        <td className="px-3 py-2 font-medium">
          <code className="bg-bg rounded px-1.5 py-0.5 text-xs">
            {purchase.document_number ?? `#${purchase.id}`}
          </code>
        </td>
        <td className="text-text-muted px-3 py-2">{purchase.issued_at ?? '-'}</td>
        <td className="text-text-muted px-3 py-2">
          {(purchase.supplier as { name?: string } | null | undefined)?.name ?? (
            <span className="text-text-muted/60">Sin proveedor</span>
          )}
        </td>
        <td className="px-3 py-2">
          <Badge variant={statusVariant(purchase.status)}>
            {PURCHASE_STATUS_LABELS[purchase.status]}
          </Badge>
        </td>
        <td className="px-3 py-2">
          {purchase.account_payable ? (
            <div className="flex flex-col gap-0.5">
              <Badge variant={payableVariant(purchase.account_payable.status)}>
                {PURCHASE_PAYABLE_STATUS_LABELS[purchase.account_payable.status]}
              </Badge>
              {Number(purchase.account_payable.balance_base_amount ?? 0) > 0 && (
                <span className="text-text-muted text-xs tabular-nums">
                  Saldo {formatMoney(purchase.account_payable.balance_base_amount)}
                </span>
              )}
            </div>
          ) : (
            <Badge variant="default">Sin CxP</Badge>
          )}
        </td>
        <td className="px-3 py-2 text-right tabular-nums">
          {formatMoney(purchase.total_base_amount)}
        </td>
        <td className="px-3 py-2 text-right">
          <div className="flex flex-col items-end gap-0.5">
            <span className="text-text-muted tabular-nums">
              {formatMoney(purchase.received_base_amount)}
            </span>
            {purchase.status === 'partially_received' && (
              <span className="text-warning text-[10px] tracking-wide uppercase">{progress}%</span>
            )}
          </div>
        </td>
        <td className="text-text-muted px-3 py-2 tabular-nums">{purchase.items_count ?? '-'}</td>
      </tr>
      {isExpanded && (
        <tr className="border-border bg-bg/20 border-b">
          <td colSpan={9} className="px-3 py-4">
            <ExpandedDetail
              purchaseId={purchase.id}
              purchase={purchase}
              onReceive={onReceive}
              onCancel={() => onCancel(purchase)}
              onPayPayable={onPayPayable}
            />
          </td>
        </tr>
      )}
    </>
  );
}

/**
 * ExpandedDetail: contenido que se muestra cuando una fila esta expandida.
 * Carga el detalle completo del PO (con items) y muestra el PurchaseSummary
 * + QuickActionsBar.
 */
function ExpandedDetail({
  purchaseId,
  purchase,
  onReceive,
  onCancel,
  onPayPayable,
}: {
  purchaseId: number;
  purchase: Purchase;
  onReceive?: () => void;
  onCancel: () => void;
  onPayPayable?: () => void;
}) {
  const { data: detail, isLoading } = usePurchase(purchaseId);

  if (isLoading && !detail) {
    return <Skeleton className="h-40 w-full" />;
  }

  return (
    <div className="space-y-3" onClick={(e) => e.stopPropagation()}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="text-text-muted text-xs tracking-wide uppercase">Detalle de la compra</div>
        <QuickActionsBar
          purchase={detail ?? purchase}
          onReceive={onReceive}
          onPayPayable={onPayPayable}
          onPrint={undefined}
        />
      </div>
      <PurchaseSummary purchase={detail ?? purchase} showItems />
      {/* Boton explicito de cancelar fuera del QuickActionsBar porque ese
          solo aparece si NO esta expanded (el padre ya tiene su propio
          ConfirmDialog legacy). Cuando integremos FASE 5 eliminamos esto. */}
      {purchase.status === 'draft' && (
        <div className="flex justify-end">
          <Button
            size="sm"
            variant="ghost"
            leftIcon={<XCircle className="size-4" />}
            onClick={onCancel}
            data-testid={`expanded-cancel-${purchaseId}`}
          >
            Cancelar compra
          </Button>
        </div>
      )}
    </div>
  );
}
