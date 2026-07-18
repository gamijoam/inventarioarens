import { useState } from 'react';
import { ChevronDown, FileText, RotateCcw } from 'lucide-react';

import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { cn } from '@/lib/cn';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useSalesReturns, type SalesReturn } from './api';

const STATUS_LABELS: Record<string, string> = {
  processed: 'Procesada',
};

const CONDITION_LABELS: Record<string, string> = {
  sellable: 'Vendible',
  damaged: 'Dañado',
};

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

function customerLabel(item: { sale?: { customer?: { name?: string; document_number?: string | null } | null } | null }): string {
  const customer = item.sale?.customer;
  if (!customer?.name) return 'Consumidor Final';
  return customer.document_number ? `${customer.name} - ${customer.document_number}` : customer.name;
}

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

function statusVariant(status: string): 'default' | 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'processed') return 'success';
  return 'info';
}

function returnedUnits(item: SalesReturn): number {
  return (item.items ?? []).reduce((sum, line) => sum + Number(line.quantity ?? 0), 0);
}

export function SalesReturnsManager() {
  const canView = useCan(PERMISSIONS.SALES_RETURNS_VIEW);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [status, setStatus] = useState<'all' | 'processed'>('all');
  const returns = useSalesReturns({ enabled: canView });
  const allData = returns.data?.data ?? [];
  const data = status === 'all' ? allData : allData.filter((item) => item.status === status);
  const processedCount = allData.filter((item) => item.status === 'processed').length;
  const totalUnits = allData.reduce((sum, item) => sum + returnedUnits(item), 0);

  if (!canView) {
    return (
      <PermissionDenied
        permission={PERMISSIONS.SALES_RETURNS_VIEW}
        message="No tienes permiso para ver devoluciones de venta."
      />
    );
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
        description="Las devoluciones creadas desde ventas aparecerán aquí para auditoría."
      />
    );
  }

  return (
    <div className="space-y-3">
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile label="Devoluciones visibles" value={String(data.length)} />
        <InfoTile label="Procesadas" value={String(processedCount)} />
        <InfoTile label="Unidades devueltas" value={String(totalUnits)} />
        <div className="rounded border border-border bg-surface px-3 py-2">
          <label className="text-xs uppercase text-text-muted" htmlFor="sales-return-status">Estado</label>
          <Select id="sales-return-status" className="mt-1" value={status} onChange={(event) => setStatus(event.target.value as 'all' | 'processed')}>
            <option value="all">Todas</option>
            <option value="processed">Procesadas</option>
          </Select>
        </div>
      </div>

      <Card>
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

function ReturnRows({ item, expanded, onToggle }: { item: SalesReturn; expanded: boolean; onToggle: () => void }) {
  return (
    <>
      <tr className="cursor-pointer border-b border-border hover:bg-bg/50" onClick={onToggle}>
        <td className="px-2 py-2 text-text-muted">
          <ChevronDown className={cn('size-4 transition-transform', expanded ? '' : '-rotate-90')} />
        </td>
        <td className="px-3 py-2 font-medium">#{item.id}</td>
        <td className="px-3 py-2">#{item.sale_id}</td>
        <td className="px-3 py-2">{customerLabel(item)}</td>
        <td className="px-3 py-2"><Badge variant={statusVariant(item.status)}>{statusLabel(item.status)}</Badge></td>
        <td className="px-3 py-2">{item.items?.length ?? '-'}</td>
        <td className="px-3 py-2 text-text-muted">{formatDate(item.processed_at ?? item.created_at)}</td>
        <td className="px-3 py-2 text-text-muted">
          <FileText className="mr-1 inline size-3.5" />
          {item.reason ?? '-'}
        </td>
      </tr>
      {expanded && (
        <tr className="border-b border-border bg-bg/20">
          <td colSpan={8} className="px-4 py-4">
            <div className="grid gap-3 lg:grid-cols-[1fr_320px]">
              <section className="rounded border border-border bg-surface">
                <div className="border-b border-border px-3 py-2 font-semibold">Items devueltos</div>
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
              <section className="rounded border border-border bg-surface p-3">
                <h3 className="font-semibold">Auditoría</h3>
                <dl className="mt-3 space-y-2 text-sm">
                  <Metric label="Estado" value={statusLabel(item.status)} />
                  <Metric label="Procesada" value={formatDate(item.processed_at)} />
                  <Metric label="Creada" value={formatDate(item.created_at)} />
                  <Metric label="Unidades" value={String(returnedUnits(item))} />
                </dl>
                <p className="mt-3 text-xs text-text-muted">
                  Esta devolución ya fue aplicada al stock/Kardex al registrarse. Las acciones de aprobación o reembolso avanzado quedan para una fase posterior.
                </p>
              </section>
            </div>
          </td>
        </tr>
      )}
    </>
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
