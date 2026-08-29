/**
 * PricesEditor: editor inline de precios por lista de un producto.
 * Carga los precios existentes, permite editarlos y guardar cambios via
 * PUT /api/products/{id}/prices.
 */
import { useEffect, useMemo, useState } from 'react';
import { Save, X, Copy } from 'lucide-react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { useProduct, useUpdateProduct, usePriceLists } from '@/features/inventory-center/api';
import { InlinePriceListCreate } from './InlinePriceListCreate';
import { getMany, putOne } from '@/api/client';
import { formatMoney } from '@/lib/money';
import { toast } from 'sonner';
import { productKeys } from '@/features/inventory-center/queries';
import {
  SALE_CURRENCIES,
  ProductPriceSchema,
  type ProductPrice,
  type PriceList,
} from '@/features/inventory-center/schemas';

interface PriceRow {
  price_list_id: number;
  amount: string;
  currency: 'USD' | 'VES';
  isNew: boolean;
  automatic: boolean;
  remove: boolean;
  dirty: boolean;
}

export interface PricesEditorProps {
  productId: number;
}

export function PricesEditor({ productId }: PricesEditorProps) {
  const { data: product, isLoading: productLoading } = useProduct(productId);
  const { data: priceLists = [], isLoading: listsLoading } = usePriceLists(false);
  const updateProduct = useUpdateProduct();
  const qc = useQueryClient();

  // Carga los precios del producto.
  // Shape real del backend (verificado 2026-07-14):
  // { "data": [{ id, tenant_id, product_id, price_list_id, price_list: {...},
  //              price: number, currency, exchange_rate_type_id, exchange_rate_type,
  //              is_active, created_at, updated_at }, ...] }
  const pricesQuery = useQuery({
    queryKey: productKeys.prices(productId),
    queryFn: async () => {
      return z
        .array(ProductPriceSchema)
        .parse(await getMany<unknown>(`/products/${productId}/prices`))
        .filter((price) => price.is_active !== false);
    },
    enabled: productId > 0,
    staleTime: 0,
    refetchOnMount: 'always',
  });

  // Construye las filas del editor: union de listas existentes con precios.
  const [rows, setRows] = useState<PriceRow[]>([]);

  useEffect(() => {
    if (priceLists.length === 0) return;
    const existingByList = new Map<number, ProductPrice>();
    (pricesQuery.data ?? []).forEach((p) => existingByList.set(p.price_list_id, p));

    // Precio efectivo de una lista respetando la cadena de lista base
    // (ej. CASHEA = DETAL + 16%), con guard de ciclos.
    const effectivePrice = (
      list: PriceList,
      seen = new Set<number>(),
    ): number | null => {
      if (seen.has(list.id)) return product?.base_price ? Number(product.base_price) : null;
      seen.add(list.id);

      const manual = existingByList.get(list.id);
      if (manual) return Number(manual.amount);

      let base: number | null = product?.base_price != null ? Number(product.base_price) : null;
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
      const computed = existing ? null : effectivePrice(pl);
      const automatic = computed != null && !existing;
      return {
        price_list_id: pl.id,
        amount: existing?.amount ?? (computed == null ? '' : computed.toFixed(2)),
        currency: existing?.currency ?? 'USD',
        isNew: !existing,
        automatic,
        remove: false,
        dirty: false,
      };
    });
    setRows(next);
  }, [priceLists, pricesQuery.data, product?.base_price]);

  const dirty = useMemo(() => rows.some((r) => r.dirty), [rows]);

  const setRow = (priceListId: number, patch: Partial<PriceRow>) => {
    setRows((prev) =>
      prev.map((r) => (r.price_list_id === priceListId ? { ...r, ...patch, dirty: true } : r)),
    );
  };

  const saveAll = async () => {
    const payload = {
      prices: rows
        .filter((r) => r.dirty && r.amount !== '')
        .map((r) => ({
          price_list_id: r.price_list_id,
          price: r.remove ? null : Number(r.amount),
          currency: r.currency,
          remove: r.remove,
        })),
    };
    if (payload.prices.length === 0) {
      toast.info('No hay cambios para guardar.');
      return;
    }
    try {
      // Reutilizamos putOne del client HTTP.
      await putOne(`/products/${productId}/prices`, payload);
      toast.success('Precios actualizados.');
      void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
      void qc.invalidateQueries({ queryKey: productKeys.prices(productId) });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      void pricesQuery.refetch();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar precios.');
    }
  };

  const copyBasePrice = (priceListId: number) => {
    if (!product) return;
    const base = product.base_price;
    if (!base) {
      toast.error('El producto no tiene precio base.');
      return;
    }
    setRow(priceListId, { amount: String(Number(base)) });
  };

  if (productLoading || listsLoading || pricesQuery.isLoading) {
    return <Spinner label="Cargando precios..." />;
  }

  if (priceLists.length === 0) {
    return (
      <Empty>
        <p className="mb-2">Aun no hay listas de precio configuradas.</p>
        <p className="mb-3 text-xs">Crea una desde aca sin salir de la pantalla.</p>
        <InlinePriceListCreate
          onCreated={() => {
            /* la query se revalida sola */
          }}
        />
      </Empty>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Precios por lista</CardTitle>
        <CardDescription>
          Edita inline y guarda. Si una lista no tiene precio aun, se creara al guardar.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3 p-0">
        <table className="table-dense w-full">
          <thead className="border-border bg-bg/60 border-b text-left">
            <tr>
              <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                Lista
              </th>
              <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                Precio
              </th>
              <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                Moneda
              </th>
              <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                Acciones
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const list = priceLists.find((p) => p.id === r.price_list_id);
              if (!list) return null;
              return (
                <tr
                  key={r.price_list_id}
                  className="border-border border-b last:border-b-0"
                  data-testid={`price-row-${r.price_list_id}`}
                >
                  <td className="px-3 py-2">
                    <div className="font-medium">{list.name}</div>
                    <div className="text-text-muted text-xs">
                      {list.code}
                      {r.isNew && (
                        <Badge variant={r.automatic ? 'success' : 'info'} className="ml-2">
                          {r.automatic ? `Automatico +${list.markup_percentage}%` : 'Nuevo'}
                        </Badge>
                      )}
                      {!r.isNew && (
                        <Badge variant="default" className="ml-2">
                          Manual
                        </Badge>
                      )}
                      {r.dirty && (
                        <Badge variant="warning" className="ml-2">
                          Sin guardar
                        </Badge>
                      )}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    <Input
                      type="number"
                      step="0.01"
                      min="0"
                      value={r.amount}
                      onChange={(e) =>
                        setRow(r.price_list_id, {
                          amount: e.target.value,
                          automatic: false,
                          remove: false,
                        })
                      }
                      className="text-right"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <Select
                      value={r.currency}
                      onChange={(e) =>
                        setRow(r.price_list_id, { currency: e.target.value as 'USD' | 'VES' })
                      }
                    >
                      {SALE_CURRENCIES.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </Select>
                  </td>
                  <td className="px-3 py-2 text-right">
                    {list.markup_percentage != null && !r.automatic && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          const base = Number(product?.base_price ?? 0);
                          const amount = base * (1 + Number(list.markup_percentage) / 100);
                          setRow(r.price_list_id, {
                            amount: amount.toFixed(2),
                            automatic: true,
                            remove: true,
                          });
                        }}
                      >
                        Usar automatico
                      </Button>
                    )}
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      onClick={() => copyBasePrice(r.price_list_id)}
                      title="Copiar precio base"
                    >
                      <Copy className="size-4" aria-hidden="true" />
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        <div className="flex items-center justify-end gap-2 p-3">
          <Button
            variant="outline"
            size="sm"
            onClick={() => pricesQuery.refetch()}
            disabled={pricesQuery.isFetching}
          >
            <X className="size-4" aria-hidden="true" />
            Cancelar
          </Button>
          <Button size="sm" onClick={saveAll} disabled={!dirty} loading={updateProduct.isPending}>
            <Save className="size-4" aria-hidden="true" />
            Guardar cambios
          </Button>
        </div>
      </CardContent>
    </Card>
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
