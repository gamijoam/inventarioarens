import { useState } from 'react';
import { Check, Gift, Tag } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { isInvoiceDiscountType, type Promotion } from '@/features/promotions/schemas';

interface PromotionsPanelProps {
  promotions?: Promotion[];
  selectedId?: number | null;
  onSelect: (promotion: Promotion, sets: number) => void;
  onSelectDiscount?: (promotion: Promotion) => void;
  invoicePromotions?: Promotion[];
  combos?: Promotion[];
  productOffers?: Promotion[];
  selectedInvoiceId?: number | null;
  selectedComboIds?: number[];
  onSelectCombo?: (promotion: Promotion, sets: number) => void;
  onSelectProductOffer?: (promotion: Promotion) => void;
  isLoading?: boolean;
  error?: string | null;
}

export function PromotionsPanel({
  promotions,
  selectedId,
  onSelect,
  onSelectDiscount,
  invoicePromotions,
  combos,
  productOffers,
  selectedInvoiceId,
  selectedComboIds = [],
  onSelectCombo,
  onSelectProductOffer,
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

  const legacyPromotions = promotions ?? [];
  const discounts =
    invoicePromotions ??
    legacyPromotions.filter((promotion) => isInvoiceDiscountType(promotion.benefit_type));
  const comboPromotions =
    combos ??
    legacyPromotions.filter((promotion) => !isInvoiceDiscountType(promotion.benefit_type));
  const offerPromotions = productOffers ?? [];
  const hasExplicitDomains =
    invoicePromotions !== undefined || combos !== undefined || productOffers !== undefined;
  const availableCount = hasExplicitDomains
    ? discounts.length + comboPromotions.length + offerPromotions.length
    : legacyPromotions.length;

  if (availableCount === 0) {
    return (
      <div className="border-border text-text-muted flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed p-6 text-center">
        <Gift className="text-primary/60 mb-3 size-8" />
        <p className="font-semibold">No hay promociones disponibles</p>
        <p className="mt-1 text-sm">No hay combos ni descuentos vigentes para este ticket.</p>
      </div>
    );
  }

  return (
    <div>
      {discounts.length > 0 && (
        <PromotionSection title="Descuentos de factura">
          {discounts.map((promotion) => {
            const selected = (selectedInvoiceId ?? selectedId ?? null) === promotion.id;

            return (
              <PromotionCard
                key={promotion.id}
                promotion={promotion}
                selected={selected}
                onClick={() => onSelectDiscount?.(promotion)}
                actionLabel={selected ? 'Descuento aplicado' : 'Aplicar a la factura'}
              />
            );
          })}
        </PromotionSection>
      )}

      {comboPromotions.length > 0 && (
        <PromotionSection
          title={hasExplicitDomains ? 'Combos' : 'Combos y promociones de productos'}
        >
          <div className="border-border bg-surface mb-4 flex items-center justify-between gap-3 rounded-xl border p-3">
            <div>
              <p className="text-sm font-semibold">Cantidad de conjuntos</p>
              <p className="text-text-muted text-xs">
                Carga todos los componentes de cada conjunto.
              </p>
            </div>
            <Input
              className="w-24"
              type="number"
              min={1}
              max={99}
              value={sets}
              onChange={(event) =>
                setSets(Math.min(99, Math.max(1, Number(event.target.value) || 1)))
              }
              aria-label="Cantidad de conjuntos"
            />
          </div>
          {comboPromotions.map((promotion) => {
            const selected = selectedComboIds.includes(promotion.id) || selectedId === promotion.id;

            return (
              <PromotionCard
                key={promotion.id}
                promotion={promotion}
                selected={selected}
                onClick={() => (onSelectCombo ?? onSelect)(promotion, sets)}
                actionLabel={selected ? 'Promoción cargada' : 'Cargar promoción'}
              />
            );
          })}
        </PromotionSection>
      )}
      {offerPromotions.length > 0 && (
        <PromotionSection title="Ofertas por producto">
          {offerPromotions.map((promotion) => (
            <PromotionCard
              key={promotion.id}
              promotion={promotion}
              selected={false}
              onClick={() => (onSelectProductOffer ?? (() => undefined))(promotion)}
              actionLabel="Aplicar a línea"
            />
          ))}
        </PromotionSection>
      )}
    </div>
  );
}

function PromotionSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mb-5 last:mb-0">
      <h3 className="mb-3 text-sm font-bold tracking-wide uppercase">{title}</h3>
      <div className="grid gap-3 sm:grid-cols-2">{children}</div>
    </section>
  );
}

function PromotionCard({
  promotion,
  selected,
  onClick,
  actionLabel,
}: {
  promotion: Promotion;
  selected: boolean;
  onClick: () => void;
  actionLabel: string;
}) {
  const isInvoiceDiscount = isInvoiceDiscountType(promotion.benefit_type);

  return (
    <article
      className={`border-border rounded-xl border p-4 transition-colors ${selected ? 'border-primary bg-primary/5' : 'bg-surface'}`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-2">
          <div className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
            <Tag className="size-4" />
          </div>
          <div className="min-w-0">
            <h3 className="truncate font-semibold">{promotion.name}</h3>
            <div className="flex flex-wrap gap-1">
              {promotion.code && <Badge variant="info">{promotion.code}</Badge>}
              {promotion.benefit_type === 'buy_x_get_y' && <Badge variant="success">2x1</Badge>}
            </div>
          </div>
        </div>
        <strong className="shrink-0 text-lg">{formatPromotionValue(promotion)}</strong>
      </div>

      <p className="text-text-muted mt-3 text-xs font-semibold uppercase">
        {isInvoiceDiscount
          ? promotion.items.length === 0
            ? 'Aplicación'
            : 'Productos elegibles'
          : 'Incluye'}
      </p>
      <p className="text-text-secondary mt-1 text-sm">
        {isInvoiceDiscount && promotion.items.length === 0
          ? 'Se aplica al total de la factura.'
          : promotion.items
              .map((item) => item.product_name ?? `Producto #${item.product_id}`)
              .join(' + ')}
      </p>

      <Button
        className="mt-4 w-full"
        variant={selected ? 'secondary' : 'outline'}
        size="sm"
        onClick={onClick}
        aria-label={`Aplicar ${promotion.code ?? promotion.name}`}
      >
        {selected && <Check className="size-4" />}
        {actionLabel}
      </Button>
    </article>
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
