/**
 * ProfitMarginPanel: muestra el margen de ganancia del producto y
 * permite recalcular el base_price. Reemplaza al WacDisplay para
 * no generar confusion en el usuario (no exponemos el WAC).
 *
 * El WAC se sigue calculando internamente (InventoryValuationService) y
 * se usa en la formula de recalculo, pero no se muestra al usuario.
 */
import { useState } from 'react';
import { Calculator, Save, TrendingUp } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';

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
  const [editing, setEditing] = useState(false);
  const [margin, setMargin] = useState<string>(
    product.profit_margin != null ? String(product.profit_margin) : '',
  );
  const [savingMargin, setSavingMargin] = useState(false);
  const [recalculating, setRecalculating] = useState(false);

  const updateMargin = useUpdateProductProfitMargin();
  const recalc = useRecalculateProductPrice();

  const marginNum = product.profit_margin == null ? null : Number(product.profit_margin);
  const basePrice = product.base_price == null ? null : Number(product.base_price);

  async function handleSaveMargin() {
    const v = Number(margin);
    if (Number.isNaN(v) || v < 0 || v > 999.99) {
      toast.error('El margen debe estar entre 0 y 999.99.');
      return;
    }
    setSavingMargin(true);
    try {
      await updateMargin.mutateAsync({ id: product.id, profit_margin: v });
      toast.success('Margen actualizado.');
      setEditing(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al guardar el margen.';
      toast.error(msg);
    } finally {
      setSavingMargin(false);
    }
  }

  async function handleRecalculate() {
    setRecalculating(true);
    try {
      const res = (await recalc.mutateAsync({ id: product.id })) as { data: { base_price: number; profit_margin: number } };
      toast.success(`Precio recalculado: $${res.data.base_price.toFixed(2)} (margen ${res.data.profit_margin}%).`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al recalcular.';
      toast.error(msg);
    } finally {
      setRecalculating(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <TrendingUp className="size-4" aria-hidden="true" />
            Precio de venta y margen
          </CardTitle>
          <CardDescription>
            El margen se aplica automaticamente al recibir compras con costo
            diferente al actual. Formula: precio = costo * (1 + margen / 100).
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Precio de venta</div>
            <div className="text-2xl font-semibold tabular-nums">
              {basePrice != null ? formatMoney(basePrice) : '—'}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-text-muted">Margen</div>
            {marginNum != null ? (
              editing ? (
                <div className="flex items-center gap-2">
                  <Input
                    type="number"
                    step="0.01"
                    min={0}
                    max={999.99}
                    value={margin}
                    onChange={(e) => setMargin(e.target.value)}
                    className="w-24"
                    data-testid="profit-margin-input"
                  />
                  <span className="text-sm">%</span>
                  <Button
                    size="sm"
                    onClick={handleSaveMargin}
                    loading={savingMargin}
                    data-testid="profit-margin-save"
                  >
                    <Save className="size-3.5" />
                  </Button>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <span className="text-2xl font-semibold tabular-nums">{marginNum.toFixed(2)}%</span>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setMargin(String(marginNum));
                      setEditing(true);
                    }}
                    data-testid="profit-margin-edit"
                  >
                    Editar
                  </Button>
                </div>
              )
            ) : (
              <div className="flex items-center gap-2">
                <span className="text-text-muted">Sin margen definido</span>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setMargin('25');
                    setEditing(true);
                  }}
                  data-testid="profit-margin-add"
                >
                  Definir margen
                </Button>
              </div>
            )}
          </div>
        </div>

        {marginNum != null && (
          <div className="border-t border-border pt-3">
            <p className="text-xs text-text-muted">
              Recalcular el precio de venta aplicara el margen al costo promedio
              actual (WAC) sin redondeo. El cliente puede redondear despues.
            </p>
            <Button
              size="sm"
              variant="secondary"
              onClick={handleRecalculate}
              loading={recalculating}
              data-testid="profit-margin-recalculate"
              className="mt-2"
            >
              <Calculator className="size-3.5" /> Recalcular ahora
            </Button>
          </div>
        )}

        {marginNum == null && (
          <Badge variant="default" className="text-[10px]">
            Define un margen para que el precio de venta se ajuste
            automaticamente al recibir compras con costo diferente.
          </Badge>
        )}
      </CardContent>
    </Card>
  );
}
