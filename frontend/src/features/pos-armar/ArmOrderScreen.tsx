import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Loader2, Minus, Plus, Search, Trash2, UserRound, Warehouse } from 'lucide-react';
import { toast } from 'sonner';

import { useAuth } from '@/auth/useAuth';
import { PosShell } from '@/components/layout/PosShell';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import type { Product } from '@/features/inventory-center/schemas';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';
import {
  type CreateCustomerPayload,
  type Customer,
  type HoldPayload,
  useBootstrapRefsForPos,
  useCreateCustomerForPos,
  useCustomers,
  useHoldOrder,
  usePriceListsForPos,
  usePosProductsDebounced,
  useWarehousesForPos,
  quoteProductForPos,
} from '@/features/pos/api';
import { TapButton } from '@/features/pos/TapButton';
import { VariantPicker, type VariantPickerValue } from '@/features/pos/VariantPicker';
import { createClientId } from '@/lib/clientId';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { applyKey, canSearch, money, normalizeSearch, type KeyAction } from './armOrderLogic';
import { OnScreenKeyboard } from './OnScreenKeyboard';

interface CartLine {
  id: string;
  product: Product;
  product_variant_id: number | null;
  product_variant_name: string | null;
  quantity: number;
  available_stock: number;
  unit_price: number;
  price_list_id: number | null;
  price_list_name: string | null;
  price_source: 'base' | 'price_list';
}

const EMPTY_CUSTOMER: CreateCustomerPayload = {
  name: '',
  document_type: 'V',
  document_number: '',
  phone: '',
  email: '',
  is_active: true,
  is_generic: false,
};

function stockOf(product: Product): number {
  return Math.max(0, Number(product.available_stock ?? 0));
}

function variantStockOf(variant: ProductVariant): number {
  return Math.max(0, Number(variant.stock_available ?? 0));
}

function customerDocument(customer: Customer): string {
  const document = [customer.document_type, customer.document_number].filter(Boolean).join('-');
  return (
    [document, customer.phone, customer.email].find((value) => Boolean(value)) ??
    'Cliente registrado'
  );
}

export function ArmOrderScreen() {
  const { signOut } = useAuth();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const [cart, setCart] = useState<CartLine[]>([]);
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<number | null>(null);
  const [selectedPriceListId, setSelectedPriceListId] = useState<number | null>(null);
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [customerOpen, setCustomerOpen] = useState(false);
  const [customerSearch, setCustomerSearch] = useState('');
  const [creatingCustomer, setCreatingCustomer] = useState(false);
  const [customerForm, setCustomerForm] = useState<CreateCustomerPayload>(EMPTY_CUSTOMER);
  const [variantPickerProduct, setVariantPickerProduct] = useState<Product | null>(null);
  const holdOrder = useHoldOrder();
  const createCustomer = useCreateCustomerForPos();
  const canCreateCustomer = useCan(PERMISSIONS.CUSTOMERS_CREATE);
  const refs = useBootstrapRefsForPos();
  const fallbackWarehouses = useWarehousesForPos();
  const fallbackPriceLists = usePriceListsForPos();
  const [priceError, setPriceError] = useState<string | null>(null);
  const warehouses = useMemo(() => {
    const bootstrapItems = refs.refs?.warehouses ?? [];
    const source = bootstrapItems.length > 0 ? bootstrapItems : (fallbackWarehouses.data ?? []);

    return source
      .filter(
        (item) =>
          item.status !== 'inactive' && (!('is_active' in item) || item.is_active !== false),
      )
      .map((item) => ({
        id: item.id,
        code: item.code,
        name: item.name,
        status: item.status ?? 'active',
      }));
  }, [fallbackWarehouses.data, refs.refs?.warehouses]);
  const priceLists = useMemo(() => {
    const source =
      refs.data?.price_lists && refs.data.price_lists.length > 0
        ? refs.data.price_lists
        : (fallbackPriceLists.data ?? []);

    return source.filter((item) => item.is_active !== false);
  }, [fallbackPriceLists.data, refs.data?.price_lists]);
  const selectedPriceList = priceLists.find((item) => item.id === selectedPriceListId) ?? null;
  const warehouse =
    warehouses.find((item) => item.id === selectedWarehouseId) ?? warehouses[0] ?? null;
  const warehouseId = warehouse?.id ?? null;

  useEffect(() => {
    const selectedStillExists = warehouses.some((item) => item.id === selectedWarehouseId);
    if (!selectedStillExists) {
      setSelectedWarehouseId(warehouses[0]?.id ?? null);
    }
  }, [selectedWarehouseId, warehouses]);

  useEffect(() => {
    if (
      selectedPriceListId !== null &&
      priceLists.some((item) => item.id === selectedPriceListId)
    ) {
      return;
    }

    const defaultPriceList = priceLists.find((item) => item.is_default);
    setSelectedPriceListId(defaultPriceList?.id ?? null);
  }, [priceLists, selectedPriceListId]);

  const {
    data: productPage,
    isLoading,
    isError,
  } = usePosProductsDebounced(query.trim(), warehouseId, {
    enabled: canSearch(query) && warehouseId != null,
    debounceMs: 150,
  });
  const products = useMemo(() => productPage?.data ?? [], [productPage?.data]);
  const customerQuery = useCustomers(customerSearch);
  const total = cart.reduce((sum, line) => sum + line.unit_price * line.quantity, 0);

  function handleKey(action: KeyAction): void {
    setQuery((current) => applyKey(current, action));
  }

  async function quoteSelectedPrice(product: Product) {
    if (!selectedPriceList) return null;

    try {
      setPriceError(null);
      return await quoteProductForPos(product.id, selectedPriceList.id);
    } catch {
      const message = `${product.name} no tiene precio activo en ${selectedPriceList.name}.`;
      setPriceError(message);
      toast.error(message);
      return null;
    }
  }

  async function addProduct(product: Product): Promise<void> {
    if (priceLists.length > 0 && !selectedPriceList) {
      const message = 'Selecciona una lista de precio antes de agregar productos.';
      setPriceError(message);
      toast.error(message);
      return;
    }

    if (Number(product.variants_count ?? 0) > 1) {
      setVariantPickerProduct(product);
      setQuery('');
      return;
    }

    const stock = stockOf(product);
    if (stock <= 0) {
      toast.error(
        `${product.name} no tiene stock disponible en ${warehouse?.name ?? 'este almacen'}.`,
      );
      return;
    }

    const quote = await quoteSelectedPrice(product);
    if (selectedPriceList && !quote) return;

    setCart((current) => {
      const existing = current.find(
        (line) => line.product.id === product.id && line.product_variant_id === null,
      );
      if (existing) {
        if (existing.quantity >= existing.available_stock) {
          toast.error(`Solo hay ${stock} unidades disponibles de ${product.name}.`);
          return current;
        }
        return current.map((line) =>
          line.id === existing.id ? { ...line, quantity: line.quantity + 1 } : line,
        );
      }
      return [
        ...current,
        {
          id: createClientId(),
          product,
          product_variant_id: null,
          product_variant_name: null,
          quantity: 1,
          available_stock: stock,
          unit_price: quote?.sale_price ?? Number(product.base_price ?? 0),
          price_list_id: quote?.price_list_id ?? null,
          price_list_name: quote?.price_list_name ?? null,
          price_source: quote ? 'price_list' : 'base',
        },
      ];
    });
    setQuery('');
  }

  async function addVariantToCart({ variant, quantity }: VariantPickerValue): Promise<void> {
    const product = variantPickerProduct;
    if (!product) return;

    const availableStock = variantStockOf(variant);
    if (availableStock <= 0) {
      toast.error(`La variante ${variant.color ?? 'seleccionada'} no tiene stock disponible.`);
      return;
    }

    const quote = await quoteSelectedPrice(product);
    if (selectedPriceList && !quote) return;

    const unitPrice =
      quote?.sale_price ?? Number(variant.price_override ?? product.base_price ?? 0);
    setCart((current) => {
      const existing = current.find(
        (line) => line.product.id === product.id && line.product_variant_id === variant.id,
      );
      if (existing) {
        if (existing.quantity + quantity > existing.available_stock) {
          toast.error(`Solo hay ${availableStock} unidades disponibles de la variante.`);
          return current;
        }
        return current.map((line) =>
          line.id === existing.id ? { ...line, quantity: line.quantity + quantity } : line,
        );
      }

      return [
        ...current,
        {
          id: createClientId(),
          product,
          product_variant_id: variant.id,
          product_variant_name: variant.color ?? `Variante #${variant.id}`,
          quantity,
          available_stock: availableStock,
          unit_price: unitPrice,
          price_list_id: quote?.price_list_id ?? null,
          price_list_name: quote?.price_list_name ?? null,
          price_source: quote ? 'price_list' : 'base',
        },
      ];
    });
    setVariantPickerProduct(null);
  }

  function changeQuantity(index: number, delta: number): void {
    setCart((current) =>
      current.flatMap((line, currentIndex) => {
        if (currentIndex !== index) return [line];
        const next = line.quantity + delta;
        if (next <= 0) return [];
        if (next > line.available_stock) {
          toast.error(`Solo hay ${line.available_stock} unidades disponibles.`);
          return [line];
        }
        return [{ ...line, quantity: next }];
      }),
    );
  }

  function removeLine(index: number): void {
    setCart((current) => current.filter((_, currentIndex) => currentIndex !== index));
  }

  function changeWarehouse(value: string): void {
    if (cart.length > 0) {
      setCart([]);
      toast.info('El ticket se limpio porque cambiaste el almacen de salida.');
    }
    setSelectedWarehouseId(Number(value));
    setQuery('');
  }

  function assignCustomer(customer: Customer | null): void {
    setSelectedCustomer(customer);
    setCustomerOpen(false);
    setCustomerSearch('');
    setCreatingCustomer(false);
  }

  async function saveCustomer(): Promise<void> {
    if (!customerForm.name.trim() || !customerForm.document_number.trim()) {
      toast.error('Nombre y documento son obligatorios.');
      return;
    }
    try {
      const customer = await createCustomer.mutateAsync({
        ...customerForm,
        name: customerForm.name.trim(),
        document_number: customerForm.document_number.trim(),
        phone: customerForm.phone?.trim() ? customerForm.phone.trim() : null,
        email: customerForm.email?.trim() ? customerForm.email.trim() : null,
      });
      setCustomerForm(EMPTY_CUSTOMER);
      assignCustomer(customer);
      toast.success('Cliente creado y asignado al ticket.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear el cliente.');
    }
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
    if (priceLists.length > 0 && !selectedPriceList) {
      const message = 'Selecciona una lista de precio antes de enviar la orden.';
      setPriceError(message);
      toast.error(message);
      return;
    }

    const payload: HoldPayload = {
      customer_id: selectedCustomer?.id ?? null,
      customer_name: selectedCustomer?.name ?? 'Consumidor Final',
      items: cart.map((line) => ({
        warehouse_id: warehouseId,
        product_id: line.product.id,
        product_variant_id: line.product_variant_id,
        price_list_id: line.price_list_id,
        price_source: line.price_source,
        quantity: line.quantity,
        product_unit_ids: [],
      })),
    };

    try {
      const order = await holdOrder.mutateAsync(payload);
      setCart([]);
      setQuery('');
      setSelectedCustomer(null);
      toast.success(`Orden #${order.id} armada. La cajera la cobrara.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo armar la orden.');
    }
  }

  return (
    <PosShell
      onExit={async () => {
        await signOut();
        await navigate({ to: '/login' });
      }}
    >
      <div className="bg-bg text-text-primary flex h-dvh min-h-0 flex-col overflow-hidden">
        <header className="border-border/80 bg-surface/95 flex min-h-16 shrink-0 items-center gap-3 border-b px-4 py-3 pr-32">
          <div className="min-w-0">
            <h1 className="text-lg font-bold">Armar pedido</h1>
            <p className="text-text-muted truncate text-xs sm:text-sm">
              Selecciona productos disponibles y envialos a caja.
            </p>
          </div>
          <div className="ml-auto flex items-end gap-2">
            <label className="min-w-0 space-y-1">
              <span className="text-text-muted flex items-center gap-1 text-[10px] font-semibold uppercase">
                <Warehouse className="size-3.5" /> Almacen de salida
              </span>
              <Select
                aria-label="Almacen de salida"
                value={warehouseId ?? ''}
                onChange={(event) => changeWarehouse(event.target.value)}
                disabled={warehouses.length === 0}
                className="h-10 max-w-64 min-w-40"
              >
                {warehouses.length === 0 && (
                  <option value="">
                    {refs.isLoading || fallbackWarehouses.isLoading
                      ? 'Cargando almacenes...'
                      : 'Sin almacenes disponibles'}
                  </option>
                )}
                {warehouses.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.code ? `${item.code} - ` : ''}
                    {item.name}
                  </option>
                ))}
              </Select>
            </label>
            <div className="flex items-center gap-2">
              <Badge variant="info">{selectedPriceList?.code ?? 'SIN LISTA'}</Badge>
              <Badge variant="info">{cart.length} productos</Badge>
            </div>
          </div>
        </header>

        <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_minmax(240px,32vw)] gap-3 overflow-hidden p-3 max-[560px]:grid-cols-1 max-[560px]:overflow-auto sm:gap-4 sm:p-4">
          <section className="flex min-h-0 flex-col gap-3">
            <div className="border-border bg-surface flex items-center gap-3 rounded-2xl border px-4 py-3">
              <Search className="text-text-muted size-5 shrink-0" />
              <p className="flex-1 truncate text-xl font-semibold">
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
              {warehouseId == null ? (
                <div className="border-warning/40 bg-warning/5 text-warning rounded-2xl border p-8 text-center text-sm">
                  {refs.isLoading || fallbackWarehouses.isLoading
                    ? 'Cargando los almacenes de esta empresa...'
                    : refs.isError || fallbackWarehouses.isError
                      ? 'No se pudieron consultar los almacenes. Revisa la conexion y vuelve a intentar.'
                      : 'Esta empresa no tiene un almacen activo. Configura uno antes de armar pedidos.'}
                </div>
              ) : isLoading ? (
                <div className="border-border bg-surface text-text-muted flex items-center justify-center gap-2 rounded-2xl border p-8 text-sm">
                  <Loader2 className="size-5 animate-spin" /> Buscando...
                </div>
              ) : isError ? (
                <div className="border-danger/40 bg-danger/5 text-danger rounded-2xl border p-8 text-center text-sm">
                  No se pudo consultar el inventario. Revisa la conexion e intenta de nuevo.
                </div>
              ) : products.length === 0 ? (
                <div className="border-border bg-surface text-text-muted rounded-2xl border p-8 text-center text-sm">
                  {canSearch(query)
                    ? `No hay productos que coincidan en ${warehouse?.name ?? 'el almacen seleccionado'}.`
                    : 'Usa el teclado para buscar un producto.'}
                </div>
              ) : (
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
                  {products.map((product) => (
                    <TapButton
                      key={product.id}
                      data-testid={`product-${product.id}`}
                      onPress={() => addProduct(product)}
                      disabled={stockOf(product) <= 0 && Number(product.variants_count ?? 0) <= 1}
                      className="border-border bg-surface hover:border-primary/60 hover:bg-primary/5 active:border-primary active:bg-primary/10 min-h-24 touch-manipulation overflow-hidden rounded-2xl border p-4 text-left shadow-sm transition-all select-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <p className="truncate font-semibold">{product.name}</p>
                      <p className="text-text-muted font-mono text-xs">
                        {product.sku ?? product.barcode ?? 'Sin codigo'}
                      </p>
                      <div className="mt-3 flex items-center justify-between gap-2">
                        <span className="text-lg font-bold">
                          {money(Number(product.base_price ?? 0))}
                        </span>
                        <Badge
                          variant={
                            stockOf(product) > 0 || Number(product.variants_count ?? 0) > 1
                              ? 'success'
                              : 'warning'
                          }
                        >
                          {stockOf(product) > 0
                            ? `Stock ${stockOf(product)}`
                            : Number(product.variants_count ?? 0) > 1
                              ? 'Ver variantes'
                              : 'Agotado'}
                        </Badge>
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
              <div className="flex items-center justify-between gap-2">
                <div>
                  <h2 className="font-bold">Ticket</h2>
                  <p className="text-text-muted text-xs">Se envia a la cajera para cobro.</p>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  aria-label="Seleccionar cliente"
                  onClick={() => setCustomerOpen(true)}
                >
                  <UserRound className="size-4" /> Cliente
                </Button>
              </div>
              <button
                type="button"
                aria-label="Cambiar cliente asignado"
                onClick={() => setCustomerOpen(true)}
                className="border-border bg-bg/50 mt-3 w-full rounded-lg border px-3 py-2 text-left"
              >
                <p className="text-text-muted text-[11px] font-semibold uppercase">
                  Cliente asignado
                </p>
                <p className="truncate text-sm font-semibold">
                  {selectedCustomer?.name ?? 'Consumidor Final'}
                </p>
                {selectedCustomer && (
                  <p className="text-text-muted truncate text-xs">
                    {customerDocument(selectedCustomer)}
                  </p>
                )}
              </button>
              <label className="mt-3 block space-y-1 text-left">
                <span className="text-text-muted text-[11px] font-semibold uppercase">
                  Lista de precio
                </span>
                <Select
                  aria-label="Lista de precio"
                  value={selectedPriceListId ?? ''}
                  onChange={(event) => {
                    const nextId = event.target.value ? Number(event.target.value) : null;
                    if (cart.length > 0) {
                      setCart([]);
                      toast.info('El ticket se limpio porque cambiaste la lista de precio.');
                    }
                    setPriceError(null);
                    setSelectedPriceListId(nextId);
                  }}
                  disabled={priceLists.length === 0}
                  className="h-11 w-full"
                >
                  <option value="">
                    {priceLists.length > 0 ? 'Selecciona una lista' : 'Precio base'}
                  </option>
                  {priceLists.map((priceList) => (
                    <option key={priceList.id} value={priceList.id}>
                      {priceList.code} - {priceList.name}
                    </option>
                  ))}
                </Select>
              </label>
              {selectedPriceList && (
                <p className="text-primary mt-2 text-xs">
                  Los precios se cotizan con {selectedPriceList.name}.
                </p>
              )}
              {priceError && (
                <p className="text-danger mt-2 text-xs" role="alert">
                  {priceError}
                </p>
              )}
            </div>
            <div className="min-h-0 flex-1 space-y-2 overflow-auto p-3">
              {cart.length === 0 ? (
                <p className="text-text-muted p-4 text-center text-sm">El ticket esta vacio.</p>
              ) : (
                cart.map((line, index) => (
                  <div
                    key={line.id}
                    className="border-border bg-bg/40 flex items-center gap-2 rounded-xl border p-2"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{line.product.name}</p>
                      <p className="text-text-muted text-xs">
                        {line.product_variant_name && `${line.product_variant_name} · `}
                        {line.quantity} x {money(line.unit_price)}
                      </p>
                      {line.price_list_name && (
                        <p className="text-primary text-[11px]">{line.price_list_name}</p>
                      )}
                    </div>
                    <TapButton
                      onPress={() => changeQuantity(index, -1)}
                      className="border-border flex size-8 items-center justify-center rounded-lg border"
                      aria-label={`Restar ${line.product.name}`}
                    >
                      <Minus className="size-4" />
                    </TapButton>
                    <TapButton
                      onPress={() => changeQuantity(index, 1)}
                      disabled={line.quantity >= line.available_stock}
                      className="border-border flex size-8 items-center justify-center rounded-lg border disabled:opacity-40"
                      aria-label={`Sumar ${line.product.name}`}
                    >
                      <Plus className="size-4" />
                    </TapButton>
                    <button
                      type="button"
                      onClick={() => removeLine(index)}
                      className="text-text-muted hover:text-danger p-1"
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

        <Dialog open={customerOpen} onOpenChange={setCustomerOpen}>
          <DialogContent className="max-h-[88dvh] max-w-2xl overflow-y-auto p-4 sm:p-6">
            <DialogHeader>
              <DialogTitle>Cliente del pedido</DialogTitle>
              <DialogDescription>
                Busca por nombre, cedula o telefono. Tambien puedes crear uno sin salir del POS.
              </DialogDescription>
            </DialogHeader>

            {!creatingCustomer ? (
              <div className="space-y-3">
                <div className="relative">
                  <Search className="text-text-muted absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                  <Input
                    value={customerSearch}
                    onChange={(event) => setCustomerSearch(event.target.value)}
                    placeholder="Nombre, cedula o telefono"
                    className="h-12 pl-10 text-base"
                    autoFocus
                  />
                </div>
                <div className="max-h-72 space-y-2 overflow-y-auto">
                  <TapButton
                    onPress={() => assignCustomer(null)}
                    className="border-border hover:border-primary w-full rounded-xl border p-3 text-left"
                  >
                    <p className="font-semibold">Consumidor Final</p>
                    <p className="text-text-muted text-xs">Continuar sin cliente registrado</p>
                  </TapButton>
                  {customerQuery.isLoading && (
                    <p className="text-text-muted p-4 text-center text-sm">Buscando clientes...</p>
                  )}
                  {customerSearch.trim().length >= 2 &&
                    !customerQuery.isLoading &&
                    (customerQuery.data ?? []).length === 0 && (
                      <p className="text-text-muted p-4 text-center text-sm">
                        No hay clientes con esa busqueda.
                      </p>
                    )}
                  {(customerQuery.data ?? []).map((customer) => (
                    <TapButton
                      key={customer.id}
                      onPress={() => assignCustomer(customer)}
                      className="border-border hover:border-primary w-full rounded-xl border p-3 text-left"
                    >
                      <p className="font-semibold">{customer.name}</p>
                      <p className="text-text-muted text-xs">{customerDocument(customer)}</p>
                    </TapButton>
                  ))}
                </div>
                {canCreateCustomer && (
                  <Button
                    variant="outline"
                    className="h-11 w-full"
                    onClick={() => setCreatingCustomer(true)}
                  >
                    <Plus className="size-4" /> Crear cliente
                  </Button>
                )}
              </div>
            ) : (
              <div className="space-y-3">
                <label className="block space-y-1 text-sm font-medium">
                  <span>Nombre</span>
                  <Input
                    value={customerForm.name}
                    onChange={(event) =>
                      setCustomerForm((current) => ({ ...current, name: event.target.value }))
                    }
                    className="h-11"
                    autoFocus
                  />
                </label>
                <div className="grid grid-cols-[90px_1fr] gap-2">
                  <label className="block space-y-1 text-sm font-medium">
                    <span>Tipo</span>
                    <Select
                      value={customerForm.document_type}
                      onChange={(event) =>
                        setCustomerForm((current) => ({
                          ...current,
                          document_type: event.target
                            .value as CreateCustomerPayload['document_type'],
                        }))
                      }
                      className="h-11"
                    >
                      {['V', 'E', 'J', 'G', 'P'].map((type) => (
                        <option key={type}>{type}</option>
                      ))}
                    </Select>
                  </label>
                  <label className="block space-y-1 text-sm font-medium">
                    <span>Cedula o documento</span>
                    <Input
                      value={customerForm.document_number}
                      onChange={(event) =>
                        setCustomerForm((current) => ({
                          ...current,
                          document_number: event.target.value,
                        }))
                      }
                      className="h-11"
                    />
                  </label>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                  <label className="block space-y-1 text-sm font-medium">
                    <span>Telefono</span>
                    <Input
                      value={customerForm.phone ?? ''}
                      onChange={(event) =>
                        setCustomerForm((current) => ({ ...current, phone: event.target.value }))
                      }
                      className="h-11"
                    />
                  </label>
                  <label className="block space-y-1 text-sm font-medium">
                    <span>Email</span>
                    <Input
                      type="email"
                      value={customerForm.email ?? ''}
                      onChange={(event) =>
                        setCustomerForm((current) => ({ ...current, email: event.target.value }))
                      }
                      className="h-11"
                    />
                  </label>
                </div>
                <div className="flex gap-2 pt-2">
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => setCreatingCustomer(false)}
                  >
                    Volver
                  </Button>
                  <Button
                    className="flex-1"
                    loading={createCustomer.isPending}
                    onClick={() => void saveCustomer()}
                  >
                    Guardar y asignar
                  </Button>
                </div>
              </div>
            )}
          </DialogContent>
        </Dialog>
        {variantPickerProduct && (
          <VariantPicker
            productId={variantPickerProduct.id}
            productName={variantPickerProduct.name}
            warehouseId={warehouseId}
            open
            onClose={() => setVariantPickerProduct(null)}
            onSelect={addVariantToCart}
          />
        )}
      </div>
    </PosShell>
  );
}
