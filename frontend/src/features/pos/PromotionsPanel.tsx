import { useState } from 'react';
import { Check, Gift, Tag } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import type { Promotion } from '@/features/promotions/schemas';

interface PromotionsPanelProps {
  promotions: Promotion[];
  selectedId: number | null;
  onSelect: (promotion: Promotion, sets: number) => void;
  isLoading?: boolean;
  error?: string | null;
}

export function PromotionsPanel({
  promotions,
  selectedId,
  onSelect,
  isLoading = false,
  error = null,
}: PromotionsPanelProps) {
  const [sets, setSets] = useState(1);

  if (isLoading) {
    return <p className="text-text-muted p-6 text-sm">Cargando promociones...</p>;
  }

  if (error) {
    return (
      <p className="border-danger/30 bg-danger/10 text-danger rounded-lg border p-4 text-sm">
        {error}
      </p>
    );
  }

  if (promotions.length === 0) {
    return (
      <div className="border-border text-text-muted flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed p-6 text-center">
        <Gift className="text-primary/60 mb-3 size-8" />
        <p className="font-semibold">No hay promociones disponibles</p>
        <p className="mt-1 text-sm">Agrega productos al ticket para consultar combos vigentes.</p>
      </div>
    );
  }

  return (
    <div>
      <div className="border-border bg-surface mb-4 flex items-center justify-between gap-3 rounded-xl border p-3">
        <div>
          <p className="text-sm font-semibold">Cantidad de conjuntos</p>
          <p className="text-text-muted text-xs">Carga todos los componentes de cada conjunto.</p>
        </div>
        <Input
          className="w-24"
          type="number"
          min={1}
          max={99}
          value={sets}
          onChange={(event) => setSets(Math.min(99, Math.max(1, Number(event.target.value) || 1)))}
          aria-label="Cantidad de conjuntos"
        />
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        {promotions.map((promotion) => {
          const selected = selectedId === promotion.id;

          return (
            <article
              key={promotion.id}
              className={`border-border rounded-xl border p-4 transition-colors ${selected ? 'border-primary bg-primary/5' : 'bg-surface'}`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2">
                  <div className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <Tag className="size-4" />
                  </div>
                  <div className="min-w-0">
                    <h3 className="truncate font-semibold">{promotion.name}</h3>
                    {promotion.code && <Badge variant="info">{promotion.code}</Badge>}
                  </div>
                </div>
                <strong className="shrink-0 text-lg">{formatPromotionValue(promotion)}</strong>
              </div>

              <p className="text-text-muted mt-3 text-xs font-semibold uppercase">Incluye</p>
              <p className="text-text-secondary mt-1 text-sm">
                {promotion.items
                  .map((item) => item.product_name ?? `Producto #${item.product_id}`)
                  .join(' + ')}
              </p>

              <Button
                className="mt-4 w-full"
                variant={selected ? 'secondary' : 'outline'}
                size="sm"
                onClick={() => onSelect(promotion, sets)}
                aria-label={`Aplicar ${promotion.code ?? promotion.name}`}
              >
                {selected && <Check className="size-4" />}
                {selected ? 'Promoción cargada' : 'Cargar promoción'}
              </Button>
            </article>
          );
        })}
      </div>
    </div>
  );
}

function formatUsd(value: number): string {
  return `$${value.toFixed(2)}`;
}

function formatPromotionValue(promotion: Promotion): string {
  return promotion.benefit_type === 'percent_discount'
    ? `${promotion.discount_percent ?? 0}% OFF`
    : promotion.benefit_type === 'fixed_discount'
      ? `-${formatUsd(promotion.discount_amount_usd ?? 0)}`
      : promotion.benefit_type === 'fixed_item_price'
        ? `${formatUsd(promotion.price_usd)}/u`
        : promotion.benefit_type === 'free_item'
          ? 'GRATIS'
          : promotion.benefit_type === 'buy_x_get_y'
            ? 'COMPRA / REGALO'
            : formatUsd(promotion.price_usd);
}
