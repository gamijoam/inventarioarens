/**
 * WacDisplay: muestra el Costo Promedio Ponderado (Weighted Average Cost) del producto.
 * Aplica field masking: si `average_cost_visible` es false, muestra '—'.
 *
 * Solo el backend calcula el WAC via InventoryValuationService (ver docs/INVENTORY_CATALOG_API.md).
 */
import { Calculator } from 'lucide-react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatMoney } from '@/lib/money';
import type { Product } from '../schemas';

export interface WacDisplayProps {
  product: Product;
}

export function WacDisplay({ product }: WacDisplayProps) {
  const visible = product.average_cost_visible !== false;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Calculator className="size-4" aria-hidden="true" />
          Costo Promedio Ponderado (WAC)
        </CardTitle>
        <CardDescription>
          Calculado automaticamente desde los movimientos de stock del producto.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-2">
        {visible ? (
          <p className="text-2xl font-semibold tabular-nums">
            {formatMoney(product.average_cost ?? undefined)}
          </p>
        ) : (
          <p className="flex items-center gap-2 text-text-muted">
            <span className="text-2xl font-semibold tabular-nums">—</span>
            <Badge variant="warning">Costo restringido</Badge>
          </p>
        )}
        <p className="text-xs text-text-muted">
          {visible
            ? 'Tu rol tiene permiso para ver el costo (finance.costs.view).'
            : 'Tu rol no tiene permiso finance.costs.view. Solo Gerente, Administrador y Owner pueden verlo.'}
        </p>
      </CardContent>
    </Card>
  );
}