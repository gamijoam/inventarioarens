/**
 * PricesEditor: editor inline de precios por lista de un producto.
 * Carga los precios existentes, permite editarlos y guardar cambios via
 * PUT /api/products/{id}/prices.
 */
import { useEffect, useMemo, useState } from 'react';
import { Save, X, Copy, Coins, Info } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { useProduct, usePriceLists } from '@/features/inventory-center/api';
import { InlinePriceListCreate } from './InlinePriceListCreate';
import { getMany, putOne } from '@/api/client';
import { formatMoney } from '@/lib/money';
import { toast } from '@/lib/toast';
import { productKeys } from '@/features/inventory-center/queries';
import {
  SALE_CURRENCIES,
  ProductPriceSchema,
  type ProductPrice,
  type PriceList,
} from '@/features/inventory-center/schemas';

export type CalcMode = 'margin' | 'markup';

/**
 * Calcula el precio final a partir del precio de venta base y el porcentaje.
 * Margen comercial sobre venta estándar:
 *     Precio = Base / (1 - % / 100)
 *     Ejemplo: Base = $50, % = 50 => Precio = $100.00
 */
export function calcAmountFromPercent(
  base: number,
  percent: number,
  mode: CalcMode = 'margin',
): number | null {
  if (base <= 0 || isNaN(percent) || percent < 0) return null;
  if (mode === 'margin') {
    if (percent >= 100) return null;
    const val = base / (1 - percent / 100);
    return Math.round(val * 100) / 100;
  } else {
    const val = base * (1 + percent / 100);
    return Math.round(val * 100) / 100;
  }
}

/**
 * Calcula el porcentaje a partir del precio final y el precio de venta base.
 * Margen comercial: % = ((Precio - Base) / Precio) * 100
 */
export function calcPercentFromAmount(
  base: number,
  amount: number,
  mode: CalcMode = 'margin',
): number | null {
  if (base <= 0 || amount <= 0 || isNaN(amount) || amount < base) return null;
  if (mode === 'margin') {
    const val = ((amount - base) / amount) * 100;
    return val >= 0 ? Math.round(val * 100) / 100 : null;
  } else {
    const val = ((amount - base) / base) * 100;
    return val >= 0 ? Math.round(val * 100) / 100 : null;
  }
}

export interface PriceRow {
  price_list_id: number;
  amount: string;
  percentage: string;
  currency: 'USD' | 'VES';
  isNew: boolean;
  automatic: boolean;
  remove: boolean;
  dirty: boolean;
}

export interface PricesEditorProps {
  productId?: number;
  currentBasePrice?: number;
  onPricesChange?: (
    prices: Array<{
      price_list_id: number;
      price: number | null;
      margin_percentage?: number | null;
      currency: 'USD' | 'VES';
      remove: boolean;
    }>
  ) => void;
  showSaveButton?: boolean;
}

export function PricesEditor({
  productId,
  currentBasePrice,
  onPricesChange,
  showSaveButton = true,
}: PricesEditorProps) {
  const { data: product, isLoading: productLoading } = useProduct(productId ?? 0);
  const { data: priceLists = [], isLoading: listsLoading } = usePriceLists(false);
  const qc = useQueryClient();
  const [isSaving, setIsSaving] = useState(false);

  // Carga los precios del producto si existe.
  const pricesQuery = useQuery({
    queryKey: productKeys.prices(productId ?? 0),
    queryFn: async () => {
      return z
        .array(ProductPriceSchema)
        .parse(await getMany<unknown>(`/products/${productId}/prices`))
        .filter((price) => price.is_active !== false);
    },
    enabled: Boolean(productId && productId > 0),
    staleTime: 0,
    refetchOnMount: 'always',
  });

  const [rows, setRows] = useState<PriceRow[]>([]);

  const rawCost = product?.average_cost ?? product?.last_purchase_cost;
  const costVal = rawCost != null && Number(rawCost) > 0 ? Number(rawCost) : null;
  const effectiveBase =
    currentBasePrice != null && currentBasePrice > 0
      ? currentBasePrice
      : product?.base_price != null && Number(product.base_price) > 0
        ? Number(product.base_price)
        : costVal != null
          ? Number(
              (
                costVal *
                (1 + Number(product?.profit_margin ?? 0) / 100)
              ).toFixed(2),
            )
          : 0;

  useEffect(() => {
    if (priceLists.length === 0) return;
    const existingByList = new Map<number, ProductPrice>();
    (pricesQuery.data ?? []).forEach((p) => existingByList.set(p.price_list_id, p));

    // Precio efectivo de una lista respetando la cadena de lista base
    const effectivePrice = (
      list: PriceList,
      seen = new Set<number>(),
    ): number | null => {
      if (seen.has(list.id)) {
        return effectiveBase > 0 ? effectiveBase : null;
      }
      seen.add(list.id);

      const manual = existingByList.get(list.id);
      if (manual && manual.amount !== '' && !isNaN(Number(manual.amount))) {
        return Number(manual.amount);
      }

      let base: number | null = effectiveBase > 0 ? effectiveBase : null;
      if (list.base_price_list_id) {
        const baseList = priceLists.find((l) => l.id === list.base_price_list_id);
        if (baseList) base = effectivePrice(baseList, seen);
      }

      if (base == null) return null;
      const markup = Number(list.markup_percentage ?? 0);
      return Number((base * (1 + markup / 100)).toFixed(2));
    };

    const next: PriceRow[] = priceLists.map((pl) => {
      const existing = existingByList.get(pl.id);
      const hasManual = Boolean(existing && existing.amount !== '' && !isNaN(Number(existing.amount)));
      const computed = hasManual ? null : effectivePrice(pl);
      const automatic = computed != null && !hasManual;
      const amount = hasManual ? existing!.amount : (computed == null ? '' : computed.toFixed(2));
      let percentage = '';
      if (
        existing?.margin_percentage != null &&
        existing.margin_percentage !== '' &&
        !isNaN(Number(existing.margin_percentage)) &&
        Number(existing.margin_percentage) >= 0
      ) {
        percentage = String(Number(existing.margin_percentage));
      } else if (amount && effectiveBase > 0) {
        const p = calcPercentFromAmount(effectiveBase, Number(amount), 'margin');
        if (p !== null && p >= 0) percentage = String(p);
      }
      return {
        price_list_id: pl.id,
        amount,
        percentage,
        currency: existing?.currency ?? 'USD',
        isNew: !existing,
        automatic,
        remove: false,
        dirty: false,
      };
    });

    setRows((prev) => {
      if (prev.length === 0) return next;
      return next.map((newRow) => {
        const prevRow = prev.find((r) => r.price_list_id === newRow.price_list_id);
        if (prevRow?.dirty) {
          return prevRow;
        }
        return newRow;
      });
    });
  }, [priceLists, pricesQuery.data, effectiveBase]);

  // Notificar al padre cuando cambian los precios
  useEffect(() => {
    if (onPricesChange && rows.length > 0) {
      const pricesToReport = rows
        .filter((r) => (!productId ? r.amount !== '' : r.dirty && r.amount !== ''))
        .map((r) => ({
          price_list_id: r.price_list_id,
          price: r.remove ? null : Number(r.amount),
          margin_percentage:
            r.percentage !== '' && !isNaN(Number(r.percentage)) && Number(r.percentage) >= 0
              ? Number(r.percentage)
              : null,
          currency: r.currency,
          remove: r.remove,
        }));
      onPricesChange(pricesToReport);
    }
  }, [rows, onPricesChange, productId]);

  const dirty = useMemo(() => rows.some((r) => r.dirty), [rows]);

  const setRow = (priceListId: number, patch: Partial<PriceRow>) => {
    setRows((prev) =>
      prev.map((r) => (r.price_list_id === priceListId ? { ...r, ...patch, dirty: true } : r)),
    );
  };

  const handlePercentageChange = (priceListId: number, val: string) => {
    if (val === '') {
      setRow(priceListId, { percentage: '', amount: '' });
      return;
    }
    const pNum = parseFloat(val);
    if (isNaN(pNum)) {
      setRow(priceListId, { percentage: val });
      return;
    }
    const base = effectiveBase > 0 ? effectiveBase : (currentBasePrice || 0);
    if (base <= 0) {
      toast.info('Ingresa primero el precio de venta base para calcular por margen de ganancia.');
      setRow(priceListId, { percentage: val });
      return;
    }
    if (pNum >= 100) {
      toast.warning('El margen sobre venta debe ser menor a 100%.');
      setRow(priceListId, { percentage: val });
      return;
    }
    const computed = calcAmountFromPercent(base, pNum, 'margin');
    if (computed !== null) {
      setRow(priceListId, {
        percentage: val,
        amount: computed.toFixed(2),
        automatic: false,
        remove: false,
      });
    } else {
      setRow(priceListId, { percentage: val });
    }
  };

  const handleAmountChange = (priceListId: number, val: string) => {
    const aNum = parseFloat(val);
    const base = effectiveBase > 0 ? effectiveBase : (currentBasePrice || 0);
    let computedPercent = '';
    if (!isNaN(aNum) && base > 0 && aNum > 0) {
      const p = calcPercentFromAmount(base, aNum, 'margin');
      if (p !== null && p >= 0) computedPercent = String(p);
    }
    setRow(priceListId, {
      amount: val,
      percentage: computedPercent,
      automatic: false,
      remove: false,
    });
  };

  const saveAll = async () => {
    const validRows = rows.filter((r) => r.dirty && r.amount !== '');
    if (validRows.length === 0) {
      toast.info('No hay cambios en precios para guardar.');
      return;
    }
    const payload = {
      prices: validRows.map((r) => ({
        price_list_id: r.price_list_id,
        price: r.remove ? null : Number(r.amount),
        margin_percentage: r.percentage !== '' && !isNaN(Number(r.percentage)) ? Number(r.percentage) : null,
        currency: r.currency,
        remove: r.remove,
      })),
    };
    try {
      setIsSaving(true);
      await putOne(`/products/${productId}/prices`, payload);
      toast.success('Precios por lista actualizados correctamente.');
      setRows((prev) => prev.map((r) => ({ ...r, dirty: false })));
      onPricesChange?.([]);
      void qc.invalidateQueries({ queryKey: productKeys.all });
      if (productId) {
        void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
        void qc.invalidateQueries({ queryKey: productKeys.prices(productId) });
      }
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      if (productId) {
        await pricesQuery.refetch();
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar precios.');
    } finally {
      setIsSaving(false);
    }
  };

  const copyBasePrice = (priceListId: number) => {
    const base = effectiveBase > 0 ? effectiveBase : (currentBasePrice || 0);
    if (!base) {
      toast.error('El producto no tiene precio base establecido.');
      return;
    }
    setRow(priceListId, {
      amount: String(Number(base)),
      percentage: '0',
      automatic: false,
      remove: false,
    });
  };

  const useAutomatic = (priceListId: number, listMarkup: number | null | undefined) => {
    const base = effectiveBase > 0 ? effectiveBase : (currentBasePrice || 0);
    const markup = Number(listMarkup ?? 0);
    const amount = base * (1 + markup / 100);
    const p = calcPercentFromAmount(base, amount, 'margin');
    setRow(priceListId, {
      amount: amount.toFixed(2),
      percentage: p !== null ? String(p) : '',
      automatic: true,
      remove: true,
    });
  };

  if (productLoading || listsLoading || pricesQuery.isLoading) {
    return <Spinner label="Cargando precios..." />;
  }

  if (priceLists.length === 0) {
    return (
      <Empty>
        <p className="mb-2">Aún no hay listas de precio configuradas.</p>
        <p className="mb-3 text-xs">Crea una lista desde aquí sin salir de la pantalla.</p>
        <InlinePriceListCreate
          onCreated={() => {
            /* la query se revalida sola */
          }}
        />
      </Empty>
    );
  }

  return (
    <div className="rounded-xl border border-border/80 bg-surface shadow-xs overflow-hidden">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 p-3.5 bg-surface-subtle/50 border-b border-border/70">
        <div>
          <h4 className="text-sm font-bold text-text-primary flex items-center gap-2">
            <Coins className="size-4 text-primary" />
            Tarifas y Precios por Lista de Venta
          </h4>
          <p className="text-xs text-text-muted mt-0.5">
            Precio base de venta: <strong className="font-mono text-text-primary">{effectiveBase > 0 ? formatMoney(effectiveBase) : '$0.00'}</strong>.
            Ingresa el <strong>% de ganancia</strong> o el <strong>precio en dólares</strong> para calcular automáticamente.
          </p>
        </div>
        {dirty && (
          <Badge variant="warning" className="text-xs self-start sm:self-auto font-medium">
            Cambios sin guardar
          </Badge>
        )}
      </div>

      <div className="overflow-x-auto">
        <table className="table-dense w-full text-xs">
          <thead className="border-border bg-bg/60 border-b text-left">
            <tr>
              <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                Lista de Precios
              </th>
              <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase w-32">
                % Ganancia
              </th>
              <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase w-36">
                Precio Venta ($)
              </th>
              <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase w-24">
                Moneda
              </th>
              <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase w-28">
                Acciones
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const list = priceLists.find((p) => p.id === r.price_list_id);
              if (!list) return null;
              const currencySymbol = r.currency === 'VES' ? 'Bs.' : '$';
              return (
                <tr
                  key={r.price_list_id}
                  className="border-border border-b last:border-b-0 hover:bg-surface-subtle/50 transition-colors"
                  data-testid={`price-row-${r.price_list_id}`}
                >
                  <td className="px-3 py-2.5">
                    <div className="font-semibold text-text-primary">{list.name}</div>
                    <div className="text-text-muted text-[11px] flex items-center gap-1.5 flex-wrap mt-0.5">
                      {list.code && <span className="font-mono font-medium">({list.code})</span>}
                      {r.isNew && (
                        <Badge variant={r.automatic ? 'success' : 'info'} className="text-[10px] py-0">
                          {r.automatic ? `Auto +${list.markup_percentage}%` : 'Nuevo'}
                        </Badge>
                      )}
                      {!r.isNew && (
                        <Badge variant="default" className="text-[10px] py-0">
                          Manual
                        </Badge>
                      )}
                      {r.dirty && (
                        <Badge variant="warning" className="text-[10px] py-0">
                          Editado
                        </Badge>
                      )}
                    </div>
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <div className="relative inline-flex items-center w-full max-w-[110px]">
                      <Input
                        type="number"
                        step="0.1"
                        placeholder="0.0"
                        value={r.percentage}
                        onChange={(e) => handlePercentageChange(r.price_list_id, e.target.value)}
                        className="text-right pr-6 h-8 text-xs font-semibold font-mono"
                        title="Porcentaje de ganancia comercial deseado sobre precio base"
                      />
                      <span className="absolute right-2 text-xs text-text-muted pointer-events-none select-none font-bold">
                        %
                      </span>
                    </div>
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <div className="relative inline-flex items-center w-full max-w-[125px]">
                      <span className="absolute left-2.5 text-xs text-text-muted pointer-events-none select-none font-medium">
                        {currencySymbol}
                      </span>
                      <Input
                        type="number"
                        step="0.01"
                        min="0"
                        value={r.amount}
                        onChange={(e) => handleAmountChange(r.price_list_id, e.target.value)}
                        className="text-right pl-6 h-8 text-xs font-bold font-mono text-emerald-600 dark:text-emerald-400"
                        placeholder="0.00"
                      />
                    </div>
                  </td>
                  <td className="px-3 py-2.5">
                    <Select
                      value={r.currency}
                      onChange={(e) =>
                        setRow(r.price_list_id, { currency: e.target.value as 'USD' | 'VES' })
                      }
                      className="h-8 text-xs font-mono"
                    >
                      {SALE_CURRENCIES.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </Select>
                  </td>
                  <td className="px-3 py-2.5 text-right whitespace-nowrap">
                    {list.markup_percentage != null && !r.automatic && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => useAutomatic(r.price_list_id, list.markup_percentage)}
                        title="Restaurar cálculo automático de la lista"
                        className="text-[11px] h-7 px-2 mr-1"
                      >
                        Auto
                      </Button>
                    )}
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      onClick={() => copyBasePrice(r.price_list_id)}
                      title="Copiar precio de venta base"
                      className="h-7 w-7"
                    >
                      <Copy className="size-3.5" aria-hidden="true" />
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {showSaveButton && (
        <div className="flex items-center justify-between gap-2 p-2.5 bg-surface-subtle/30 border-t border-border/60">
          <span className="text-[11px] text-text-muted hidden sm:inline-flex items-center gap-1">
            <Info className="size-3 text-primary" />
            {productId
              ? 'Los precios se aplican al instante en el punto de venta.'
              : 'Las tarifas y márgenes configurados se guardarán automáticamente con el nuevo producto.'}
          </span>
          {productId ? (
            <div className="flex items-center gap-2 ml-auto">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => pricesQuery.refetch()}
                disabled={pricesQuery.isFetching || isSaving}
                className="h-7 text-xs"
              >
                <X className="size-3.5 mr-1" aria-hidden="true" />
                Descartar
              </Button>
              <Button
                type="button"
                size="sm"
                onClick={saveAll}
                disabled={!dirty || isSaving}
                loading={isSaving}
                className="h-7 text-xs gap-1.5"
              >
                <Save className="size-3.5" aria-hidden="true" />
                Guardar Precios
              </Button>
            </div>
          ) : (
            <div className="ml-auto text-xs text-text-muted font-medium">
              Listo para guardar con la ficha
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return (
    <div className="border-border bg-surface text-text-muted rounded-lg border border-dashed p-6 text-center text-sm">
      {children}
    </div>
  );
}

// Re-export del helper para uso externo.
export { formatMoney as formatPrice };
