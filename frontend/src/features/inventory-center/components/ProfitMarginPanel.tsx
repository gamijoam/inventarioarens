/**
 * ProfitMarginPanel: gestiona el margen de ganancia del producto.
 *
 * Muestra:
 *  - Precio de venta actual (base_price)
 *  - Margen actual (profit_margin) o CTA "Definir margen"
 *  - Preview del precio proyectado: Costo actual * (1 + margen / 100)
 *
 * El dialog modal permite:
 *  1. Editar el margen (con preview en vivo del precio proyectado)
 *  2. Guardar el margen (PATCH /products/{id}/profit-margin)
 *  3. Recalcular el base_price con el nuevo margen
 *     (POST /products/{id}/recalculate-price)
 */
import { useMemo, useState } from 'react';
import { Calculator, Loader2, Save, TrendingUp, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
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
import { formatMoney } from '@/lib/money';
import type { Product } from '../schemas';

export interface ProfitMarginPanelProps {
  product: Product;
}

export function ProfitMarginPanel({ product }: ProfitMarginPanelProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [marginInput, setMarginInput] = useState<string>(
    product.profit_margin != null ? String(product.profit_margin) : '25',
  );
  const [saving, setSaving] = useState(false);
  const [recalculating, setRecalculating] = useState(false);

  const updateMargin = useUpdateProductProfitMargin();
  const recalc = useRecalculateProductPrice();

  const marginNum = product.profit_margin == null ? null : Number(product.profit_margin);
  const basePrice = product.base_price == null ? null : Number(product.base_price);
  const wac = product.average_cost == null ? null : Number(product.average_cost);

  const inputNum = Number(marginInput);
  const validInput = !Number.isNaN(inputNum) && inputNum >= 0 && inputNum <= 999.99;
  const projectedPrice = useMemo(() => {
    if (!validInput || wac == null) return null;
    return wac * (1 + inputNum / 100);
  }, [validInput, inputNum, wac]);

  function openDialog() {
    setMarginInput(marginNum != null ? String(marginNum) : '25');
    setDialogOpen(true);
  }

  async function handleSave() {
    if (!validInput) {
      toast.error('El margen debe estar entre 0 y 999.99.');
      return;
    }
    setSaving(true);
    try {
      await updateMargin.mutateAsync({ id: product.id, profit_margin: inputNum });
      toast.success('Margen actualizado.');
      setDialogOpen(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar el margen.');
    } finally {
      setSaving(false);
    }
  }

  async function handleSaveAndRecalc() {
    if (!validInput) {
      toast.error('El margen debe estar entre 0 y 999.99.');
      return;
    }
    setSaving(true);
    setRecalculating(true);
    try {
      await updateMargin.mutateAsync({ id: product.id, profit_margin: inputNum });
      const res = (await recalc.mutateAsync({ id: product.id })) as {
        data: { base_price: number; profit_margin: number };
      };
      toast.success(
        `Margen guardado y precio recalculado: ${formatMoney(res.data.base_price)}.`,
      );
      setDialogOpen(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar o recalcular.');
    } finally {
      setSaving(false);
      setRecalculating(false);
    }
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <TrendingUp className="size-4" aria-hidden="true" />
            Precio de venta y margen
          </CardTitle>
          <CardDescription>
            El margen se aplica automaticamente al recibir compras con costo
            diferente al actual.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <div className="text-xs uppercase tracking-wide text-text-muted">
                Precio de venta
              </div>
              <div className="text-2xl font-semibold tabular-nums">
                {basePrice != null ? formatMoney(basePrice) : '—'}
              </div>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wide text-text-muted">Margen</div>
              {marginNum != null ? (
                <div className="flex items-center gap-2">
                  <span className="text-2xl font-semibold tabular-nums">
                    {marginNum.toFixed(2)}%
                  </span>
                  <Button size="sm" variant="outline" onClick={openDialog}>
                    Editar margen
                  </Button>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <span className="text-text-muted">Sin margen definido</span>
                  <Button size="sm" onClick={openDialog}>
                    Definir margen
                  </Button>
                </div>
              )}
            </div>
          </div>

          {marginNum != null && wac != null && (
            <p className="text-xs text-text-muted">
              Con el costo actual y {marginNum.toFixed(2)}% de margen, el precio
              seria {formatMoney(wac * (1 + marginNum / 100))}.
            </p>
          )}

          {wac == null && (
            <p className="text-xs text-text-muted">
              Aun no hay costo registrado. Recibe una compra para que podamos
              previsualizar el precio de venta.
            </p>
          )}

          {marginNum == null && (
            <Badge variant="default" className="text-[10px]">
              Define un margen para que el precio de venta se ajuste
              automaticamente al recibir compras con costo diferente.
            </Badge>
          )}
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <TrendingUp className="size-4" />
              {marginNum != null ? 'Editar margen' : 'Definir margen'}
            </DialogTitle>
            <DialogDescription>
              Formula: precio = costo actual &times; (1 + margen / 100).
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div>
              <label className="text-sm font-medium" htmlFor="profit-margin-input">
                Porcentaje de ganancia
              </label>
              <div className="mt-1 flex items-center gap-2">
                <Input
                  id="profit-margin-input"
                  type="number"
                  step="0.01"
                  min={0}
                  max={999.99}
                  value={marginInput}
                  onChange={(e) => setMarginInput(e.target.value)}
                  data-testid="profit-margin-input"
                />
                <span className="text-sm">%</span>
              </div>
              {!validInput && (
                <p className="mt-1 text-xs text-red-500">
                  El margen debe estar entre 0 y 999.99.
                </p>
              )}
            </div>

            {projectedPrice != null && (
              <div className="rounded-md border border-border bg-surface-muted p-3">
                <p className="text-xs uppercase tracking-wide text-text-muted">
                  Precio de venta proyectado
                </p>
                <p className="mt-1 text-2xl font-semibold tabular-nums">
                  {formatMoney(projectedPrice)}
                </p>
                <p className="mt-1 text-xs text-text-muted">
                  Costo actual {formatMoney(wac ?? 0)} &times; (1 +{' '}
                  {validInput ? inputNum.toFixed(2) : '—'} / 100)
                </p>
              </div>
            )}

            {wac == null && (
              <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-700">
                No hay costo registrado todavia. No podemos previsualizar el
                precio, pero puedes guardar el margen de todas formas.
              </p>
            )}
          </div>

          <DialogFooter className="flex flex-col gap-2 sm:flex-row sm:justify-between">
            <Button
              variant="ghost"
              onClick={() => setDialogOpen(false)}
              disabled={saving || recalculating}
            >
              <X className="size-3.5" /> Cancelar
            </Button>
            <div className="flex flex-col gap-2 sm:flex-row">
              <Button
                variant="outline"
                onClick={handleSave}
                loading={saving}
                disabled={!validInput || recalculating}
                data-testid="profit-margin-save"
              >
                <Save className="size-3.5" /> Solo guardar margen
              </Button>
              <Button
                onClick={handleSaveAndRecalc}
                loading={saving || recalculating}
                disabled={!validInput || wac == null}
                data-testid="profit-margin-save-recalc"
              >
                {recalculating ? (
                  <Loader2 className="size-3.5 animate-spin" />
                ) : (
                  <Calculator className="size-3.5" />
                )}
                Guardar y recalcular precio
              </Button>
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
