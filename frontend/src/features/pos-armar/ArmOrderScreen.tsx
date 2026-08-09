import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Loader2, Plus, Search, Trash2 } from 'lucide-react';

import { useAuth } from '@/auth/useAuth';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import type { Product } from '@/features/inventory-center/schemas';
import { PosShell } from '@/components/layout/PosShell';
import {
  type HoldPayload,
  useBootstrapRefsForPos,
  useHoldOrder,
  usePosProductsDebounced,
} from '@/features/pos/api';
import { TapButton } from '@/features/pos/TapButton';
import { applyKey, canSearch, money, normalizeSearch, type KeyAction } from './armOrderLogic';
import { OnScreenKeyboard } from './OnScreenKeyboard';

interface CartLine {
  id: string;
  product: Product;
  quantity: number;
}

/**
 * Pantalla tactil "Armar orden" para vendedores (permiso pos.orders.hold).
 *
 * A diferencia del POS de caja, esta pantalla NO usa el teclado del sistema:
 * tiene un teclado on-screen propio con botones grandes, pensado para
 * tablets Android donde el teclado virtual cancela los taps. El vendedor
 * busca, arma el ticket y lo envia para que la cajera lo cobre.
 */
export function ArmOrderScreen() {
  const { signOut } = useAuth();
  const [query, setQuery] = useState('');
  const [cart, setCart] = useState<CartLine[]>([]);
  const holdOrder = useHoldOrder();
  const refs = useBootstrapRefsForPos();
  const warehouse = refs.refs?.warehouses?.[0] ?? null;
  const warehouseId = warehouse?.id ?? null;

  const { data: productPage, isLoading } = usePosProductsDebounced(query, warehouseId, {
    enabled: canSearch(query) && warehouseId != null,
    debounceMs: 150,
  });
  const products = useMemo(() => productPage?.data ?? [], [productPage?.data]);

  const total = cart.reduce(
    (sum, line) => sum + Number(line.product.base_price ?? 0) * line.quantity,
    0,
  );

  function handleKey(action: KeyAction): void {
    setQuery((current) => applyKey(current, action));
  }

  function addProduct(product: Product): void {
    setCart((current) => {
      const existing = current.find((line) => line.product.id === product.id);
      if (existing) {
        return current.map((line) =>
          line.id === existing.id ? { ...line, quantity: line.quantity + 1 } : line,
        );
      }
      return [...current, { id: crypto.randomUUID(), product, quantity: 1 }];
    });
    setQuery('');
  }

  function removeLine(index: number): void {
    setCart((current) => current.filter((_, i) => i !== index));
  }

  async function submitOrder(): Promise<void> {
    if (cart.length === 0) {
      toast.error('Agrega al menos un producto.');
      return;
    }
    if (!warehouseId) {
      toast.error('No hay almacen disponible.');
      return;
    }

    const payload: HoldPayload = {
      customer_name: 'Consumidor Final',
      items: cart.map((line) => ({
        warehouse_id: warehouseId,
        product_id: line.product.id,
        price_list_id: null,
        price_source: 'base',
        quantity: line.quantity,
        product_unit_ids: [],
      })),
    };

    try {
      const order = await holdOrder.mutateAsync(payload);
      setCart([]);
      setQuery('');
      toast.success(`Orden #${order.id} armada. La cajera la cobrara.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo armar la orden.');
    }
  }

  return (
    <PosShell onExit={() => void signOut()}>
      <div className="bg-bg text-text-primary flex h-dvh min-h-0 flex-col overflow-hidden">
        <header className="border-border/80 bg-surface/95 flex min-h-16 shrink-0 items-center gap-3 border-b px-4 py-3 pr-32">
          <div className="min-w-0">
            <h1 className="text-text-primary text-lg font-bold">Armar pedido</h1>
            <p className="text-text-muted truncate text-xs sm:text-sm">
              {warehouse?.name ? `${warehouse.name} · ` : ''}Selecciona productos y envíalos a caja.
            </p>
          </div>
          <div className="ml-auto flex items-center">
            <Badge variant="info">{cart.length} productos</Badge>
          </div>
        </header>

        <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_minmax(240px,32vw)] gap-3 overflow-hidden p-3 max-[560px]:grid-cols-1 max-[560px]:overflow-auto sm:gap-4 sm:p-4">
          <section className="flex min-h-0 flex-col gap-3">
            <div className="border-border bg-surface flex items-center gap-3 rounded-2xl border px-4 py-3">
              <Search className="text-text-muted size-5 shrink-0" />
              <p className="text-text-primary flex-1 truncate text-xl font-semibold">
                {normalizeSearch(query) ? query : 'Escribe para buscar...'}
              </p>
              {query && (
                <button
                  type="button"
                  data-testid="clear-search"
                  onClick={() => setQuery('')}
                  className="text-text-muted hover:text-text-primary"
                  aria-label="Limpiar busqueda"
                >
                  <Trash2 className="size-5" />
                </button>
              )}
            </div>

            <div className="min-h-0 flex-1 overflow-auto rounded-2xl">
              {isLoading ? (
                <div className="border-border bg-surface text-text-muted flex items-center justify-center rounded-2xl border p-8 text-sm">
                  <Loader2 className="size-5 animate-spin" /> Buscando...
                </div>
              ) : products.length === 0 ? (
                <div className="border-border bg-surface text-text-muted rounded-2xl border p-8 text-center text-sm">
                  {canSearch(query)
                    ? 'No hay productos con esa busqueda.'
                    : 'Usa el teclado para buscar un producto.'}
                </div>
              ) : (
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
                  {products.map((product) => (
                    <TapButton
                      key={product.id}
                      data-testid={`product-${product.id}`}
                      onPress={() => addProduct(product)}
                      className="border-border bg-surface hover:border-primary/60 hover:bg-primary/5 active:border-primary active:bg-primary/10 group min-h-24 touch-manipulation overflow-hidden rounded-2xl border p-4 text-left shadow-sm transition-all select-none"
                    >
                      <p className="truncate font-semibold">{product.name}</p>
                      <p className="text-text-muted font-mono text-xs">
                        {product.sku ?? product.barcode ?? 'Sin codigo'}
                      </p>
                      <div className="mt-3 flex items-center justify-between">
                        <span className="text-lg font-bold">
                          {money(Number(product.base_price ?? 0))}
                        </span>
                        <span className="bg-primary/10 text-primary rounded-full p-1.5 opacity-0 transition-opacity group-hover:opacity-100">
                          <Plus className="size-4" />
                        </span>
                      </div>
                    </TapButton>
                  ))}
                </div>
              )}
            </div>

            <OnScreenKeyboard onKey={handleKey} disabled={holdOrder.isPending} />
          </section>

          <aside className="border-border bg-surface flex min-h-0 flex-col rounded-2xl border shadow-sm max-[560px]:min-h-80">
            <div className="border-border border-b p-4">
              <h2 className="font-bold">Ticket</h2>
              <p className="text-text-muted text-xs">Se envia a la cajera para cobro.</p>
            </div>
            <div className="min-h-0 flex-1 space-y-2 overflow-auto p-3">
              {cart.length === 0 ? (
                <p className="text-text-muted p-4 text-center text-sm">El ticket esta vacio.</p>
              ) : (
                cart.map((line, index) => (
                  <div
                    key={line.id}
                    className="border-border bg-bg/40 flex items-center gap-3 rounded-xl border p-2"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{line.product.name}</p>
                      <p className="text-text-muted text-xs">
                        x{line.quantity} ·{' '}
                        {money(Number(line.product.base_price ?? 0) * line.quantity)}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => removeLine(index)}
                      className="text-text-muted hover:text-danger"
                      aria-label={`Quitar ${line.product.name}`}
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                ))
              )}
            </div>
            <div className="border-border border-t p-4">
              <div className="mb-3 flex items-center justify-between">
                <span className="text-text-muted text-sm">Total</span>
                <span className="text-2xl font-bold">{money(total)}</span>
              </div>
              <Button
                className="h-14 w-full text-base"
                disabled={cart.length === 0 || holdOrder.isPending}
                onClick={() => void submitOrder()}
              >
                {holdOrder.isPending ? (
                  <Loader2 className="size-5 animate-spin" />
                ) : (
                  <Plus className="size-5" />
                )}
                Enviar a la cajera
              </Button>
            </div>
          </aside>
        </div>
      </div>
    </PosShell>
  );
}
