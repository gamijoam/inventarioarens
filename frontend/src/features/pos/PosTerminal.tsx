import { useEffect, useMemo, useRef, useState } from 'react';
import { usePosCartStore, type Panel } from './cartStore';
import { Link } from '@tanstack/react-router';
import {
  Banknote,
  CreditCard,
  History,
  Loader2,
  Minus,
  PauseCircle,
  Plus,
  Printer,
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
import { Textarea } from '@/components/ui/Textarea';
import { PERMISSIONS } from '@/permissions/constants';
import { usePermissionContext } from '@/permissions/PermissionContext';
import { cn } from '@/lib/cn';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import {
  type CashRegisterSession,
  type CheckoutPayload,
  type CreateCustomerPayload,
  type Customer,
  type PosOrder,
  type PosPaymentMethod,
  type ProductSerial,
  quoteProductForPos,
  useAddCashMovement,
  useAddPosPayments,
  useAvailableProductSerialsForPos,
  useCancelPosOrder,
  useCheckout,
  useCloseCashSession,
  useCreateCustomerForPos,
  useCustomers,
  useOpenCashSession,
  useOpenPosOrders,
  useBootstrapRefsForPos,
  usePosBootstrap,
    usePosProductsDebounced,
  useSessionOrders,
} from './api';
import {
  calculateCartTotals,
  calculatePaymentTotals,
  clampQuantity,
  hasStockIssue,
  firstPriceIssue,
  hasPriceIssue,
  lineTotal,
  missingSerialIssue,
  paymentBaseAmount,
  type CurrencyCode,
  type DiscountType,
  type PosCartLine,
  type PosPaymentLine,
  roundMoney,
} from './posLogic';
import {
  type PrintJob,
  sendJobToLocalAgent,
  ticketPdfUrl,
  useCreatePosPrintJob,
  usePrinterStations,
  useUpdatePrintJobStatus,
} from '@/features/printing/api';

type QuickCustomerForm = Omit<CreateCustomerPayload, 'is_active' | 'is_generic'>;

const PAYMENT_METHODS: Array<{ value: PosPaymentMethod; label: string }> = [
  { value: 'cash', label: 'Efectivo' },
  { value: 'card', label: 'Tarjeta' },
  { value: 'mobile_payment', label: 'Pago movil' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'external_financing', label: 'Financiamiento' },
  { value: 'other', label: 'Otro' },
];

const BASE_PRICE_LIST_LABEL = 'Precio base';

export function PosTerminal() {
  const { permissions } = usePermissionContext();
  const canView = permissions.has(PERMISSIONS.POS_VIEW);
  const canCheckout = permissions.has(PERMISSIONS.POS_CHECKOUT);
  const canCollectReceivables = permissions.has(PERMISSIONS.ACCOUNTS_RECEIVABLE_COLLECT);
  const canCancel = permissions.has(PERMISSIONS.POS_CANCEL);
  const canOpenCash = permissions.has(PERMISSIONS.CASH_REGISTER_OPEN);
  const canMoveCash = permissions.has(PERMISSIONS.CASH_REGISTER_MOVE) || permissions.has(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canCloseCash = permissions.has(PERMISSIONS.CASH_REGISTER_CLOSE);
  const canCreateCustomer = permissions.has(PERMISSIONS.CUSTOMERS_CREATE);
  const canPrint = permissions.has(PERMISSIONS.PRINTING_PRINT);
  const canReprint = permissions.has(PERMISSIONS.PRINTING_REPRINT);
  const canDigital = permissions.has(PERMISSIONS.PRINTING_DIGITAL);

  const searchRef = useRef<HTMLInputElement | null>(null);
  // Estado POS (Zustand) ============================================
  // Carrito, pagos, panel, query y seleccion de almacen/lista se
  // almacenan en un store global con selectores atomicos para que
  // editar una linea no re-renderice el header ni el buscador. Ver
  // docs/SPRINT2_POS_2026-07-21.md (QW9) y cartStore.ts.
  const cart = usePosCartStore((s) => s.lines);
  const payments = usePosCartStore((s) => s.payments);
  const panel = usePosCartStore((s) => s.panel);
  const query = usePosCartStore((s) => s.query);
  const productSearch = usePosCartStore((s) => s.productSearch);
  const serialLineId = usePosCartStore((s) => s.serialLineId);
  const warehouseId = usePosCartStore((s) => s.warehouseId);
  const selectedPriceListId = usePosCartStore((s) => s.selectedPriceListId);
  const selectedCustomer = usePosCartStore((s) => s.selectedCustomer);
  const customerName = usePosCartStore((s) => s.customerName);
  const customerSearch = usePosCartStore((s) => s.customerSearch);
  const setQuery = usePosCartStore((s) => s.setQuery);
  const setProductSearch = usePosCartStore((s) => s.setProductSearch);
  const setPanel = usePosCartStore((s) => s.setPanel);
  const setSerialLineId = usePosCartStore((s) => s.setSerialLineId);
  const setWarehouseId = usePosCartStore((s) => s.setWarehouseId);
  const setSelectedPriceListId = usePosCartStore((s) => s.setSelectedPriceListId);
  const setSelectedCustomer = usePosCartStore((s) => s.setSelectedCustomer);
  const setCustomerName = usePosCartStore((s) => s.setCustomerName);
  const setCustomerSearch = usePosCartStore((s) => s.setCustomerSearch);
  // Wrappers legacy: el codigo existente usa setCart/setPayments con
  // updater functions o arrays directos. Mantenemos esa API delegando
  // al store de Zustand para evitar reescribir cada llamada inline.
  const setCart = (
    updater: PosCartLine[] | ((current: PosCartLine[]) => PosCartLine[]),
  ): void => {
    usePosCartStore.setState((state) => ({
      lines: typeof updater === 'function' ? updater(state.lines) : updater,
    }));
  };
  const setPayments = (
    updater: PosPaymentLine[] | ((current: PosPaymentLine[]) => PosPaymentLine[]),
  ): void => {
    usePosCartStore.setState((state) => ({
      payments: typeof updater === 'function' ? updater(state.payments) : updater,
    }));
  };

  // Estado local (formularios modales) ==============================
  const [priceListNotice, setPriceListNotice] = useState<string | null>(null);
  const [repricing, setRepricing] = useState(false);
  const [quickCustomer, setQuickCustomer] = useState<QuickCustomerForm>({
    name: '',
    document_type: 'V',
    document_number: '',
    phone: '',
    email: '',
    fiscal_address: '',
  });
  const [lastReceipt, setLastReceipt] = useState<PosOrder | null>(null);
  const [lastPrintJobs, setLastPrintJobs] = useState<PrintJob[]>([]);
  const [selectedPending, setSelectedPending] = useState<PosOrder | null>(null);
  const [openingBaseAmount, setOpeningBaseAmount] = useState('0');
  const [openingLocalAmount, setOpeningLocalAmount] = useState('0');
  const [openingBranchId, setOpeningBranchId] = useState<number | ''>('');
  const [openingRegisterId, setOpeningRegisterId] = useState<number | ''>('');
  const [cashMovement, setCashMovement] = useState({ type: 'outflow', amount: '', notes: '' });
  const [closingAmount, setClosingAmount] = useState('');
  const [creditDueDate, setCreditDueDate] = useState('');

  const bootstrapRefs = useBootstrapRefsForPos();
  const bootstrap = usePosBootstrap();
  const bootstrapReady = !bootstrap.isLoading && !bootstrap.isError;
  const warehouses = useMemo(() => bootstrapRefs.refs?.warehouses ?? [], [bootstrapRefs.refs]);
  const branches = useMemo(() => bootstrapRefs.refs?.branches ?? [], [bootstrapRefs.refs]);
  const cashRegisters = useMemo(() => bootstrapRefs.refs?.cash_registers ?? [], [bootstrapRefs.refs]);
  const sessions = useMemo(
    () => (bootstrap.data?.open_session ? [bootstrap.data.open_session] : []),
    [bootstrap.data],
  );
  const { data: pendingOrders = [] } = useOpenPosOrders();
  const { data: customerResults = [] } = useCustomers(customerSearch);
  const activeProductSearch = panel === 'product-search' ? productSearch : query;
  const shouldSearchProducts = activeProductSearch.trim().length >= 2;
  const { data: productPage, isLoading: loadingProducts } = usePosProductsDebounced(activeProductSearch, warehouseId, {
    enabled: shouldSearchProducts,
  });
  const configuredPaymentMethods = useMemo(
    () => bootstrap.data?.payment_methods ?? [],
    [bootstrap.data],
  );
  const priceLists = useMemo(() => bootstrap.data?.price_lists ?? [], [bootstrap.data]);
  const exchangeRateTypes = useMemo(
    () => bootstrap.data?.exchange_rate_types ?? [],
    [bootstrap.data],
  );
  const currentRates = useMemo(() => bootstrap.data?.exchange_rates ?? [], [bootstrap.data]);
  const { data: printerStations = [] } = usePrinterStations({ enabled: canPrint || canDigital || canReprint });
  const activePaymentMethods = useMemo(
    () => configuredPaymentMethods
      .filter((method) => method.is_active !== false)
      .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name)),
    [configuredPaymentMethods],
  );
  const selectedPriceList = useMemo(
    () => priceLists.find((list) => list.id === selectedPriceListId) ?? null,
    [priceLists, selectedPriceListId],
  );
  const allowedPaymentMethods = useMemo(
    () => filterPaymentMethodsForPriceList(activePaymentMethods, selectedPriceList),
    [activePaymentMethods, selectedPriceList],
  );
  const priceListPaymentIssue = getPriceListPaymentIssue(selectedPriceList, allowedPaymentMethods);
  const checkout = useCheckout();
  const addPayments = useAddPosPayments();
  const cancelOrder = useCancelPosOrder();
  const createCustomer = useCreateCustomerForPos();
  const openCash = useOpenCashSession();
  const addCashMovement = useAddCashMovement();
  const closeCash = useCloseCashSession();
  const createPrintJob = useCreatePosPrintJob();
  const updatePrintJobStatus = useUpdatePrintJobStatus();

  const activeCashRegisters = useMemo(
    () => cashRegisters.filter((register) => (register as { status?: string }).status !== 'inactive'),
    [cashRegisters],
  );
  const activeSession = useMemo(
    () => sessions.find((session) => session.status === 'open' && Boolean(session.cash_register_id)) ?? null,
    [sessions],
  );
  const { data: recentPaidOrders = [] } = useSessionOrders(activeSession?.id ?? null, 'paid', 10);
  const activePrinterStation = useMemo(
    () =>
      printerStations.find((station) => station.is_active && activeSession?.cash_register_id && station.cash_register_id === activeSession.cash_register_id)
      ?? printerStations.find((station) => station.is_active && activeSession?.branch_id && station.branch_id === activeSession.branch_id && !station.cash_register_id)
      ?? printerStations.find((station) => station.is_active)
      ?? null,
    [activeSession, printerStations],
  );
  const selectedWarehouse = warehouses.find((warehouse) => warehouse.id === warehouseId) ?? warehouses[0] ?? null;
  const serialLine = cart.find((line) => line.id === serialLineId) ?? null;
  const { data: availableSerials = [], isLoading: loadingSerials } = useAvailableProductSerialsForPos(
    serialLine?.product_id ?? null,
    serialLine?.warehouse_id ?? null,
  );
  const products = productPage?.data ?? [];
  const cartTotals = useMemo(() => calculateCartTotals(cart), [cart]);
  const paymentTotals = useMemo(() => calculatePaymentTotals(payments, cartTotals.total), [payments, cartTotals.total]);
  const paymentSetupIssue = getPaymentSetupIssue(payments, allowedPaymentMethods);
  const priceIssue = firstPriceIssue(cart);
  const serialIssue = missingSerialIssue(cart);
  const checkoutBlockReason = getCheckoutBlockReason({
    canCheckout,
    hasSession: Boolean(activeSession),
    cartCount: cart.length,
    paymentCount: payments.length,
    remaining: paymentTotals.remaining,
    hasStockIssue: hasStockIssue(cart),
    hasPriceIssue: hasPriceIssue(cart),
    priceIssue,
    serialIssue,
    paymentSetupIssue,
    priceListPaymentIssue,
  });
  const openingRate = bestActiveRate(currentRates, exchangeRateTypes);

  useEffect(() => {
    if (!warehouseId && warehouses[0]) setWarehouseId(warehouses[0].id);
  }, [warehouseId, warehouses]);

  useEffect(() => {
    if (branches[0] && openingBranchId === '') setOpeningBranchId(branches[0].id);
  }, [branches, openingBranchId]);

  useEffect(() => {
    if (activeCashRegisters[0] && openingRegisterId === '') setOpeningRegisterId(activeCashRegisters[0].id);
  }, [activeCashRegisters, openingRegisterId]);

  useEffect(() => {
    searchRef.current?.focus();
  }, [cart.length, panel]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'F2') {
        event.preventDefault();
        if (priceListPaymentIssue) {
          toast.error(priceListPaymentIssue);
          return;
        }
        setPanel('pay');
      }
      if (event.key === 'F3') {
        event.preventDefault();
        setProductSearch(query);
        setPanel('product-search');
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
  }, [cart, payments, activeSession, selectedCustomer, customerName, query, priceListPaymentIssue]);

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

  if (!activeSession && !bootstrap.isLoading && !bootstrap.isError && bootstrapReady) {
    return (
      <OpenCashScreen
        canOpenCash={canOpenCash}
        branches={branches}
        cashRegisters={activeCashRegisters}
        branchId={openingBranchId}
        registerId={openingRegisterId}
        baseAmount={openingBaseAmount}
        localAmount={openingLocalAmount}
        rateLabel={openingRate ? `${openingRate.code} @ ${formatLocalNumber(openingRate.rate)}` : null}
        onBranchChange={setOpeningBranchId}
        onRegisterChange={setOpeningRegisterId}
        onBaseAmountChange={setOpeningBaseAmount}
        onLocalAmountChange={setOpeningLocalAmount}
        onOpen={() => {
          if (!openingBranchId) return toast.error('Selecciona una sucursal.');
          if (!openingRegisterId) return toast.error('Selecciona una caja fisica activa.');
          if (Number(openingLocalAmount || 0) > 0 && !openingRate) {
            return toast.error('Configura una tasa activa USD/VES antes de abrir con fondo VES.');
          }
          openCash.mutate({
            branch_id: Number(openingBranchId),
            cash_register_id: Number(openingRegisterId),
            opening_base_amount: Number(openingBaseAmount || 0),
            opening_local_amount: Number(openingLocalAmount || 0),
            exchange_rate_type_id: Number(openingLocalAmount || 0) > 0 ? openingRate?.exchange_rate_type_id : null,
            notes: 'Apertura desde POS',
          }, {
            onError: (error) => {
              void bootstrap.refetch();
              toast.error(errorMessage(error));
            },
          });
        }}
        busy={openCash.isPending}
      />
    );
  }

  return (
    <div className="h-screen overflow-hidden bg-bg text-text-primary">
      <header className="grid grid-cols-1 gap-3 border-b border-border bg-surface px-4 py-3 2xl:grid-cols-[340px_minmax(720px,1fr)_auto]">
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
        <div className="grid min-w-0 gap-2 md:grid-cols-[minmax(260px,1fr)_210px_230px]">
          <div className="space-y-1">
            <label className="block text-[10px] font-semibold uppercase text-text-muted">Buscar / escanear</label>
            <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
            <Input
              ref={searchRef}
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  void handleProductSearchEnter();
                }
              }}
              className="h-10 pl-9 text-base"
              placeholder="Escanea codigo, SKU o escribe producto"
              data-testid="pos-search"
            />
            </div>
          </div>
          <div className="space-y-1">
            <label className="block text-[10px] font-semibold uppercase text-text-muted">Almacen</label>
            <Select
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
          <div className="space-y-1">
            <label className="block text-[10px] font-semibold uppercase text-text-muted">Lista de precio</label>
            <Select
              value={selectedPriceListId ?? 'base'}
              onChange={(event) => void changePriceList(event.target.value === 'base' ? null : Number(event.target.value))}
              disabled={repricing}
            >
              <option value="base">{BASE_PRICE_LIST_LABEL}</option>
              {priceLists.map((list) => (
                <option key={list.id} value={list.id}>
                  {list.code} - {list.name}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div className="flex flex-wrap items-end gap-2 2xl:justify-end">
          <Button variant="outline" size="sm" onClick={() => {
            setProductSearch(query);
            setPanel('product-search');
          }}>
            <Search className="size-4" /> <ShortcutText label="F3" text="Buscar" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              if (priceListPaymentIssue) return toast.error(priceListPaymentIssue);
              setPanel('pay');
            }}
            disabled={allowedPaymentMethods.length === 0 || Boolean(priceListPaymentIssue)}
          >
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

      <main className="grid h-[calc(100vh-73px)] grid-cols-1 gap-3 overflow-hidden p-3 xl:grid-cols-[minmax(680px,1fr)_430px]">
        <section className="flex min-h-0 flex-col rounded border border-border bg-surface">
          <div className="flex items-center justify-between border-b border-border p-3">
            <div>
              <h2 className="font-semibold">Ticket actual</h2>
              <p className="text-xs text-text-muted">{selectedCustomer ? 'Cliente asignado' : customerName}</p>
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
          <CustomerAssignmentBanner
            customer={selectedCustomer}
            customerName={customerName}
            onChange={() => setPanel('customer')}
            onClear={() => {
              setSelectedCustomer(null);
              setCustomerName('Consumidor Final');
            }}
          />
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
                    onSerials={() => {
                      setSerialLineId(line.id);
                      setPanel('serials');
                    }}
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
            {priceListNotice ? (
              <p className="mb-3 rounded border border-warning bg-warning/10 p-3 text-sm text-warning">{priceListNotice}</p>
            ) : null}
            {priceListPaymentIssue && activePaymentMethods.length > 0 ? (
              <div className="mb-3 rounded border border-warning bg-warning/10 p-3 text-sm text-warning">
                {priceListPaymentIssue}
                <Button asChild className="mt-3 w-full" variant="outline">
                  <Link to="/inventory/admin">Configurar lista</Link>
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
                  disabled={allowedPaymentMethods.length === 0 || Boolean(priceListPaymentIssue)}
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
            <Button
              className="h-10 w-full"
              variant="secondary"
              disabled={!canCheckout || !canCollectReceivables || cart.length === 0 || hasStockIssue(cart) || hasPriceIssue(cart) || Boolean(priceListPaymentIssue) || checkout.isPending}
              onClick={() => setPanel('credit')}
            >
              <Wallet className="size-4" />
              Enviar a CxC
            </Button>
          </div>
        </aside>
      </main>

      {panel && (
        <PanelShell title={panelTitle(panel)} onClose={() => setPanel(null)} wide={panel === 'pay' || panel === 'customer'}>
          {panel === 'customer' && (
            <CustomerPanel
              search={customerSearch}
              customers={customerResults}
              customerName={customerName}
              form={quickCustomer}
              canCreate={canCreateCustomer}
              creating={createCustomer.isPending}
              onSearch={setCustomerSearch}
              onGeneric={() => {
                setSelectedCustomer(null);
                setCustomerName('Consumidor Final');
                setPanel(null);
              }}
              onName={setCustomerName}
              onFormChange={(patch) => setQuickCustomer((current) => ({ ...current, ...patch }))}
              onCreate={() => void createQuickCustomer()}
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
          {panel === 'receipt' && (
            <ReceiptPanel
              order={lastReceipt}
              jobs={lastPrintJobs}
              history={recentPaidOrders}
              onSelectHistory={(order) => setLastReceipt(order)}
              canPrint={canPrint}
              canReprint={canReprint}
              canDigital={canDigital}
              busy={createPrintJob.isPending || updatePrintJobStatus.isPending}
              onPrint={(copy, output) => lastReceipt && createAndDispatchPrintJobs(lastReceipt, copy, output)}
              onOpenPdf={(job) => window.open(ticketPdfUrl(job), '_blank')}
            />
          )}
          {panel === 'product-search' && (
            <ProductSearchPanel
              search={productSearch}
              products={products}
              warehouses={warehouses}
              warehouseId={warehouseId}
              priceListName={selectedPriceList?.name ?? BASE_PRICE_LIST_LABEL}
              loading={loadingProducts}
              onSearch={setProductSearch}
              onWarehouseChange={setWarehouseId}
              onSelect={async (product) => {
                const added = await addProduct(product);
                if (added) setPanel(null);
              }}
            />
          )}
          {panel === 'pay' && (
            <QuickPaymentPanel
              methods={allowedPaymentMethods}
              cartTotal={cartTotals.total}
              payments={payments}
              currentRates={currentRates}
              rateTypes={exchangeRateTypes}
              priceListName={selectedPriceList?.name ?? BASE_PRICE_LIST_LABEL}
              issue={priceListPaymentIssue}
              onSelect={(methodId) => {
                addQuickPayment(methodId);
                setPanel(null);
              }}
            />
          )}
          {panel === 'credit' && (
            <CreditPanel
              customer={selectedCustomer}
              total={cartTotals.total}
              paid={paymentTotals.paid}
              dueDate={creditDueDate}
              canCredit={canCheckout && canCollectReceivables}
              busy={checkout.isPending}
              onDueDate={setCreditDueDate}
              onCustomer={() => setPanel('customer')}
              onConfirm={() => void confirmCreditSale()}
            />
          )}
          {panel === 'serials' && serialLine && (
            <SerialSelectionPanel
              line={serialLine}
              serials={availableSerials}
              loading={loadingSerials}
              onToggle={(serial) => toggleSerial(serialLine.id, serial)}
            />
          )}
        </PanelShell>
      )}
    </div>
  );

  async function quoteProduct(product: Pick<Product, 'id' | 'name'>, priceList: PriceList) {
    try {
      return await quoteProductForPos(product.id, priceList.id);
    } catch (error) {
      const message = `${product.name} no tiene precio activo en la lista ${priceList.name}.`;
      setPriceListNotice(message);
      toast.error(message);
      return null;
    }
  }

  async function changePriceList(nextId: number | null): Promise<void> {
    if (nextId === selectedPriceListId) return;
    const nextList = nextId ? priceLists.find((list) => list.id === nextId) : null;
    if (nextId && !nextList) return;

    setPriceListNotice(null);
    if (cart.length === 0) {
      setSelectedPriceListId(nextId);
      setPayments([]);
      return;
    }

    setRepricing(true);
    try {
      if (!nextList) {
        setCart((current) =>
          current.map((line) => ({
            ...line,
            unit_price: Number(line.base_unit_price ?? line.unit_price),
            currency: (line.base_currency ?? line.currency) as CurrencyCode,
            price_list_id: null,
            price_list_name: BASE_PRICE_LIST_LABEL,
            price_issue: null,
          })),
        );
      } else {
        const quoted = await Promise.all(cart.map(async (line) => ({
          line,
          quote: await quoteProductForPos(line.product_id, nextList.id),
        })));
        setCart((current) =>
          current.map((line) => {
            const found = quoted.find((item) => item.line.id === line.id);
            if (!found) return line;

            return {
              ...line,
              unit_price: found.quote.base_price_usd,
              currency: found.quote.sale_currency as CurrencyCode,
              price_list_id: nextList.id,
              price_list_name: found.quote.price_list_name ?? nextList.name,
              price_issue: null,
            };
          }),
        );
      }
      setSelectedPriceListId(nextId);
      setPayments([]);
      toast.success(`Ticket actualizado a ${nextList?.name ?? BASE_PRICE_LIST_LABEL}. Pagos limpiados.`);
    } catch (error) {
      const message = `No se puede cambiar a ${nextList?.name ?? BASE_PRICE_LIST_LABEL}: hay productos sin precio en esa lista.`;
      setPriceListNotice(message);
      toast.error(message);
    } finally {
      setRepricing(false);
    }
  }

  async function addProduct(product: Product): Promise<boolean> {
    const available = Number(product.available_stock ?? 0);
    if ((product.track_stock ?? true) && available <= 0) {
      toast.error('Producto sin stock disponible.');
      return false;
    }
    if (!selectedWarehouse) {
      toast.error('Selecciona un almacen.');
      return false;
    }
    const quote = selectedPriceList ? await quoteProduct(product, selectedPriceList) : null;
    if (selectedPriceList && !quote) return false;

    const shouldSelectSerials = product.tracking_type === 'serialized';
    let newLineId: string | null = null;
    setCart((current) => {
      const existing = current.find((line) => line.product_id === product.id && line.warehouse_id === selectedWarehouse.id);
      if (existing) {
        newLineId = existing.id;
        return current.map((line) =>
          line.id === existing.id
            ? { ...line, quantity: clampQuantity(line.quantity + 1, line.available_stock) }
            : line,
        );
      }
      newLineId = crypto.randomUUID();
      return [
        ...current,
        {
          id: newLineId,
          product_id: product.id,
          name: product.name,
          sku: product.sku,
          barcode: product.barcode,
          warehouse_id: selectedWarehouse.id,
          quantity: 1,
          available_stock: available,
          unit_price: quote?.base_price_usd ?? Number(product.base_price ?? 0),
          base_unit_price: Number(product.base_price ?? 0),
          currency: (quote?.sale_currency ?? product.sale_currency ?? 'USD') as CurrencyCode,
          base_currency: (product.sale_currency ?? 'USD') as CurrencyCode,
          price_list_id: selectedPriceList?.id ?? null,
          price_list_name: quote?.price_list_name ?? selectedPriceList?.name ?? BASE_PRICE_LIST_LABEL,
          price_issue: null,
          tracking_type: product.tracking_type,
          // `track_stock` por defecto es true en el backend; si el producto
          // es un servicio o concepto facturable, el listado lo trae en
          // false y el POS no exige stock ni genera movimiento (QW10).
          track_stock: product.track_stock !== false,
          selected_serials: [],
        },
      ];
    });
    setQuery('');
    if (shouldSelectSerials) {
      window.setTimeout(() => {
        if (newLineId) setSerialLineId(newLineId);
        setPanel('serials');
      }, 0);
    }
    return true;
  }

  function updateLine(id: string, patch: Partial<PosCartLine>): void {
    setCart((current) =>
      current.map((line) => {
        if (line.id !== id) return line;
        const next = { ...line, ...patch };
        next.quantity = clampQuantity(Number(next.quantity), next.available_stock);
        if (next.tracking_type === 'serialized' && next.selected_serials && next.selected_serials.length > next.quantity) {
          next.selected_serials = next.selected_serials.slice(0, next.quantity);
        }
        return next;
      }),
    );
  }

  function toggleSerial(lineId: string, serial: ProductSerial): void {
    setCart((current) =>
      current.map((line) => {
        if (line.id !== lineId) return line;
        const selected = line.selected_serials ?? [];
        const exists = selected.some((item) => item.id === serial.id);
        if (exists) {
          return { ...line, selected_serials: selected.filter((item) => item.id !== serial.id) };
        }
        const usedInAnotherLine = current.some((item) => item.id !== lineId && item.selected_serials?.some((selectedSerial) => selectedSerial.id === serial.id));
        if (usedInAnotherLine) {
          toast.error('Ese IMEI/serial ya esta seleccionado en otra linea.');
          return line;
        }
        if (selected.length >= line.quantity) {
          toast.error(`Ya seleccionaste ${line.quantity} IMEI/serial para esta linea.`);
          return line;
        }
        return {
          ...line,
          selected_serials: [
            ...selected,
            { id: serial.id, serial_type: serial.serial_type, serial_number: serial.serial_number },
          ],
        };
      }),
    );
  }

  function openMissingSerialPanel(): void {
    const missing = cart.find((line) => line.tracking_type === 'serialized' && (line.selected_serials?.length ?? 0) !== Number(line.quantity));
    if (missing) setSerialLineId(missing.id);
    setPanel('serials');
  }

  async function handleProductSearchEnter(): Promise<void> {
    const term = query.trim();
    if (term.length < 2) return;
    const normalized = term.toLowerCase();
    const exact = products.find((product) =>
      [product.barcode, product.sku].some((value) => value?.toLowerCase() === normalized),
    );
    if (exact) {
      await addProduct(exact);
      return;
    }
    if (products[0]) {
      setProductSearch(term);
      setPanel('product-search');
      return;
    }
    toast.error('No se encontro un producto con ese codigo.');
  }

  function addQuickPayment(paymentMethodId: number): void {
    const configured = allowedPaymentMethods.find((item) => item.id === paymentMethodId);
    if (!configured) return;
    addPaymentLine((configured.method ?? 'other') as PosPaymentMethod, paymentMethodId);
    setPanel(null);
  }

  function addPaymentLine(method: PosPaymentMethod, paymentMethodId?: number): void {
    const configured = paymentMethodId ? allowedPaymentMethods.find((item) => item.id === paymentMethodId) : null;
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
      if (serialIssue) openMissingSerialPanel();
      return;
    }
    if (!activeSession) {
      toast.error('No hay caja abierta.');
      return;
    }
    try {
      const order = await checkout.mutateAsync(buildCheckoutPayload(activeSession.id, 'captured'));
      setLastReceipt(order);
      void createAndDispatchPrintJobs(order, false);
      clearTicket();
      setPanel('receipt');
      toast.success('Venta confirmada.');
    } catch (error) {
      void bootstrap.refetch();
      toast.error(error instanceof Error ? error.message : 'No se pudo completar el cobro.');
    }
  }

  async function confirmCreditSale(): Promise<void> {
    if (!activeSession) {
      toast.error('No hay caja abierta.');
      return;
    }
    if (!canCheckout || !canCollectReceivables) {
      toast.error('Necesitas permisos de POS y CxC para vender a credito.');
      return;
    }
    if (!selectedCustomer) {
      toast.error('La venta a credito requiere un cliente registrado.');
      setPanel('customer');
      return;
    }
    if (cart.length === 0 || hasStockIssue(cart) || hasPriceIssue(cart) || serialIssue) {
      if (serialIssue) toast.error(serialIssue);
      else if (priceIssue) toast.error(priceIssue);
      else toast.error('Revisa productos y stock antes de enviar a CxC.');
      if (serialIssue) openMissingSerialPanel();
      return;
    }
    if (paymentSetupIssue || priceListPaymentIssue) {
      toast.error(paymentSetupIssue ?? priceListPaymentIssue ?? 'Revisa la configuracion de cobro.');
      return;
    }
    try {
      const order = await checkout.mutateAsync({
        ...buildCheckoutPayload(activeSession.id, 'captured'),
        credit: true,
        credit_due_date: creditDueDate || null,
      });
      setLastReceipt(order);
      void createAndDispatchPrintJobs(order, false);
      clearTicket();
      setCreditDueDate('');
      setPanel('receipt');
      toast.success('Venta enviada a cuentas por cobrar.');
    } catch (error) {
      void bootstrap.refetch();
      toast.error(error instanceof Error ? error.message : 'No se pudo enviar la venta a CxC.');
    }
  }

  async function createQuickCustomer(): Promise<void> {
    const name = quickCustomer.name.trim();
    const documentNumber = quickCustomer.document_number.trim();
    if (!canCreateCustomer) {
      toast.error('No tienes permiso para crear clientes.');
      return;
    }
    if (!name || !documentNumber) {
      toast.error('Nombre y documento son obligatorios.');
      return;
    }

    try {
      const customer = await createCustomer.mutateAsync({
        ...quickCustomer,
        name,
        document_number: documentNumber,
        phone: quickCustomer.phone?.trim() || null,
        email: quickCustomer.email?.trim() || null,
        fiscal_address: quickCustomer.fiscal_address?.trim() || null,
        is_active: true,
        is_generic: false,
      });
      setSelectedCustomer(customer);
      setCustomerName(customer.name);
      setCustomerSearch(customer.name);
      setQuickCustomer({
        name: '',
        document_type: 'V',
        document_number: '',
        phone: '',
        email: '',
        fiscal_address: '',
      });
      setPanel(null);
      toast.success('Cliente creado y asignado al ticket.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear el cliente.');
    }
  }

  async function holdSale(): Promise<void> {
    if (!activeSession || cart.length === 0) return;
    if (priceListPaymentIssue) {
      toast.error(priceListPaymentIssue);
      return;
    }
    if (priceIssue) {
      toast.error(priceIssue);
      return;
    }
    if (serialIssue) {
      toast.error(serialIssue);
      openMissingSerialPanel();
      return;
    }
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
      void bootstrap.refetch();
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
    void createAndDispatchPrintJobs(paid, false);
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
        price_list_id: line.price_list_id ?? selectedPriceList?.id ?? null,
        quantity: line.quantity,
        discount_type: line.discount_type ?? null,
        discount_value: line.discount_value ?? null,
        discount_reason: line.discount_reason ?? null,
        product_unit_ids: line.tracking_type === 'serialized' ? (line.selected_serials ?? []).map((serial) => serial.id) : [],
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
    setPriceListNotice(null);
    setSelectedCustomer(null);
    setCustomerName('Consumidor Final');
    setSelectedPending(null);
  }

  async function createAndDispatchPrintJobs(order: PosOrder, copy: boolean, output?: 'thermal' | 'digital' | 'both'): Promise<void> {
    if (!canPrint && !canDigital && !copy) return;
    if (copy && !canReprint) {
      toast.error('No tienes permiso para reimprimir tickets.');
      return;
    }

    const requestedOutput = output ?? activePrinterStation?.output_mode ?? (canDigital ? 'digital' : 'thermal');
    if ((requestedOutput === 'digital' || requestedOutput === 'both') && !canDigital) {
      toast.error('No tienes permiso para generar tickets digitales.');
      return;
    }

    try {
      const jobs = await createPrintJob.mutateAsync({
        orderId: order.id,
        output: requestedOutput,
        copy,
        printerStationId: activePrinterStation?.id ?? null,
      });
      setLastPrintJobs(jobs);

      await Promise.all(jobs.map(async (job) => {
        try {
          await updatePrintJobStatus.mutateAsync({ jobId: job.id, status: 'sent' });
          const result = await sendJobToLocalAgent(job);
          const finalStatus = job.output === 'digital' ? 'generated' : 'printed';
          await updatePrintJobStatus.mutateAsync({
            jobId: job.id,
            status: finalStatus,
            message: result.message ?? null,
            digitalPdfPath: result.pdf_path ?? null,
            digitalHtmlPath: result.html_path ?? null,
          });
          if (job.output === 'digital' && result.pdf_path) toast.success(`PDF generado: ${result.pdf_path}`);
          if (job.output === 'thermal') toast.success('Ticket enviado a impresora.');
        } catch (error) {
          await updatePrintJobStatus.mutateAsync({
            jobId: job.id,
            status: 'failed',
            message: error instanceof Error ? error.message : 'No se pudo imprimir.',
          });
          if (job.output === 'digital') {
            window.open(ticketPdfUrl(job), '_blank');
            toast.warning('Agente no disponible. Abrimos el PDF en el navegador.');
            return;
          }
          toast.error('Agente local no disponible. Puedes reintentar desde F9.');
        }
      }));
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear el ticket de impresion.');
    }
  }
}

function CartLineRow({
  line,
  onChange,
  onSerials,
  onRemove,
}: {
  line: PosCartLine;
  onChange: (patch: Partial<PosCartLine>) => void;
  onSerials: () => void;
  onRemove: () => void;
}) {
  const stockIssue = line.quantity > line.available_stock;
  const serialCount = line.selected_serials?.length ?? 0;
  const serialIssue = line.tracking_type === 'serialized' && serialCount !== Number(line.quantity);
  return (
    <div className={cn('grid gap-3 p-3 xl:grid-cols-[minmax(220px,1fr)_440px_120px_40px] xl:items-center', (stockIssue || serialIssue) && 'bg-warning/10')}>
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
          {line.price_list_name && <Badge variant="default" className="text-[10px]">{line.price_list_name}</Badge>}
          {line.tracking_type === 'serialized' && (
            <button type="button" className={cn('font-semibold', serialIssue ? 'text-warning' : 'text-success')} onClick={onSerials}>
              IMEI {serialCount}/{line.quantity}
            </button>
          )}
        </div>
        {line.tracking_type === 'serialized' && serialCount > 0 && (
          <div className="flex flex-wrap gap-1">
            {line.selected_serials?.map((serial) => (
              <Badge key={serial.id} variant="default" className="font-mono text-[10px]">{serial.serial_number}</Badge>
            ))}
          </div>
        )}
        {line.price_issue && (
          <p className="rounded border border-warning bg-warning/10 px-2 py-1 text-xs text-warning">{line.price_issue}</p>
        )}
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
        {line.tracking_type === 'serialized' && (
          <Button className="sm:col-span-2" variant={serialIssue ? 'secondary' : 'outline'} size="sm" onClick={onSerials}>
            Seleccionar IMEI/serial
          </Button>
        )}
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
  baseAmount: string;
  localAmount: string;
  rateLabel: string | null;
  busy: boolean;
  onBranchChange: (id: number | '') => void;
  onRegisterChange: (id: number | '') => void;
  onBaseAmountChange: (amount: string) => void;
  onLocalAmountChange: (amount: string) => void;
  onOpen: () => void;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-bg p-4">
      <div className="w-full max-w-lg rounded border border-border bg-surface p-6 shadow-sm">
        <h1 className="text-xl font-semibold">Abrir turno POS</h1>
        <p className="mt-1 text-sm text-text-muted">Para vender necesitas abrir tu turno en una caja fisica activa.</p>
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
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs font-medium uppercase text-text-muted">Fondo USD</label>
                <Input type="number" min="0" value={props.baseAmount} onChange={(event) => props.onBaseAmountChange(event.target.value)} placeholder="0.00" />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium uppercase text-text-muted">Fondo VES</label>
                <Input type="number" min="0" value={props.localAmount} onChange={(event) => props.onLocalAmountChange(event.target.value)} placeholder="0.00" />
              </div>
            </div>
            <p className="text-xs text-text-muted">
              {props.rateLabel ? `VES se convierte con ${props.rateLabel}.` : 'Sin tasa activa USD/VES para convertir fondo VES.'}
            </p>
            <Button
              className="w-full"
              onClick={props.onOpen}
              disabled={props.busy || !props.branchId || !props.registerId || props.cashRegisters.length === 0}
            >
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

function SerialSelectionPanel({
  line,
  serials,
  loading,
  onToggle,
}: {
  line: PosCartLine;
  serials: ProductSerial[];
  loading: boolean;
  onToggle: (serial: ProductSerial) => void;
}) {
  const selectedIds = new Set((line.selected_serials ?? []).map((serial) => serial.id));
  const complete = selectedIds.size === Number(line.quantity);

  return (
    <div className="space-y-4">
      <div className="rounded border border-border bg-bg/40 p-3">
        <p className="text-lg font-semibold">{line.name}</p>
        <p className="text-sm text-text-muted">
          Selecciona {line.quantity} IMEI/serial disponible para confirmar esta venta.
        </p>
        <Badge className="mt-3" variant={complete ? 'success' : 'warning'}>
          {selectedIds.size}/{line.quantity} seleccionados
        </Badge>
      </div>

      {loading ? (
        <div className="flex items-center gap-2 rounded border border-border p-3 text-sm text-text-muted">
          <Loader2 className="size-4 animate-spin" /> Buscando IMEIs disponibles...
        </div>
      ) : serials.length === 0 ? (
        <div className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">
          No hay IMEIs disponibles para este producto en el almacen seleccionado.
        </div>
      ) : (
        <div className="max-h-[70vh] divide-y divide-border overflow-auto rounded border border-border">
          {serials.map((serial) => {
            const checked = selectedIds.has(serial.id);
            const disabled = !checked && selectedIds.size >= Number(line.quantity);
            return (
              <button
                key={serial.id}
                type="button"
                disabled={disabled}
                onClick={() => onToggle(serial)}
                className={cn(
                  'flex w-full items-center justify-between gap-3 p-3 text-left transition-colors hover:bg-bg disabled:cursor-not-allowed disabled:opacity-50',
                  checked && 'bg-primary/10',
                )}
              >
                <div>
                  <p className="font-mono font-semibold">{serial.serial_number}</p>
                  <p className="text-xs text-text-muted">{serial.serial_type?.toUpperCase() ?? 'SERIAL'} - {serial.warehouse_name ?? 'Almacen'}</p>
                </div>
                <Badge variant={checked ? 'success' : 'default'}>{checked ? 'Seleccionado' : serial.status}</Badge>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function QuickPaymentPanel({
  methods,
  cartTotal,
  payments,
  currentRates,
  rateTypes,
  priceListName,
  issue,
  onSelect,
}: {
  methods: Array<{ id: number; name: string; method?: string | null; currency_mode?: 'USD' | 'VES' | 'flexible'; requires_reference?: boolean; sort_order?: number }>;
  cartTotal: number;
  payments: PosPaymentLine[];
  currentRates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>;
  rateTypes: Array<{ id: number; is_default?: boolean; is_active?: boolean; code: string }>;
  priceListName: string;
  issue: string | null;
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
      <p className="rounded border border-border bg-bg/40 px-3 py-2 text-xs text-text-muted">
        Metodos permitidos para {priceListName}.
      </p>

      {methods.length === 0 ? (
        <div className="rounded border border-warning bg-warning/10 p-4 text-sm text-warning">
          {issue ?? 'No hay metodos activos para esta lista de precio.'}
          <Button asChild className="mt-3" variant="outline">
            <Link to="/inventory/admin">Configurar lista</Link>
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

function ProductSearchPanel({
  search,
  products,
  warehouses,
  warehouseId,
  priceListName,
  loading,
  onSearch,
  onWarehouseChange,
  onSelect,
}: {
  search: string;
  products: Product[];
  warehouses: Array<{ id: number; code: string; name: string }>;
  warehouseId: number | null;
  priceListName: string | null;
  loading: boolean;
  onSearch: (value: string) => void;
  onWarehouseChange: (value: number | null) => void;
  onSelect: (product: Product) => void | Promise<void>;
}) {
  const canSearch = search.trim().length >= 2;

  return (
    <div className="space-y-4">
      <div className="grid gap-2 md:grid-cols-[minmax(260px,1fr)_220px]">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
          <Input
            autoFocus
            value={search}
            onChange={(event) => onSearch(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && products[0]) {
                event.preventDefault();
                void onSelect(products[0]);
              }
            }}
            className="h-11 pl-9 text-base"
            placeholder="Nombre, SKU o codigo de barras"
          />
        </div>
        <Select value={warehouseId ?? ''} onChange={(event) => onWarehouseChange(event.target.value ? Number(event.target.value) : null)}>
          {warehouses.map((warehouse) => (
            <option key={warehouse.id} value={warehouse.id}>
              {warehouse.code} - {warehouse.name}
            </option>
          ))}
        </Select>
      </div>
      {priceListName && (
        <p className="rounded border border-border bg-bg/40 px-3 py-2 text-xs text-text-muted">
          Los productos se cotizan al agregarlos con la lista {priceListName}.
        </p>
      )}

      {!canSearch ? (
        <div className="rounded border border-border bg-bg/40 p-6 text-center text-sm text-text-muted">
          Escribe al menos 2 caracteres o escanea un codigo para buscar.
        </div>
      ) : loading ? (
        <div className="flex items-center gap-2 rounded border border-border bg-bg/40 p-4 text-sm text-text-muted">
          <Loader2 className="size-4 animate-spin" /> Buscando productos
        </div>
      ) : products.length === 0 ? (
        <div className="rounded border border-border bg-bg/40 p-6 text-center text-sm text-text-muted">
          No hay productos con esa busqueda.
        </div>
      ) : (
        <div className="grid max-h-[60vh] gap-2 overflow-auto pr-1 md:grid-cols-2">
          {products.map((product) => (
            <button
              key={product.id}
              type="button"
              onClick={() => void onSelect(product)}
              className="rounded border border-border bg-bg/40 p-3 text-left transition-colors hover:border-primary"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate font-semibold">{product.name}</p>
                  <p className="font-mono text-xs text-text-muted">{product.sku ?? product.barcode ?? 'Sin codigo'}</p>
                </div>
                <Badge variant={Number(product.available_stock ?? 0) > 0 ? 'success' : 'warning'} className="text-[10px]">
                  {Number(product.available_stock ?? 0) > 0
                    ? `Stock ${Number(product.available_stock)}`
                    : 'Sin stock'}
                </Badge>
                {Number(product.available_stock ?? 0) <= Number(product.min_stock ?? 0) && Number(product.min_stock ?? 0) > 0 && (
                  <p className="mt-1 text-[10px] text-warning">Stock bajo (min {product.min_stock})</p>
                )}
              </div>
              <p className="mt-3 text-xl font-bold">{money(Number(product.base_price ?? 0))}</p>
              <p className="mt-1 text-xs text-text-muted">Se valida precio de lista al seleccionar</p>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function CustomerAssignmentBanner({
  customer,
  customerName,
  onChange,
  onClear,
}: {
  customer: Customer | null;
  customerName: string;
  onChange: () => void;
  onClear: () => void;
}) {
  const document = customerDocument(customer);

  if (!customer) {
    return (
      <div className="border-b border-border bg-bg/30 px-3 py-2">
        <button
          type="button"
          onClick={onChange}
          className="flex w-full items-center justify-between gap-3 rounded border border-dashed border-border px-3 py-2 text-left transition-colors hover:border-primary"
        >
          <span className="min-w-0">
            <span className="block text-xs font-semibold uppercase text-text-muted">Cliente</span>
            <span className="block truncate text-sm font-medium">{customerName}</span>
          </span>
          <Badge variant="default">F4</Badge>
        </button>
      </div>
    );
  }

  return (
    <div className="border-b border-primary/20 bg-primary/5 px-3 py-2">
      <div className="flex items-center justify-between gap-3 rounded border border-primary/30 bg-surface px-3 py-2">
        <div className="flex min-w-0 items-center gap-2">
          <UserRound className="size-5 shrink-0 text-primary" />
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <Badge variant="info">Cliente asignado</Badge>
              {document && <span className="truncate text-xs text-text-muted">{document}</span>}
            </div>
            <p className="mt-1 truncate text-sm font-semibold">{customer.name}</p>
          </div>
        </div>
        <div className="flex shrink-0 gap-1">
          <Button variant="outline" size="sm" onClick={onChange}>Cambiar</Button>
          <Button variant="ghost" size="icon-sm" onClick={onClear} aria-label="Quitar cliente"><X className="size-4" /></Button>
        </div>
      </div>
    </div>
  );
}

function CustomerPanel(props: {
  search: string;
  customerName: string;
  customers: Customer[];
  form: QuickCustomerForm;
  canCreate: boolean;
  creating: boolean;
  onSearch: (value: string) => void;
  onName: (value: string) => void;
  onFormChange: (patch: Partial<QuickCustomerForm>) => void;
  onCreate: () => void;
  onGeneric: () => void;
  onSelect: (customer: Customer) => void;
}) {
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
      <div className="space-y-3">
        <div className="rounded border border-border bg-bg/40 p-3">
          <p className="text-sm font-semibold">Asignar cliente</p>
          <p className="mt-1 text-xs text-text-muted">Busca uno existente o usa consumidor final para venta rapida.</p>
          <Button className="mt-3 w-full" variant="outline" onClick={props.onGeneric}>Consumidor Final</Button>
        </div>
        <Input value={props.customerName} onChange={(event) => props.onName(event.target.value)} placeholder="Nombre manual para ticket" />
        <Input value={props.search} onChange={(event) => props.onSearch(event.target.value)} placeholder="Buscar cliente por nombre o documento" />
        <div className="max-h-72 space-y-2 overflow-auto pr-1">
          {props.search.trim().length > 0 && props.search.trim().length < 2 && (
            <p className="rounded border border-border bg-bg/40 p-3 text-sm text-text-muted">Escribe al menos 2 caracteres para buscar.</p>
          )}
          {props.search.trim().length >= 2 && props.customers.length === 0 && (
            <p className="rounded border border-border bg-bg/40 p-3 text-sm text-text-muted">No hay clientes con esa busqueda.</p>
          )}
          {props.customers.map((customer) => (
            <button key={customer.id} type="button" onClick={() => props.onSelect(customer)} className="w-full rounded border border-border p-3 text-left hover:border-primary">
              <p className="font-medium">{customer.name}</p>
              <p className="text-xs text-text-muted">{customerDocument(customer) ?? customer.email ?? customer.phone ?? 'Cliente'}</p>
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-3 rounded border border-border bg-bg/40 p-3">
        <div>
          <p className="text-sm font-semibold">Crear cliente rapido</p>
          <p className="mt-1 text-xs text-text-muted">Usa los mismos datos base del modulo Clientes.</p>
        </div>
        {!props.canCreate ? (
          <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">No tienes permiso para crear clientes.</p>
        ) : (
          <>
            <Input value={props.form.name} onChange={(event) => props.onFormChange({ name: event.target.value })} placeholder="Nombre o razon social" />
            <div className="grid grid-cols-[110px_1fr] gap-2">
              <Select value={props.form.document_type} onChange={(event) => props.onFormChange({ document_type: event.target.value as QuickCustomerForm['document_type'] })}>
                <option value="V">V</option>
                <option value="E">E</option>
                <option value="J">J</option>
                <option value="G">G</option>
                <option value="P">P</option>
              </Select>
              <Input value={props.form.document_number} onChange={(event) => props.onFormChange({ document_number: event.target.value })} placeholder="Documento" />
            </div>
            <div className="grid grid-cols-2 gap-2">
              <Input value={props.form.phone ?? ''} onChange={(event) => props.onFormChange({ phone: event.target.value })} placeholder="Telefono" />
              <Input type="email" value={props.form.email ?? ''} onChange={(event) => props.onFormChange({ email: event.target.value })} placeholder="Email" />
            </div>
            <Textarea value={props.form.fiscal_address ?? ''} onChange={(event) => props.onFormChange({ fiscal_address: event.target.value })} rows={2} placeholder="Direccion fiscal" />
            <Button className="w-full" onClick={props.onCreate} loading={props.creating}>Crear y asignar</Button>
          </>
        )}
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

function ReceiptPanel({
  order,
  jobs,
  history,
  onSelectHistory,
  canPrint,
  canReprint,
  canDigital,
  busy,
  onPrint,
  onOpenPdf,
}: {
  order: PosOrder | null;
  jobs: PrintJob[];
  history: PosOrder[];
  onSelectHistory: (order: PosOrder) => void;
  canPrint: boolean;
  canReprint: boolean;
  canDigital: boolean;
  busy: boolean;
  onPrint: (copy: boolean, output?: 'thermal' | 'digital' | 'both') => void;
  onOpenPdf: (job: PrintJob) => void;
}) {
  if (!order) return <p className="text-sm text-text-muted">Aun no hay recibo en esta sesion.</p>;
  const digitalJob = jobs.find((job) => job.output === 'digital');
  return (
    <div className="space-y-3">
      <div className="rounded border border-border p-3">
        <p className="font-semibold">Orden POS #{order.id}</p>
        <p className="text-sm text-text-muted">{order.customer_name ?? 'Consumidor Final'}</p>
      </div>
      <AmountRow label="Total" value={order.total_base_amount ?? 0} />
      <AmountRow label="Pagado" value={order.paid_base_amount ?? 0} />
      <Badge variant={order.status === 'paid' ? 'success' : 'info'}>{order.status}</Badge>
      {jobs.length > 0 && (
        <div className="space-y-2 rounded border border-border p-3">
          <p className="text-sm font-semibold">Impresion</p>
          {jobs.map((job) => (
            <div key={job.id} className="flex items-center justify-between gap-2 text-sm">
              <span>{job.output === 'digital' ? 'Digital' : 'Termica'} #{job.id}</span>
              <Badge variant={job.status === 'failed' ? 'danger' : job.status === 'printed' || job.status === 'generated' ? 'success' : 'info'}>
                {job.status}
              </Badge>
            </div>
          ))}
        </div>
      )}
      <div className="grid gap-2">
        {canPrint && (
          <Button disabled={busy} onClick={() => onPrint(false)}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />}
            Imprimir
          </Button>
        )}
        {canDigital && (
          <Button variant="outline" disabled={busy} onClick={() => (digitalJob ? onOpenPdf(digitalJob) : onPrint(false, 'digital'))}>
            <Receipt className="size-4" /> PDF digital
          </Button>
        )}
        {canReprint && (
          <Button variant="outline" disabled={busy} onClick={() => onPrint(true)}>
            <RotateCcw className="size-4" /> Copia
          </Button>
        )}
      </div>
      {history.length > 1 && (
        <div className="space-y-1 rounded border border-border p-2">
          <p className="px-1 text-xs font-semibold uppercase text-text-muted">Recibos recientes</p>
          {history
            .filter((item) => item.id !== order.id)
            .slice(0, 5)
            .map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onSelectHistory(item)}
                className="flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-left text-sm hover:bg-bg/40"
                data-testid={`history-receipt-${item.id}`}
              >
                <span className="font-mono">#{item.id}</span>
                <span className="truncate text-text-muted">{item.customer_name ?? 'Consumidor Final'}</span>
                <span className="font-semibold">${(item.total_base_amount ?? 0).toFixed(2)}</span>
              </button>
            ))}
        </div>
      )}
    </div>
  );
}

function CreditPanel(props: {
  customer: Customer | null;
  total: number;
  paid: number;
  dueDate: string;
  canCredit: boolean;
  busy: boolean;
  onDueDate: (value: string) => void;
  onCustomer: () => void;
  onConfirm: () => void;
}) {
  const balance = Math.max(0, roundMoney(props.total - props.paid));

  return (
    <div className="space-y-4">
      <div className="rounded border border-border bg-bg/50 p-4">
        <p className="text-sm text-text-muted">Saldo que ira a CxC</p>
        <p className="mt-1 text-4xl font-bold">{money(balance)}</p>
        <p className="mt-2 text-sm text-text-muted">
          Lo pagado ahora entra a caja; el saldo queda pendiente para cobranza.
        </p>
      </div>
      {!props.customer ? (
        <div className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">
          La venta a credito requiere un cliente registrado.
          <Button className="mt-3 w-full" variant="outline" onClick={props.onCustomer}>Asignar cliente</Button>
        </div>
      ) : (
        <div className="rounded border border-border bg-bg/40 p-3">
          <p className="text-xs font-semibold uppercase text-text-muted">Cliente</p>
          <p className="mt-1 font-semibold">{props.customer.name}</p>
          <p className="text-sm text-text-muted">{customerDocument(props.customer) ?? 'Sin documento'}</p>
        </div>
      )}
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="credit-due-date">Vencimiento opcional</label>
        <Input
          id="credit-due-date"
          type="date"
          value={props.dueDate}
          onChange={(event) => props.onDueDate(event.target.value)}
        />
      </div>
      <Button
        className="w-full"
        disabled={!props.canCredit || !props.customer || balance <= 0 || props.busy}
        onClick={props.onConfirm}
      >
        {props.busy && <Loader2 className="size-4 animate-spin" />}
        Confirmar venta a credito
      </Button>
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
    case 'product-search':
      return 'Buscar producto';
    case 'credit':
      return 'Enviar a cuentas por cobrar';
    case 'serials':
      return 'IMEI / seriales';
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
  hasPriceIssue: boolean;
  priceIssue: string | null;
  serialIssue: string | null;
  paymentSetupIssue: string | null;
  priceListPaymentIssue: string | null;
}): string | null {
  if (!input.canCheckout) return 'No tienes permiso pos.checkout para cobrar ventas.';
  if (!input.hasSession) return 'No hay caja abierta para cobrar.';
  if (input.cartCount === 0) return 'Agrega al menos un producto para cobrar.';
  if (input.priceListPaymentIssue) return input.priceListPaymentIssue;
  if (input.hasPriceIssue) return input.priceIssue ?? 'Hay productos sin precio para la lista seleccionada.';
  if (input.hasStockIssue) return 'Hay productos con stock insuficiente. La venta esta bloqueada.';
  if (input.serialIssue) return input.serialIssue;
  if (input.paymentCount === 0) return 'Agrega al menos una linea de pago.';
  if (input.paymentSetupIssue) return input.paymentSetupIssue;
  if (input.remaining > 0) return `Falta capturar ${money(input.remaining)} para completar el pago.`;

  return null;
}

function filterPaymentMethodsForPriceList<T extends { id: number; is_active?: boolean }>(
  methods: T[],
  priceList: PriceList | null,
): T[] {
  if (!priceList) return methods.filter((method) => method.is_active !== false);

  const allowed = new Set(priceList?.payment_method_ids ?? []);
  if (allowed.size === 0) return [];

  return methods.filter((method) => method.is_active !== false && allowed.has(method.id));
}

function getPriceListPaymentIssue(
  priceList: PriceList | null,
  allowedPaymentMethods: Array<{ id: number }>,
): string | null {
  if (!priceList) return null;
  const configuredIds = priceList.payment_method_ids ?? [];
  if (configuredIds.length === 0) {
    return `La lista ${priceList.name} no tiene metodos de pago configurados para POS.`;
  }
  if (allowedPaymentMethods.length === 0) {
    return `Los metodos asignados a ${priceList.name} no estan activos.`;
  }

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

function customerDocument(customer: Customer | null): string | null {
  if (!customer) return null;
  if (customer.document_type && customer.document_number) return `${customer.document_type}-${customer.document_number}`;
  return customer.tax_id ?? null;
}

function money(value: number): string {
  return `$${roundMoney(Number(value || 0)).toFixed(2)}`;
}

function formatLocalNumber(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'No se pudo completar la accion.';
}
