import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@tanstack/react-router';
import {
  Banknote,
  CreditCard,
  History,
  Loader2,
  Minus,
  PauseCircle,
  Plus,
  Receipt,
  RotateCcw,
  Search,
  Trash2,
  UserRound,
  Wallet,
  X,
} from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PERMISSIONS } from '@/permissions/constants';
import { usePermissionContext } from '@/permissions/PermissionContext';
import { cn } from '@/lib/cn';
import type { Product } from '@/features/inventory-center/schemas';
import {
  type CashRegisterSession,
  type CheckoutPayload,
  type Customer,
  type PosOrder,
  type PosPaymentMethod,
  useAddCashMovement,
  useAddPosPayments,
  useBranchesForPos,
  useCancelPosOrder,
  useCashRegisters,
  useCashSessions,
  useCheckout,
  useCloseCashSession,
  useCurrentExchangeRatesForPos,
  useCustomers,
  useExchangeRateTypesForPos,
  useOpenCashSession,
  useOpenPosOrders,
  usePaymentMethods,
  usePosProducts,
  useWarehousesForPos,
} from './api';
import {
  calculateCartTotals,
  calculatePaymentTotals,
  clampQuantity,
  hasStockIssue,
  lineTotal,
  paymentBaseAmount,
  type CurrencyCode,
  type DiscountType,
  type PosCartLine,
  type PosPaymentLine,
  roundMoney,
} from './posLogic';

type Panel = 'pay' | 'hold' | 'customer' | 'cash' | 'receipt' | null;

const PAYMENT_METHODS: Array<{ value: PosPaymentMethod; label: string }> = [
  { value: 'cash', label: 'Efectivo' },
  { value: 'card', label: 'Tarjeta' },
  { value: 'mobile_payment', label: 'Pago movil' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'external_financing', label: 'Financiamiento' },
  { value: 'other', label: 'Otro' },
];

export function PosTerminal() {
  const { permissions } = usePermissionContext();
  const canView = permissions.has(PERMISSIONS.POS_VIEW);
  const canCheckout = permissions.has(PERMISSIONS.POS_CHECKOUT);
  const canCancel = permissions.has(PERMISSIONS.POS_CANCEL);
  const canOpenCash = permissions.has(PERMISSIONS.CASH_REGISTER_OPEN);
  const canMoveCash = permissions.has(PERMISSIONS.CASH_REGISTER_MOVE) || permissions.has(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canCloseCash = permissions.has(PERMISSIONS.CASH_REGISTER_CLOSE);

  const searchRef = useRef<HTMLInputElement | null>(null);
  const [query, setQuery] = useState('');
  const [warehouseId, setWarehouseId] = useState<number | null>(null);
  const [cart, setCart] = useState<PosCartLine[]>([]);
  const [payments, setPayments] = useState<PosPaymentLine[]>([]);
  const [panel, setPanel] = useState<Panel>(null);
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [customerSearch, setCustomerSearch] = useState('');
  const [customerName, setCustomerName] = useState('Consumidor Final');
  const [lastReceipt, setLastReceipt] = useState<PosOrder | null>(null);
  const [selectedPending, setSelectedPending] = useState<PosOrder | null>(null);
  const [openingAmount, setOpeningAmount] = useState('0');
  const [openingBranchId, setOpeningBranchId] = useState<number | ''>('');
  const [openingRegisterId, setOpeningRegisterId] = useState<number | ''>('');
  const [cashMovement, setCashMovement] = useState({ type: 'outflow', amount: '', notes: '' });
  const [closingAmount, setClosingAmount] = useState('');

  const { data: warehouses = [] } = useWarehousesForPos();
  const { data: branches = [] } = useBranchesForPos();
  const { data: cashRegisters = [] } = useCashRegisters();
  const { data: sessions = [], isLoading: loadingSessions } = useCashSessions();
  const { data: pendingOrders = [] } = useOpenPosOrders();
  const { data: customerResults = [] } = useCustomers(customerSearch);
  const { data: productPage, isLoading: loadingProducts } = usePosProducts(query, warehouseId);
  const { data: configuredPaymentMethods = [] } = usePaymentMethods();
  const { data: exchangeRateTypes = [] } = useExchangeRateTypesForPos();
  const { data: currentRates = [] } = useCurrentExchangeRatesForPos();
  const activePaymentMethods = useMemo(
    () => configuredPaymentMethods.filter((method) => method.is_active !== false).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name)),
    [configuredPaymentMethods],
  );
  const checkout = useCheckout();
  const addPayments = useAddPosPayments();
  const cancelOrder = useCancelPosOrder();
  const openCash = useOpenCashSession();
  const addCashMovement = useAddCashMovement();
  const closeCash = useCloseCashSession();

  const activeSession = useMemo(
    () => sessions.find((session) => session.status === 'open') ?? sessions[0] ?? null,
    [sessions],
  );
  const selectedWarehouse = warehouses.find((warehouse) => warehouse.id === warehouseId) ?? warehouses[0] ?? null;
  const products = productPage?.data ?? [];
  const cartTotals = useMemo(() => calculateCartTotals(cart), [cart]);
  const paymentTotals = useMemo(() => calculatePaymentTotals(payments, cartTotals.total), [payments, cartTotals.total]);
  const paymentSetupIssue = getPaymentSetupIssue(payments, configuredPaymentMethods);
  const checkoutBlockReason = getCheckoutBlockReason({
    canCheckout,
    hasSession: Boolean(activeSession),
    cartCount: cart.length,
    paymentCount: payments.length,
    remaining: paymentTotals.remaining,
    hasStockIssue: hasStockIssue(cart),
    paymentSetupIssue,
  });

  useEffect(() => {
    if (!warehouseId && warehouses[0]) setWarehouseId(warehouses[0].id);
  }, [warehouseId, warehouses]);

  useEffect(() => {
    if (branches[0] && openingBranchId === '') setOpeningBranchId(branches[0].id);
  }, [branches, openingBranchId]);

  useEffect(() => {
    if (cashRegisters[0] && openingRegisterId === '') setOpeningRegisterId(cashRegisters[0].id);
  }, [cashRegisters, openingRegisterId]);

  useEffect(() => {
    searchRef.current?.focus();
  }, [cart.length, panel]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'F2') {
        event.preventDefault();
        setPanel('pay');
      }
      if (event.key === 'F4') {
        event.preventDefault();
        setPanel('customer');
      }
      if (event.key === 'F6') {
        event.preventDefault();
        void holdSale();
      }
      if (event.key === 'F7') {
        event.preventDefault();
        setPanel('hold');
      }
      if (event.key === 'F9') {
        event.preventDefault();
        setPanel('receipt');
      }
      if (event.key === 'Escape') setPanel(null);
      if (event.key === 'Delete' && cart.length > 0) {
        event.preventDefault();
        setCart((current) => current.slice(0, -1));
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [cart, payments, activeSession, selectedCustomer, customerName]);

  if (!canView) {
    return (
      <div className="flex min-h-[70vh] items-center justify-center bg-bg">
        <div className="max-w-md rounded border border-border bg-surface p-6 text-center shadow-sm">
          <Wallet className="mx-auto mb-3 size-8 text-text-muted" />
          <h1 className="text-lg font-semibold">POS no disponible</h1>
          <p className="mt-2 text-sm text-text-muted">Necesitas el permiso pos.view para usar la caja de venta.</p>
        </div>
      </div>
    );
  }

  if (!activeSession && !loadingSessions) {
    return (
      <OpenCashScreen
        canOpenCash={canOpenCash}
        branches={branches}
        cashRegisters={cashRegisters}
        branchId={openingBranchId}
        registerId={openingRegisterId}
        amount={openingAmount}
        onBranchChange={setOpeningBranchId}
        onRegisterChange={setOpeningRegisterId}
        onAmountChange={setOpeningAmount}
        onOpen={() => {
          if (!openingBranchId) return toast.error('Selecciona una sucursal.');
          openCash.mutate({
            branch_id: Number(openingBranchId),
            cash_register_id: openingRegisterId ? Number(openingRegisterId) : null,
            opening_currency: 'USD',
            opening_amount: Number(openingAmount || 0),
            notes: 'Apertura desde POS',
          });
        }}
        busy={openCash.isPending}
      />
    );
  }

  return (
    <div className="h-screen overflow-hidden bg-bg text-text-primary">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-3">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex size-10 items-center justify-center rounded bg-primary text-primary-foreground">
            <Receipt className="size-5" />
          </div>
          <div>
            <h1 className="text-lg font-semibold leading-tight">POS</h1>
            <p className="text-xs text-text-muted">
              {activeSession?.cash_register?.name ?? 'Caja abierta'} - {selectedWarehouse?.name ?? 'Sin almacen'}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => setPanel('pay')} disabled={activePaymentMethods.length === 0}>
            <CreditCard className="size-4" /> <ShortcutText label="F2" text="Pago" />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setPanel('customer')}>
            <UserRound className="size-4" /> <ShortcutText label="F4" text="Cliente" />
          </Button>
          <Button variant="outline" size="sm" disabled={cart.length === 0 || !canCheckout || checkout.isPending} onClick={() => void holdSale()}>
            <PauseCircle className="size-4" /> <ShortcutText label="F6" text="Espera" />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setPanel('hold')}>
            <History className="size-4" /> <ShortcutText label="F7" text="Pendientes" />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setPanel('cash')}>
            <Banknote className="size-4" /> Caja
          </Button>
        </div>
      </header>

      <main className="grid h-[calc(100vh-65px)] grid-cols-1 gap-3 overflow-hidden p-3 xl:grid-cols-[390px_minmax(560px,1fr)_430px]">
        <section className="flex min-h-0 flex-col rounded border border-border bg-surface">
          <div className="border-b border-border p-3">
            <label className="text-xs font-semibold uppercase text-text-muted">Buscar o escanear</label>
            <div className="relative mt-2">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
              <Input
                ref={searchRef}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && products[0]) {
                    event.preventDefault();
                    addProduct(products[0]);
                  }
                }}
                className="h-12 pl-9 text-base"
                placeholder="Codigo, SKU o nombre"
                data-testid="pos-search"
              />
            </div>
            <Select
              className="mt-2"
              value={warehouseId ?? ''}
              onChange={(event) => setWarehouseId(event.target.value ? Number(event.target.value) : null)}
            >
              {warehouses.map((warehouse) => (
                <option key={warehouse.id} value={warehouse.id}>
                  {warehouse.code} - {warehouse.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="min-h-0 flex-1 overflow-auto p-2">
            {loadingProducts ? (
              <div className="flex items-center gap-2 p-3 text-sm text-text-muted">
                <Loader2 className="size-4 animate-spin" /> Buscando productos
              </div>
            ) : products.length === 0 ? (
              <div className="p-3 text-sm text-text-muted">Escanea un codigo o escribe para buscar productos.</div>
            ) : (
              <div className="space-y-2">
                {products.map((product) => (
                  <button
                    key={product.id}
                    type="button"
                    onClick={() => addProduct(product)}
                    className="w-full rounded border border-border bg-bg/40 p-3 text-left transition-colors hover:border-primary"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{product.name}</p>
                        <p className="font-mono text-xs text-text-muted">{product.sku ?? product.barcode ?? 'Sin codigo'}</p>
                      </div>
                      <Badge variant={Number(product.available_stock ?? 0) > 0 ? 'success' : 'warning'} className="text-[10px]">
                        Stock {Number(product.available_stock ?? 0)}
                      </Badge>
                    </div>
                    <p className="mt-2 text-lg font-semibold">{money(Number(product.base_price ?? 0))}</p>
                  </button>
                ))}
              </div>
            )}
          </div>
        </section>

        <section className="flex min-h-0 flex-col rounded border border-border bg-surface">
          <div className="flex items-center justify-between border-b border-border p-3">
            <div>
              <h2 className="font-semibold">Ticket actual</h2>
              <p className="text-xs text-text-muted">{selectedCustomer?.name ?? customerName}</p>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setPanel('customer')}>
                <UserRound className="size-4" /> Cliente
              </Button>
              <Button variant="outline" size="sm" onClick={() => clearTicket()}>
                <RotateCcw className="size-4" /> Nuevo
              </Button>
            </div>
          </div>
          <div className="min-h-0 flex-1 overflow-auto">
            {cart.length === 0 ? (
              <div className="flex h-full items-center justify-center p-6 text-center text-sm text-text-muted">
                Agrega productos con el buscador o escanea un codigo de barras.
              </div>
            ) : (
              <div className="divide-y divide-border">
                {cart.map((line) => (
                  <CartLineRow
                    key={line.id}
                    line={line}
                    onChange={(patch) => updateLine(line.id, patch)}
                    onRemove={() => setCart((current) => current.filter((item) => item.id !== line.id))}
                  />
                ))}
              </div>
            )}
          </div>
        </section>

        <aside className="flex min-h-0 flex-col rounded border border-border bg-surface">
          <div className="border-b border-border p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase text-text-muted">Total</p>
                <p className="mt-1 text-4xl font-bold tracking-normal">{money(cartTotals.total)}</p>
              </div>
              <div className="min-w-28 space-y-1 text-right text-xs text-text-muted">
                <AmountRow label="Subtotal" value={cartTotals.subtotal} />
                {cartTotals.discount > 0 && <AmountRow label="Desc." value={cartTotals.discount} muted />}
              </div>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-auto p-4">
            {activePaymentMethods.length === 0 ? (
              <div className="mb-3 rounded border border-warning bg-warning/10 p-3 text-sm text-warning">
                Configura metodos de pago para cobrar rapido.
                <Button asChild className="mt-3 w-full" variant="outline">
                  <Link to="/payment-methods">Configurar metodos</Link>
                </Button>
              </div>
            ) : null}
            <div className="space-y-3">
              <AmountRow label="Pagado" value={paymentTotals.paid} />
              {payments.length > 0 && (
                <div className="max-h-44 space-y-2 overflow-auto pr-1">
                  {payments.map((payment) => (
                    <PaymentChip
                      key={payment.id}
                      payment={payment}
                      methods={configuredPaymentMethods}
                      rateTypes={exchangeRateTypes}
                      onChange={(patch) => updatePayment(payment.id, patch)}
                      onRemove={() => setPayments((current) => current.filter((item) => item.id !== payment.id))}
                    />
                  ))}
                </div>
              )}
              {payments.length === 0 && (
                <button
                  type="button"
                  onClick={() => setPanel('pay')}
                  disabled={activePaymentMethods.length === 0}
                  className="w-full rounded border border-dashed border-border px-3 py-4 text-sm font-medium text-text-muted transition-colors hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Agregar pago con F2
                </button>
              )}
            </div>
            <div className="mt-4 space-y-2 rounded border border-border bg-bg/50 p-3">
              <AmountRow label="Restante USD" value={paymentTotals.remaining} />
              {bestActiveRate(currentRates, exchangeRateTypes) && (
                <AmountRow
                  label={`Restante VES (${bestActiveRate(currentRates, exchangeRateTypes)?.code})`}
                  value={paymentAmountForCurrency(paymentTotals.remaining, 'VES', bestActiveRate(currentRates, exchangeRateTypes)?.rate ?? null)}
                  currency="VES"
                />
              )}
              <div className="mt-2 rounded bg-success/10 p-3">
                <p className="text-xs text-text-muted">Vuelto</p>
                <p className="text-3xl font-bold text-success">{money(paymentTotals.change)}</p>
                {paymentTotals.change > 0 && paymentTotals.change_currency === 'VES' && (
                  <p className="mt-1 text-sm font-semibold text-success">
                    Bs {roundMoney(paymentTotals.change_amount ?? 0).toFixed(2)}
                    {paymentTotals.change_rate ? ` @ ${paymentTotals.change_rate}` : ''}
                  </p>
                )}
              </div>
            </div>
          </div>

          <div className="space-y-2 border-t border-border p-3">
            {checkoutBlockReason && (
              <p className="rounded border border-warning bg-warning/10 px-3 py-2 text-xs text-warning">
                {checkoutBlockReason}
              </p>
            )}
            <Button className="h-12 w-full text-base" disabled={Boolean(checkoutBlockReason) || checkout.isPending} onClick={() => void confirmPaidSale()}>
              {checkout.isPending ? <Loader2 className="size-4 animate-spin" /> : <CreditCard className="size-5" />}
              Cobrar
            </Button>
          </div>
        </aside>
      </main>

      {panel && (
        <PanelShell title={panelTitle(panel)} onClose={() => setPanel(null)} wide={panel === 'pay'}>
          {panel === 'customer' && (
            <CustomerPanel
              search={customerSearch}
              customers={customerResults}
              customerName={customerName}
              onSearch={setCustomerSearch}
              onGeneric={() => {
                setSelectedCustomer(null);
                setCustomerName('Consumidor Final');
                setPanel(null);
              }}
              onName={setCustomerName}
              onSelect={(customer) => {
                setSelectedCustomer(customer);
                setCustomerName(customer.name);
                setPanel(null);
              }}
            />
          )}
          {panel === 'hold' && (
            <HoldPanel
              orders={pendingOrders}
              selected={selectedPending}
              canCancel={canCancel}
              onSelect={setSelectedPending}
              onPaySelected={() => selectedPending && payPendingOrder(selectedPending)}
              onCancel={(order) => cancelOrder.mutate(order.id)}
            />
          )}
          {panel === 'cash' && activeSession && (
            <CashPanel
              session={activeSession}
              canMove={canMoveCash}
              canClose={canCloseCash}
              movement={cashMovement}
              closingAmount={closingAmount}
              onMovementChange={setCashMovement}
              onClosingAmount={setClosingAmount}
              onAddMovement={() => {
                if (!Number(cashMovement.amount)) return toast.error('Ingresa un monto.');
                addCashMovement.mutate({
                  sessionId: activeSession.id,
                  payload: {
                    type: cashMovement.type as 'inflow' | 'outflow' | 'adjustment',
                    method: 'cash',
                    currency: 'USD',
                    amount: Number(cashMovement.amount),
                    notes: cashMovement.notes,
                  },
                });
                setCashMovement({ type: 'outflow', amount: '', notes: '' });
              }}
              onCloseSession={() => {
                if (!Number(closingAmount)) return toast.error('Ingresa el efectivo contado.');
                closeCash.mutate({
                  sessionId: activeSession.id,
                  payload: { counted_currency: 'USD', counted_amount: Number(closingAmount), closing_notes: 'Cierre desde POS' },
                });
              }}
            />
          )}
          {panel === 'receipt' && <ReceiptPanel order={lastReceipt} />}
          {panel === 'pay' && (
            <QuickPaymentPanel
              methods={activePaymentMethods}
              cartTotal={cartTotals.total}
              payments={payments}
              currentRates={currentRates}
              rateTypes={exchangeRateTypes}
              onSelect={(methodId) => {
                addQuickPayment(methodId);
                setPanel(null);
              }}
            />
          )}
        </PanelShell>
      )}
    </div>
  );

  function addProduct(product: Product): void {
    const available = Number(product.available_stock ?? 0);
    if ((product.track_stock ?? true) && available <= 0) {
      toast.error('Producto sin stock disponible.');
      return;
    }
    if (!selectedWarehouse) {
      toast.error('Selecciona un almacen.');
      return;
    }
    setCart((current) => {
      const existing = current.find((line) => line.product_id === product.id && line.warehouse_id === selectedWarehouse.id);
      if (existing) {
        return current.map((line) =>
          line.id === existing.id
            ? { ...line, quantity: clampQuantity(line.quantity + 1, line.available_stock) }
            : line,
        );
      }
      return [
        ...current,
        {
          id: crypto.randomUUID(),
          product_id: product.id,
          name: product.name,
          sku: product.sku,
          barcode: product.barcode,
          warehouse_id: selectedWarehouse.id,
          quantity: 1,
          available_stock: available,
          unit_price: Number(product.base_price ?? 0),
          currency: (product.sale_currency ?? 'USD') as CurrencyCode,
          price_list_id: null,
        },
      ];
    });
    setQuery('');
  }

  function updateLine(id: string, patch: Partial<PosCartLine>): void {
    setCart((current) =>
      current.map((line) => {
        if (line.id !== id) return line;
        const next = { ...line, ...patch };
        next.quantity = clampQuantity(Number(next.quantity), next.available_stock);
        return next;
      }),
    );
  }

  function addQuickPayment(paymentMethodId: number): void {
    const configured = configuredPaymentMethods.find((item) => item.id === paymentMethodId);
    if (!configured) return;
    addPaymentLine((configured.method ?? 'other') as PosPaymentMethod, paymentMethodId);
    setPanel(null);
  }

  function addPaymentLine(method: PosPaymentMethod, paymentMethodId?: number): void {
    const configured = paymentMethodId ? configuredPaymentMethods.find((item) => item.id === paymentMethodId) : null;
    const currencyMode = configured?.currency_mode ?? 'USD';
    const currency = currencyMode === 'VES' ? 'VES' : 'USD';
    const rate = bestActiveRate(currentRates, exchangeRateTypes);
    setPayments((current) => [
      ...current,
      {
        id: crypto.randomUUID(),
        method: (configured?.method ?? method) as PosPaymentMethod,
        currency,
        amount: paymentAmountForCurrency(
          Math.max(0, calculateCartTotals(cart).total - calculatePaymentTotals(current, calculateCartTotals(cart).total).paid),
          currency,
          rate?.rate ?? null,
        ),
        received_amount: method === 'cash'
          ? paymentAmountForCurrency(Math.max(0, calculateCartTotals(cart).total - calculatePaymentTotals(current, calculateCartTotals(cart).total).paid), currency, rate?.rate ?? null)
          : null,
        payment_method_id: paymentMethodId ?? null,
        exchange_rate_type_id: rate?.exchange_rate_type_id ?? null,
        exchange_rate: rate?.rate ?? null,
        reference: configured?.requires_reference ? '' : null,
        status: 'captured',
      },
    ]);
  }

  function updatePayment(id: string, patch: Partial<PosPaymentLine>): void {
    setPayments((current) => current.map((payment) => (payment.id === id ? { ...payment, ...patch } : payment)));
  }

  async function confirmPaidSale(): Promise<void> {
    if (checkoutBlockReason) {
      toast.error(checkoutBlockReason);
      if (payments.length === 0 && cart.length > 0 && canCheckout) setPanel('pay');
      return;
    }
    if (!activeSession) {
      toast.error('No hay caja abierta.');
      return;
    }
    try {
      const order = await checkout.mutateAsync(buildCheckoutPayload(activeSession.id, 'captured'));
      setLastReceipt(order);
      clearTicket();
      setPanel('receipt');
      toast.success('Venta confirmada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo completar el cobro.');
    }
  }

  async function holdSale(): Promise<void> {
    if (!activeSession || cart.length === 0) return;
    if (!canCheckout) {
      toast.error('No tienes permiso pos.checkout para poner ventas en espera.');
      return;
    }
    try {
      await checkout.mutateAsync({
        ...buildCheckoutPayload(activeSession.id, 'pending'),
        payments: [{ method: 'cash', currency: 'USD', amount: 0.01, status: 'pending', reference: 'hold' }],
      });
      clearTicket();
      toast.success('Venta puesta en espera.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo poner la venta en espera.');
    }
  }

  async function payPendingOrder(order: PosOrder): Promise<void> {
    if (payments.length === 0) {
      toast.error('Agrega pagos para completar el ticket.');
      return;
    }
    const paid = await addPayments.mutateAsync({
      orderId: order.id,
      payments: payments.map(toPaymentPayload),
    });
    setLastReceipt(paid);
    setSelectedPending(null);
    setPayments([]);
    setPanel('receipt');
  }

  function buildCheckoutPayload(sessionId: number, status: 'captured' | 'pending'): CheckoutPayload {
    return {
      cash_register_session_id: sessionId,
      customer_id: selectedCustomer?.id ?? null,
      customer_name: selectedCustomer ? selectedCustomer.name : customerName,
      items: cart.map((line) => ({
        warehouse_id: line.warehouse_id,
        product_id: line.product_id,
        price_list_id: line.price_list_id ?? null,
        quantity: line.quantity,
        discount_type: line.discount_type ?? null,
        discount_value: line.discount_value ?? null,
        discount_reason: line.discount_reason ?? null,
      })),
      payments: status === 'captured' ? payments.map(toPaymentPayload) : [],
    };
  }

  function toPaymentPayload(payment: PosPaymentLine): CheckoutPayload['payments'][number] {
    return {
      payment_method_id: payment.payment_method_id ?? null,
      method: payment.method as PosPaymentMethod,
      currency: payment.currency,
      amount: Number(payment.amount || 0),
      exchange_rate_type_id: payment.exchange_rate_type_id ?? null,
      status: payment.status ?? 'captured',
      reference: payment.reference ?? null,
    };
  }

  function clearTicket(): void {
    setCart([]);
    setPayments([]);
    setSelectedCustomer(null);
    setCustomerName('Consumidor Final');
    setSelectedPending(null);
  }
}

function CartLineRow({ line, onChange, onRemove }: { line: PosCartLine; onChange: (patch: Partial<PosCartLine>) => void; onRemove: () => void }) {
  const stockIssue = line.quantity > line.available_stock;
  return (
    <div className={cn('grid gap-3 p-3 xl:grid-cols-[minmax(220px,1fr)_380px_120px_40px] xl:items-center', stockIssue && 'bg-warning/10')}>
      <div className="min-w-0 space-y-1">
        <div className="flex min-w-0 items-center gap-2">
          <p className="truncate text-base font-semibold">{line.name}</p>
          <Badge variant={stockIssue ? 'warning' : 'success'} className="shrink-0 text-[10px]">
            Stock {line.available_stock}
          </Badge>
        </div>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
          <span className="font-mono">{line.sku ?? line.barcode ?? line.product_id}</span>
          <span>{money(line.unit_price)} c/u</span>
        </div>
      </div>
      <div className="grid gap-2 sm:grid-cols-[124px_1fr]">
        <div className="flex items-center gap-1">
          <Button size="icon-sm" variant="outline" onClick={() => onChange({ quantity: line.quantity - 1 })}><Minus className="size-3" /></Button>
          <Input className="h-9 text-center" type="number" min="1" value={line.quantity} onChange={(event) => onChange({ quantity: Number(event.target.value) })} />
          <Button size="icon-sm" variant="outline" onClick={() => onChange({ quantity: line.quantity + 1 })}><Plus className="size-3" /></Button>
        </div>
        <div className="grid grid-cols-[minmax(100px,1fr)_92px] gap-2">
          <Select value={line.discount_type ?? ''} onChange={(event) => onChange({ discount_type: (event.target.value || null) as DiscountType | null })}>
            <option value="">Sin descuento</option>
            <option value="percent">Porcentaje</option>
            <option value="fixed">Monto</option>
          </Select>
          <Input type="number" min="0" value={line.discount_value ?? ''} onChange={(event) => onChange({ discount_value: Number(event.target.value || 0) })} />
        </div>
      </div>
      <div className="text-right">
        <p className="text-xs text-text-muted">Total linea</p>
        <p className="text-lg font-bold">{money(lineTotal(line))}</p>
      </div>
      <Button size="icon-sm" variant="ghost" onClick={onRemove} aria-label="Eliminar linea"><Trash2 className="size-4" /></Button>
    </div>
  );
}

function PaymentChip({
  payment,
  methods,
  rateTypes,
  onChange,
  onRemove,
}: {
  payment: PosPaymentLine;
  methods: Array<{ id: number; name: string; method?: string | null; currency_mode?: 'USD' | 'VES' | 'flexible'; requires_reference?: boolean }>;
  rateTypes: Array<{ id: number; code: string; name: string; is_default?: boolean; is_active?: boolean }>;
  onChange: (patch: Partial<PosPaymentLine>) => void;
  onRemove: () => void;
}) {
  const selectedMethod = methods.find((method) => method.id === payment.payment_method_id) ?? null;
  const requiresReference = selectedMethod?.requires_reference || payment.method !== 'cash';
  const rateType = rateTypes.find((rate) => rate.id === payment.exchange_rate_type_id) ?? null;
  const baseAmount = paymentBaseAmount(payment);

  return (
    <div className="rounded border border-border bg-bg/40 p-2">
      <div className="grid grid-cols-[minmax(0,1fr)_112px_auto] items-center gap-2">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <p className="truncate text-sm font-semibold">{selectedMethod?.name ?? methodLabel(payment.method)}</p>
            <Badge variant="info">{payment.currency}</Badge>
          </div>
          <p className="mt-1 text-xs text-text-muted">
            {payment.currency === 'VES' && rateType
              ? `${rateType.code}${payment.exchange_rate ? ` @ ${payment.exchange_rate}` : ''}`
              : methodLabel(payment.method)}
          </p>
        </div>
        <Input
          className="h-9 text-right text-sm font-semibold"
          type="number"
          min="0"
          value={payment.amount}
          onChange={(event) => onChange({ amount: Number(event.target.value) })}
          placeholder="Monto"
        />
        <Button size="icon-sm" variant="ghost" onClick={onRemove}><X className="size-4" /></Button>
      </div>

      {(payment.currency === 'VES' && payment.exchange_rate) || payment.method === 'cash' || requiresReference ? (
        <div className="mt-2 grid gap-2">
          {payment.currency === 'VES' && payment.exchange_rate && (
            <p className="text-xs font-medium text-text-muted">Equivale a {money(baseAmount)}</p>
          )}
          {payment.method === 'cash' && (
            <Input
              className="h-9 text-sm font-semibold"
              type="number"
              min="0"
              value={payment.received_amount ?? ''}
              placeholder="Recibido"
              onChange={(event) => onChange({ received_amount: Number(event.target.value) })}
            />
          )}
          {requiresReference && (
            <Input
              className="h-9 text-sm"
              value={payment.reference ?? ''}
              placeholder={selectedMethod?.requires_reference ? 'Referencia obligatoria' : 'Referencia'}
              onChange={(event) => onChange({ reference: event.target.value })}
            />
          )}
        </div>
      ) : null}
    </div>
  );
}

function OpenCashScreen(props: {
  canOpenCash: boolean;
  branches: Array<{ id: number; name: string; code: string }>;
  cashRegisters: Array<{ id: number; name: string; code?: string | null }>;
  branchId: number | '';
  registerId: number | '';
  amount: string;
  busy: boolean;
  onBranchChange: (id: number | '') => void;
  onRegisterChange: (id: number | '') => void;
  onAmountChange: (amount: string) => void;
  onOpen: () => void;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-bg p-4">
      <div className="w-full max-w-lg rounded border border-border bg-surface p-6 shadow-sm">
        <h1 className="text-xl font-semibold">Abrir caja</h1>
        <p className="mt-1 text-sm text-text-muted">Para vender en POS necesitas una caja fisica abierta.</p>
        {!props.canOpenCash ? (
          <p className="mt-4 rounded border border-warning bg-warning/10 p-3 text-sm text-warning">No tienes permiso para abrir caja.</p>
        ) : (
          <div className="mt-5 space-y-3">
            {(props.branches.length === 0 || props.cashRegisters.length === 0) && (
              <div className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">
                Falta configurar sucursales o cajas fisicas antes de abrir turno.
                <Button asChild className="mt-3 w-full" variant="outline">
                  <Link to="/cash-register">Configurar cajas</Link>
                </Button>
              </div>
            )}
            <Select value={props.branchId} onChange={(event) => props.onBranchChange(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Sucursal...</option>
              {props.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
            </Select>
            <Select value={props.registerId} onChange={(event) => props.onRegisterChange(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Caja fisica...</option>
              {props.cashRegisters.map((register) => <option key={register.id} value={register.id}>{register.code ?? register.id} - {register.name}</option>)}
            </Select>
            <Input type="number" min="0" value={props.amount} onChange={(event) => props.onAmountChange(event.target.value)} placeholder="Fondo inicial USD" />
            <Button className="w-full" onClick={props.onOpen} disabled={props.busy}>
              {props.busy && <Loader2 className="size-4 animate-spin" />} Abrir turno
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

function PanelShell({ title, children, onClose, wide = false }: { title: string; children: React.ReactNode; onClose: () => void; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30">
      <div className={cn('h-full w-full overflow-auto border-l border-border bg-surface p-4 shadow-xl', wide ? 'max-w-4xl' : 'max-w-md')}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-semibold">{title}</h2>
          <Button size="icon-sm" variant="ghost" onClick={onClose}><X className="size-4" /></Button>
        </div>
        {children}
      </div>
    </div>
  );
}

function QuickPaymentPanel({
  methods,
  cartTotal,
  payments,
  currentRates,
  rateTypes,
  onSelect,
}: {
  methods: Array<{ id: number; name: string; method?: string | null; currency_mode?: 'USD' | 'VES' | 'flexible'; requires_reference?: boolean; sort_order?: number }>;
  cartTotal: number;
  payments: PosPaymentLine[];
  currentRates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>;
  rateTypes: Array<{ id: number; is_default?: boolean; is_active?: boolean; code: string }>;
  onSelect: (methodId: number) => void;
}) {
  const remaining = calculatePaymentTotals(payments, cartTotal).remaining;
  const rate = bestActiveRate(currentRates, rateTypes);

  return (
    <div className="space-y-4">
      <div className="rounded border border-border bg-bg/50 p-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-sm text-text-muted">Restante</p>
            <p className="text-4xl font-bold">{money(remaining)}</p>
          </div>
          {rate ? (
            <div className="text-right">
              <p className="text-sm text-text-muted">Equivalente VES</p>
              <p className="text-2xl font-bold">Bs {paymentAmountForCurrency(remaining, 'VES', rate.rate).toFixed(2)}</p>
              <p className="text-xs text-text-muted">{rate.code} @ {rate.rate}</p>
            </div>
          ) : (
            <div className="rounded border border-warning bg-warning/10 px-3 py-2 text-sm text-warning">
              Configura una tasa activa USD/VES antes de cobrar.
            </div>
          )}
        </div>
      </div>

      {methods.length === 0 ? (
        <div className="rounded border border-warning bg-warning/10 p-4 text-sm text-warning">
          No hay metodos activos para POS.
          <Button asChild className="mt-3" variant="outline">
            <Link to="/payment-methods">Configurar metodos</Link>
          </Button>
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {methods.map((method) => {
            const preview = previewQuickPayment(method, cartTotal, payments, currentRates, rateTypes);
            return (
              <button
                key={method.id}
                type="button"
                onClick={() => onSelect(method.id)}
                className="min-h-28 rounded border border-border bg-bg/40 p-4 text-left transition-colors hover:border-primary hover:bg-primary/5"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate font-semibold">{method.name}</p>
                    <p className="mt-1 text-xs text-text-muted">{methodLabel(method.method)}</p>
                  </div>
                  <Badge variant={method.currency_mode === 'VES' ? 'info' : 'default'}>
                    {method.currency_mode === 'flexible' ? 'USD/VES' : method.currency_mode ?? 'USD'}
                  </Badge>
                </div>
                <p className="mt-4 text-2xl font-bold">{preview.amountLabel}</p>
                <p className="text-xs text-text-muted">{preview.detail}</p>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function CustomerPanel(props: { search: string; customerName: string; customers: Customer[]; onSearch: (value: string) => void; onName: (value: string) => void; onGeneric: () => void; onSelect: (customer: Customer) => void }) {
  return (
    <div className="space-y-3">
      <Button className="w-full" variant="outline" onClick={props.onGeneric}>Consumidor Final</Button>
      <Input value={props.customerName} onChange={(event) => props.onName(event.target.value)} placeholder="Nombre manual para ticket" />
      <Input value={props.search} onChange={(event) => props.onSearch(event.target.value)} placeholder="Buscar cliente por nombre o documento" />
      <div className="space-y-2">
        {props.customers.map((customer) => (
          <button key={customer.id} type="button" onClick={() => props.onSelect(customer)} className="w-full rounded border border-border p-3 text-left hover:border-primary">
            <p className="font-medium">{customer.name}</p>
            <p className="text-xs text-text-muted">{customer.tax_id ?? customer.email ?? customer.phone ?? 'Cliente'}</p>
          </button>
        ))}
      </div>
    </div>
  );
}

function HoldPanel(props: { orders: PosOrder[]; selected: PosOrder | null; canCancel: boolean; onSelect: (order: PosOrder) => void; onPaySelected: () => void; onCancel: (order: PosOrder) => void }) {
  return (
    <div className="space-y-3">
      {props.orders.length === 0 && <p className="text-sm text-text-muted">No hay tickets en espera.</p>}
      {props.orders.map((order) => (
        <button key={order.id} type="button" onClick={() => props.onSelect(order)} className={cn('w-full rounded border p-3 text-left', props.selected?.id === order.id ? 'border-primary bg-primary/5' : 'border-border')}>
          <p className="font-medium">Ticket #{order.id}</p>
          <p className="text-sm text-text-muted">{order.customer_name ?? 'Consumidor Final'} - {money(order.total_base_amount ?? 0)}</p>
        </button>
      ))}
      <Button className="w-full" disabled={!props.selected} onClick={props.onPaySelected}>Cobrar seleccionado</Button>
      {props.selected && props.canCancel && <Button className="w-full" variant="outline" onClick={() => props.onCancel(props.selected!)}>Cancelar ticket</Button>}
    </div>
  );
}

function CashPanel(props: { session: CashRegisterSession; canMove: boolean; canClose: boolean; movement: { type: string; amount: string; notes: string }; closingAmount: string; onMovementChange: (value: { type: string; amount: string; notes: string }) => void; onClosingAmount: (value: string) => void; onAddMovement: () => void; onCloseSession: () => void }) {
  return (
    <div className="space-y-4">
      <div className="rounded border border-border bg-bg/50 p-3">
        <AmountRow label="Fondo inicial" value={props.session.opening_base_amount ?? 0} />
        <AmountRow label="Esperado" value={props.session.expected_base_amount ?? 0} />
      </div>
      {props.canMove && (
        <div className="space-y-2">
          <h3 className="text-sm font-semibold">Movimiento extra</h3>
          <Select value={props.movement.type} onChange={(event) => props.onMovementChange({ ...props.movement, type: event.target.value })}>
            <option value="inflow">Entrada</option>
            <option value="outflow">Salida</option>
            <option value="adjustment">Ajuste</option>
          </Select>
          <Input type="number" min="0" value={props.movement.amount} onChange={(event) => props.onMovementChange({ ...props.movement, amount: event.target.value })} placeholder="Monto USD" />
          <Input value={props.movement.notes} onChange={(event) => props.onMovementChange({ ...props.movement, notes: event.target.value })} placeholder="Motivo" />
          <Button className="w-full" onClick={props.onAddMovement}>Registrar movimiento</Button>
        </div>
      )}
      {props.canClose && (
        <div className="space-y-2 border-t border-border pt-4">
          <h3 className="text-sm font-semibold">Cierre de caja</h3>
          <Input type="number" min="0" value={props.closingAmount} onChange={(event) => props.onClosingAmount(event.target.value)} placeholder="Efectivo contado USD" />
          <Button className="w-full" variant="outline" onClick={props.onCloseSession}>Cerrar turno</Button>
        </div>
      )}
    </div>
  );
}

function ReceiptPanel({ order }: { order: PosOrder | null }) {
  if (!order) return <p className="text-sm text-text-muted">Aun no hay recibo en esta sesion.</p>;
  return (
    <div className="space-y-3">
      <div className="rounded border border-border p-3">
        <p className="font-semibold">Orden POS #{order.id}</p>
        <p className="text-sm text-text-muted">{order.customer_name ?? 'Consumidor Final'}</p>
      </div>
      <AmountRow label="Total" value={order.total_base_amount ?? 0} />
      <AmountRow label="Pagado" value={order.paid_base_amount ?? 0} />
      <Badge variant={order.status === 'paid' ? 'success' : 'info'}>{order.status}</Badge>
    </div>
  );
}

function AmountRow({ label, value, muted = false, currency = 'USD' }: { label: string; value: number; muted?: boolean; currency?: CurrencyCode }) {
  return (
    <div className={cn('flex items-center justify-between', muted && 'text-text-muted')}>
      <span>{label}</span>
      <span className="font-medium">{currency === 'VES' ? `Bs ${roundMoney(value).toFixed(2)}` : money(value)}</span>
    </div>
  );
}

function ShortcutText({ label, text }: { label: string; text: string }) {
  return (
    <span className="inline-flex items-center gap-1">
      <kbd className="font-semibold text-text-primary">{label}</kbd>
      <span>{text}</span>
    </span>
  );
}

function panelTitle(panel: Panel): string {
  switch (panel) {
    case 'pay':
      return 'Pago rapido';
    case 'hold':
      return 'Ventas en espera';
    case 'customer':
      return 'Cliente';
    case 'cash':
      return 'Caja';
    case 'receipt':
      return 'Ultimo recibo';
    default:
      return '';
  }
}

function getCheckoutBlockReason(input: {
  canCheckout: boolean;
  hasSession: boolean;
  cartCount: number;
  paymentCount: number;
  remaining: number;
  hasStockIssue: boolean;
  paymentSetupIssue: string | null;
}): string | null {
  if (!input.canCheckout) return 'No tienes permiso pos.checkout para cobrar ventas.';
  if (!input.hasSession) return 'No hay caja abierta para cobrar.';
  if (input.cartCount === 0) return 'Agrega al menos un producto para cobrar.';
  if (input.hasStockIssue) return 'Hay productos con stock insuficiente. La venta esta bloqueada.';
  if (input.paymentCount === 0) return 'Agrega al menos una linea de pago.';
  if (input.paymentSetupIssue) return input.paymentSetupIssue;
  if (input.remaining > 0) return `Falta capturar ${money(input.remaining)} para completar el pago.`;

  return null;
}

function getPaymentSetupIssue(
  payments: PosPaymentLine[],
  methods: Array<{ id: number; name: string; requires_reference?: boolean }>,
): string | null {
  for (const [index, payment] of payments.entries()) {
    const line = index + 1;
    const configured = methods.find((method) => method.id === payment.payment_method_id);
    if (!payment.payment_method_id) return `Selecciona un metodo configurado en la linea de pago ${line}.`;
    if (!payment.exchange_rate_type_id) return `Selecciona la tasa para la linea de pago ${line}.`;
    if (!payment.exchange_rate) return `No hay tasa activa para la linea de pago ${line}.`;
    if (configured?.requires_reference && !String(payment.reference ?? '').trim()) {
      return `${configured.name} requiere referencia antes de cobrar.`;
    }
  }

  return null;
}

function previewQuickPayment(
  method: { currency_mode?: 'USD' | 'VES' | 'flexible'; method?: string | null },
  total: number,
  payments: PosPaymentLine[],
  rates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>,
  rateTypes: Array<{ id: number; is_default?: boolean; is_active?: boolean; code: string }>,
): { amountLabel: string; detail: string } {
  const remaining = Math.max(0, total - calculatePaymentTotals(payments, total).paid);
  const rate = bestActiveRate(rates, rateTypes);
  const currency = method.currency_mode === 'VES' ? 'VES' : 'USD';
  const amount = paymentAmountForCurrency(remaining, currency, rate?.rate ?? null);
  const amountLabel = currency === 'VES' ? `Bs ${amount.toFixed(2)}` : money(amount);
  const detail = currency === 'VES'
    ? `${rate?.code ?? 'Tasa'}${rate?.rate ? ` @ ${rate.rate}` : ' sin valor activo'}`
    : methodLabel(method.method);

  return { amountLabel, detail };
}

function bestActiveRate(
  rates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>,
  rateTypes: Array<{ id: number; code?: string; is_default?: boolean; is_active?: boolean }>,
): { exchange_rate_type_id: number; code: string; rate: number } | null {
  const validRates = rates.filter((rate) =>
    Number(rate.rate) > 0
      && (!rate.base_currency || rate.base_currency === 'USD')
      && (!rate.quote_currency || rate.quote_currency === 'VES'),
  );
  if (validRates.length === 0) return null;

  const defaultType = rateTypes.find((rateType) => rateType.is_default && rateType.is_active !== false);
  const defaultRate = defaultType
    ? validRates.find((rate) => rate.exchange_rate_type_id === defaultType.id)
    : null;
  const selected = defaultRate ?? validRates[0];
  if (!selected) return null;
  const type = rateTypes.find((rateType) => rateType.id === selected.exchange_rate_type_id);

  return {
    exchange_rate_type_id: selected.exchange_rate_type_id,
    code: selected.exchange_rate_type_code ?? type?.code ?? 'Tasa',
    rate: Number(selected.rate),
  };
}

function paymentAmountForCurrency(remainingBase: number, currency: CurrencyCode, rate?: number | null): number {
  if (currency === 'VES') return roundMoney(remainingBase * Number(rate || 0));
  return roundMoney(remainingBase);
}

function methodLabel(method?: string | null): string {
  return PAYMENT_METHODS.find((item) => item.value === method)?.label ?? method ?? 'Pago';
}

function money(value: number): string {
  return `$${roundMoney(Number(value || 0)).toFixed(2)}`;
}
