import { useEffect, useMemo, useRef, useState } from 'react';
import { usePosCartStore, usePosCartPersistence, type Panel } from './cartStore';
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
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/Sheet';
import { Textarea } from '@/components/ui/Textarea';
import { PERMISSIONS } from '@/permissions/constants';
import { usePermissionContext } from '@/permissions/PermissionContext';
import { cn } from '@/lib/cn';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { ProductImage as ProductImageView } from '@/features/inventory-center/components/ProductImage';
import {
  type CashRegisterSession,
  type CheckoutPayload,
  type CreateCustomerPayload,
  type Customer,
  type PosOrder,
  type PosPaymentMethod,
  type PaymentMethod,
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
  mergePosExchangeRates,
  mergePosExchangeRateTypes,
  mergePosPriceLists,
  resolvePosOpenSession,
  useCashSessions,
  useCurrentExchangeRatesForPos,
  useExchangeRateTypesForPos,
  usePriceListsForPos,
  useOpenCashSession,
  useOpenPosOrders,
  useBootstrapRefsForPos,
  usePaymentMethods,
  usePosBootstrap,
  usePosProductsDebounced,
  useSessionOrders,
  useWarehousesForPos,
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
  openTicketPdf,
  sendJobToLocalAgent,
  useCreatePosPrintJob,
  usePrinterStations,
  useUpdatePrintJobStatus,
} from '@/features/printing/api';

type QuickCustomerForm = Omit<CreateCustomerPayload, 'is_active' | 'is_generic'>;

const PAYMENT_METHODS: { value: PosPaymentMethod; label: string }[] = [
  { value: 'cash', label: 'Efectivo' },
  { value: 'card', label: 'Tarjeta' },
  { value: 'mobile_payment', label: 'Pago movil' },
  { value: 'transfer', label: 'Transferencia' },
  { value: 'zelle', label: 'Zelle' },
  { value: 'external_financing', label: 'Financiamiento' },
  { value: 'other', label: 'Otro' },
];

const BASE_PRICE_LIST_LABEL = 'Precio base';

/**
 * Helpers de filtro de atajos de teclado.
 *
 * El POS tiene atajos globales (F2, F3, F4, F6, F7, F9, Escape, Delete)
 * que se capturan en window. Si el usuario esta editando un campo de
 * texto (input, textarea, contentEditable), esos atajos NO deben dispararse
 * porque harian cosas inesperadas (ej: Delete borra la ultima linea del
 * carrito mientras el usuario queria borrar un caracter).
 *
 * Escape SI se permite dentro de un form (cierra modales), pero los
 * atajos de cambio de panel (F2..F9) NO.
 */
function isEditableField(target: HTMLElement | null): boolean {
  if (!target) return false;
  const tag = target.tagName;
  if (tag === 'INPUT') {
    const type = (target as HTMLInputElement).type?.toLowerCase() ?? 'text';
    // Los inputs de tipo button/submit/checkbox/radio NO son editables.
    // Select es solo lectura. Textarea es editable.
    return ['text', 'search', 'email', 'tel', 'url', 'password', 'number', 'search'].includes(type);
  }
  if (tag === 'TEXTAREA') return true;
  if (target.isContentEditable) return true;
  return false;
}

function isInForm(target: HTMLElement | null): boolean {
  if (!target) return false;
  return target.closest('form, [role="dialog"], [data-panel]') !== null;
}

export function shouldHandlePosGlobalShortcut(key: string, isEditableField: boolean): boolean {
  if (['F2', 'F3', 'F4', 'F6', 'F7', 'F9'].includes(key)) return true;
  if (key === 'Delete') return !isEditableField;

  return false;
}

export function shouldTriggerPosCheckoutOnEnter(input: {
  panel: Panel;
  isEditableField: boolean;
  isSearchInput: boolean;
}): boolean {
  if (input.isEditableField || input.isSearchInput) return false;
  return input.panel === null || input.panel === 'pay';
}

export function shouldTriggerPosCheckoutShortcut(
  key: string,
  input: { panel: Panel; isEditableField: boolean; isSearchInput: boolean },
): boolean {
  if (!['Enter', 'F10'].includes(key)) return false;
  return shouldTriggerPosCheckoutOnEnter(input);
}

export function PosTerminal() {
  const { permissions } = usePermissionContext();
  const canView = permissions.has(PERMISSIONS.POS_VIEW);
  const canCheckout = permissions.has(PERMISSIONS.POS_CHECKOUT);
  const canCollectReceivables = permissions.has(PERMISSIONS.ACCOUNTS_RECEIVABLE_COLLECT);
  const canCancel = permissions.has(PERMISSIONS.POS_CANCEL);
  const canOpenCash = permissions.has(PERMISSIONS.CASH_REGISTER_OPEN);
  const canMoveCash =
    permissions.has(PERMISSIONS.CASH_REGISTER_MOVE) ||
    permissions.has(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canCloseCash = permissions.has(PERMISSIONS.CASH_REGISTER_CLOSE);
  const canCreateCustomer = permissions.has(PERMISSIONS.CUSTOMERS_CREATE);
  const canPrint = permissions.has(PERMISSIONS.PRINTING_PRINT);
  const canReprint = permissions.has(PERMISSIONS.PRINTING_REPRINT);
  const canDigital = permissions.has(PERMISSIONS.PRINTING_DIGITAL);

  const searchRef = useRef<HTMLInputElement | null>(null);
  const holdSaleRef = useRef<(() => Promise<void>) | null>(null);
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
  const setCart = (updater: PosCartLine[] | ((current: PosCartLine[]) => PosCartLine[])): void => {
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

  // Fallback: si /api/pos/bootstrap no devolvio warehouses (cache vacio o
  // query fallo), consultamos /api/warehouses directo. Esto evita que el
  // selector quede vacio y los queries de productos fallen con warehouseId=null.
  // Sprint POS 5 fix: antes si bootstrap fallaba, warehouses quedaba vacio.
  const bootstrapHasWarehouses = (bootstrapRefs.refs?.warehouses?.length ?? 0) > 0;
  const { data: standaloneWarehouses } = useWarehousesForPos();
  const standaloneWarehousesList = useMemo(
    () => standaloneWarehouses ?? [],
    [standaloneWarehouses],
  );

  const warehouses: { id: number; code: string; name: string; branch_id: number | null }[] =
    useMemo(() => {
      if (bootstrapHasWarehouses) {
        return bootstrapRefs.refs?.warehouses ?? [];
      }
      return standaloneWarehousesList as {
        id: number;
        code: string;
        name: string;
        branch_id: number | null;
      }[];
    }, [bootstrapHasWarehouses, bootstrapRefs.refs?.warehouses, standaloneWarehousesList]);
  const branches = useMemo(() => bootstrapRefs.refs?.branches ?? [], [bootstrapRefs.refs]);
  const cashRegisters = useMemo(
    () => bootstrapRefs.refs?.cash_registers ?? [],
    [bootstrapRefs.refs],
  );
  const { data: fallbackSessions = [] } = useCashSessions();
  const sessions = useMemo(
    () => {
      const session = resolvePosOpenSession(bootstrap.data?.open_session ?? null, fallbackSessions);
      return session ? [session] : [];
    },
    [bootstrap.data?.open_session, fallbackSessions],
  );
  const { data: pendingOrders = [] } = useOpenPosOrders();
  const { data: customerResults = [] } = useCustomers(customerSearch);
  const activeProductSearch = panel === 'product-search' ? productSearch : query;
  const shouldSearchProducts = activeProductSearch.trim().length >= 2;
  const { data: productPage, isLoading: loadingProducts } = usePosProductsDebounced(
    activeProductSearch,
    warehouseId,
    {
      enabled: shouldSearchProducts,
    },
  );
  const configuredPaymentMethods = useMemo(
    () => bootstrap.data?.payment_methods ?? [],
    [bootstrap.data],
  );
  const { data: fallbackPaymentMethods = [] } = usePaymentMethods();
  const { data: fallbackPriceLists = [] } = usePriceListsForPos();
  const { data: fallbackExchangeRateTypes = [] } = useExchangeRateTypesForPos();
  const { data: fallbackCurrentRates = [] } = useCurrentExchangeRatesForPos();
  const priceLists = useMemo(
    () => mergePosPriceLists(bootstrap.data?.price_lists ?? [], fallbackPriceLists),
    [bootstrap.data?.price_lists, fallbackPriceLists],
  );
  const exchangeRateTypes = useMemo(
    () =>
      mergePosExchangeRateTypes(
        bootstrap.data?.exchange_rate_types ?? [],
        fallbackExchangeRateTypes,
      ),
    [bootstrap.data?.exchange_rate_types, fallbackExchangeRateTypes],
  );
  const currentRates = useMemo(
    () => mergePosExchangeRates(bootstrap.data?.exchange_rates ?? [], fallbackCurrentRates),
    [bootstrap.data?.exchange_rates, fallbackCurrentRates],
  );
  const activeRate = useMemo(
    () => bestActiveRate(currentRates, exchangeRateTypes),
    [currentRates, exchangeRateTypes],
  );
  const { data: printerStations = [] } = usePrinterStations({
    enabled: canPrint || canDigital || canReprint,
  });
  const activePaymentMethods = useMemo(
    () => resolvePaymentMethods(configuredPaymentMethods, fallbackPaymentMethods),
    [configuredPaymentMethods, fallbackPaymentMethods],
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
    () =>
      cashRegisters.filter((register) => (register as { status?: string }).status !== 'inactive'),
    [cashRegisters],
  );
  const activeSession = useMemo(
    () => sessions.find((session) => session.status === 'open') ?? null,
    [sessions],
  );

  // Persistencia del carrito: hidrata desde sessionStorage al montar y
  // sincroniza cambios al store de vuelta. Solo persiste cuando hay
  // contenido (lineas o pagos) y se key-a por tenant+cashier para
  // evitar colisiones entre sesiones distintas.
  usePosCartPersistence(
    activeSession ? Number(activeSession.tenant_id) : null,
    activeSession ? Number(activeSession.cashier_id) : null,
  );
  const { data: recentPaidOrders = [] } = useSessionOrders(activeSession?.id ?? null, 'paid', 10);
  const activePrinterStation = useMemo(
    () =>
      printerStations.find(
        (station) =>
          station.is_active &&
          activeSession?.cash_register_id &&
          station.cash_register_id === activeSession.cash_register_id,
      ) ??
      printerStations.find(
        (station) =>
          station.is_active &&
          activeSession?.branch_id &&
          station.branch_id === activeSession.branch_id &&
          !station.cash_register_id,
      ) ??
      printerStations.find((station) => station.is_active) ??
      null,
    [activeSession, printerStations],
  );
  const selectedWarehouse =
    warehouses.find((warehouse) => warehouse.id === warehouseId) ?? warehouses[0] ?? null;
  const serialLine = cart.find((line) => line.id === serialLineId) ?? null;
  const { data: availableSerials = [], isLoading: loadingSerials } =
    useAvailableProductSerialsForPos(
      serialLine?.product_id ?? null,
      serialLine?.warehouse_id ?? null,
    );
  const products = productPage?.data ?? [];
  const quickSearchResults = useMemo(() => products.slice(0, 4), [products]);
  const [quickSearchIndex, setQuickSearchIndex] = useState(0);
  useEffect(() => {
    setQuickSearchIndex(0);
  }, [query, quickSearchResults.length]);
  const cartTotals = useMemo(() => calculateCartTotals(cart), [cart]);

  // Tasa representativa del carrito: si todos los items cotizados usan la
  // misma tasa (ej. PARALELO anclada al producto), usamos ESA para mostrar
  // el equivalente VES en la sidebar Y para los pagos. Si hay tasas
  // mixtas, caemos a la tasa global del bootstrap.
  const cartRate = useMemo(() => {
    const ratesFromCart = cart
      .map((line) => Number(line.exchange_rate ?? 0))
      .filter((rate) => rate > 0);
    if (ratesFromCart.length === 0) return null;
    const first = ratesFromCart[0];
    const allSame = ratesFromCart.every((rate) => rate === first);
    if (!allSame) return null;
    return {
      code: (cart[0]?.exchange_rate_type_code as string | null) ?? null,
      rate: first,
      exchange_rate_type_id:
        (cart[0]?.exchange_rate_type_id as number | null) ?? null,
    };
  }, [cart]);
  const paymentTotals = useMemo(
    () => calculatePaymentTotals(payments, cartTotals.total),
    [payments, cartTotals.total],
  );
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
  const openingRate = activeRate;
  holdSaleRef.current = holdSale;

  useEffect(() => {
    // Sprint POS 5 fix: validar que warehouses[0]?.id sea un numero positivo
    // antes de setearlo. Antes el codigo era:
    //   if (!warehouseId && warehouses[0]) setWarehouseId(warehouses[0].id);
    // que lanzaba NaN si warehouses[0] era undefined (caso bootstrap fallo).
    const firstId = warehouses[0]?.id;
    if (
      typeof firstId === 'number' &&
      Number.isFinite(firstId) &&
      firstId > 0 &&
      firstId !== warehouseId
    ) {
      setWarehouseId(firstId);
    }
  }, [setWarehouseId, warehouseId, warehouses]);

  useEffect(() => {
    if (branches[0] && openingBranchId === '') setOpeningBranchId(branches[0].id);
  }, [branches, openingBranchId]);

  useEffect(() => {
    if (activeCashRegisters[0] && openingRegisterId === '')
      setOpeningRegisterId(activeCashRegisters[0].id);
  }, [activeCashRegisters, openingRegisterId]);

  useEffect(() => {
    if (panel === null) {
      searchRef.current?.focus();
    }
  }, [cart.length, panel]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      const inEditableField = isEditableField(target);
      const inForm = isInForm(target);
      const isSearchInput = target?.dataset.posSearchInput === 'true';

      if (shouldTriggerPosCheckoutShortcut(event.key, { panel, isEditableField: inEditableField, isSearchInput })) {
        event.preventDefault();
        void confirmPaidSale();
        return;
      }

      if (shouldHandlePosGlobalShortcut(event.key, inEditableField)) {
        event.preventDefault();
        switch (event.key) {
          case 'F2': {
            if (priceListPaymentIssue) {
              toast.error(priceListPaymentIssue);
              return;
            }
            setPanel('pay');
            return;
          }
          case 'F3': {
            setProductSearch(query);
            setPanel('product-search');
            return;
          }
          case 'F4': {
            setPanel('customer');
            return;
          }
          case 'F6': {
            void holdSaleRef.current?.();
            return;
          }
          case 'F7': {
            setPanel('hold');
            return;
          }
          case 'F9': {
            setPanel('receipt');
            return;
          }
          case 'Delete': {
            if (cart.length > 0) {
              setCart((current) => current.slice(0, -1));
            }
            return;
          }
          default:
            return;
        }
      }
      if (event.key === 'Escape') {
        // Escape cierra modales SIEMPRE, incluso dentro de inputs.
        if (inForm) return;
        setPanel(null);
        return;
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [
    cart,
    payments,
    activeSession,
    selectedCustomer,
    customerName,
    query,
    panel,
    priceListPaymentIssue,
    setPanel,
    setProductSearch,
    confirmPaidSale,
  ]);

  if (!canView) {
    return (
      <div className="bg-bg flex min-h-[70vh] items-center justify-center">
        <div className="border-border bg-surface max-w-md rounded border p-6 text-center shadow-sm">
          <Wallet className="text-text-muted mx-auto mb-3 size-8" />
          <h1 className="text-lg font-semibold">POS no disponible</h1>
          <p className="text-text-muted mt-2 text-sm">
            Necesitas el permiso pos.view para usar la caja de venta.
          </p>
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
        rateLabel={
          openingRate ? `${openingRate.code} @ ${formatLocalNumber(openingRate.rate)}` : null
        }
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
          openCash.mutate(
            {
              branch_id: Number(openingBranchId),
              cash_register_id: Number(openingRegisterId),
              opening_base_amount: Number(openingBaseAmount || 0),
              opening_local_amount: Number(openingLocalAmount || 0),
              exchange_rate_type_id:
                Number(openingLocalAmount || 0) > 0 ? openingRate?.exchange_rate_type_id : null,
              notes: 'Apertura desde POS',
            },
            {
              onError: (error) => {
                void bootstrap.refetch();
                toast.error(errorMessage(error));
              },
            },
          );
        }}
        busy={openCash.isPending}
      />
    );
  }

  return (
    <div className="text-text-primary h-screen overflow-hidden bg-[#f4f6fb]">
      <header className="border-border/80 bg-surface/95 grid grid-cols-1 gap-3 border-b px-4 py-3 shadow-sm backdrop-blur 2xl:grid-cols-[340px_minmax(720px,1fr)_auto]">
        <div className="flex min-w-0 items-center gap-3">
          <div className="from-primary text-primary-foreground shadow-primary/20 flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br to-[#2f238f] shadow-md">
            <Receipt className="size-5" />
          </div>
          <div>
            <h1 className="text-lg leading-tight font-semibold">POS</h1>
            <p className="text-text-muted text-xs">
              {activeSession?.cash_register?.name ?? 'Caja abierta'} -{' '}
              {selectedWarehouse?.name ?? 'Sin almacen'}
            </p>
          </div>
        </div>
        <div className="grid min-w-0 gap-2 md:grid-cols-[minmax(260px,1fr)_210px_230px]">
          <div className="space-y-1">
            <label className="text-text-muted block text-[10px] font-semibold uppercase">
              Buscar / escanear
            </label>
            <div className="relative">
              <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
              <Input
                ref={searchRef}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'ArrowDown' && quickSearchResults.length > 0) {
                    event.preventDefault();
                    setQuickSearchIndex((current) => (current + 1) % quickSearchResults.length);
                    return;
                  }
                  if (event.key === 'ArrowUp' && quickSearchResults.length > 0) {
                    event.preventDefault();
                    setQuickSearchIndex(
                      (current) =>
                        (current - 1 + quickSearchResults.length) % quickSearchResults.length,
                    );
                    return;
                  }
                  if (event.key === 'Enter') {
                    event.preventDefault();
                    const selectedProduct =
                      quickSearchResults[quickSearchIndex] ?? quickSearchResults[0];
                    if (selectedProduct) {
                      void addProduct(selectedProduct).then((added) => {
                        if (added) {
                          setQuery('');
                          setQuickSearchIndex(0);
                        }
                      });
                      return;
                    }
                    void handleProductSearchEnter();
                  }
                }}
                className="h-10 pl-9 text-base"
                placeholder="Escanea codigo, SKU o escribe producto"
                data-pos-search-input="true"
                data-testid="pos-search"
              />
              {!panel && query.trim().length >= 2 && quickSearchResults.length > 0 && (
                <div className="border-border bg-surface absolute top-[calc(100%+8px)] right-0 left-0 z-20 overflow-hidden rounded-2xl border shadow-xl">
                  <div className="border-border text-text-muted flex items-center justify-between border-b px-3 py-2 text-[10px] tracking-wide uppercase">
                    <span>Resultados rapidos</span>
                    <button
                      type="button"
                      className="text-primary font-semibold hover:underline"
                      onClick={() => {
                        setProductSearch(query);
                        setPanel('product-search');
                      }}
                    >
                      Ver todos
                    </button>
                  </div>
                  <div className="max-h-96 overflow-auto p-2">
                    {quickSearchResults.map((product, index) => (
                      <button
                        key={product.id}
                        type="button"
                        className={cn(
                          'hover:bg-bg flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors',
                          index === quickSearchIndex && 'bg-primary/5 ring-1 ring-primary/20',
                        )}
                        onClick={() => {
                          void addProduct(product).then((added) => {
                            if (added) {
                              setQuery('');
                              setQuickSearchIndex(0);
                            }
                          });
                        }}
                        onMouseEnter={() => setQuickSearchIndex(index)}
                      >
                        <ProductImageView
                          image={primaryProductImage(product)}
                          src={productImageSrc(product) ?? undefined}
                          alt={product.name}
                          variant="thumb"
                          className="border-border bg-bg size-12 shrink-0 rounded-lg border"
                        />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold">{product.name}</p>
                          <p className="text-text-muted truncate text-xs">
                            {product.sku ?? product.barcode ?? 'Sin codigo'}
                          </p>
                        </div>
                        <Badge
                          variant={Number(product.available_stock ?? 0) > 0 ? 'success' : 'warning'}
                          className="text-[10px]"
                        >
                          {Number(product.available_stock ?? 0) > 0
                            ? `Stock ${Number(product.available_stock)}`
                            : 'Sin stock'}
                        </Badge>
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
          <div className="space-y-1">
            <label className="text-text-muted block text-[10px] font-semibold uppercase">
              Almacen
            </label>
            <Select
              value={warehouseId ?? ''}
              onChange={(event) =>
                setWarehouseId(event.target.value ? Number(event.target.value) : null)
              }
            >
              {warehouses.map((warehouse) => (
                <option key={warehouse.id} value={warehouse.id}>
                  {warehouse.code} - {warehouse.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1">
            <label className="text-text-muted block text-[10px] font-semibold uppercase">
              Lista de precio
            </label>
            <Select
              value={selectedPriceListId ?? 'base'}
              onChange={(event) =>
                void changePriceList(
                  event.target.value === 'base' ? null : Number(event.target.value),
                )
              }
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
          <Button
            size="sm"
            onClick={() => {
              if (priceListPaymentIssue) return toast.error(priceListPaymentIssue);
              void confirmPaidSale();
            }}
            disabled={Boolean(checkoutBlockReason) || checkout.isPending}
            className="shadow-sm"
          >
            {checkout.isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <CreditCard className="size-4" />
            )}
            Cobrar
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setProductSearch(query);
              setPanel('product-search');
            }}
          >
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
          <Button
            variant="outline"
            size="sm"
            disabled={cart.length === 0 || !canCheckout || checkout.isPending}
            onClick={() => void holdSale()}
          >
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
        <section className="border-border/80 bg-surface flex min-h-0 flex-col overflow-hidden rounded-2xl border shadow-sm">
          <div className="border-border from-surface to-bg/70 flex items-center justify-between border-b bg-gradient-to-r p-4">
            <div>
              <h2 className="font-semibold">Ticket actual</h2>
              <p className="text-text-muted text-xs">
                {selectedCustomer ? 'Cliente asignado' : customerName}
              </p>
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
          <div className="min-h-0 flex-1 overflow-auto bg-[#f8fafc] p-3">
            {cart.length === 0 ? (
              <div className="border-border bg-surface text-text-muted flex h-full items-center justify-center rounded-2xl border border-dashed p-6 text-center text-sm">
                <div>
                  <Search className="text-primary/50 mx-auto mb-3 size-8" />
                  <p className="text-text-secondary font-semibold">Ticket listo para vender</p>
                  <p className="mt-1">
                    Agrega productos con el buscador o escanea un codigo de barras.
                  </p>
                </div>
              </div>
            ) : (
              <div className="space-y-2">
                {cart.map((line) => (
                  <CartLineRow
                    key={line.id}
                    line={line}
                    onChange={(patch) => updateLine(line.id, patch)}
                    onSerials={() => {
                      setSerialLineId(line.id);
                      setPanel('serials');
                    }}
                    onRemove={() =>
                      setCart((current) => current.filter((item) => item.id !== line.id))
                    }
                  />
                ))}
              </div>
            )}
          </div>
        </section>

        <aside className="border-border/80 bg-surface flex min-h-0 flex-col overflow-hidden rounded-2xl border shadow-sm">
          <div className="border-border border-b bg-gradient-to-br from-[#17112f] to-[#2f238f] p-4 text-white">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold text-white/70 uppercase">Total</p>
                <p className="mt-1 text-4xl font-bold tracking-normal">{money(cartTotals.total)}</p>
              </div>
              <div className="min-w-28 space-y-1 text-right text-xs text-white/70">
                <AmountRow label="Subtotal" value={cartTotals.subtotal} />
                {cartTotals.discount > 0 && (
                  <AmountRow label="Desc." value={cartTotals.discount} muted />
                )}
              </div>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-auto p-4">
            {activePaymentMethods.length === 0 ? (
              <div className="border-warning bg-warning/10 text-warning mb-3 rounded border p-3 text-sm">
                Configura metodos de pago para cobrar rapido.
                <Button asChild className="mt-3 w-full" variant="outline">
                  <Link to="/payment-methods">Configurar metodos</Link>
                </Button>
              </div>
            ) : null}
            {priceListNotice ? (
              <p className="border-warning bg-warning/10 text-warning mb-3 rounded border p-3 text-sm">
                {priceListNotice}
              </p>
            ) : null}
            {priceListPaymentIssue && activePaymentMethods.length > 0 ? (
              <div className="border-warning bg-warning/10 text-warning mb-3 rounded border p-3 text-sm">
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
                      onRemove={() =>
                        setPayments((current) => current.filter((item) => item.id !== payment.id))
                      }
                    />
                  ))}
                </div>
              )}
              {payments.length === 0 && (
                <button
                  type="button"
                  onClick={() => setPanel('pay')}
                  disabled={allowedPaymentMethods.length === 0 || Boolean(priceListPaymentIssue)}
                  className="border-border text-text-muted hover:border-primary hover:text-primary w-full rounded border border-dashed px-3 py-4 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Agregar pago con F2
                </button>
              )}
            </div>
            <div className="border-border bg-bg/50 mt-4 space-y-2 rounded border p-3">
              <AmountRow label="Restante USD" value={paymentTotals.remaining} />
              {(() => {
                const tasaParaSidebar = cartRate ?? (activeRate ? { code: activeRate.code, rate: activeRate.rate } : null);
                if (!tasaParaSidebar) return null;
                return (
                  <AmountRow
                    label={`Restante VES (${tasaParaSidebar.code ?? '?'})`}
                    value={paymentAmountForCurrency(paymentTotals.remaining, 'VES', tasaParaSidebar.rate)}
                    currency="VES"
                  />
                );
              })()}
              <div className="bg-success/10 mt-2 rounded p-3">
                <p className="text-text-muted text-xs">Vuelto</p>
                <p className="text-success text-3xl font-bold">{money(paymentTotals.change)}</p>
                {paymentTotals.change > 0 && paymentTotals.change_currency === 'VES' && (
                  <p className="text-success mt-1 text-sm font-semibold">
                    Bs {roundMoney(paymentTotals.change_amount ?? 0).toFixed(2)}
                    {paymentTotals.change_rate ? ` @ ${paymentTotals.change_rate}` : ''}
                  </p>
                )}
              </div>
            </div>
          </div>

          <div className="border-border space-y-2 border-t p-3">
            {checkoutBlockReason && (
              <p className="border-warning bg-warning/10 text-warning rounded border px-3 py-2 text-xs">
                {checkoutBlockReason}
              </p>
            )}
            <Button
              className="h-12 w-full text-base"
              disabled={Boolean(checkoutBlockReason) || checkout.isPending}
              onClick={() => void confirmPaidSale()}
            >
              {checkout.isPending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <CreditCard className="size-5" />
              )}
              <ShortcutText label="F10" text="Cobrar" />
            </Button>
            <Button
              className="h-10 w-full"
              variant="secondary"
              disabled={
                !canCheckout ||
                !canCollectReceivables ||
                cart.length === 0 ||
                hasStockIssue(cart) ||
                hasPriceIssue(cart) ||
                Boolean(priceListPaymentIssue) ||
                checkout.isPending
              }
              onClick={() => setPanel('credit')}
            >
              <Wallet className="size-4" />
              Enviar a CxC
            </Button>
          </div>
        </aside>
      </main>

      {panel && (
        <PanelShell
          title={panelTitle(panel)}
          onClose={() => setPanel(null)}
          wide={panel === 'pay' || panel === 'customer'}
          actions={
            <>
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setProductSearch(query);
                  setPanel('product-search');
                }}
              >
                <Search className="size-4" /> F3 Buscar
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
                <CreditCard className="size-4" /> F2 Pago
              </Button>
              <Button variant="outline" size="sm" onClick={() => setPanel('customer')}>
                <UserRound className="size-4" /> F4 Cliente
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => void holdSale()}
                disabled={cart.length === 0 || !canCheckout || checkout.isPending}
              >
                <PauseCircle className="size-4" /> F6 Espera
              </Button>
              <Button variant="outline" size="sm" onClick={() => setPanel('hold')}>
                <History className="size-4" /> F7 Pendientes
              </Button>
              <Button variant="outline" size="sm" onClick={() => setPanel('receipt')}>
                <Receipt className="size-4" /> F9 Recibo
              </Button>
            </>
          }
        >
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
                  payload: {
                    counted_currency: 'USD',
                    counted_amount: Number(closingAmount),
                    closing_notes: 'Cierre desde POS',
                  },
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
              onPrint={(copy, output) =>
                lastReceipt && createAndDispatchPrintJobs(lastReceipt, copy, output)
              }
              onOpenPdf={(job) => void openTicketPdf(job)}
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
    } catch {
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
            currency: line.base_currency ?? line.currency,
            price_list_id: null,
            price_list_name: BASE_PRICE_LIST_LABEL,
            price_issue: null,
          })),
        );
      } else {
        const quoted = await Promise.all(
          cart.map(async (line) => ({
            line,
            quote: await quoteProductForPos(line.product_id, nextList.id),
          })),
        );
        setCart((current) =>
          current.map((line) => {
            const found = quoted.find((item) => item.line.id === line.id);
            if (!found) return line;

            return {
              ...line,
              unit_price: found.quote.base_price_usd,
              currency: found.quote.sale_currency,
              price_list_id: nextList.id,
              price_list_name: found.quote.price_list_name ?? nextList.name,
              price_issue: null,
            };
          }),
        );
      }
      setSelectedPriceListId(nextId);
      setPayments([]);
      toast.success(
        `Ticket actualizado a ${nextList?.name ?? BASE_PRICE_LIST_LABEL}. Pagos limpiados.`,
      );
    } catch {
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
      const existing = current.find(
        (line) => line.product_id === product.id && line.warehouse_id === selectedWarehouse.id,
      );
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
          currency: quote?.sale_currency ?? product.sale_currency ?? 'USD',
          base_currency: product.sale_currency ?? 'USD',
          exchange_rate: quote?.exchange_rate ?? null,
          exchange_rate_type_id: quote?.exchange_rate_type_id ?? null,
          exchange_rate_type_code: quote?.exchange_rate_type_code ?? null,
          price_list_id: selectedPriceList?.id ?? null,
          price_list_name:
            quote?.price_list_name ?? selectedPriceList?.name ?? BASE_PRICE_LIST_LABEL,
          price_issue: null,
          image_url: productImageSrc(product),
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
        if (
          next.tracking_type === 'serialized' &&
          next.selected_serials &&
          next.selected_serials.length > next.quantity
        ) {
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
        const usedInAnotherLine = current.some(
          (item) =>
            item.id !== lineId &&
            item.selected_serials?.some((selectedSerial) => selectedSerial.id === serial.id),
        );
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
    const missing = cart.find(
      (line) =>
        line.tracking_type === 'serialized' &&
        (line.selected_serials?.length ?? 0) !== Number(line.quantity),
    );
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
    const configured = paymentMethodId
      ? allowedPaymentMethods.find((item) => item.id === paymentMethodId)
      : null;
    const currencyMode = configured?.currency_mode ?? 'USD';
    const currency = currencyMode === 'VES' ? 'VES' : 'USD';
    // Prioridad: tasa del carrito (anclada al producto) > tasa default.
    // Esto respeta la tasa del producto cuando todos los items comparten
    // la misma. Si las tasas son mixtas, cae a activeRate.
    const rate = cartRate ?? activeRate;
    setPayments((current) => [
      ...current,
      {
        id: crypto.randomUUID(),
        method: configured?.method ?? method,
        currency,
        amount: paymentAmountForCurrency(
          Math.max(
            0,
            calculateCartTotals(cart).total -
              calculatePaymentTotals(current, calculateCartTotals(cart).total).paid,
          ),
          currency,
          rate?.rate ?? null,
        ),
        received_amount:
          method === 'cash'
            ? paymentAmountForCurrency(
                Math.max(
                  0,
                  calculateCartTotals(cart).total -
                    calculatePaymentTotals(current, calculateCartTotals(cart).total).paid,
                ),
                currency,
                rate?.rate ?? null,
              )
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
    setPayments((current) =>
      current.map((payment) => (payment.id === id ? { ...payment, ...patch } : payment)),
    );
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
      toast.error(
        paymentSetupIssue ?? priceListPaymentIssue ?? 'Revisa la configuracion de cobro.',
      );
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
        phone: optionalText(quickCustomer.phone),
        email: optionalText(quickCustomer.email),
        fiscal_address: optionalText(quickCustomer.fiscal_address),
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
        payments: [
          { method: 'cash', currency: 'USD', amount: 0.01, status: 'pending', reference: 'hold' },
        ],
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

  function buildCheckoutPayload(
    sessionId: number,
    status: 'captured' | 'pending',
  ): CheckoutPayload {
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
        product_unit_ids:
          line.tracking_type === 'serialized'
            ? (line.selected_serials ?? []).map((serial) => serial.id)
            : [],
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

  async function createAndDispatchPrintJobs(
    order: PosOrder,
    copy: boolean,
    output?: 'thermal' | 'digital' | 'both',
  ): Promise<void> {
    if (!canPrint && !canDigital && !copy) return;
    if (copy && !canReprint) {
      toast.error('No tienes permiso para reimprimir tickets.');
      return;
    }

    const requestedOutput =
      output ?? activePrinterStation?.output_mode ?? (canDigital ? 'digital' : 'thermal');
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

      await Promise.all(
        jobs.map(async (job) => {
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
            if (job.output === 'digital' && result.pdf_path)
              toast.success(`Ticket virtual generado: ${result.pdf_path}`);
            if (job.output === 'thermal') toast.success('Ticket enviado a impresora.');
          } catch (error) {
            await updatePrintJobStatus.mutateAsync({
              jobId: job.id,
              status: 'failed',
              message: error instanceof Error ? error.message : 'No se pudo imprimir.',
            });
            if (job.output === 'digital') {
              await openTicketPdf(job);
              toast.warning('Agente no disponible. Abrimos el PDF en el navegador.');
              return;
            }
            toast.error('Agente local no disponible. Puedes reintentar desde F9.');
          }
        }),
      );
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'No se pudo crear el ticket de impresion.',
      );
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
    <div
      className={cn(
        'bg-surface grid gap-3 rounded-xl border border-transparent p-3 shadow-sm transition-colors xl:grid-cols-[minmax(220px,1fr)_440px_120px_40px] xl:items-center',
        (stockIssue || serialIssue) && 'border-warning/40 bg-warning/10',
      )}
    >
      <div className="min-w-0 space-y-1">
        <div className="flex min-w-0 items-start gap-3">
          <ProductImageView
            src={line.image_url ?? undefined}
            alt={line.name}
            variant="thumb"
            className="border-border bg-bg size-12 shrink-0 rounded-xl border"
          />
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex min-w-0 items-center gap-2">
              <p className="truncate text-base font-semibold">{line.name}</p>
              <Badge variant={stockIssue ? 'warning' : 'success'} className="shrink-0 text-[10px]">
                Stock {line.available_stock}
              </Badge>
            </div>
            <div className="text-text-muted flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
              <span className="font-mono">{line.sku ?? line.barcode ?? line.product_id}</span>
              <span>{money(line.unit_price)} c/u</span>
              {line.price_list_name && (
                <Badge variant="default" className="text-[10px]">
                  {line.price_list_name}
                </Badge>
              )}
              {line.tracking_type === 'serialized' && (
                <button
                  type="button"
                  className={cn('font-semibold', serialIssue ? 'text-warning' : 'text-success')}
                  onClick={onSerials}
                >
                  IMEI {serialCount}/{line.quantity}
                </button>
              )}
            </div>
          </div>
        </div>
        {line.tracking_type === 'serialized' && serialCount > 0 && (
          <div className="flex flex-wrap gap-1">
            {line.selected_serials?.map((serial) => (
              <Badge key={serial.id} variant="default" className="font-mono text-[10px]">
                {serial.serial_number}
              </Badge>
            ))}
          </div>
        )}
        {line.price_issue && (
          <p className="border-warning bg-warning/10 text-warning rounded border px-2 py-1 text-xs">
            {line.price_issue}
          </p>
        )}
      </div>
      <div className="grid gap-2 sm:grid-cols-[124px_1fr]">
        <div className="flex items-center gap-1">
          <Button
            size="icon-sm"
            variant="outline"
            onClick={() => onChange({ quantity: line.quantity - 1 })}
          >
            <Minus className="size-3" />
          </Button>
          <Input
            className="h-9 text-center"
            type="number"
            min="1"
            value={line.quantity}
            onChange={(event) => onChange({ quantity: Number(event.target.value) })}
          />
          <Button
            size="icon-sm"
            variant="outline"
            onClick={() => onChange({ quantity: line.quantity + 1 })}
          >
            <Plus className="size-3" />
          </Button>
        </div>
        <div className="grid grid-cols-[minmax(100px,1fr)_92px] gap-2">
          <Select
            value={line.discount_type ?? ''}
            onChange={(event) =>
              onChange({ discount_type: (event.target.value || null) as DiscountType | null })
            }
          >
            <option value="">Sin descuento</option>
            <option value="percent">Porcentaje</option>
            <option value="fixed">Monto</option>
          </Select>
          <Input
            type="number"
            min="0"
            value={line.discount_value ?? ''}
            onChange={(event) => onChange({ discount_value: Number(event.target.value || 0) })}
          />
        </div>
        {line.tracking_type === 'serialized' && (
          <Button
            className="sm:col-span-2"
            variant={serialIssue ? 'secondary' : 'outline'}
            size="sm"
            onClick={onSerials}
          >
            Seleccionar IMEI/serial
          </Button>
        )}
      </div>
      <div className="text-right">
        <p className="text-text-muted text-xs">Total linea</p>
        <p className="text-lg font-bold">{money(lineTotal(line))}</p>
      </div>
      <Button size="icon-sm" variant="ghost" onClick={onRemove} aria-label="Eliminar linea">
        <Trash2 className="size-4" />
      </Button>
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
  methods: {
    id: number;
    name: string;
    method?: string | null;
    currency_mode?: 'USD' | 'VES' | 'flexible';
    requires_reference?: boolean;
  }[];
  rateTypes: {
    id: number;
    code: string;
    name: string;
    is_default?: boolean;
    is_active?: boolean;
  }[];
  onChange: (patch: Partial<PosPaymentLine>) => void;
  onRemove: () => void;
}) {
  const selectedMethod = methods.find((method) => method.id === payment.payment_method_id) ?? null;
  const requiresReference =
    selectedMethod?.requires_reference === true || payment.method !== 'cash';
  const rateType = rateTypes.find((rate) => rate.id === payment.exchange_rate_type_id) ?? null;
  const baseAmount = paymentBaseAmount(payment);

  return (
    <div className="border-border bg-bg/40 rounded border p-2">
      <div className="grid grid-cols-[minmax(0,1fr)_112px_auto] items-center gap-2">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <p className="truncate text-sm font-semibold">
              {selectedMethod?.name ?? methodLabel(payment.method)}
            </p>
            <Badge variant="info">{payment.currency}</Badge>
          </div>
          <p className="text-text-muted mt-1 text-xs">
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
        <Button size="icon-sm" variant="ghost" onClick={onRemove}>
          <X className="size-4" />
        </Button>
      </div>

      {(payment.currency === 'VES' && payment.exchange_rate) ||
      payment.method === 'cash' ||
      requiresReference ? (
        <div className="mt-2 grid gap-2">
          {payment.currency === 'VES' && payment.exchange_rate && (
            <p className="text-text-muted text-xs font-medium">Equivale a {money(baseAmount)}</p>
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
              placeholder={
                selectedMethod?.requires_reference ? 'Referencia obligatoria' : 'Referencia'
              }
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
  branches: { id: number; name: string; code: string }[];
  cashRegisters: { id: number; name: string; code?: string | null }[];
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
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#eef2ff] p-4">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(79,70,229,0.18),transparent_34%),radial-gradient(circle_at_bottom_right,rgba(14,165,233,0.14),transparent_32%)]" />
      <div className="bg-surface relative grid w-full max-w-5xl overflow-hidden rounded-[2rem] border border-white/70 shadow-2xl shadow-slate-900/10 lg:grid-cols-[0.95fr_1.05fr]">
        <div className="flex min-h-[520px] flex-col justify-between bg-gradient-to-br from-[#17112f] via-[#241761] to-[#4338ca] p-8 text-white">
          <div>
            <div className="flex size-14 items-center justify-center rounded-2xl bg-white/15 shadow-lg shadow-black/10 backdrop-blur">
              <Receipt className="size-7" />
            </div>
            <p className="mt-8 text-sm font-semibold tracking-[0.25em] text-white/55 uppercase">
              Punto de venta
            </p>
            <h1 className="mt-3 max-w-sm text-4xl font-bold tracking-tight">Abrir turno POS</h1>
            <p className="mt-4 max-w-md text-sm leading-6 text-white/70">
              Selecciona sucursal, caja fisica y fondo inicial para comenzar a vender con
              trazabilidad de caja.
            </p>
          </div>
          <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-1">
            <div className="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
              <p className="text-white/55">Tasa activa</p>
              <p className="mt-1 font-semibold">{props.rateLabel ?? 'Sin tasa USD/VES'}</p>
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
              <p className="text-white/55">Cajas disponibles</p>
              <p className="mt-1 font-semibold">{props.cashRegisters.length}</p>
            </div>
          </div>
        </div>
        <div className="p-6 sm:p-8">
          <div>
            <p className="text-primary text-xs font-semibold tracking-wide uppercase">
              Inicio de turno
            </p>
            <h2 className="mt-2 text-2xl font-bold">Datos de apertura</h2>
            <p className="text-text-muted mt-2 text-sm">
              La venta queda bloqueada hasta que exista una caja abierta para tu usuario.
            </p>
          </div>
          {!props.canOpenCash ? (
            <p className="border-warning bg-warning/10 text-warning mt-6 rounded-2xl border p-4 text-sm">
              No tienes permiso para abrir caja.
            </p>
          ) : (
            <div className="mt-6 space-y-4">
              {(props.branches.length === 0 || props.cashRegisters.length === 0) && (
                <div className="border-warning bg-warning/10 text-warning rounded-2xl border p-4 text-sm">
                  Falta configurar sucursales o cajas fisicas antes de abrir turno.
                  <Button asChild className="mt-3 w-full" variant="outline">
                    <Link to="/cash-register">Configurar cajas</Link>
                  </Button>
                </div>
              )}
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledControl label="Sucursal">
                  <Select
                    value={props.branchId}
                    onChange={(event) =>
                      props.onBranchChange(event.target.value ? Number(event.target.value) : '')
                    }
                  >
                    <option value="">Sucursal...</option>
                    {props.branches.map((branch) => (
                      <option key={branch.id} value={branch.id}>
                        {branch.code} - {branch.name}
                      </option>
                    ))}
                  </Select>
                </LabeledControl>
                <LabeledControl label="Caja fisica">
                  <Select
                    value={props.registerId}
                    onChange={(event) =>
                      props.onRegisterChange(event.target.value ? Number(event.target.value) : '')
                    }
                  >
                    <option value="">Caja fisica...</option>
                    {props.cashRegisters.map((register) => (
                      <option key={register.id} value={register.id}>
                        {register.code ?? register.id} - {register.name}
                      </option>
                    ))}
                  </Select>
                </LabeledControl>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledControl label="Fondo USD">
                  <Input
                    type="number"
                    min="0"
                    value={props.baseAmount}
                    onChange={(event) => props.onBaseAmountChange(event.target.value)}
                    placeholder="0.00"
                  />
                </LabeledControl>
                <LabeledControl label="Fondo VES">
                  <Input
                    type="number"
                    min="0"
                    value={props.localAmount}
                    onChange={(event) => props.onLocalAmountChange(event.target.value)}
                    placeholder="0.00"
                  />
                </LabeledControl>
              </div>
              <p className="border-border bg-bg/50 text-text-muted rounded-2xl border px-4 py-3 text-xs">
                {props.rateLabel
                  ? `VES se convierte con ${props.rateLabel}.`
                  : 'Sin tasa activa USD/VES para convertir fondo VES.'}
              </p>
              <Button
                className="h-12 w-full text-base"
                onClick={props.onOpen}
                disabled={
                  props.busy ||
                  !props.branchId ||
                  !props.registerId ||
                  props.cashRegisters.length === 0
                }
              >
                {props.busy && <Loader2 className="size-4 animate-spin" />} Abrir turno
              </Button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function PanelShell({
  title,
  children,
  onClose,
  wide = false,
  actions,
}: {
  title: string;
  children: React.ReactNode;
  onClose: () => void;
  wide?: boolean;
  actions?: React.ReactNode;
}) {
  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        className={cn(
          'flex h-full w-full flex-col overflow-hidden border-l border-white/40 bg-[#f5f7fb] p-0 shadow-2xl',
          wide ? 'sm:max-w-5xl' : 'sm:max-w-xl',
        )}
      >
        <SheetHeader className="border-border bg-surface relative overflow-hidden border-b px-5 py-4 pr-12">
          <div className="from-primary absolute inset-x-0 top-0 h-1 bg-gradient-to-r via-[#2f238f] to-sky-400" />
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-primary text-[10px] font-semibold tracking-[0.22em] uppercase">
                POS
              </p>
              <SheetTitle className="mt-1 text-xl">{title}</SheetTitle>
              <SheetDescription>Operación rápida del punto de venta.</SheetDescription>
            </div>
          </div>
          {actions ? <div className="mt-4 flex flex-wrap gap-2">{actions}</div> : null}
        </SheetHeader>
        <div className="min-h-0 flex-1 overflow-auto p-5">{children}</div>
      </SheetContent>
    </Sheet>
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
      <div className="border-border bg-surface rounded-2xl border p-4 shadow-sm">
        <p className="text-lg font-semibold">{line.name}</p>
        <p className="text-text-muted text-sm">
          Selecciona {line.quantity} IMEI/serial disponible para confirmar esta venta.
        </p>
        <Badge className="mt-3" variant={complete ? 'success' : 'warning'}>
          {selectedIds.size}/{line.quantity} seleccionados
        </Badge>
      </div>

      {loading ? (
        <div className="border-border text-text-muted flex items-center gap-2 rounded border p-3 text-sm">
          <Loader2 className="size-4 animate-spin" /> Buscando IMEIs disponibles...
        </div>
      ) : serials.length === 0 ? (
        <div className="border-warning bg-warning/10 text-warning rounded border p-3 text-sm">
          No hay IMEIs disponibles para este producto en el almacen seleccionado.
        </div>
      ) : (
        <div className="divide-border border-border bg-surface max-h-[70vh] divide-y overflow-auto rounded-2xl border shadow-sm">
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
                  'hover:bg-bg flex w-full items-center justify-between gap-3 p-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                  checked && 'bg-primary/10 ring-primary/20 ring-1 ring-inset',
                )}
              >
                <div>
                  <p className="font-mono font-semibold">{serial.serial_number}</p>
                  <p className="text-text-muted text-xs">
                    {serial.serial_type?.toUpperCase() ?? 'SERIAL'} -{' '}
                    {serial.warehouse_name ?? 'Almacen'}
                  </p>
                </div>
                <Badge variant={checked ? 'success' : 'default'}>
                  {checked ? 'Seleccionado' : serial.status}
                </Badge>
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
  methods: {
    id: number;
    name: string;
    method?: string | null;
    currency_mode?: 'USD' | 'VES' | 'flexible';
    requires_reference?: boolean;
    sort_order?: number;
  }[];
  cartTotal: number;
  payments: PosPaymentLine[];
  currentRates: {
    exchange_rate_type_id: number;
    exchange_rate_type_code?: string | null;
    rate: number;
    base_currency?: string;
    quote_currency?: string;
  }[];
  rateTypes: { id: number; is_default?: boolean; is_active?: boolean; code: string }[];
  priceListName: string;
  issue: string | null;
  onSelect: (methodId: number) => void;
}) {
  const remaining = calculatePaymentTotals(payments, cartTotal).remaining;
  const rate = bestActiveRate(currentRates, rateTypes);

  return (
    <div className="space-y-4">
      <div className="border-border rounded-2xl border bg-gradient-to-br from-[#17112f] to-[#2f238f] p-5 text-white shadow-md">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-sm text-white/70">Restante</p>
            <p className="text-4xl font-bold">{money(remaining)}</p>
          </div>
          {rate ? (
            <div className="text-right">
              <p className="text-sm text-white/70">Equivalente VES</p>
              <p className="text-2xl font-bold">
                Bs {paymentAmountForCurrency(remaining, 'VES', rate.rate).toFixed(2)}
              </p>
              <p className="text-xs text-white/60">
                {rate.code} @ {rate.rate}
              </p>
            </div>
          ) : (
            <div className="border-warning bg-warning/10 text-warning rounded border px-3 py-2 text-sm">
              Configura una tasa activa USD/VES antes de cobrar.
            </div>
          )}
        </div>
      </div>
      <p className="border-border bg-bg/40 text-text-muted rounded border px-3 py-2 text-xs">
        Metodos permitidos para {priceListName}.
      </p>

      {methods.length === 0 ? (
        <div className="border-warning bg-warning/10 text-warning rounded border p-4 text-sm">
          {issue ?? 'No hay metodos activos para esta lista de precio.'}
          <Button asChild className="mt-3" variant="outline">
            <Link to="/inventory/admin">Configurar lista</Link>
          </Button>
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {methods.map((method) => {
            const preview = previewQuickPayment(
              method,
              cartTotal,
              payments,
              currentRates,
              rateTypes,
            );
            return (
              <button
                key={method.id}
                type="button"
                onClick={() => onSelect(method.id)}
                className="border-border bg-surface hover:border-primary/60 hover:bg-primary/5 min-h-32 rounded-2xl border p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate font-semibold">{method.name}</p>
                    <p className="text-text-muted mt-1 text-xs">{methodLabel(method.method)}</p>
                  </div>
                  <Badge variant={method.currency_mode === 'VES' ? 'info' : 'default'}>
                    {method.currency_mode === 'flexible'
                      ? 'USD/VES'
                      : (method.currency_mode ?? 'USD')}
                  </Badge>
                </div>
                <p className="mt-4 text-2xl font-bold">{preview.amountLabel}</p>
                <p className="text-text-muted text-xs">{preview.detail}</p>
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
  warehouses: { id: number; code: string; name: string }[];
  warehouseId: number | null;
  priceListName: string | null;
  loading: boolean;
  onSearch: (value: string) => void;
  onWarehouseChange: (value: number | null) => void;
  onSelect: (product: Product) => void | Promise<void>;
}) {
  const canSearch = search.trim().length >= 2;
  const [selectedIndex, setSelectedIndex] = useState(0);

  useEffect(() => {
    setSelectedIndex(0);
  }, [search, products.length]);

  const safeIndex = products.length > 0 ? Math.min(selectedIndex, products.length - 1) : 0;

  return (
    <div className="space-y-4">
      <div className="grid gap-2 md:grid-cols-[minmax(260px,1fr)_220px]">
        <div className="relative">
          <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
          <Input
            autoFocus
            value={search}
            onChange={(event) => onSearch(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown' && products.length > 0) {
                event.preventDefault();
                setSelectedIndex((current) => (current + 1) % products.length);
                return;
              }
              if (event.key === 'ArrowUp' && products.length > 0) {
                event.preventDefault();
                setSelectedIndex((current) => (current - 1 + products.length) % products.length);
                return;
              }
              if (event.key === 'Enter' && products.length > 0) {
                event.preventDefault();
                const selectedProduct = products[safeIndex] ?? products[0] ?? null;
                if (selectedProduct) {
                  void onSelect(selectedProduct);
                }
              }
            }}
            className="h-11 pl-9 text-base"
            placeholder="Nombre, SKU o codigo de barras"
            data-pos-search-input="true"
          />
        </div>
        <Select
          value={warehouseId ?? ''}
          onChange={(event) =>
            onWarehouseChange(event.target.value ? Number(event.target.value) : null)
          }
        >
          {warehouses.map((warehouse) => (
            <option key={warehouse.id} value={warehouse.id}>
              {warehouse.code} - {warehouse.name}
            </option>
          ))}
        </Select>
      </div>
      {priceListName && (
        <p className="border-border bg-bg/40 text-text-muted rounded border px-3 py-2 text-xs">
          Los productos se cotizan al agregarlos con la lista {priceListName}.
        </p>
      )}

      {!canSearch ? (
        <div className="border-border bg-bg/40 text-text-muted rounded border p-6 text-center text-sm">
          Escribe al menos 2 caracteres o escanea un codigo para buscar.
        </div>
      ) : loading ? (
        <div className="border-border bg-bg/40 text-text-muted flex items-center gap-2 rounded border p-4 text-sm">
          <Loader2 className="size-4 animate-spin" /> Buscando productos
        </div>
      ) : products.length === 0 ? (
        <div className="border-border bg-bg/40 text-text-muted rounded border p-6 text-center text-sm">
          No hay productos con esa busqueda.
        </div>
      ) : (
        <div className="grid max-h-[68vh] gap-3 overflow-auto pr-1 md:grid-cols-2 xl:grid-cols-3">
          {products.map((product, index) => (
            <button
              key={product.id}
              type="button"
              onClick={() => void onSelect(product)}
              onMouseEnter={() => setSelectedIndex(index)}
              className={cn(
                'group border-border bg-surface hover:border-primary/60 focus-visible:ring-primary overflow-hidden rounded-2xl border text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2',
                index === safeIndex && 'border-primary bg-primary/5 ring-1 ring-primary/20',
              )}
            >
              <ProductImageView
                image={primaryProductImage(product)}
                src={productImageSrc(product) ?? undefined}
                alt={product.name}
                variant="thumb"
                className="border-border bg-bg aspect-[4/3] w-full border-b"
              />
              <div className="p-3">
                <div className="flex gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate font-semibold">{product.name}</p>
                        <p className="text-text-muted font-mono text-xs">
                          {product.sku ?? product.barcode ?? 'Sin codigo'}
                        </p>
                      </div>
                      <Badge
                        variant={Number(product.available_stock ?? 0) > 0 ? 'success' : 'warning'}
                        className="text-[10px]"
                      >
                        {Number(product.available_stock ?? 0) > 0
                          ? `Stock ${Number(product.available_stock)}`
                          : 'Sin stock'}
                      </Badge>
                    </div>
                    {Number(product.available_stock ?? 0) <= Number(product.min_stock ?? 0) &&
                      Number(product.min_stock ?? 0) > 0 && (
                        <p className="text-warning mt-1 text-[10px]">
                          Stock bajo (min {product.min_stock})
                        </p>
                      )}
                  </div>
                </div>
                <div className="mt-3 flex items-end justify-between gap-2">
                  <div>
                    <p className="text-text-muted text-[10px] font-semibold uppercase">
                      Precio base
                    </p>
                    <p className="text-xl font-bold">{money(Number(product.base_price ?? 0))}</p>
                  </div>
                  <span className="bg-primary/10 text-primary rounded-full px-2 py-1 text-[10px] font-semibold opacity-0 transition-opacity group-hover:opacity-100">
                    Agregar
                  </span>
                </div>
                <p className="text-text-muted mt-1 text-xs">
                  Se valida precio de lista al seleccionar
                </p>
              </div>
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
      <div className="border-border bg-bg/30 border-b px-3 py-2">
        <button
          type="button"
          onClick={onChange}
          className="border-border hover:border-primary flex w-full items-center justify-between gap-3 rounded border border-dashed px-3 py-2 text-left transition-colors"
        >
          <span className="min-w-0">
            <span className="text-text-muted block text-xs font-semibold uppercase">Cliente</span>
            <span className="block truncate text-sm font-medium">{customerName}</span>
          </span>
          <Badge variant="default">F4</Badge>
        </button>
      </div>
    );
  }

  return (
    <div className="border-primary/20 bg-primary/5 border-b px-3 py-2">
      <div className="border-primary/30 bg-surface flex items-center justify-between gap-3 rounded border px-3 py-2">
        <div className="flex min-w-0 items-center gap-2">
          <UserRound className="text-primary size-5 shrink-0" />
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <Badge variant="info">Cliente asignado</Badge>
              {document && <span className="text-text-muted truncate text-xs">{document}</span>}
            </div>
            <p className="mt-1 truncate text-sm font-semibold">{customer.name}</p>
          </div>
        </div>
        <div className="flex shrink-0 gap-1">
          <Button variant="outline" size="sm" onClick={onChange}>
            Cambiar
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={onClear} aria-label="Quitar cliente">
            <X className="size-4" />
          </Button>
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
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
      <div className="space-y-3">
        <PanelCard
          eyebrow="Cliente del ticket"
          title="Asignar cliente"
          description="Busca uno existente o usa consumidor final para una venta rapida."
          action={
            <Button variant="outline" onClick={props.onGeneric}>
              Consumidor Final
            </Button>
          }
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <LabeledControl label="Nombre en ticket">
              <Input
                value={props.customerName}
                onChange={(event) => props.onName(event.target.value)}
                placeholder="Nombre manual para ticket"
              />
            </LabeledControl>
            <LabeledControl label="Buscar cliente">
              <Input
                value={props.search}
                onChange={(event) => props.onSearch(event.target.value)}
                placeholder="Nombre, documento o telefono"
              />
            </LabeledControl>
          </div>
        </PanelCard>
        <div className="max-h-72 space-y-2 overflow-auto pr-1">
          {props.search.trim().length > 0 && props.search.trim().length < 2 && (
            <p className="border-border bg-surface text-text-muted rounded-2xl border p-4 text-sm shadow-sm">
              Escribe al menos 2 caracteres para buscar.
            </p>
          )}
          {props.search.trim().length >= 2 && props.customers.length === 0 && (
            <p className="border-border bg-surface text-text-muted rounded-2xl border border-dashed p-5 text-center text-sm shadow-sm">
              No hay clientes con esa busqueda.
            </p>
          )}
          {props.customers.map((customer) => (
            <button
              key={customer.id}
              type="button"
              onClick={() => props.onSelect(customer)}
              className="border-border bg-surface hover:border-primary/60 w-full rounded-2xl border p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate font-semibold">{customer.name}</p>
                  <p className="text-text-muted text-xs">
                    {customerDocument(customer) ?? customer.email ?? customer.phone ?? 'Cliente'}
                  </p>
                </div>
                <Badge variant="info">Asignar</Badge>
              </div>
            </button>
          ))}
        </div>
      </div>

      <PanelCard
        eyebrow="Alta rapida"
        title="Crear cliente"
        description="Usa los mismos datos base del modulo Clientes."
      >
        {!props.canCreate ? (
          <p className="border-warning bg-warning/10 text-warning rounded-2xl border p-4 text-sm">
            No tienes permiso para crear clientes.
          </p>
        ) : (
          <>
            <LabeledControl label="Nombre o razon social">
              <Input
                value={props.form.name}
                onChange={(event) => props.onFormChange({ name: event.target.value })}
                placeholder="Nombre o razon social"
              />
            </LabeledControl>
            <div className="grid grid-cols-[110px_1fr] gap-2">
              <LabeledControl label="Tipo">
                <Select
                  value={props.form.document_type}
                  onChange={(event) =>
                    props.onFormChange({
                      document_type: event.target.value as QuickCustomerForm['document_type'],
                    })
                  }
                >
                  <option value="V">V</option>
                  <option value="E">E</option>
                  <option value="J">J</option>
                  <option value="G">G</option>
                  <option value="P">P</option>
                </Select>
              </LabeledControl>
              <LabeledControl label="Documento">
                <Input
                  value={props.form.document_number}
                  onChange={(event) => props.onFormChange({ document_number: event.target.value })}
                  placeholder="Documento"
                />
              </LabeledControl>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <LabeledControl label="Telefono">
                <Input
                  value={props.form.phone ?? ''}
                  onChange={(event) => props.onFormChange({ phone: event.target.value })}
                  placeholder="Telefono"
                />
              </LabeledControl>
              <LabeledControl label="Email">
                <Input
                  type="email"
                  value={props.form.email ?? ''}
                  onChange={(event) => props.onFormChange({ email: event.target.value })}
                  placeholder="Email"
                />
              </LabeledControl>
            </div>
            <LabeledControl label="Direccion fiscal">
              <Textarea
                value={props.form.fiscal_address ?? ''}
                onChange={(event) => props.onFormChange({ fiscal_address: event.target.value })}
                rows={3}
                placeholder="Direccion fiscal"
              />
            </LabeledControl>
            <Button className="h-11 w-full" onClick={props.onCreate} loading={props.creating}>
              Crear y asignar
            </Button>
          </>
        )}
      </PanelCard>
    </div>
  );
}

function HoldPanel(props: {
  orders: PosOrder[];
  selected: PosOrder | null;
  canCancel: boolean;
  onSelect: (order: PosOrder) => void;
  onPaySelected: () => void;
  onCancel: (order: PosOrder) => void;
}) {
  return (
    <div className="space-y-3">
      {props.orders.length === 0 && (
        <EmptyPanelState
          icon={<PauseCircle className="size-7" />}
          title="Sin tickets en espera"
          description="Cuando pauses una venta aparecerá aquí para retomarla y cobrarla."
        />
      )}
      {props.orders.length > 0 && (
        <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_280px]">
          <div className="space-y-2">
            {props.orders.map((order) => (
              <button
                key={order.id}
                type="button"
                onClick={() => props.onSelect(order)}
                className={cn(
                  'bg-surface hover:border-primary/60 w-full rounded-2xl border p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md',
                  props.selected?.id === order.id
                    ? 'border-primary bg-primary/5 ring-primary/20 ring-1'
                    : 'border-border',
                )}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold">Ticket #{order.id}</p>
                    <p className="text-text-muted text-sm">
                      {order.customer_name ?? 'Consumidor Final'}
                    </p>
                  </div>
                  <p className="text-xl font-bold">{money(order.total_base_amount ?? 0)}</p>
                </div>
              </button>
            ))}
          </div>
          <PanelCard
            eyebrow="Seleccionado"
            title={props.selected ? `Ticket #${props.selected.id}` : 'Elige un ticket'}
            description={
              props.selected
                ? 'Retoma el ticket para completar el cobro o cancelarlo.'
                : 'Selecciona un ticket en espera para ver acciones.'
            }
          >
            <div className="space-y-2">
              <Button
                className="h-11 w-full"
                disabled={!props.selected}
                onClick={props.onPaySelected}
              >
                Cobrar seleccionado
              </Button>
              {props.selected && props.canCancel && (
                <Button
                  className="h-11 w-full"
                  variant="outline"
                  onClick={() => props.onCancel(props.selected!)}
                >
                  Cancelar ticket
                </Button>
              )}
            </div>
          </PanelCard>
        </div>
      )}
    </div>
  );
}

function CashPanel(props: {
  session: CashRegisterSession;
  canMove: boolean;
  canClose: boolean;
  movement: { type: string; amount: string; notes: string };
  closingAmount: string;
  onMovementChange: (value: { type: string; amount: string; notes: string }) => void;
  onClosingAmount: (value: string) => void;
  onAddMovement: () => void;
  onCloseSession: () => void;
}) {
  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <MetricCard label="Fondo inicial" value={money(props.session.opening_base_amount ?? 0)} />
          <MetricCard
            label="Esperado"
            value={money(props.session.expected_base_amount ?? 0)}
            tone="success"
          />
        </div>
        <PanelCard
          eyebrow="Turno activo"
          title={props.session.cash_register?.name ?? 'Caja POS'}
          description={`Sesion #${props.session.id} abierta para venta directa.`}
        >
          <div className="grid gap-2 text-sm sm:grid-cols-2">
            <InfoLine label="Sucursal" value={props.session.branch?.name ?? 'Sin sucursal'} />
            <InfoLine label="Estado" value={props.session.status} />
          </div>
        </PanelCard>
      </div>
      {props.canMove && (
        <PanelCard
          eyebrow="Caja"
          title="Movimiento extra"
          description="Registra entradas, salidas o ajustes de efectivo fuera de una venta."
        >
          <div className="space-y-3">
            <LabeledControl label="Tipo">
              <Select
                value={props.movement.type}
                onChange={(event) =>
                  props.onMovementChange({ ...props.movement, type: event.target.value })
                }
              >
                <option value="inflow">Entrada</option>
                <option value="outflow">Salida</option>
                <option value="adjustment">Ajuste</option>
              </Select>
            </LabeledControl>
            <LabeledControl label="Monto USD">
              <Input
                type="number"
                min="0"
                value={props.movement.amount}
                onChange={(event) =>
                  props.onMovementChange({ ...props.movement, amount: event.target.value })
                }
                placeholder="Monto USD"
              />
            </LabeledControl>
            <LabeledControl label="Motivo">
              <Input
                value={props.movement.notes}
                onChange={(event) =>
                  props.onMovementChange({ ...props.movement, notes: event.target.value })
                }
                placeholder="Motivo"
              />
            </LabeledControl>
            <Button className="h-11 w-full" onClick={props.onAddMovement}>
              Registrar movimiento
            </Button>
          </div>
        </PanelCard>
      )}
      {props.canClose && (
        <PanelCard
          eyebrow="Cierre"
          title="Cerrar caja"
          description="Ingresa el efectivo contado para comparar contra el esperado."
        >
          <div className="space-y-3">
            <LabeledControl label="Efectivo contado USD">
              <Input
                type="number"
                min="0"
                value={props.closingAmount}
                onChange={(event) => props.onClosingAmount(event.target.value)}
                placeholder="Efectivo contado USD"
              />
            </LabeledControl>
            <Button className="h-11 w-full" variant="outline" onClick={props.onCloseSession}>
              Cerrar turno
            </Button>
          </div>
        </PanelCard>
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
  if (!order) return <p className="text-text-muted text-sm">Aun no hay recibo en esta sesion.</p>;
  const digitalJob = jobs.find((job) => job.output === 'digital');
  return (
    <div className="space-y-3">
      <div className="border-success/20 from-success/15 to-surface rounded-2xl border bg-gradient-to-br p-5 shadow-sm">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-success text-xs font-semibold tracking-wide uppercase">
              Venta completada
            </p>
            <p className="mt-1 text-2xl font-bold">Orden POS #{order.id}</p>
            <p className="text-text-muted mt-1 text-sm">
              {order.customer_name ?? 'Consumidor Final'}
            </p>
          </div>
          <Badge variant={order.status === 'paid' ? 'success' : 'info'}>{order.status}</Badge>
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <div className="border-border bg-surface rounded-xl border p-3">
            <p className="text-text-muted text-xs">Total</p>
            <p className="text-xl font-bold">{money(order.total_base_amount ?? 0)}</p>
          </div>
          <div className="border-border bg-surface rounded-xl border p-3">
            <p className="text-text-muted text-xs">Pagado</p>
            <p className="text-success text-xl font-bold">{money(order.paid_base_amount ?? 0)}</p>
          </div>
          <div className="border-border bg-surface rounded-xl border p-3">
            <p className="text-text-muted text-xs">Balance</p>
            <p className="text-xl font-bold">
              {money(
                Math.max(
                  0,
                  Number(order.total_base_amount ?? 0) - Number(order.paid_base_amount ?? 0),
                ),
              )}
            </p>
          </div>
        </div>
      </div>
      {jobs.length > 0 && (
        <div className="border-border bg-surface space-y-2 rounded-2xl border p-4 shadow-sm">
          <p className="text-sm font-semibold">Impresion</p>
          {jobs.map((job) => (
            <div
              key={job.id}
              className="border-border bg-bg/50 flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm"
            >
              <span>
                {job.output === 'digital' ? 'Digital' : 'Termica'} #{job.id}
              </span>
              <Badge
                variant={
                  job.status === 'failed'
                    ? 'danger'
                    : job.status === 'printed' || job.status === 'generated'
                      ? 'success'
                      : 'info'
                }
              >
                {job.status}
              </Badge>
            </div>
          ))}
        </div>
      )}
      <div className="grid gap-2 sm:grid-cols-2">
        {canPrint && (
          <Button disabled={busy} onClick={() => onPrint(false)}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />}
            Imprimir
          </Button>
        )}
        {canDigital && (
          <Button
            variant="outline"
            disabled={busy}
            onClick={() => (digitalJob ? onOpenPdf(digitalJob) : onPrint(false, 'digital'))}
          >
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
        <div className="border-border bg-surface space-y-1 rounded-2xl border p-3 shadow-sm">
          <p className="text-text-muted px-1 text-xs font-semibold uppercase">Recibos recientes</p>
          {history
            .filter((item) => item.id !== order.id)
            .slice(0, 5)
            .map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onSelectHistory(item)}
                className="hover:bg-bg/40 flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-left text-sm"
                data-testid={`history-receipt-${item.id}`}
              >
                <span className="font-mono">#{item.id}</span>
                <span className="text-text-muted truncate">
                  {item.customer_name ?? 'Consumidor Final'}
                </span>
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
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="border-border rounded-[1.75rem] border bg-gradient-to-br from-[#17112f] to-[#2f238f] p-6 text-white shadow-lg shadow-slate-900/10">
        <p className="text-sm font-semibold tracking-wide text-white/60 uppercase">Saldo a CxC</p>
        <p className="mt-2 text-5xl font-bold tracking-tight">{money(balance)}</p>
        <div className="mt-6 grid gap-3 sm:grid-cols-2">
          <div className="rounded-2xl border border-white/10 bg-white/10 p-4">
            <p className="text-xs text-white/55">Total venta</p>
            <p className="mt-1 text-xl font-semibold">{money(props.total)}</p>
          </div>
          <div className="rounded-2xl border border-white/10 bg-white/10 p-4">
            <p className="text-xs text-white/55">Pagado ahora</p>
            <p className="mt-1 text-xl font-semibold">{money(props.paid)}</p>
          </div>
        </div>
        <p className="mt-5 text-sm leading-6 text-white/70">
          Lo pagado ahora entra a caja; el saldo queda pendiente para cobranza.
        </p>
      </div>
      <PanelCard
        eyebrow="Credito"
        title="Datos de cobranza"
        description="Define cliente y vencimiento antes de enviar a CxC."
      >
        {!props.customer ? (
          <div className="border-warning bg-warning/10 text-warning rounded-2xl border p-4 text-sm">
            La venta a credito requiere un cliente registrado.
            <Button className="mt-3 w-full" variant="outline" onClick={props.onCustomer}>
              Asignar cliente
            </Button>
          </div>
        ) : (
          <div className="border-primary/20 bg-primary/5 rounded-2xl border p-4">
            <p className="text-primary text-xs font-semibold tracking-wide uppercase">Cliente</p>
            <p className="mt-1 font-semibold">{props.customer.name}</p>
            <p className="text-text-muted text-sm">
              {customerDocument(props.customer) ?? 'Sin documento'}
            </p>
          </div>
        )}
        <div className="mt-3 space-y-3">
          <LabeledControl label="Vencimiento opcional" htmlFor="credit-due-date">
            <Input
              id="credit-due-date"
              type="date"
              value={props.dueDate}
              onChange={(event) => props.onDueDate(event.target.value)}
            />
          </LabeledControl>
          <Button
            className="h-11 w-full"
            disabled={!props.canCredit || !props.customer || balance <= 0 || props.busy}
            onClick={props.onConfirm}
          >
            {props.busy && <Loader2 className="size-4 animate-spin" />}
            Confirmar venta a credito
          </Button>
        </div>
      </PanelCard>
    </div>
  );
}

function PanelCard({
  eyebrow,
  title,
  description,
  action,
  children,
  className,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section
      className={cn('border-border bg-surface rounded-[1.5rem] border p-4 shadow-sm', className)}
    >
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          {eyebrow ? (
            <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">
              {eyebrow}
            </p>
          ) : null}
          <h3 className="mt-1 text-lg font-semibold tracking-tight">{title}</h3>
          {description ? <p className="text-text-muted mt-1 text-sm">{description}</p> : null}
        </div>
        {action ? <div className="shrink-0">{action}</div> : null}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

function LabeledControl({
  label,
  htmlFor,
  children,
}: {
  label: string;
  htmlFor?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label
        htmlFor={htmlFor}
        className="text-text-muted mb-1.5 block text-[10px] font-semibold tracking-wide uppercase"
      >
        {label}
      </label>
      {children}
    </div>
  );
}

function MetricCard({
  label,
  value,
  tone = 'default',
}: {
  label: string;
  value: string;
  tone?: 'default' | 'success';
}) {
  return (
    <div className="border-border bg-surface rounded-[1.5rem] border p-4 shadow-sm">
      <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">{label}</p>
      <p className={cn('mt-2 text-2xl font-bold', tone === 'success' && 'text-success')}>{value}</p>
    </div>
  );
}

function InfoLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-border bg-bg/50 rounded-2xl border px-3 py-2">
      <p className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">{label}</p>
      <p className="mt-1 truncate text-sm font-semibold">{value}</p>
    </div>
  );
}

function EmptyPanelState({
  icon,
  title,
  description,
}: {
  icon: React.ReactNode;
  title: string;
  description: string;
}) {
  return (
    <div className="border-border bg-surface flex min-h-64 items-center justify-center rounded-[1.75rem] border border-dashed p-8 text-center shadow-sm">
      <div>
        <div className="bg-primary/10 text-primary mx-auto flex size-14 items-center justify-center rounded-2xl">
          {icon}
        </div>
        <p className="mt-4 font-semibold">{title}</p>
        <p className="text-text-muted mx-auto mt-1 max-w-sm text-sm">{description}</p>
      </div>
    </div>
  );
}

function AmountRow({
  label,
  value,
  muted = false,
  currency = 'USD',
}: {
  label: string;
  value: number;
  muted?: boolean;
  currency?: CurrencyCode;
}) {
  return (
    <div className={cn('flex items-center justify-between', muted && 'text-text-muted')}>
      <span>{label}</span>
      <span className="font-medium">
        {currency === 'VES' ? `Bs ${roundMoney(value).toFixed(2)}` : money(value)}
      </span>
    </div>
  );
}

function ShortcutText({ label, text }: { label: string; text: string }) {
  return (
    <span className="inline-flex items-center gap-1">
      <kbd className="text-text-primary font-semibold">{label}</kbd>
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
  if (input.hasPriceIssue)
    return input.priceIssue ?? 'Hay productos sin precio para la lista seleccionada.';
  if (input.hasStockIssue) return 'Hay productos con stock insuficiente. La venta esta bloqueada.';
  if (input.serialIssue) return input.serialIssue;
  if (input.paymentCount === 0) return 'Agrega al menos una linea de pago.';
  if (input.paymentSetupIssue) return input.paymentSetupIssue;
  if (input.remaining > 0)
    return `Falta capturar ${money(input.remaining)} para completar el pago.`;

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
  allowedPaymentMethods: { id: number }[],
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
  methods: { id: number; name: string; requires_reference?: boolean }[],
): string | null {
  for (const [index, payment] of payments.entries()) {
    const line = index + 1;
    const configured = methods.find((method) => method.id === payment.payment_method_id);
    if (!payment.payment_method_id)
      return `Selecciona un metodo configurado en la linea de pago ${line}.`;
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
  rates: {
    exchange_rate_type_id: number;
    exchange_rate_type_code?: string | null;
    rate: number;
    base_currency?: string;
    quote_currency?: string;
  }[],
  rateTypes: { id: number; is_default?: boolean; is_active?: boolean; code: string }[],
): { amountLabel: string; detail: string } {
  const remaining = Math.max(0, total - calculatePaymentTotals(payments, total).paid);
  const rate = bestActiveRate(rates, rateTypes);
  const currency = method.currency_mode === 'VES' ? 'VES' : 'USD';
  const amount = paymentAmountForCurrency(remaining, currency, rate?.rate ?? null);
  const amountLabel = currency === 'VES' ? `Bs ${amount.toFixed(2)}` : money(amount);
  const detail =
    currency === 'VES'
      ? `${rate?.code ?? 'Tasa'}${rate?.rate ? ` @ ${rate.rate}` : ' sin valor activo'}`
      : methodLabel(method.method);

  return { amountLabel, detail };
}

function bestActiveRate(
  rates: {
    exchange_rate_type_id: number;
    exchange_rate_type_code?: string | null | undefined;
    rate: number;
    base_currency?: string | undefined;
    quote_currency?: string | undefined;
    effective_at?: string | null | undefined;
  }[],
  rateTypes: { id: number; code?: string; is_default?: boolean; is_active?: boolean }[],
): { exchange_rate_type_id: number; code: string; rate: number } | null {
  const validRates = rates.filter(
    (rate) =>
      Number(rate.rate) > 0 &&
      (!rate.base_currency || rate.base_currency === 'USD') &&
      (!rate.quote_currency || rate.quote_currency === 'VES'),
  );
  if (validRates.length === 0) return null;

  // 1. Preferir siempre el tipo de tasa marcado como is_default=true
  //    (si esta activo). Esto evita que el POS use una tasa vieja o una
  //    que el admin acaba de activar por error.
  const defaultType = rateTypes.find(
    (rateType) => rateType.is_default === true && rateType.is_active !== false,
  );
  if (defaultType) {
    const rate = validRates.find((r) => r.exchange_rate_type_id === defaultType.id);
    if (rate) {
      const type = rateTypes.find((t) => t.id === defaultType.id);
      return {
        exchange_rate_type_id: defaultType.id,
        code: rate.exchange_rate_type_code ?? type?.code ?? 'Tasa',
        rate: Number(rate.rate),
      };
    }
  }

  // 2. Si no hay default, usar la tasa con effective_at mas reciente
  //    (la ultima que el admin registro).
  const sortedByDate = [...validRates].sort((a, b) => {
    const dateA = a.effective_at ? new Date(a.effective_at).getTime() : 0;
    const dateB = b.effective_at ? new Date(b.effective_at).getTime() : 0;
    return dateB - dateA;
  });
  const selected = sortedByDate[0];
  if (!selected) return null;
  const type = rateTypes.find((rateType) => rateType.id === selected.exchange_rate_type_id);

  return {
    exchange_rate_type_id: selected.exchange_rate_type_id,
    code: selected.exchange_rate_type_code ?? type?.code ?? 'Tasa',
    rate: Number(selected.rate),
  };
}

function paymentAmountForCurrency(
  remainingBase: number,
  currency: CurrencyCode,
  rate?: number | null,
): number {
  if (currency === 'VES') return roundMoney(remainingBase * Number(rate ?? 0));
  return roundMoney(remainingBase);
}

function methodLabel(method?: string | null): string {
  return PAYMENT_METHODS.find((item) => item.value === method)?.label ?? method ?? 'Pago';
}

function optionalText(value?: string | null): string | null {
  const trimmed = value?.trim();
  if (!trimmed) return null;
  return trimmed;
}

function customerDocument(customer: Customer | null): string | null {
  if (!customer) return null;
  if (customer.document_type && customer.document_number)
    return `${customer.document_type}-${customer.document_number}`;
  return customer.tax_id ?? null;
}

function primaryProductImage(product: Product) {
  return product.images?.find((image) => image.is_primary) ?? product.images?.[0];
}

function productImageSrc(product: Product): string | null {
  const image = primaryProductImage(product);
  return image?.thumb_url ?? product.primary_image_url ?? product.image_url ?? null;
}

export function resolvePaymentMethods(
  configured: PaymentMethod[],
  fallback: PaymentMethod[],
): PaymentMethod[] {
  return (configured.length > 0 ? configured : fallback)
    .filter((method) => method.is_active !== false)
    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name));
}

function money(value: number): string {
  return `$${roundMoney(Number(value || 0)).toFixed(2)}`;
}

function formatLocalNumber(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'No se pudo completar la accion.';
}
