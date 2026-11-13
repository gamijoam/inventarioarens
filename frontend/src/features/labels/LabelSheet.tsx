import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/cn';
import { Barcode } from './Barcode';
import type { LabelFormat, ProductLabel } from './schemas';

interface PrintableLabel {
  key: string;
  name: string;
  code: string | null;
  priceUsd: number;
  priceVes: number | null | undefined;
  caption: string | null;
}

interface LabelSheetProps {
  labels: ProductLabel[];
  copies: number;
  format: LabelFormat;
  showVes: boolean;
  showPackagings: boolean;
}

export function expandLabels(labels: ProductLabel[], showPackagings: boolean): PrintableLabel[] {
  return labels.flatMap((label) => {
    const rate = label.exchange_rate ? `${label.exchange_rate_type_code ?? 'Tasa'} ${formatMoney(label.exchange_rate, { showCurrency: false })}` : null;
    const unit: PrintableLabel = {
      key: `p-${label.product_id}`,
      name: label.name,
      code: label.barcode ?? label.sku ?? null,
      priceUsd: label.price_usd,
      priceVes: label.price_ves,
      caption: rate,
    };
    const packs = showPackagings
      ? label.packagings.map((pack, index) => ({
          key: `p-${label.product_id}-k-${index}`,
          name: `${label.name} - ${pack.name}`,
          code: pack.barcode ?? null,
          priceUsd: pack.price_usd,
          priceVes: pack.price_ves,
          caption: `${pack.factor} unidades`,
        }))
      : [];
    return [unit, ...packs];
  });
}

export function LabelSheet({ labels, copies, format, showVes, showPackagings }: LabelSheetProps) {
  const items = expandLabels(labels, showPackagings).flatMap((item) =>
    Array.from({ length: Math.max(1, copies) }, (_, copy) => ({ ...item, key: `${item.key}-${copy}` })),
  );

  return (
    <div
      data-testid="label-sheet"
      className={cn('label-sheet grid gap-1 bg-white text-black', format === 'a4' ? 'grid-cols-3' : 'grid-cols-[repeat(auto-fill,50mm)]')}
    >
      {items.map((item, index) => (
        <div
          key={item.key}
          className={cn(
            'flex break-inside-avoid flex-col justify-between overflow-hidden border border-dashed border-gray-400 p-1.5',
            format === 'a4' ? 'h-[36mm]' : 'h-[30mm] w-[50mm]',
          )}
        >
          <p className="line-clamp-2 text-[11px] font-semibold leading-tight">{item.name}</p>
          <div className="flex items-end justify-between gap-1">
            <span data-testid={`label-price-usd-${index}`} className="text-lg font-black leading-none">
              {formatMoney(item.priceUsd)}
            </span>
            {showVes && item.priceVes != null && (
              <span data-testid={`label-price-ves-${index}`} className="text-[11px] font-semibold leading-none">
                {formatMoney({ amount: String(item.priceVes), currency: 'VES' })}
              </span>
            )}
          </div>
          {item.code && <Barcode value={item.code} className="h-[7mm] w-full" />}
          <div className="flex justify-between font-mono text-[8px] leading-none">
            <span>{item.code ?? ''}</span>
            <span>{item.caption ?? ''}</span>
          </div>
        </div>
      ))}
    </div>
  );
}
