/**
 * TransferSummary: card visual con el resumen de un traslado.
 * Similar a PurchaseSummary pero para InventoryTransfers:
 * - Status stepper (Solicitado → Preparado → Despachado → Completado).
 * - Progreso de recepcion (cuando parcial o con diferencias).
 * - Header del transfer + datos del proveedor + warehouse origen/destino.
 * - Lista de items (opcional via showItems).
 */
import { Building2, Calendar, FileText, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Card, CardContent } from '@/components/ui/Card';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { formatRelative } from '@/lib/format';
import {
  TRANSFER_STATUS_LABELS,
  type Transfer,
  type TransferStatus,
} from '@/features/transfers/schemas';

interface TransferSummaryProps {
  transfer: Transfer;
  showItems?: boolean;
}

function statusVariant(status: TransferStatus): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'requested':
      return 'default';
    case 'prepared':
      return 'info';
    case 'prepared_with_differences':
      return 'warning';
    case 'dispatched':
      return 'info';
    case 'completed':
      return 'success';
    case 'completed_with_differences':
      return 'warning';
    case 'cancelled':
      return 'default';
  }
}

export function TransferSummary({ transfer, showItems = false }: TransferSummaryProps) {
  const totalBase = Number(transfer.total_base_amount ?? 0);
  const receivedBase = Number(transfer.received_base_amount ?? 0);
  const progress = totalBase > 0 ? Math.min(100, Math.round((receivedBase / totalBase) * 100)) : 0;
  const items = Array.isArray(transfer.items) ? transfer.items : [];

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <code className="rounded bg-bg px-1.5 py-0.5 text-sm font-semibold">
                {transfer.document_number ?? '#' + transfer.id}
              </code>
              <Badge variant={statusVariant(transfer.status)}>
                {TRANSFER_STATUS_LABELS[transfer.status]}
              </Badge>
              <Badge variant={transfer.validation_mode === 'logistics' ? 'info' : 'default'} className="text-[10px]">
                {transfer.validation_mode === 'logistics' ? 'Logistico' : 'Directo'}
              </Badge>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
              {transfer.dispatched_at && (
                <span className="inline-flex items-center gap-1">
                  <Calendar className="size-3" /> Despachado: {transfer.dispatched_at}
                </span>
              )}
              {transfer.received_at && (
                <span className="inline-flex items-center gap-1">
                  <Calendar className="size-3" /> Recibido: {transfer.received_at}
                </span>
              )}
              {transfer.created_at && (
                <span>Actualizado {formatRelative(transfer.created_at)}</span>
              )}
            </div>
          </div>
          <div className="flex items-end gap-4">
            <div className="text-right">
              <div className="text-xs uppercase tracking-wide text-text-muted">Total USD</div>
              <div className="text-2xl font-bold tabular-nums">{formatMoney(transfer.total_base_amount)}</div>
            </div>
          </div>
        </div>

        {/* Stepper */}
        <StatusStepper status={transfer.status} />

        {/* Progreso de recepcion */}
        {(transfer.status === 'prepared_with_differences' || transfer.status === 'completed_with_differences') && (
          <div className="space-y-1.5">
            <div className="flex items-center justify-between text-xs">
              <span className="text-text-muted">Progreso de recepcion</span>
              <span className="font-semibold tabular-nums">
                {formatMoney(receivedBase)} / {formatMoney(totalBase)} ({progress}%)
              </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-bg">
              <div
                className={cn(
                  'h-full transition-all',
                  transfer.status === 'completed_with_differences' ? 'bg-warning' : 'bg-info',
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

        {/* Warehouse origen/destino */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <InfoRow icon={<Building2 className="size-4" />} label="Almacen origen">
            {(transfer.from_warehouse as { code?: string; name?: string } | null | undefined)?.code ?? `Almacen #${transfer.from_warehouse_id}`}
          </InfoRow>
          <InfoRow icon={<Building2 className="size-4" />} label="Almacen destino">
            {(transfer.to_warehouse as { code?: string; name?: string } | null | undefined)?.code ?? `Almacen #${transfer.to_warehouse_id}`}
          </InfoRow>
        </div>

        {/* Driver */}
        {transfer.driver && (
          <div className="space-y-1 rounded border border-info/30 bg-info/5 p-3">
            <div className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-info">
              <Truck className="size-3" /> Transportista
            </div>
            <div className="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
              <div>
                <div className="text-[10px] text-text-muted">Nombre</div>
                <div className="font-medium">{transfer.driver.name}</div>
              </div>
              {transfer.driver.document_number && (
                <div>
                  <div className="text-[10px] text-text-muted">Documento</div>
                  <div>{transfer.driver.document_number}</div>
                </div>
              )}
              {transfer.driver.vehicle_plate && (
                <div>
                  <div className="text-[10px] text-text-muted">Placa</div>
                  <div>{transfer.driver.vehicle_plate}</div>
                </div>
              )}
              {transfer.driver.carrier_company && (
                <div>
                  <div className="text-[10px] text-text-muted">Transportista</div>
                  <div>{transfer.driver.carrier_company}</div>
                </div>
              )}
            </div>
            {transfer.driver.is_driver_signed && (
              <div className="text-[10px] text-success">Firmado por el transportista.</div>
            )}
            {transfer.driver.is_receiver_signed && (
              <div className="text-[10px] text-success">Firmado por el receptor.</div>
            )}
          </div>
        )}

        {/* Lista de items */}
        {showItems && items.length > 0 && (
          <div className="space-y-2 border-t border-border pt-3">
            <div className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              <FileText className="size-3.5" /> Items ({items.length})
            </div>
            <ul className="divide-y divide-border rounded-md border border-border bg-bg/30">
              {items.map((it) => {
                const product = it.product as { name?: string; sku?: string } | null | undefined;
                const warehouse = it.warehouse as { code?: string } | null | undefined;
                return (
                  <li key={it.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium">{product?.name ?? `Producto #${it.product_id}`}</div>
                      <div className="flex items-center gap-1.5 text-xs text-text-muted">
                        {product?.sku && <code className="rounded bg-bg px-1 py-0.5">{product.sku}</code>}
                        <span>|</span>
                        <span>{warehouse?.code ?? `Almacen #${it.warehouse_id}`}</span>
                      </div>
                    </div>
                    <div className="tabular-nums text-text-muted">
                      {Number(it.received_quantity ?? 0).toFixed(2)} / {Number(it.quantity ?? 0).toFixed(2)}
                    </div>
                  </li>
                );
              })}
            </ul>
          </div>
        )}

        {/* Notas */}
        {transfer.notes && (
          <div className="space-y-1">
            <div className="text-xs font-semibold uppercase tracking-wide text-text-secondary">Notas</div>
            <p className="text-sm whitespace-pre-wrap">{transfer.notes}</p>
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
    <div className="flex items-start gap-2 rounded-md border border-border bg-bg/30 px-3 py-2">
      <span className="mt-0.5 text-text-muted">{icon}</span>
      <div className="min-w-0 flex-1">
        <div className="text-[10px] font-semibold uppercase tracking-wide text-text-muted">{label}</div>
        <div className="mt-0.5 truncate text-sm">{children}</div>
      </div>
    </div>
  );
}

function StatusStepper({ status }: { status: TransferStatus }) {
  if (status === 'cancelled') {
    return (
      <div className="flex items-center justify-center rounded-md border border-danger/40 bg-danger/10 px-3 py-2 text-sm font-medium text-danger">
        Traslado cancelado
      </div>
    );
  }

  const steps: { key: TransferStatus; label: string }[] = [
    { key: 'requested', label: 'Solicitado' },
    { key: 'prepared', label: 'Preparado' },
    { key: 'dispatched', label: 'Despachado' },
    { key: 'completed', label: 'Completado' },
  ];

  const activeIdx = status === 'requested' ? 0
    : status === 'prepared' || status === 'prepared_with_differences' ? 1
    : status === 'dispatched' ? 2
    : 3;

  return (
    <div className="flex items-center gap-2" role="status" aria-label="Estado del traslado">
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
                !done && !current && 'border border-border bg-bg text-text-muted',
              )}
              aria-current={current ? 'step' : undefined}
            >
              {done ? '\u2713' : idx + 1}
            </div>
            <span className={cn('text-xs font-medium', current ? 'text-text-primary' : 'text-text-muted')}>
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
