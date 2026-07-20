/**
 * TransferChecklistTab: tab que muestra el checklist (preparation o
 * reception) de un traslado. Lista cada item con checkboxes que el
 * transportista/receptor puede ir marcando uno a uno via el endpoint
 * POST /api/inventory-transfers/{id}/checklist/{stage}/items/{itemId}/check.
 *
 * Muestra barra de progreso global y por item.
 */
import { useState } from 'react';
import { Check } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { Checkbox } from '@/components/ui/Checkbox';
import { useCheckChecklistItem, useChecklist } from '@/features/transfers/api';
import { cn } from '@/lib/cn';

interface TransferChecklistTabProps {
  transferId: number;
  stage: 'preparation' | 'reception';
}

const STAGE_LABELS = {
  preparation: 'Checklist de preparacion',
  reception: 'Checklist de recepcion',
} as const;

const STAGE_DESCRIPTIONS = {
  preparation: 'El transportista confirma cada item antes de despachar.',
  reception: 'El receptor confirma cada item al momento de la entrega.',
} as const;

export function TransferChecklistTab({ transferId, stage }: TransferChecklistTabProps) {
  const { data, isLoading, isError } = useChecklist(transferId, stage);
  const checkItem = useCheckChecklistItem();
  const [pendingItem, setPendingItem] = useState<number | null>(null);

  if (isLoading) return <Skeleton className="h-48 w-full" />;
  if (isError || !data) {
    return (
      <Card>
        <CardContent className="p-6 text-center text-sm text-text-muted">
          No se pudo cargar el checklist.
        </CardContent>
      </Card>
    );
  }

  const isCompleted = data.progress_percent >= 100;
  const status = data.status;
  const allItems = data.items ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center justify-between">
          <span>{STAGE_LABELS[stage]}</span>
          <span className={cn(
            'text-sm font-semibold tabular-nums',
            isCompleted ? 'text-success' : 'text-text-muted',
          )}>
            {data.progress_percent}%
          </span>
        </CardTitle>
        <CardDescription>
          {STAGE_DESCRIPTIONS[stage]} Estado: <strong>{status}</strong>.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {/* Progress bar */}
        <div className="h-2 w-full overflow-hidden rounded-full bg-bg">
          <div
            className={cn('h-full transition-all', isCompleted ? 'bg-success' : 'bg-info')}
            style={{ width: `${data.progress_percent}%` }}
            role="progressbar"
            aria-valuenow={data.progress_percent}
            aria-valuemin={0}
            aria-valuemax={100}
          />
        </div>

        {/* Items */}
        {allItems.length === 0 ? (
          <div className="text-center text-sm text-text-muted">No hay items en este checklist.</div>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {allItems.map((item) => {
              const isSerialized = item.tracking_type === 'serialized';
              const checkedUnitIds = (item.checked_product_unit_ids ?? []).length;
              const expectedUnitIds = (item.expected_product_unit_ids ?? []).length;
              const unitProgress = expectedUnitIds > 0 ? Math.min(100, Math.round((checkedUnitIds / expectedUnitIds) * 100)) : 100;
              const itemProgress = item.expected_quantity && item.expected_quantity > 0
                ? Math.min(100, Math.round(((item.checked_quantity ?? 0) / item.expected_quantity) * 100))
                : 0;
              const isItemDone = unitProgress >= 100 && itemProgress >= 100;
              const isPending = pendingItem === item.id;

              return (
                <li key={item.id} className={cn('p-3', isItemDone && 'bg-success/5')}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        {isSerialized && expectedUnitIds > 0 ? (
                          <UnitCounter
                            checked={checkedUnitIds}
                            expected={expectedUnitIds}
                            done={isItemDone}
                            onCheck={() => {
                              if (isItemDone || isPending) return;
                              setPendingItem(item.id);
                              checkItem.mutate(
                                {
                                  transferId,
                                  stage,
                                  itemId: item.id,
                                  values: {
                                    checked_quantity: null,
                                    // Para serializados: enviar TODOS los IMEIs esperados
                                    // como checkeados (el user hizo click = el item esta completo).
                                    // El backend cuenta count(checked_product_unit_ids) para
                                    // calcular el progreso.
                                    checked_product_unit_ids: isSerialized && Array.isArray(item.expected_product_unit_ids) ? item.expected_product_unit_ids : [],
                                    reason: null,
                                    notes: null,
                                  },
                                },
                                { onSettled: () => setPendingItem(null) },
                              );
                            }}
                            disabled={isPending}
                          />
                        ) : (
                          <Checkbox
                            checked={isItemDone}
                            disabled={isPending}
                            onCheckedChange={() => {
                              if (isPending) return;
                              setPendingItem(item.id);
                              checkItem.mutate(
                                {
                                  transferId,
                                  stage,
                                  itemId: item.id,
                                  values: {
                                    checked_quantity: isItemDone ? 0 : (item.expected_quantity ?? 0),
                                    checked_product_unit_ids: [],
                                    reason: null,
                                    notes: null,
                                  },
                                },
                                { onSettled: () => setPendingItem(null) },
                              );
                            }}
                            aria-label={`Marcar ${item.product_name ?? `item #${item.id}`} como checked`}
                          />
                        )}
                        <div className="min-w-0 flex-1">
                          <div className="truncate font-medium">{item.product_name ?? `Producto #${item.product_id}`}</div>
                          <div className="text-xs text-text-muted">
                            {item.product_sku && <span>SKU {item.product_sku} | </span>}
                            {isSerialized
                              ? `${checkedUnitIds} / ${expectedUnitIds} IMEIs`
                              : `${Number(item.checked_quantity ?? 0).toFixed(2)} / ${Number(item.expected_quantity ?? 0).toFixed(2)} esperado`}
                          </div>
                        </div>
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <span className={cn(
                        'text-xs font-semibold tabular-nums',
                        isItemDone ? 'text-success' : 'text-text-muted',
                      )}>
                        {isSerialized ? `${unitProgress}%` : `${itemProgress}%`}
                      </span>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}

        {isCompleted && (
          <div className="rounded-md border border-success/40 bg-success/10 p-3 text-center text-sm text-success">
            Checklist completo. Todos los items confirmados.
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function UnitCounter({
  checked,
  expected,
  done,
  onCheck,
  disabled,
}: {
  checked: number;
  expected: number;
  done: boolean;
  onCheck: () => void;
  disabled: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onCheck}
      disabled={disabled}
      className={cn(
        'flex size-6 shrink-0 items-center justify-center rounded text-xs font-semibold transition-colors',
        done ? 'bg-success text-success-foreground' : 'border border-border bg-bg text-text-muted hover:border-info',
      )}
      aria-label="Toggle checklist item"
    >
      {done ? <Check className="size-3" /> : `${checked}/${expected}`}
    </button>
  );
}
