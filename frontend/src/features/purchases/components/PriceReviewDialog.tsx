/**
 * PriceReviewDialog: dialog que se muestra despues de recibir una compra
 * cuando hay items cuyo costo cambio >5% y el producto tiene margen
 * definido. El usuario elige por item:
 *  - Recalcular (usar el margen actual del producto)
 *  - Cambiar margen y recalcular (input del nuevo margen)
 *  - Mantener (no tocar el base_price)
 *
 * Se invoca desde el flow de receive cuando la API retorna
 * `price_review_items: [...]` no vacio.
 */
import { useState } from 'react';
import { Calculator, Check, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';

import {
  useRecalculateProductPrice,
  useUpdateProductProfitMargin,
} from '@/features/inventory-center/api';

export interface PriceReviewItem {
  item_id?: number | null;
  product_id: number;
  product_name: string;
  previous_wac: number;
  previous_base_price: number | null;
  new_unit_cost: number;
  profit_margin: number;
  suggested_new_base_price: number;
  diff_percent: number;
}

export interface PriceReviewResult {
  product_id: number;
  decision: 'recalculate' | 'keep' | 'change-margin';
  new_margin?: number;
}

interface PriceReviewDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  items: PriceReviewItem[];
  onResolved: (results: PriceReviewResult[]) => void;
}

export function PriceReviewDialog({
  open,
  onOpenChange,
  items,
  onResolved,
}: PriceReviewDialogProps) {
  const recalc = useRecalculateProductPrice();
  const updateMargin = useUpdateProductProfitMargin();

  const [results, setResults] = useState<Record<number, PriceReviewResult | undefined>>({});
  const [editingMargin, setEditingMargin] = useState<Record<number, number | undefined>>({});
  const [pendingId, setPendingId] = useState<number | null>(null);

  async function handleRecalc(item: PriceReviewItem) {
    setPendingId(item.product_id);
    try {
      const newMargin = editingMargin[item.product_id];
      const args = newMargin != null
        ? { id: item.product_id, profit_margin: newMargin as number }
        : { id: item.product_id };
      const res = (await recalc.mutateAsync(args)) as { data: { base_price: number } };
      setResults((r) => ({
        ...r,
        [item.product_id]: {
          product_id: item.product_id,
          decision: newMargin != null ? 'change-margin' : 'recalculate',
          new_margin: newMargin,
        },
      }));
      toast.success(`${item.product_name}: base_price actualizado a $${res.data.base_price.toFixed(2)}.`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al recalcular.';
      toast.error(msg);
    } finally {
      setPendingId(null);
    }
  }

  async function handleKeep(item: PriceReviewItem) {
    setResults((r) => ({
      ...r,
      [item.product_id]: { product_id: item.product_id, decision: 'keep' },
    }));
    toast.success(`${item.product_name}: precio de venta mantenido.`);
  }

  async function handleUpdateMargin(item: PriceReviewItem, newMargin: number) {
    if (newMargin < 0 || newMargin > 999.99) {
      toast.error('El margen debe estar entre 0 y 999.99.');
      return;
    }
    setPendingId(item.product_id);
    try {
      await updateMargin.mutateAsync({ id: item.product_id, profit_margin: newMargin });
      // Re-aplicar el recalculo con el nuevo margen.
      await recalc.mutateAsync({ id: item.product_id, profit_margin: newMargin });
      setResults((r) => ({
        ...r,
        [item.product_id]: { product_id: item.product_id, decision: 'change-margin', new_margin: newMargin },
      }));
      toast.success(`${item.product_name}: margen actualizado a ${newMargin}% y precio recalculado.`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al actualizar margen.';
      toast.error(msg);
    } finally {
      setPendingId(null);
      setEditingMargin((m) => ({ ...m, [item.product_id]: undefined }));
    }
  }

  function handleConfirmAll() {
    const all = items.map((it) => (
      results[it.product_id] ?? { product_id: it.product_id, decision: 'keep' as const }
    ));
    onResolved(all);
  }

  if (items.length === 0) return null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Calculator className="size-4" aria-hidden="true" />
            Revisar precios de venta
          </DialogTitle>
          <DialogDescription>
            {items.length} {items.length === 1 ? 'producto cambio' : 'productos cambiaron'}
            su costo de reposicion de forma significativa. El margen de ganancia
            del producto sugiere recalcular el precio de venta.
          </DialogDescription>
        </DialogHeader>

        <ul className="max-h-96 space-y-2 overflow-y-auto">
          {items.map((it) => {
            const decision = results[it.product_id];
            const pending = pendingId === it.product_id;
            const editingNew = editingMargin[it.product_id];
            return (
              <li
                key={it.product_id}
                className="rounded border border-border bg-bg/30 p-3"
                data-testid={`price-review-item-${it.product_id}`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="font-medium">{it.product_name}</div>
                    <div className="mt-1 text-xs text-text-muted">
                      Costo: ${it.previous_wac.toFixed(2)} → ${it.new_unit_cost.toFixed(2)}{' '}
                      (<span className={it.diff_percent >= 0 ? 'text-warning' : 'text-success'}>
                        {it.diff_percent > 0 ? '+' : ''}{it.diff_percent.toFixed(1)}%
                      </span>)
                      {' '}· Margen actual: <strong>{it.profit_margin}%</strong>
                    </div>
                    {it.previous_base_price !== null && (
                      <div className="text-xs text-text-muted">
                        Precio de venta actual: ${it.previous_base_price.toFixed(2)} ·{' '}
                        Precio sugerido: <strong>${it.suggested_new_base_price.toFixed(2)}</strong>
                      </div>
                    )}
                  </div>
                  {decision && (
                    <BadgeComponent decision={decision.decision} />
                  )}
                </div>

                {decision === undefined && (
                  <div className="mt-2 flex flex-wrap gap-2">
                    <Button
                      size="sm"
                      onClick={() => handleRecalc(it)}
                      disabled={pending || editingNew != null}
                      loading={pending && editingNew == null}
                      data-testid={`price-review-recalc-${it.product_id}`}
                    >
                      <Calculator className="size-3.5" /> Recalcular
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setEditingMargin((m) => ({ ...m, [it.product_id]: it.profit_margin }))}
                      disabled={pending}
                      data-testid={`price-review-change-margin-${it.product_id}`}
                    >
                      Cambiar margen y recalcular
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => handleKeep(it)}
                      disabled={pending}
                      data-testid={`price-review-keep-${it.product_id}`}
                    >
                      Mantener
                    </Button>
                  </div>
                )}

                {editingNew != null && decision === undefined && (
                  <div className="mt-2 flex items-center gap-2">
                    <Label htmlFor={`margin-input-${it.product_id}`} className="text-xs">
                      Margen (%)
                    </Label>
                    <Input
                      id={`margin-input-${it.product_id}`}
                      type="number"
                      step="0.1"
                      min={0}
                      max={999.99}
                      value={editingNew}
                      onChange={(e) => setEditingMargin((m) => ({ ...m, [it.product_id]: Number(e.target.value) }))}
                      className="w-24"
                      data-testid={`price-review-margin-input-${it.product_id}`}
                    />
                    <Button
                      size="sm"
                      onClick={() => handleUpdateMargin(it, editingNew)}
                      loading={pending}
                      data-testid={`price-review-margin-confirm-${it.product_id}`}
                    >
                      Aplicar
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setEditingMargin((m) => ({ ...m, [it.product_id]: undefined }))}
                    >
                      <X className="size-3.5" />
                    </Button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={handleConfirmAll}
            data-testid="price-review-confirm-all"
          >
            <Check className="size-4" /> Confirmar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function BadgeComponent({ decision }: { decision: PriceReviewResult['decision'] }) {
  if (decision === 'recalculate') {
    return <span className="rounded bg-success/10 px-2 py-0.5 text-[10px] text-success">Recalculado</span>;
  }
  if (decision === 'change-margin') {
    return <span className="rounded bg-info/10 px-2 py-0.5 text-[10px] text-info">Margen actualizado</span>;
  }
  return <span className="rounded bg-warning/10 px-2 py-0.5 text-[10px] text-warning">Mantenido</span>;
}
