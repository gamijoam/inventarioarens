/**
 * PricesEditor: editor inline de precios por lista de un producto.
 * Carga los precios existentes, permite editarlos y guardar cambios via
 * PUT /api/products/{id}/prices.
 */
import { useEffect, useMemo, useState } from 'react';
import { Save, X, Copy } from 'lucide-react';
import { useSessionStore } from '@/stores/session';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { useProduct, useUpdateProduct, usePriceLists } from '@/features/inventory-center/api';
import { putOne } from '@/api/client';
import { formatMoney } from '@/lib/money';
import { toast } from 'sonner';
import { productKeys } from '@/features/inventory-center/queries';
import { SALE_CURRENCIES, ProductPriceSchema, type ProductPrice } from '@/features/inventory-center/schemas';

interface PriceRow {
  price_list_id: number;
  amount: string;
  currency: 'USD' | 'VES';
  isNew: boolean;
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
    queryKey: [...productKeys.detail(productId), 'prices'],
    queryFn: async () => {
      const { tenant } = useSessionStore.getState();
      // Plan C: la cookie httpOnly se envia con credentials: 'include'.
      const res = await fetch(`/api/products/${productId}/prices`, {
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(tenant?.slug ? { 'X-Tenant': tenant.slug } : {}),
        },
      });
      if (!res.ok) throw new Error('Error al cargar precios');
      const json: unknown = await res.json();
      const data = (json as { data?: unknown }).data;
      return z.array(ProductPriceSchema).parse(data);
    },
    enabled: productId > 0,
  });

  // Construye las filas del editor: union de listas existentes con precios.
  const [rows, setRows] = useState<PriceRow[]>([]);

  useEffect(() => {
    if (priceLists.length === 0) return;
    const existingByList = new Map<number, ProductPrice>();
    (pricesQuery.data ?? []).forEach((p) => existingByList.set(p.price_list_id, p));
    const next: PriceRow[] = priceLists.map((pl) => {
      const existing = existingByList.get(pl.id);
      return {
        price_list_id: pl.id,
        amount: existing?.amount ?? '',
        currency: existing?.currency ?? 'USD',
        isNew: !existing,
        dirty: false,
      };
    });
    setRows(next);
  }, [priceLists, pricesQuery.data]);

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
          price: Number(r.amount),
          currency: r.currency,
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
        <p>Aun no hay listas de precio configuradas.</p>
        <p className="mt-1 text-xs">
          Crea una lista de precio desde el modulo de catalogos para poder asignar precios aqui.
        </p>
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
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Lista</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Precio</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Moneda</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const list = priceLists.find((p) => p.id === r.price_list_id);
              if (!list) return null;
              return (
                <tr
                  key={r.price_list_id}
                  className="border-b border-border last:border-b-0"
                  data-testid={`price-row-${r.price_list_id}`}
                >
                  <td className="px-3 py-2">
                    <div className="font-medium">{list.name}</div>
                    <div className="text-xs text-text-muted">
                      {list.code}
                      {r.isNew && (
                        <Badge variant="info" className="ml-2">
                          Nuevo
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
                      onChange={(e) => setRow(r.price_list_id, { amount: e.target.value })}
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
          <Button
            size="sm"
            onClick={saveAll}
            disabled={!dirty}
            loading={updateProduct.isPending}
          >
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
    <div className="rounded-lg border border-dashed border-border bg-surface p-6 text-center text-sm text-text-muted">
      {children}
    </div>
  );
}

// Re-export del helper para uso externo.
export { formatMoney as formatPrice };
