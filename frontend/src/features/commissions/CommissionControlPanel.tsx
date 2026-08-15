import { useEffect, useState } from 'react';
import { Columns3, RotateCcw } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { usePaymentMethods } from '@/features/pos/api';
import { useUsers } from '@/features/users/api';

import { useCommissionControl } from './api';
import {
  defaultControlColumns,
  presetControlColumns,
  reconcileControlColumns,
  type ControlPreset,
} from './controlColumns';
import { formatControlNumber, formatControlPayment } from './displayFormat';
import type { CommissionControl, CommissionControlRow } from './schemas';

const STORAGE_KEY = 'commissions-control-visible-columns-v2';
const EMPTY_COLUMNS: CommissionControl['meta']['columns'] = [];

interface ControlFilters {
  date_from?: string;
  date_to?: string;
  user_id?: number;
  payment_method_id?: number;
}

export function CommissionControlPanel({ ownOnly }: { ownOnly: boolean }) {
  const [filters, setFilters] = useState<ControlFilters>({});
  const [savedColumns, setSavedColumns] = useState<string[]>(() => {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw === null) return [];
      const value = JSON.parse(raw) as unknown;
      return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
    } catch {
      return [];
    }
  });
  const { data, isLoading, isError } = useCommissionControl(filters);
  const { data: usersPage } = useUsers(
    { search: '', status: 'active', scope: 'tenant', page: 1, per_page: 100 },
    !ownOnly,
  );
  const { data: paymentMethods = [] } = usePaymentMethods();

  const columns = data?.meta.columns ?? EMPTY_COLUMNS;
  const visibleKeys =
    savedColumns.length > 0
      ? reconcileControlColumns(columns, savedColumns)
      : defaultControlColumns(columns);
  const visibleColumns = columns.filter((column) => visibleKeys.includes(column.key));

  useEffect(() => {
    if (columns.length > 0 && savedColumns.length > 0) {
      const reconciled = reconcileControlColumns(columns, savedColumns);
      if (reconciled.join('|') !== savedColumns.join('|')) {
        setSavedColumns(reconciled);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(reconciled));
      }
    }
  }, [columns, savedColumns]);

  function updateFilters(next: Partial<ControlFilters>): void {
    setFilters((current) => ({ ...current, ...next }));
  }

  function applyPreset(preset: ControlPreset): void {
    const next = presetControlColumns(columns, preset);
    setSavedColumns(next);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  }

  function toggleColumn(key: string, checked: boolean): void {
    const next = checked ? [...visibleKeys, key] : visibleKeys.filter((item) => item !== key);
    setSavedColumns(next);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  }

  function resetColumns(): void {
    setSavedColumns([]);
    localStorage.removeItem(STORAGE_KEY);
  }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-text-muted text-xs font-semibold tracking-[0.16em] uppercase">
            Control de ventas
          </p>
          <h2 className="mt-1 text-lg font-semibold">Comisiones V2</h2>
          <p className="text-text-muted mt-1 text-sm">
            Vista completa por defecto. Puedes armar una vista personalizada.
          </p>
        </div>
        <Badge variant="info">{data?.meta.total ?? 0} filas</Badge>
      </div>

      <div className="border-border bg-surface flex flex-wrap items-end gap-3 rounded-xl border p-3">
        <FilterField label="Desde">
          <Input
            type="date"
            value={filters.date_from ?? ''}
            onChange={(event) => updateFilters({ date_from: event.target.value || undefined })}
          />
        </FilterField>
        <FilterField label="Hasta">
          <Input
            type="date"
            value={filters.date_to ?? ''}
            onChange={(event) => updateFilters({ date_to: event.target.value || undefined })}
          />
        </FilterField>
        {!ownOnly && (
          <FilterField label="Vendedor o cajero">
            <Select
              value={filters.user_id ?? ''}
              onChange={(event) =>
                updateFilters({
                  user_id: event.target.value ? Number(event.target.value) : undefined,
                })
              }
            >
              <option value="">Todas las personas</option>
              {(usersPage?.data ?? []).map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </Select>
          </FilterField>
        )}
        <FilterField label="Metodo de pago">
          <Select
            value={filters.payment_method_id ?? ''}
            onChange={(event) =>
              updateFilters({
                payment_method_id: event.target.value ? Number(event.target.value) : undefined,
              })
            }
          >
            <option value="">Todos los metodos</option>
            {paymentMethods.map((method) => (
              <option key={method.id} value={method.id}>
                {method.name}
              </option>
            ))}
          </Select>
        </FilterField>
      </div>

      <div className="border-border bg-surface rounded-xl border p-3">
        <div className="flex flex-wrap items-center gap-2">
          <Columns3 className="text-primary size-4" />
          <span className="text-sm font-medium">Armar tabla</span>
          <Button size="sm" variant="secondary" onClick={() => applyPreset('full')}>
            Vista completa
          </Button>
          <Button size="sm" variant="ghost" onClick={() => applyPreset('money')}>
            Solo dinero
          </Button>
          <Button size="sm" variant="ghost" onClick={() => applyPreset('payments')}>
            Solo metodos
          </Button>
          <Button size="sm" variant="ghost" onClick={() => applyPreset('commission_ves')}>
            Comisiones Bs
          </Button>
          <Button
            size="sm"
            variant="ghost"
            leftIcon={<RotateCcw className="size-3.5" />}
            onClick={resetColumns}
          >
            Restablecer
          </Button>
        </div>
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2">
          {columns.map((column) => (
            <label key={column.key} className="text-text-muted flex items-center gap-2 text-xs">
              <Checkbox
                checked={visibleKeys.includes(column.key)}
                onCheckedChange={(checked) => toggleColumn(column.key, checked === true)}
              />
              {column.label}
            </label>
          ))}
        </div>
      </div>

      {isLoading ? (
        <div className="border-border bg-surface h-48 animate-pulse rounded-xl border" />
      ) : isError ? (
        <div className="border-danger/40 bg-danger/5 text-danger rounded-xl border p-5 text-sm">
          No se pudo cargar el control de ventas.
        </div>
      ) : data?.data.length ? (
        <ControlTable rows={data.data} columns={visibleColumns} summary={data.summary} />
      ) : (
        <EmptyState
          title="Sin ventas para estos filtros"
          description="Cambia el rango de fechas o limpia los filtros."
        />
      )}
    </section>
  );
}

function ControlTable({
  rows,
  columns,
  summary,
}: {
  rows: CommissionControlRow[];
  columns: { key: string; label: string }[];
  summary: CommissionControl['summary'];
}) {
  return (
    <div className="border-border bg-surface overflow-x-auto rounded-xl border">
      <table className="table-dense w-full table-fixed text-xs">
        <colgroup>
          {columns.map((column) => (
            <col key={column.key} style={{ width: columnWidth(column.key, columns) }} />
          ))}
        </colgroup>
        <thead className="bg-primary text-primary-foreground text-left text-xs">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                className="px-2 py-2 font-semibold break-words whitespace-normal"
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id} className="border-border border-b last:border-b-0">
              {columns.map((column) => (
                <td
                  key={column.key}
                  className="px-2 py-2 align-top break-words whitespace-normal tabular-nums"
                >
                  {renderCell(row, column.key)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        <tfoot className="bg-success/15 text-sm font-semibold">
          <tr>
            {columns.map((column) => (
              <td key={column.key} className="px-2 py-2 break-words whitespace-normal tabular-nums">
                {renderTotal(summary, column.key)}
              </td>
            ))}
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

function renderCell(row: CommissionControlRow, key: string): string {
  if (key === 'product')
    return row.product.sku ? `${row.product.name} (${row.product.sku})` : row.product.name;
  if (key === 'seller') return row.seller?.name ?? '-';
  if (key === 'cashier') return row.cashier?.name ?? '-';
  if (key === 'branch') return row.branch?.name ?? '-';
  if (key === 'date') return row.date ? row.date.slice(0, 10) : '-';
  if (key === 'order_id') return `#${row.order_id}`;
  if (key === 'equivalent_usd') {
    const equivalent = formatControlNumber(row.equivalent_usd);
    if (equivalent === '-' || !row.exchange_rate) return equivalent;

    const rate = formatControlNumber(row.exchange_rate);
    return `${equivalent} @ ${row.exchange_rate_type_code ?? 'tasa'} ${rate}`;
  }
  if (key.startsWith('payment_method_')) {
    const payment = row.payment_columns[key];
    return payment ? formatControlPayment(payment.amount, payment.currency) : '-';
  }

  const value = row[key as keyof CommissionControlRow];
  if (
    [
      'quantity',
      'amount_usd',
      'amount_ves',
      'equivalent_usd',
      'exchange_rate',
      'financed_amount',
      'total',
      'commission_usd',
      'commission_ves',
    ].includes(key)
  ) {
    return typeof value === 'string' || typeof value === 'number'
      ? formatControlNumber(value)
      : '-';
  }
  if (typeof value === 'string' || typeof value === 'number') return String(value);
  return '-';
}

function renderTotal(summary: CommissionControl['summary'], key: string): string {
  if (
    [
      'quantity',
      'amount_usd',
      'amount_ves',
      'equivalent_usd',
      'total',
      'commission_usd',
      'commission_ves',
    ].includes(key)
  ) {
    const value = summary[key as keyof CommissionControl['summary']];
    return typeof value === 'string' || typeof value === 'number'
      ? formatControlNumber(value)
      : '-';
  }
  if (key.startsWith('payment_method_')) {
    const payment = (
      summary.payment_columns as Record<string, { amount: string; currency: string }> | undefined
    )?.[key];
    return payment ? formatControlPayment(payment.amount, payment.currency) : '-';
  }
  return '';
}

function columnWidth(key: string, columns: { key: string }[]): string {
  const totalWeight = columns.reduce((total, column) => total + columnWeight(column.key), 0);
  return `${(columnWeight(key) / totalWeight) * 100}%`;
}

function columnWeight(key: string): number {
  if (key === 'product') return 2.5;
  if (key === 'quantity') return 0.65;
  if (key.startsWith('payment_method_')) return 1;
  if (['seller', 'cashier', 'branch'].includes(key)) return 1.3;
  return 1;
}

function FilterField({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="text-text-muted grid gap-1 text-xs font-medium">
      {label}
      {children}
    </label>
  );
}
