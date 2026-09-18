import { useCallback, useDeferredValue, useEffect, useMemo, useRef, useState } from 'react';
import {
  loadPersistedPriceListPreference,
  savePersistedPriceListPreference,
  usePosCartStore,
  usePosCartPersistence,
  type Panel,
} from './cartStore';
import { Link, useNavigate } from '@tanstack/react-router';
import {
  ArrowDownToLine,
  ArrowLeft,
  ArrowUpFromLine,
  CircleCheck,
  ClipboardList,
  Coins,
  CreditCard,
  Eye,
  EyeOff,
  FileText,
  Gift,
  Loader2,
  Minus,
  PauseCircle,
  Plus,
  Printer,
  Receipt,
  RotateCcw,
  Search,
  SlidersHorizontal,
  Tag,
  Trash2,
  UserRound,
  Wallet,
  ChevronDown,
  X,
} from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/DropdownMenu';
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
import { PosShell, type PosShellAction, type PosShellContext } from '@/components/layout/PosShell';
import { PERMISSIONS } from '@/permissions/constants';
import { usePermissionContext } from '@/permissions/PermissionContext';
import { useSessionStore } from '@/stores/session';
import { useAuth } from '@/auth/useAuth';
import { cn } from '@/lib/cn';
import { createClientId } from '@/lib/clientId';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { ProductImage as ProductImageView } from '@/features/inventory-center/components/ProductImage';
import {
  usePosCombos,
  usePosInvoicePromotions,
  usePosProductOffers,
} from '@/features/promotions/api';
import { isInvoiceDiscountType, type Promotion } from '@/features/promotions/schemas';
import { PromotionsPanel } from './PromotionsPanel';
import { InvoicePromotionDecisionPanel } from './InvoicePromotionDecisionPanel';
import { VariantPicker } from './VariantPicker';
import { ProductSearchDetailModal } from './ProductSearchDetailModal';
import { TicketPreviewDialog } from './TicketPreviewDialog';
import {
  DenominationGrid,
  cashCountTotals,
  closeDifference,
  hasDifference,
  localMoney,
  type CloseForm,
} from './CashRegisterSetup';
import { QuotationCreateDialog } from '@/features/quotations/QuotationCreateDialog';
import { QuotationPickerDialog } from '@/features/quotations/QuotationPickerDialog';
import { getProductVariants } from '@/features/inventory-center/variantApi';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';
import { APP_MODE } from '@/config/branding';
import {
  type CashRegisterSession,
  type CheckoutPayload,
  type CreateCustomerPayload,
  type Customer,
  type HoldPayload,
  type PosOrder,
  type PosPaymentMethod,
  type PaymentMethod,
  type PosProductQuote,
  type ProductSerial,
  quoteProductForPos,
  useAddCashMovement,
  useAddPosPayments,
  useAvailableProductSerialsForPos,
  useCancelPosOrder,
  useCheckout,
  useHoldOrder,
  useCloseCashSession,
  useCreateCustomerForPos,
  useCustomers,
  mergePosExchangeRates,
  mergePosExchangeRateTypes,
  mergePosPriceLists,
  resolvePosPaymentRate,
  resolvePosOpenSession,
  useBranchesForPos,
  useCashSessions,
  useCashRegisters,
  useCurrentExchangeRatesForPos,
  useExchangeRateTypesForPos,
  getProductForPos,
  lookupProductSerialRequest,
  usePriceListsForPos,
  useOpenCashSession,
  useOpenPosOrders,
  useBootstrapRefsForPos,
  usePaymentMethods,
  usePosBootstrap,
  usePosProductsDebounced,
  fetchPosProductsDirect,
  useSessionOrders,
  useWarehousesForPos,
} from './api';
import { useCustomerCredit } from '@/features/customers/api';
import { useCompleteSalesReturnExchange } from '@/features/sales-returns/api';
import {
  calculateCartTotals,
  calculatePaymentTotals,
  formatCartUnitPrice,
  formatUnitSuffix,
  clampQuantity,
  expandPromotionItems,
  hasStockIssue,
  firstPriceIssue,
  hasPriceIssue,
  lineTotal,
  missingSerialIssue,
  paymentBaseAmount,
  findMatchingVariantLine,
  requiresPosVariantSelection,
  resolveInitialPosPriceListId,
  promotionLineUnitPrice,
  invoicePromotionPaymentIssue,
  type CurrencyCode,
  type DiscountType,
  type PosCartLine,
  type PosPaymentLine,
  roundMoney,
  resolvePendingPromotionTotal,
  shouldApplyInvoicePromotion,
} from './posLogic';
import { countPendingOrders, newPendingOrderIds } from './pendingBadge';
import { TapButton } from './TapButton';
import { isTouchPrimaryDevice, shouldAutoFocusSearch } from './touchSupport';
import {
  type PrintJob,
  downloadTicketPdf,
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
  { value: 'customer_credit', label: 'Saldo a favor' },
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

/**
 * Decide si un `pointerdown` debe contar como seleccion/accion en el POS.
 *
 * En tablets, un `pointerdown` sobre un boton es mas confiable que `click`:
 * cuando el teclado virtual esta abierto (p. ej. por el input de busqueda),
 * el PRIMER tap solo cierra el teclado y `click` no se dispara. Usar
 * `pointerdown` (que se emite al tocar, antes del blur del teclado) evita
 * ese "doble tap" y hace que el POS responda al instante en tactil.
 *
 * Para no romper el flujo de mouse (seleccion por hover + Enter), solo
 * tratamos como accion los punteros tactiles (pointerType touch/pen). Un
 * click de mouse sigue pasando por el `onClick` normal.
 */
export function isTouchPointer(event: { pointerType?: string }): boolean {
  return event.pointerType === 'touch' || event.pointerType === 'pen';
}

/**
 * Handler de boton apto para tablet: si el evento es tactil (touch/pen),
 * ejecuta la accion de inmediato y hace `preventDefault()` para que el
 * navegador NO dispare luego el `click` sintetico (evita el doble disparo).
 * Para mouse devuelve false y deja que el `onClick` normal del boton actue.
 */
export function triggerPosPointerAction(
  event: { pointerType?: string; preventDefault?: () => void },
  action: () => void,
): boolean {
  if (!isTouchPointer(event)) return false;
  event.preventDefault?.();
  action();
  return true;
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
  if (key === 'F10') {
    // F10 es la tecla de función física dedicada a "Cobrar / Confirmar venta".
    // A diferencia de Enter, F10 NO es un caracter imprimible ni de navegación,
    // por lo que debe funcionar tanto en la pantalla principal (panel null)
    // como en la pantalla de cobro (panel pay), incluso si el cursor está en el
    // buscador de productos o en un campo de texto/monto.
    return input.panel === null || input.panel === 'pay';
  }

  if (key === 'Enter') {
    return shouldTriggerPosCheckoutOnEnter(input);
  }

  return false;
}

export interface PosShellContextInput {
  tenantName: string;
  branchName: string | null;
  warehouseName: string | null;
  cashRegisterName: string | null;
  rateLabel: string | null;
  bootstrapLoading: boolean;
  hasActiveSession: boolean;
  isOnline: boolean;
}

export function buildPosShellContext(input: PosShellContextInput): PosShellContext {
  return {
    tenantName: input.tenantName,
    branchName: input.branchName,
    warehouseName: input.warehouseName,
    cashRegisterName: input.cashRegisterName,
    rateLabel: input.rateLabel,
    sessionStatus: input.bootstrapLoading ? 'loading' : input.hasActiveSession ? 'open' : 'closed',
    syncStatus: input.isOnline ? 'online' : 'offline',
  };
}

export interface PosShellActionCallbacks {
  onOpenCash: () => void;
  onOpenPending: () => void;
  onOpenReceipt: () => void;
}

export function buildPosShellActions(
  callbacks: PosShellActionCallbacks,
  sellerOnlyMode = false,
  pendingCount = 0,
  pendingAlert = false,
): PosShellAction[] {
  const actions: PosShellAction[] = [
    {
      id: 'pending',
      label: 'Pendientes',
      permission: PERMISSIONS.POS_VIEW,
      onClick: callbacks.onOpenPending,
      badge: pendingCount,
      alert: pendingAlert,
      icon: <ClipboardList className="size-4" aria-hidden="true" />,
    },
    {
      id: 'receipt',
      label: 'Recibo',
      permission: PERMISSIONS.POS_VIEW,
      onClick: callbacks.onOpenReceipt,
      icon: <Receipt className="size-4" aria-hidden="true" />,
    },
  ];

  if (!sellerOnlyMode) {
    // Caja agrupa todo lo del cash register: abrir sesion, movimientos extra
    // y cerrar turno (el CashPanel ya ofrece esas secciones). Antes existia
    // un boton separado 'Cerrar turno' que abria exactamente el mismo panel.
    actions.unshift({
      id: 'cash',
      label: 'Caja',
      permission: PERMISSIONS.CASH_REGISTER_VIEW,
      onClick: callbacks.onOpenCash,
      icon: <Wallet className="size-4" aria-hidden="true" />,
    });
  }

  return actions;
}

export function formatPosRateLabel(
  rate: { code: string; name?: string; rate: number } | null,
): string {
  if (!rate) return 'Sin tasa activa';
  const typeLabel =
    rate.name && rate.name !== rate.code ? `${rate.name} (${rate.code})` : (rate.name ?? rate.code);
  return `${typeLabel} @ ${formatLocalNumber(rate.rate)}`;
}

export function resolvePosProductPrice(
  product: Product,
  selectedPriceList: PriceList | null,
): number {
  const basePrice = Number(product.base_price ?? 0);
  if (!selectedPriceList) return basePrice;

  // Si el producto tiene precios cargados en prices (relacion cargada con with_prices: 1)
  if (product.prices && product.prices.length > 0) {
    const found = product.prices.find(
      (p) =>
        (p.price_list_id === selectedPriceList.id || p.price_list?.id === selectedPriceList.id) &&
        (p.is_active ?? true),
    );
    if (found && found.price != null && Number(found.price) > 0) {
      return Number(found.price);
    }
  }

  // Si la lista de precios aplica un margen sobre el precio base
  if (selectedPriceList.markup_percentage && basePrice > 0) {
    return Math.round(basePrice * (1 + Number(selectedPriceList.markup_percentage) / 100) * 100) / 100;
  }

  return basePrice;
}

export interface SearchPanelAction {
  setProductSearch: (value: string) => void;
  setPanel: (panel: 'product-search') => void;
}

/**
 * Handler de una sugerencia del buscador del POS.
 *
 * Abre el panel de busqueda completo (mismo flujo que F3 / "Ver todos") en
 * lugar de agregar directo al carrito. Razon: los productos con variantes o
 * color necesitan el VariantPicker, y agregar directo desde el dropdown
 * fallaba en tablets. Desde el panel, el usuario toca el producto y el
 * VariantPicker se abre de forma fiable.
 */
export function openSearchFromSuggestion(query: string, action: SearchPanelAction): void {
  action.setProductSearch(query);
  action.setPanel('product-search');
}

export const POS_LAYOUT_CLASS_NAME = 'flex min-h-0 flex-1 flex-col overflow-hidden';

interface PromotionLoadEntry {
  item: { product_id: number; quantity: number };
  product: Product;
  unitPrice: number;
}

interface PendingPromotionLoad {
  promotion: Promotion;
  sets: number;
  instanceUuid: string;
  entries: PromotionLoadEntry[];
  nextIndex: number;
}

export function PosTerminal() {
  const navigate = useNavigate();
  const { signOut } = useAuth();
  const [exitingPos, setExitingPos] = useState(false);
  const [quotationOpen, setQuotationOpen] = useState(false);
  const [quotationsOpen, setQuotationsOpen] = useState(false);
  const { permissions } = usePermissionContext();
  const tenantName = useSessionStore((state) => state.tenant?.name ?? 'Empresa actual');
  const canView = permissions.has(PERMISSIONS.POS_VIEW);
  const canCheckout = permissions.has(PERMISSIONS.POS_CHECKOUT);
  const canHold = permissions.has(PERMISSIONS.POS_ORDERS_HOLD);
  const canDiscount = permissions.has(PERMISSIONS.POS_DISCOUNT);
  /**
   * Modo vendedor: arma ordenes con pos.orders.hold pero NO cobra
   * (no tiene pos.checkout). No requiere abrir sesion de caja y la UI
   * oculta el cobro/pago.
   */
  const sellerOnlyMode = canHold && !canCheckout;
  // Vendedor (pos.orders.hold sin pos.checkout): se redirige a la pantalla
  // tactil de armar orden, que no requiere caja. El POS de cajero se
  // conserva intacto en /pos.
  useEffect(() => {
    if (sellerOnlyMode) {
      void navigate({ to: '/pos/armar' });
    }
  }, [sellerOnlyMode, navigate]);
  const canViewPromotions = permissions.has(PERMISSIONS.POS_PROMOTIONS_VIEW);
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
  const confirmPaidSaleRef = useRef<(() => Promise<void>) | null>(null);
  const isHandlingBarcodeEnterRef = useRef(false);
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
  const selectedPromotion = usePosCartStore((s) => s.selectedPromotion);
  const selectedInvoicePromotion = usePosCartStore((s) => s.selectedInvoicePromotion);
  const comboApplications = usePosCartStore((s) => s.comboApplications);
  const productOfferApplications = usePosCartStore((s) => s.productOfferApplications);
  const exchangeDraft = usePosCartStore((s) => s.exchangeDraft);
  const exchangeReturnId = usePosCartStore((s) => s.exchangeReturnId);
  const customerName = usePosCartStore((s) => s.customerName);
  const customerSearch = usePosCartStore((s) => s.customerSearch);
  const setQuery = usePosCartStore((s) => s.setQuery);
  const setProductSearch = usePosCartStore((s) => s.setProductSearch);
  const setPanel = usePosCartStore((s) => s.setPanel);
  const setSerialLineId = usePosCartStore((s) => s.setSerialLineId);
  const setWarehouseId = usePosCartStore((s) => s.setWarehouseId);
  const setSelectedPriceListId = usePosCartStore((s) => s.setSelectedPriceListId);
  const setSelectedCustomer = usePosCartStore((s) => s.setSelectedCustomer);
  const clearAll = usePosCartStore((s) => s.clearAll);
  const setSelectedPromotion = usePosCartStore((s) => s.setSelectedPromotion);
  const setSelectedInvoicePromotion = usePosCartStore((s) => s.setSelectedInvoicePromotion);
  const clearSelectedInvoicePromotion = usePosCartStore((s) => s.clearSelectedInvoicePromotion);
  const addComboApplication = usePosCartStore((s) => s.addComboApplication);
  const addProductOfferApplication = usePosCartStore((s) => s.addProductOfferApplication);
  const clearComboApplications = usePosCartStore((s) => s.clearComboApplications);
  const clearProductOfferApplications = usePosCartStore((s) => s.clearProductOfferApplications);
  const setExchangeDraft = usePosCartStore((s) => s.setExchangeDraft);
  const setExchangeReturnId = usePosCartStore((s) => s.setExchangeReturnId);
  const setCustomerName = usePosCartStore((s) => s.setCustomerName);
  const setCustomerSearch = usePosCartStore((s) => s.setCustomerSearch);
  const removeLine = usePosCartStore((s) => s.removeLine);
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
  const [previewOrder, setPreviewOrder] = useState<PosOrder | null>(null);
  const [selectedPending, setSelectedPending] = useState<PosOrder | null>(null);
  const [promotionView, setPromotionView] = useState<'all' | 'invoice' | 'combo' | 'product_offer'>(
    'all',
  );
  const [invoicePromotionAction, setInvoicePromotionAction] = useState<
    'validate' | 'reject' | null
  >(null);
  const [cashSessionClosed, setCashSessionClosed] = useState(false);
  const [cashSessionOpening, setCashSessionOpening] = useState(false);
  const [openingBaseAmount, setOpeningBaseAmount] = useState('0');
  const [openingLocalAmount, setOpeningLocalAmount] = useState('0');
  const [openingBranchId, setOpeningBranchId] = useState<number | ''>('');
  const [openingRegisterId, setOpeningRegisterId] = useState<number | ''>('');
  const [cashMovement, setCashMovement] = useState({ type: 'outflow', amount: '', notes: '' });
  const [cashCloseForm, setCashCloseForm] = useState<CloseForm>({
    sessionId: null,
    usd: '',
    ves: '',
    notes: '',
    counts: [],
    blind: true,
  });
  const [creditDueDate, setCreditDueDate] = useState('');
  const [variantPickerProduct, setVariantPickerProduct] = useState<Product | null>(null);
  const [variantPickerQuantity, setVariantPickerQuantity] = useState(1);
  // Contexto de promocion propagado al VariantPicker cuando la linea viene de
  // una promocion cargada (precio de promocion + ref), para que al elegir el
  // color la linea mantenga el valor de la promocion.
  const [variantPickerPromotion, setVariantPickerPromotion] = useState<{
    price: number;
    ref: { id: number; code?: string | null; benefitType?: string } | null;
    comboInstanceUuid?: string | null;
  } | null>(null);
  const [pendingPromotionLoad, setPendingPromotionLoad] =
    useState<PendingPromotionLoad | null>(null);
  const [serialSearch, setSerialSearch] = useState('');
  const [isOnline, setIsOnline] = useState(() =>
    typeof navigator === 'undefined' ? true : navigator.onLine,
  );
  const deferredSerialSearch = useDeferredValue(serialSearch);

  async function exitPos(): Promise<void> {
    setExitingPos(true);
    try {
      if (APP_MODE === 'pos') {
        await signOut();
        await navigate({ to: '/login' });
      } else {
        if (typeof window !== 'undefined' && window.history.length > 1) {
          window.history.back();
        } else {
          await navigate({ to: '/dashboard' });
        }
      }
    } finally {
      setExitingPos(false);
    }
  }

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  const bootstrapRefs = useBootstrapRefsForPos();
  const bootstrap = usePosBootstrap();
  const bootstrapReady = !bootstrap.isLoading && !bootstrap.isError;

  // Fallback: si /api/pos/bootstrap no devolvio warehouses (cache vacio o
  // query fallo), consultamos /api/warehouses directo. Esto evita que el
  // selector quede vacio y los queries de productos fallen con warehouseId=null.
  // Sprint POS 5 fix: antes si bootstrap fallaba, warehouses quedaba vacio.
  const bootstrapHasWarehouses = (bootstrapRefs.refs?.warehouses?.length ?? 0) > 0;
  const { data: standaloneWarehouses } = useWarehousesForPos();
  const { data: standaloneBranches = [] } = useBranchesForPos();
  const { data: standaloneCashRegisters = [] } = useCashRegisters();
  const standaloneWarehousesList = useMemo(
    () => standaloneWarehouses ?? [],
    [standaloneWarehouses],
  );

  const warehouses: {
    id: number;
    code: string;
    name: string;
    branch_id: number | null;
    is_default?: boolean;
  }[] = useMemo(() => {
    if (bootstrapHasWarehouses) {
      return bootstrapRefs.refs?.warehouses ?? [];
    }
    return standaloneWarehousesList as {
      id: number;
      code: string;
      name: string;
      branch_id: number | null;
      is_default?: boolean;
    }[];
  }, [bootstrapHasWarehouses, bootstrapRefs.refs?.warehouses, standaloneWarehousesList]);
  const branches = useMemo(
    () => (bootstrapRefs.refs?.branches?.length ? bootstrapRefs.refs.branches : standaloneBranches),
    [bootstrapRefs.refs, standaloneBranches],
  );
  const cashRegisters = useMemo(
    () =>
      bootstrapRefs.refs?.cash_registers?.length
        ? bootstrapRefs.refs.cash_registers
        : standaloneCashRegisters,
    [bootstrapRefs.refs, standaloneCashRegisters],
  );
  const { data: fallbackSessions = [] } = useCashSessions();
  const sessions = useMemo(() => {
    if (bootstrap.data && bootstrap.data.open_session === null) return [];

    const session = resolvePosOpenSession(bootstrap.data?.open_session ?? null, fallbackSessions);
    return session ? [session] : [];
  }, [bootstrap.data, fallbackSessions]);
  const { data: pendingOrders = [] } = useOpenPosOrders();
  // Rastrea los ids ya vistos para detectar ordenes nuevas entre pollings
  // y mostrar la alerta sin molestar por cada refetch.
  const seenPendingIdsRef = useRef<Set<number>>(new Set());
  const [pendingAlert, setPendingAlert] = useState<number[]>([]);
  useEffect(() => {
    if (pendingOrders.length === 0) return;
    const currentIds = pendingOrders.map((order) => order.id);
    const fresh = newPendingOrderIds(
      Array.from(seenPendingIdsRef.current),
      pendingOrders.map((order) => ({ id: order.id })),
    );
    if (fresh.length > 0) {
      seenPendingIdsRef.current = new Set(currentIds);
      setPendingAlert(fresh);
      if (panel !== 'hold') {
        toast.info(
          fresh.length === 1
            ? `Nueva orden pendiente #${fresh[0]}. Revisa Pendientes.`
            : `${fresh.length} ordenes pendientes nuevas. Revisa Pendientes.`,
        );
      }
    } else {
      // Sin nuevas: solo amplia el set conocido para no repetir alertas.
      seenPendingIdsRef.current = new Set(currentIds);
    }
  }, [pendingOrders, panel]);
  const pendingCount = countPendingOrders(pendingOrders);
  // True mientras haya una alerta de orden nueva que la cajera no ha visto.
  const hasPendingAlert = pendingAlert.length > 0;
  const { data: customerResults = [] } = useCustomers(customerSearch);
  const { data: customerCredit } = useCustomerCredit(selectedCustomer?.id ?? null);
  const effectiveWarehouseId =
    warehouses.find((w) => w.id === warehouseId)?.id ??
    warehouses.find((w) => w.is_default)?.id ??
    warehouses[0]?.id ??
    null;
  const activeProductSearch = panel === 'product-search' ? productSearch : query;
  const shouldSearchProducts = activeProductSearch.trim().length >= 2;
  const {
    data: productPage,
    isLoading: loadingProducts,
    debouncedSearch,
  } = usePosProductsDebounced(activeProductSearch, effectiveWarehouseId, {
    enabled: shouldSearchProducts,
  });
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
  const activeRate = useMemo(
    () =>
      resolvePosPaymentRate(
        currentRates,
        exchangeRateTypes,
        selectedPriceList?.payment_exchange_rate_type_id,
      ),
    [currentRates, exchangeRateTypes, selectedPriceList?.payment_exchange_rate_type_id],
  );
  const allowedPaymentMethods = useMemo(
    () => filterPaymentMethodsForPriceList(activePaymentMethods, selectedPriceList),
    [activePaymentMethods, selectedPriceList],
  );
  const priceListMethodIssue = getPriceListPaymentIssue(selectedPriceList, allowedPaymentMethods);
  const priceListRateIssue =
    selectedPriceList?.payment_exchange_rate_type_id && !activeRate
      ? `La tasa configurada para ${selectedPriceList.name} no tiene un valor USD/VES activo.`
      : null;
  const priceListPaymentIssue = priceListMethodIssue ?? priceListRateIssue;
  const checkout = useCheckout();
  const holdOrder = useHoldOrder();
  const completeExchange = useCompleteSalesReturnExchange();
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

  useEffect(() => {
    if (activeSession) setCashSessionOpening(false);
  }, [activeSession]);

  // Persistencia del carrito: hidrata desde sessionStorage al montar y
  // sincroniza cambios al store de vuelta. Solo persiste cuando hay
  // contenido (lineas o pagos) y se key-a por tenant+cashier para
  // evitar colisiones entre sesiones distintas.
  usePosCartPersistence(
    activeSession ? Number(activeSession.tenant_id) : null,
    activeSession ? Number(activeSession.cashier_id) : null,
  );

  useEffect(() => {
    if (!activeSession || priceLists.length === 0 || cart.length > 0 || payments.length > 0) return;

    const persistedPreference = loadPersistedPriceListPreference(
      Number(activeSession.tenant_id),
      Number(activeSession.cashier_id),
    );
    const initialPriceListId = resolveInitialPosPriceListId(priceLists, persistedPreference);

    if (initialPriceListId !== selectedPriceListId) {
      setSelectedPriceListId(initialPriceListId);
    }
  }, [
    activeSession,
    cart.length,
    payments.length,
    priceLists,
    selectedPriceListId,
    setSelectedPriceListId,
  ]);
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
  const canThermalPrint =
    canPrint && !!activePrinterStation && activePrinterStation.output_mode !== 'digital';
  const selectedWarehouse =
    warehouses.find((warehouse) => warehouse.id === warehouseId) ?? warehouses[0] ?? null;
  const cartProductIds = useMemo(() => cart.map((line) => line.product_id), [cart]);
  const promotionQuery = {
    warehouseId: selectedWarehouse?.id ?? null,
    productIds: cartProductIds,
    selectable: true,
  };
  const availableInvoicePromotions = usePosInvoicePromotions(promotionQuery);
  const availableCombos = usePosCombos(promotionQuery);
  const availableProductOffers = usePosProductOffers(promotionQuery);
  const promotionsLoading =
    availableInvoicePromotions.isLoading ||
    availableCombos.isLoading ||
    availableProductOffers.isLoading;
  const promotionsError =
    availableInvoicePromotions.isError || availableCombos.isError || availableProductOffers.isError;
  useEffect(() => {
    const selectedComponentsPresent = selectedInvoicePromotion
      ? selectedInvoicePromotion.items.length === 0
        ? true
        : selectedInvoicePromotion.items.every((item) => cartProductIds.includes(item.product_id))
      : false;
    if (selectedInvoicePromotion && (!selectedComponentsPresent || cartProductIds.length === 0)) {
      clearSelectedInvoicePromotion();
    }
  }, [cartProductIds, clearSelectedInvoicePromotion, selectedInvoicePromotion]);
  const shellContext = buildPosShellContext({
    tenantName,
    branchName: activeSession?.branch?.name ?? null,
    warehouseName: selectedWarehouse?.name ?? null,
    cashRegisterName: activeSession?.cash_register?.name ?? null,
    rateLabel: activeRate ? formatPosRateLabel(activeRate) : null,
    bootstrapLoading: bootstrap.isLoading,
    hasActiveSession: Boolean(activeSession),
    isOnline,
  });
  const shellActions = buildPosShellActions(
    {
      onOpenCash: () => setPanel('cash'),
      onOpenPending: () => {
        setPendingAlert([]);
        setPanel('hold');
      },
      onOpenReceipt: () => setPanel('receipt'),
    },
    sellerOnlyMode,
    pendingCount,
    hasPendingAlert,
  );
  const serialLine = cart.find((line) => line.id === serialLineId) ?? null;
  const { data: availableSerials = [], isLoading: loadingSerials } =
    useAvailableProductSerialsForPos(
      serialLine?.product_id ?? null,
      serialLine?.warehouse_id ?? null,
      deferredSerialSearch,
    );
  useEffect(() => {
    setSerialSearch('');
  }, [panel, serialLineId]);
  const products = useMemo(() => productPage?.data ?? [], [productPage?.data]);
  const quickSearchResults = useMemo(
    () =>
      panel === null &&
      query.trim().length >= 2 &&
      debouncedSearch.trim().toLocaleLowerCase() === query.trim().toLocaleLowerCase()
        ? products.slice(0, 4)
        : [],
    [debouncedSearch, panel, products, query],
  );
  const [quickSearchIndex, setQuickSearchIndex] = useState(0);
  useEffect(() => {
    setQuickSearchIndex(0);
  }, [query, quickSearchResults.length]);
  const pendingInvoicePromotion = useMemo(() => {
    const applications = (
      selectedPending?.sale as { promotion_applications?: unknown[] } | undefined
    )?.promotion_applications;
    return (
      applications?.find(
        (
          application,
        ): application is {
          status?: string;
          promotion_name?: string;
          payment_currency?: string | null;
          base_before_amount?: number | null;
        } =>
          typeof application === 'object' &&
          application !== null &&
          (application as { scope?: string }).scope === 'invoice' &&
          (application as { status?: string }).status === 'requested',
      ) ?? null
    );
  }, [selectedPending]);
  const cartTotals = useMemo(() => {
    const totals = calculateCartTotals(cart);
    if (selectedPending && invoicePromotionAction === 'reject') {
      return {
        ...totals,
        discount: 0,
        total: resolvePendingPromotionTotal(
          totals.total,
          pendingInvoicePromotion?.base_before_amount,
          invoicePromotionAction,
        ),
      };
    }
    if (
      !shouldApplyInvoicePromotion(selectedInvoicePromotion, selectedPending !== null) ||
      !selectedInvoicePromotion ||
      !isInvoiceDiscountType(selectedInvoicePromotion.benefit_type)
    ) {
      return totals;
    }

    const eligibleTotal =
      selectedInvoicePromotion.items.length === 0
        ? totals.total
        : roundMoney(
            cart
              .filter((line) =>
                selectedInvoicePromotion.items.some((item) => item.product_id === line.product_id),
              )
              .reduce((sum, line) => sum + lineTotal(line), 0),
          );
    const invoiceDiscount =
      selectedInvoicePromotion.benefit_type === 'percent_discount'
        ? roundMoney(eligibleTotal * (Number(selectedInvoicePromotion.discount_percent ?? 0) / 100))
        : roundMoney(
            Math.min(eligibleTotal, Number(selectedInvoicePromotion.discount_amount_usd ?? 0)),
          );

    return {
      ...totals,
      discount: roundMoney(totals.discount + invoiceDiscount),
      total: roundMoney(totals.total - invoiceDiscount),
    };
  }, [
    cart,
    invoicePromotionAction,
    pendingInvoicePromotion,
    selectedInvoicePromotion,
    selectedPending,
  ]);

  const totalCartUnits = useMemo(
    () => cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0),
    [cart],
  );

  const paymentTotals = useMemo(
    () => calculatePaymentTotals(payments, cartTotals.total),
    [payments, cartTotals.total],
  );
  const promotionPaymentIssue =
    invoicePromotionAction === 'reject'
      ? null
      : invoicePromotionPaymentIssue(
          pendingInvoicePromotion?.payment_currency ?? selectedInvoicePromotion?.payment_currency,
          payments,
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
    promotionPaymentIssue,
    priceListPaymentIssue,
  });
  const openingRate = activeRate;
  holdSaleRef.current = holdSale;
  confirmPaidSaleRef.current = confirmPaidSale;

  useEffect(() => {
    // Solo auto-setea el almacen cuando NO hay uno valido seleccionado.
    // Antes este efecto forzaba SIEMPRE el primer almacen (o el predeterminado)
    // cuando warehouseId difería, lo que revertia inmediatamente cualquier
    // cambio manual del cajero ("no me deja cambiar de almacen").
    const defaultWh = warehouses.find((w) => w.is_default === true);
    const firstId = defaultWh?.id ?? warehouses[0]?.id;
    const hasValidCurrent =
      typeof warehouseId === 'number' &&
      Number.isFinite(warehouseId) &&
      warehouseId > 0 &&
      warehouses.some((w) => w.id === warehouseId);
    if (
      typeof firstId === 'number' &&
      Number.isFinite(firstId) &&
      firstId > 0 &&
      !hasValidCurrent
    ) {
      setWarehouseId(firstId);
    }
  }, [setWarehouseId, warehouseId, warehouses]);

  useEffect(() => {
    if (!exchangeDraft || cart.length > 0) return;

    setCart([
      {
        id: createClientId(),
        product_id: exchangeDraft.product.id,
        name: exchangeDraft.product.name,
        sku: exchangeDraft.product.sku ?? null,
        barcode: exchangeDraft.product.barcode ?? null,
        warehouse_id: exchangeDraft.warehouseId,
        quantity: exchangeDraft.quantity,
        available_stock: 0,
        unit_price: Number(exchangeDraft.product.base_price ?? 0),
        base_unit_price: Number(exchangeDraft.product.base_price ?? 0),
        currency: 'USD',
        base_currency: 'USD',
        price_list_id: null,
        price_list_name: BASE_PRICE_LIST_LABEL,
        price_issue: null,
        tracking_type: exchangeDraft.product.tracking_type ?? 'quantity',
        unit_of_measure: exchangeDraft.product.unit_of_measure ?? 'unit',
        track_stock: true,
        selected_serials: [],
      },
    ]);
    setWarehouseId(exchangeDraft.warehouseId);
    setSelectedPriceListId(null);
    setQuery(exchangeDraft.product.name);
    setSelectedCustomer(exchangeDraft.customer);
    setCustomerName(exchangeDraft.customer.name);
    usePosCartStore.setState({
      payments: [
        {
          id: `exchange-credit-${Date.now()}`,
          method: 'customer_credit',
          currency: 'USD',
          amount: exchangeDraft.creditAmount,
          payment_method_id: null,
          exchange_rate_type_id: null,
          exchange_rate: null,
          status: 'captured',
        },
      ],
    });
    setExchangeReturnId(exchangeDraft.salesReturnId);
    setExchangeDraft(null);
    toast.info('Canje cargado en el POS. Revisa el faltante y confirma el cobro.');
  }, [
    cart.length,
    exchangeDraft,
    setExchangeDraft,
    setSelectedCustomer,
    setCustomerName,
    setWarehouseId,
    setSelectedPriceListId,
    setQuery,
    setExchangeReturnId,
  ]);

  useEffect(() => {
    if (!exchangeReturnId || cart.length === 0) return;
    const exchangeLine = cart[0];
    if (!exchangeLine) return;
    const refreshedProduct = products.find((product) => product.id === exchangeLine.product_id);
    if (!refreshedProduct) return;

    const availableStock = Number(refreshedProduct.available_stock ?? 0);
    const trackStock = refreshedProduct.track_stock !== false;
    const trackingType = refreshedProduct.tracking_type ?? exchangeLine.tracking_type;

    usePosCartStore.setState((state) => {
      const lines = state.lines.map((line) => {
        if (line.id !== exchangeLine.id) return line;
        if (
          line.available_stock === availableStock &&
          line.track_stock === trackStock &&
          line.tracking_type === trackingType
        )
          return line;

        return {
          ...line,
          available_stock: availableStock,
          track_stock: trackStock,
          tracking_type: trackingType,
        };
      });

      return lines === state.lines || lines.every((line, index) => line === state.lines[index])
        ? state
        : { lines };
    });
  }, [cart, exchangeReturnId, products]);

  useEffect(() => {
    if (branches[0] && openingBranchId === '') setOpeningBranchId(branches[0].id);
  }, [branches, openingBranchId]);

  useEffect(() => {
    if (activeCashRegisters[0] && openingRegisterId === '')
      setOpeningRegisterId(activeCashRegisters[0].id);
  }, [activeCashRegisters, openingRegisterId]);

  const isRefreshingCartRef = useRef(false);

  const refreshCartProducts = useCallback(async () => {
    const currentLines = usePosCartStore.getState().lines;
    if (currentLines.length === 0 || isRefreshingCartRef.current) return;

    isRefreshingCartRef.current = true;
    try {
      const uniqueKeys = Array.from(
        new Set(currentLines.map((l) => `${l.product_id}_${l.warehouse_id}`)),
      );

      const productMap = new Map<string, Product>();
      const quoteMap = new Map<string, PosProductQuote>();

      await Promise.all(
        uniqueKeys.map(async (key) => {
          const [prodIdStr, whIdStr] = key.split('_');
          const pId = Number(prodIdStr);
          const wId = Number(whIdStr) || effectiveWarehouseId || null;
          try {
            const product = await getProductForPos(pId, wId);
            productMap.set(key, product);

            if (selectedPriceListId) {
              try {
                const quote = await quoteProductForPos(pId, selectedPriceListId);
                quoteMap.set(key, quote);
              } catch {
                // Si la lista de precio no tiene cotizacion para este producto, se ignora
              }
            }
          } catch {
            // Ignorar errores puntuales si el producto no responde o fue eliminado
          }
        }),
      );

      if (productMap.size === 0) return;

      usePosCartStore.setState((state) => {
        let changed = false;
        const newLines = state.lines.map((line) => {
          const key = `${line.product_id}_${line.warehouse_id}`;
          const product = productMap.get(key);
          if (!product) return line;

          const quote = quoteMap.get(key);
          const newName = product.name;
          const newSku = product.sku ?? null;
          const newBarcode = product.barcode ?? null;
          const newUom = product.unit_of_measure ?? 'unit';
          const newTrackingType = product.tracking_type ?? null;
          const newTrackStock = product.track_stock !== false;
          const newAvailableStock = Number(product.available_stock ?? 0);
          const newBasePrice = Number(product.base_price ?? 0);
          const newImageUrl =
            product.image_url ?? product.primary_image_url ?? line.image_url ?? null;

          let newUnitPrice = line.unit_price;
          let newCurrency = line.currency;
          if (!line.promotion_id) {
            if (quote && line.price_source === 'price_list') {
              newUnitPrice = quote.base_price_usd;
              newCurrency = quote.sale_currency;
            } else if (line.price_source !== 'price_list' || !line.price_list_id) {
              if (line.base_unit_price === undefined || line.unit_price === line.base_unit_price) {
                newUnitPrice = newBasePrice;
                newCurrency = (product.sale_currency ?? 'USD') as CurrencyCode;
              }
            }
          }

          if (
            line.name === newName &&
            line.sku === newSku &&
            line.barcode === newBarcode &&
            line.unit_of_measure === newUom &&
            line.tracking_type === newTrackingType &&
            line.track_stock === newTrackStock &&
            line.available_stock === newAvailableStock &&
            line.base_unit_price === newBasePrice &&
            line.unit_price === newUnitPrice &&
            line.currency === newCurrency &&
            line.image_url === newImageUrl
          ) {
            return line;
          }

          changed = true;
          return {
            ...line,
            name: newName,
            sku: newSku,
            barcode: newBarcode,
            unit_of_measure: newUom,
            tracking_type: newTrackingType,
            track_stock: newTrackStock,
            available_stock: newAvailableStock,
            base_unit_price: newBasePrice,
            unit_price: newUnitPrice,
            currency: newCurrency,
            image_url: newImageUrl,
          };
        });

        return changed ? { lines: newLines } : state;
      });
    } finally {
      isRefreshingCartRef.current = false;
    }
  }, [effectiveWarehouseId, selectedPriceListId]);

  const cartLength = cart.length;
  const prevCartLengthRef = useRef(0);
  useEffect(() => {
    if (cartLength > 0 && prevCartLengthRef.current === 0) {
      void refreshCartProducts();
    }
    prevCartLengthRef.current = cartLength;
  }, [cartLength, refreshCartProducts]);

  useEffect(() => {
    const handleFocus = () => {
      void refreshCartProducts();
    };
    const handleVisibility = () => {
      if (document.visibilityState === 'visible') {
        void refreshCartProducts();
      }
    };
    window.addEventListener('focus', handleFocus);
    document.addEventListener('visibilitychange', handleVisibility);
    return () => {
      window.removeEventListener('focus', handleFocus);
      document.removeEventListener('visibilitychange', handleVisibility);
    };
  }, [refreshCartProducts]);

  const prevPanelRef = useRef<Panel>(panel);
  useEffect(() => {
    if (prevPanelRef.current !== null && panel === null && cart.length > 0) {
      void refreshCartProducts();
    }
    prevPanelRef.current = panel;
  }, [panel, cart.length, refreshCartProducts]);

  useEffect(() => {
    // En dispositivos tactiles NO re-focalizar el buscador: reabre el
    // teclado virtual, cambia el layout y pierde la accion del tap. En
    // escritorio si conviene para escribir directo.
    if (panel === null && shouldAutoFocusSearch(isTouchPrimaryDevice())) {
      searchRef.current?.focus();
    }
  }, [cart.length, panel]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      const inEditableField = isEditableField(target);
      const inForm = isInForm(target);
      const isSearchInput = target?.dataset.posSearchInput === 'true';

      // F10 siempre debe anular el comportamiento nativo del navegador (evita que Chrome/Edge abra menu bar)
      if (event.key === 'F10') {
        event.preventDefault();
        if (
          shouldTriggerPosCheckoutShortcut(event.key, {
            panel,
            isEditableField: inEditableField,
            isSearchInput,
          })
        ) {
          if (sellerOnlyMode) {
            void holdSaleRef.current?.();
          } else {
            // Si estamos en la pantalla principal con productos y sin pagos, abrir directamente el panel de pago
            if (panel === null && payments.length === 0 && cart.length > 0) {
              if (priceListPaymentIssue) {
                toast.error(priceListPaymentIssue);
              } else {
                setPanel('pay');
              }
            } else {
              void confirmPaidSaleRef.current?.();
            }
          }
        }
        return;
      }

      // F3 siempre debe anular el comportamiento nativo del navegador y abrir el modal desktop de Búsqueda y Detalle
      if (event.key === 'F3') {
        event.preventDefault();
        setProductSearch(query || '');
        setPanel('product-search');
        return;
      }

      if (
        shouldTriggerPosCheckoutShortcut(event.key, {
          panel,
          isEditableField: inEditableField,
          isSearchInput,
        })
      ) {
        event.preventDefault();
        if (sellerOnlyMode) {
          void holdSaleRef.current?.();
        } else {
          void confirmPaidSaleRef.current?.();
        }
        return;
      }

      if (shouldHandlePosGlobalShortcut(event.key, inEditableField)) {
        event.preventDefault();
        switch (event.key) {
          case 'F2': {
            if (sellerOnlyMode) {
              return;
            }
            if (priceListPaymentIssue) {
              toast.error(priceListPaymentIssue);
              return;
            }
            setPanel('pay');
            return;
          }
          case 'F3': {
            setProductSearch(query || '');
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
    sellerOnlyMode,
    priceListPaymentIssue,
    setPanel,
    setProductSearch,
  ]);

  if (!canView) {
    return (
      <PosShell
        context={shellContext}
        actions={shellActions}
        onExit={exitPos}
        exitDisabled={exitingPos}
      >
        <div className="bg-bg flex min-h-[70vh] items-center justify-center">
          <div className="border-border bg-surface max-w-md rounded border p-6 text-center shadow-sm">
            <Wallet className="text-text-muted mx-auto mb-3 size-8" />
            <h1 className="text-lg font-semibold">POS no disponible</h1>
            <p className="text-text-muted mt-2 text-sm">
              Necesitas el permiso pos.view para usar la caja de venta.
            </p>
          </div>
        </div>
      </PosShell>
    );
  }

  if (
    !sellerOnlyMode &&
    (cashSessionClosed ||
      (!activeSession &&
        !cashSessionOpening &&
        !bootstrap.isLoading &&
        !bootstrap.isError &&
        bootstrapReady))
  ) {
    return (
      <PosShell
        context={shellContext}
        actions={shellActions}
        onExit={exitPos}
        exitDisabled={exitingPos}
      >
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
            setCashSessionOpening(true);
            setCashSessionClosed(false);
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
                  setCashSessionOpening(false);
                  setCashSessionClosed(true);
                  void bootstrap.refetch();
                  toast.error(errorMessage(error));
                },
                onSettled: () => setCashSessionClosed(false),
              },
            );
          }}
          busy={openCash.isPending}
        />
      </PosShell>
    );
  }

  return (
    <PosShell
      context={shellContext}
      actions={shellActions}
      onExit={exitPos}
      exitDisabled={exitingPos}
      hideHeader
    >
      <div className={`text-text-primary ${POS_LAYOUT_CLASS_NAME} bg-[#f4f6fb]`}>
        <header className="border-border/80 bg-surface/95 flex shrink-0 flex-wrap items-center justify-between gap-2.5 border-b px-3.5 py-2 shadow-sm backdrop-blur">
          {/* Navegación y Búsqueda */}
          <div className="flex items-center gap-2 flex-1 min-w-0">
            <Button
              variant="outline"
              size="sm"
              onClick={() => void exitPos()}
              title="Volver atrás / Salir del POS"
              className="gap-1.5 shadow-sm font-semibold shrink-0 h-9"
            >
              <ArrowLeft className="size-4" />
              <span className="hidden sm:inline">Volver</span>
            </Button>

            <div className="relative flex-1 min-w-[200px] max-w-xl">
              <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
              <Input
                ref={searchRef}
                value={query}
                onChange={(event) => {
                  setQuery(event.target.value);
                  setQuickSearchIndex(0);
                }}
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
                    if (quickSearchResults.length > 0) {
                      const targetIndex =
                        quickSearchIndex >= 0 && quickSearchIndex < quickSearchResults.length
                          ? quickSearchIndex
                          : 0;
                      const selectedProduct = quickSearchResults[targetIndex];
                      if (selectedProduct) {
                        void addProduct(selectedProduct).then((added) => {
                          if (added) {
                            setQuery('');
                            setQuickSearchIndex(0);
                          }
                        });
                        return;
                      }
                    }
                    void handleProductSearchEnter();
                  }
                }}
                className="h-9 pl-9 pr-8 text-sm"
                placeholder="Escanea código, SKU o producto... [F3]"
                data-pos-search-input="true"
                data-testid="pos-search"
              />
              {query && (
                <button
                  type="button"
                  onClick={() => setQuery('')}
                  className="text-text-muted hover:text-text-primary absolute top-1/2 right-2.5 -translate-y-1/2 p-0.5 rounded"
                  title="Borrar búsqueda"
                >
                  <X className="size-3.5" />
                </button>
              )}
              {!panel &&
                query.trim().length >= 2 &&
                (loadingProducts || quickSearchResults.length > 0) && (
                  <div className="border-border bg-surface absolute top-[calc(100%+8px)] right-0 left-0 z-20 overflow-hidden rounded-2xl border shadow-xl">
                    <div className="border-border text-text-muted flex items-center justify-between border-b px-3 py-2 text-[10px] tracking-wide uppercase">
                      <span>
                        Resultados rápidos
                        {selectedPriceList && (
                          <span className="ml-1.5 text-primary font-semibold normal-case">
                            ({selectedPriceList.name})
                          </span>
                        )}
                      </span>
                      <TapButton
                        onPress={() => {
                          setProductSearch(query);
                          setPanel('product-search');
                        }}
                        className="text-primary font-semibold hover:underline"
                      >
                        Ver todos
                      </TapButton>
                    </div>
                    <div className="max-h-96 overflow-auto p-2">
                      {loadingProducts && quickSearchResults.length === 0 ? (
                        <div className="text-text-muted px-2 py-3 text-sm">
                          Buscando productos...
                        </div>
                      ) : quickSearchResults.length === 0 ? (
                        <div className="text-text-muted px-2 py-3 text-sm">
                          No hay productos con esa búsqueda.
                        </div>
                      ) : (
                        quickSearchResults.map((product, index) => {
                          const itemPriceUsd = resolvePosProductPrice(product, selectedPriceList);
                          const itemPriceVes =
                            activeRate && activeRate.rate > 0
                              ? itemPriceUsd * activeRate.rate
                              : null;

                          return (
                            <TapButton
                              key={product.id}
                              onPress={() => {
                                void addProduct(product).then((added) => {
                                  if (added) {
                                    setQuery('');
                                    setQuickSearchIndex(0);
                                  }
                                });
                              }}
                              onMouseEnter={() => setQuickSearchIndex(index)}
                              className={cn(
                                'hover:bg-primary/8 flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors',
                                index === quickSearchIndex &&
                                  'bg-primary/15 ring-2 ring-primary/50 shadow-sm scale-[1.01]',
                              )}
                            >
                              <ProductImageView
                                image={primaryProductImage(product)}
                                src={productImageSrc(product) ?? undefined}
                                alt={product.name}
                                variant="thumb"
                                className="border-border bg-bg size-12 shrink-0 rounded-lg border"
                              />
                              <div className="min-w-0 flex-1">
                                <p
                                  className={cn(
                                    'truncate text-sm font-bold text-text-primary',
                                    index === quickSearchIndex && 'text-primary',
                                  )}
                                >
                                  {product.name}
                                </p>
                                <p className="text-text-secondary truncate text-xs font-medium mt-0.5">
                                  <span className="font-mono text-text-primary font-bold bg-bg/80 border border-border/80 px-1.5 py-0.2 rounded mr-1">
                                    {product.sku ?? product.barcode ?? 'Sin código'}
                                  </span>
                                  {product.unit_of_measure &&
                                    product.unit_of_measure.toLowerCase() !== 'unit' && (
                                      <span className="ml-1 font-bold text-text-primary uppercase">
                                        · {product.unit_of_measure}
                                      </span>
                                    )}
                                </p>
                              </div>
                              <div className="text-right shrink-0 px-1">
                                <span className="text-sm font-bold text-text-primary block">
                                  {money(itemPriceUsd)}
                                </span>
                                {itemPriceVes !== null && (
                                  <span className="text-[10px] text-text-muted font-mono block">
                                    Bs {formatLocalNumber(itemPriceVes)}
                                  </span>
                                )}
                              </div>
                              <span
                                className={cn(
                                  'shrink-0 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold border',
                                  Number(product.available_stock ?? 0) > 0
                                    ? 'bg-emerald-500/15 text-emerald-700 border-emerald-500/40 dark:text-emerald-400'
                                    : 'bg-rose-500/15 text-rose-700 border-rose-500/40 dark:text-rose-400',
                                )}
                              >
                                {Number(product.available_stock ?? 0) > 0
                                  ? `📦 ${Number(product.available_stock)}${product.unit_of_measure && product.unit_of_measure.toLowerCase() !== 'unit' ? ` ${product.unit_of_measure}` : ''} en stock`
                                  : '⚠️ Sin stock'}
                              </span>
                            </TapButton>
                          );
                        })
                      )}
                    </div>
                  </div>
                )}
            </div>

            {/* Selector de Almacén */}
            <div className="hidden lg:block w-36 shrink-0">
              <Select
                value={warehouseId ?? ''}
                onChange={(event) =>
                  setWarehouseId(event.target.value ? Number(event.target.value) : null)
                }
                className="h-9 text-xs"
                title="Almacén de despacho"
              >
                {warehouses.map((warehouse) => (
                  <option key={warehouse.id} value={warehouse.id}>
                    📦 {warehouse.code} - {warehouse.name}
                  </option>
                ))}
              </Select>
            </div>

            {/* Selector de Lista de Precio */}
            <div className="hidden md:block w-44 shrink-0">
              <Select
                value={selectedPriceListId ?? 'base'}
                onChange={(event) =>
                  void changePriceList(
                    event.target.value === 'base' ? null : Number(event.target.value),
                  )
                }
                disabled={repricing || selectedPending !== null}
                className="h-9 text-xs"
                title="Tarifa / Lista de precios"
                data-testid="pos-price-list"
              >
                <option value="base">🏷️ {BASE_PRICE_LIST_LABEL}</option>
                {priceLists.map((list) => (
                  <option key={list.id} value={list.id}>
                    🏷️ {list.code} - {list.name}
                  </option>
                ))}
              </Select>
            </div>
          </div>

          {/* Contexto, Cliente y Acciones */}
          <div className="flex items-center gap-2 shrink-0">
            {activeRate && (
              <div
                className="hidden xl:flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-surface-subtle border border-border text-[11px] font-mono font-medium text-text-secondary shrink-0"
                title={`Tasa activa: ${activeRate.name ?? activeRate.code}`}
              >
                <span className="text-text-muted font-normal">{activeRate.code}:</span>
                <span className="font-bold text-text-primary">
                  {formatLocalNumber(activeRate.rate)} VES
                </span>
              </div>
            )}

            {/* Botón Cliente [F4] */}
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPanel('customer')}
              className={cn(
                'gap-1.5 h-9 text-xs font-medium shrink-0',
                selectedCustomer && 'border-primary/50 text-primary bg-primary/5 font-semibold',
              )}
              title="Asignar cliente al ticket [F4]"
            >
              <UserRound className="size-3.5" />
              <span>
                <ShortcutText label="F4" text="Cliente" />
              </span>
              {selectedCustomer && (
                <span className="max-w-[70px] truncate text-[11px] opacity-80 hidden md:inline">
                  ({selectedCustomer.name.split(' ')[0]})
                </span>
              )}
            </Button>

            {/* Botón Pendientes directo */}
            <Button
              variant={pendingCount > 0 ? 'secondary' : 'outline'}
              size="sm"
              onClick={() => {
                setPendingAlert([]);
                setPanel('hold');
              }}
              className={cn(
                'gap-1.5 h-9 text-xs font-medium shrink-0 relative',
                hasPendingAlert && 'animate-pulse ring-2 ring-primary',
              )}
              title="Ventas en espera / órdenes pendientes"
            >
              <ClipboardList className="size-3.5" />
              <span className="hidden sm:inline">Pendientes</span>
              {pendingCount > 0 && (
                <span className="bg-primary-foreground text-primary rounded-full px-1.5 py-0.2 text-[10px] font-bold">
                  {pendingCount}
                </span>
              )}
            </Button>

            {/* Menú Desplegable "ACCIONES" */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1.5 h-9 text-xs font-semibold shrink-0 shadow-xs"
                  data-testid="pos-actions-menu-trigger"
                >
                  <SlidersHorizontal className="size-3.5" />
                  <span>Acciones</span>
                  <ChevronDown className="size-3 opacity-60" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56 p-1.5 shadow-xl border-border bg-surface">
                <DropdownMenuLabel className="text-[10px] font-bold uppercase tracking-wider text-text-muted px-2 py-1">
                  Operaciones de Caja
                </DropdownMenuLabel>

                {!sellerOnlyMode && (
                  <DropdownMenuItem onClick={() => setPanel('cash')} className="gap-2.5 py-2">
                    <Wallet className="size-4 text-emerald-600" />
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-xs">Caja / Turno</p>
                      <p className="text-[10px] text-text-muted">Movimientos y cierre de caja</p>
                    </div>
                  </DropdownMenuItem>
                )}

                {!sellerOnlyMode && (
                  <DropdownMenuItem
                    onClick={() => void holdSale()}
                    disabled={cart.length === 0 || !canCheckout || checkout.isPending}
                    className="gap-2.5 py-2"
                  >
                    <PauseCircle className="size-4 text-amber-600" />
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-xs">Poner en espera</p>
                      <p className="text-[10px] text-text-muted">Guardar ticket actual [F6]</p>
                    </div>
                  </DropdownMenuItem>
                )}

                {sellerOnlyMode && (
                  <DropdownMenuItem
                    onClick={() => void holdSale()}
                    disabled={cart.length === 0 || holdOrder.isPending}
                    className="gap-2.5 py-2"
                  >
                    <PauseCircle className="size-4 text-primary" />
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-xs">Armar orden</p>
                      <p className="text-[10px] text-text-muted">Enviar a caja</p>
                    </div>
                  </DropdownMenuItem>
                )}

                <DropdownMenuItem onClick={() => setPanel('receipt')} className="gap-2.5 py-2">
                  <Receipt className="size-4 text-blue-600" />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-xs">Último recibo</p>
                    <p className="text-[10px] text-text-muted">Reimprimir comprobante</p>
                  </div>
                </DropdownMenuItem>

                <DropdownMenuSeparator className="my-1" />

                <DropdownMenuLabel className="text-[10px] font-bold uppercase tracking-wider text-text-muted px-2 py-1">
                  Cotizaciones
                </DropdownMenuLabel>

                <DropdownMenuItem
                  onClick={() => setQuotationOpen(true)}
                  disabled={cart.length === 0}
                  className="gap-2.5 py-2"
                >
                  <FileText className="size-4 text-purple-600" />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-xs">Crear cotización</p>
                    <p className="text-[10px] text-text-muted">Generar con carrito actual</p>
                  </div>
                </DropdownMenuItem>

                <DropdownMenuItem onClick={() => setQuotationsOpen(true)} className="gap-2.5 py-2">
                  <FileText className="size-4 text-indigo-600" />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-xs">Ver cotizaciones</p>
                    <p className="text-[10px] text-text-muted">Historial y cargar a venta</p>
                  </div>
                </DropdownMenuItem>

                {canViewPromotions && (
                  <>
                    <DropdownMenuSeparator className="my-1" />
                    <DropdownMenuLabel className="text-[10px] font-bold uppercase tracking-wider text-text-muted px-2 py-1">
                      Promociones y Ofertas
                    </DropdownMenuLabel>

                    <DropdownMenuItem
                      onClick={() => {
                        setPromotionView('invoice');
                        setPanel('promotions');
                      }}
                      disabled={!selectedWarehouse}
                      className="gap-2.5 py-2"
                    >
                      <Tag className="size-4 text-amber-500" />
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-xs">Promoción factura</p>
                        <p className="text-[10px] text-text-muted">Descuentos globales</p>
                      </div>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                      onClick={() => {
                        setPromotionView('combo');
                        setPanel('promotions');
                      }}
                      disabled={!selectedWarehouse}
                      className="gap-2.5 py-2"
                    >
                      <Gift className="size-4 text-rose-500" />
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-xs">Combos</p>
                        <p className="text-[10px] text-text-muted">Packs y promociones</p>
                      </div>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                      onClick={() => {
                        setPromotionView('product_offer');
                        setPanel('promotions');
                      }}
                      disabled={!selectedWarehouse}
                      className="gap-2.5 py-2"
                    >
                      <Tag className="size-4 text-teal-500" />
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-xs">Ofertas especiales</p>
                        <p className="text-[10px] text-text-muted">Precios de oportunidad</p>
                      </div>
                    </DropdownMenuItem>
                  </>
                )}

                <DropdownMenuSeparator className="my-1" />

                <DropdownMenuItem
                  onClick={() => {
                    setProductSearch(query || '');
                    setPanel('product-search');
                  }}
                  className="gap-2.5 py-2"
                >
                  <Search className="size-4 text-text-muted" />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-xs">Búsqueda avanzada</p>
                    <p className="text-[10px] text-text-muted">Catálogo detallado [F3]</p>
                  </div>
                </DropdownMenuItem>

                <DropdownMenuItem
                  onClick={clearPos}
                  disabled={cart.length === 0}
                  className="gap-2.5 py-2 text-danger focus:text-danger focus:bg-danger/10"
                >
                  <Trash2 className="size-4" />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-xs">Limpiar POS</p>
                    <p className="text-[10px] text-danger/80">Vaciar carrito actual</p>
                  </div>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        <main className="grid min-h-0 min-w-0 flex-1 grid-rows-[minmax(0,1fr)] gap-3 overflow-hidden p-3 xl:grid-cols-[minmax(680px,1fr)_430px]">
          <section className="border-border/80 bg-surface flex min-h-0 flex-col overflow-hidden rounded-2xl border shadow-sm">
            <div className="border-border from-surface to-bg/70 flex items-center justify-between gap-3 border-b bg-gradient-to-r px-4 py-2.5">
              {/* Título Ticket y Cliente en la misma fila */}
              <div className="flex items-center gap-2.5 min-w-0 flex-wrap">
                <div className="flex items-center gap-2 shrink-0">
                  <h2 className="font-bold text-sm tracking-tight text-text-primary">Ticket actual</h2>
                  {cart.length > 0 && (
                    <Badge variant="outline" className="text-xs font-bold font-mono px-2 py-0.5 text-text-primary bg-surface-subtle border-border">
                      {totalCartUnits} {totalCartUnits === 1 ? 'artículo' : 'artículos'}
                    </Badge>
                  )}
                  {exchangeReturnId && <Badge variant="info">Canje #{exchangeReturnId}</Badge>}
                </div>

                <span className="text-border text-xs hidden sm:inline">•</span>

                {/* Selector/Visualizador de Cliente Integrado */}
                <div className="flex items-center gap-1.5 min-w-0">
                  <button
                    type="button"
                    onClick={() => setPanel('customer')}
                    className={cn(
                      'group flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-all max-w-[280px]',
                      selectedCustomer
                        ? 'border-primary/40 bg-primary/8 text-primary font-semibold hover:bg-primary/15'
                        : 'border-border bg-surface hover:border-primary/50 text-text-secondary hover:text-text-primary',
                    )}
                    title="Click para cambiar cliente [F4]"
                  >
                    <UserRound className="size-3.5 shrink-0 text-text-muted group-hover:text-primary transition-colors" />
                    <span className="text-[11px] text-text-muted uppercase font-normal">Cliente:</span>
                    <span className="truncate font-medium">
                      {selectedCustomer ? selectedCustomer.name : customerName}
                    </span>
                    {selectedCustomer && customerDocument(selectedCustomer) && (
                      <span className="text-[10px] text-text-muted font-normal hidden md:inline">
                        ({customerDocument(selectedCustomer)})
                      </span>
                    )}
                    <span className="text-[10px] font-mono px-1 py-0.2 rounded bg-bg border border-border text-text-muted opacity-70 group-hover:opacity-100">
                      F4
                    </span>
                  </button>

                  {selectedCustomer && (
                    <button
                      type="button"
                      onClick={() => {
                        if (exchangeReturnId) {
                          toast.error('El cliente de un canje no puede cambiarse.');
                          return;
                        }
                        setSelectedCustomer(null);
                        setCustomerName('Consumidor Final');
                      }}
                      className="text-text-muted hover:text-danger p-1 rounded hover:bg-danger/10 transition-colors"
                      title="Quitar cliente (volver a Consumidor Final)"
                    >
                      <X className="size-3.5" />
                    </button>
                  )}
                </div>

                {exchangeReturnId && (
                  <span className="text-primary text-[11px] italic">
                    (Confirma el pago para completar devolución)
                  </span>
                )}
              </div>

              {/* Botón de limpiar ticket a la derecha */}
              <div className="flex items-center gap-2 shrink-0">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={clearPos}
                  disabled={cart.length === 0}
                  className="h-8 text-xs text-text-muted hover:text-danger gap-1.5"
                  title="Vaciar ticket actual"
                >
                  <Trash2 className="size-3.5" />
                  <span className="hidden sm:inline">Limpiar</span>
                </Button>
              </div>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-[#f8fafc] p-3">
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
                      canDiscount={canDiscount}
                      onChange={(patch) => updateLine(line.id, patch)}
                      onSerials={() => {
                        setSerialLineId(line.id);
                        setPanel('serials');
                      }}
                      onRemove={() => removeLine(line.id)}
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
                  <div className="flex items-center gap-2">
                    <p className="text-xs font-semibold text-white/70 uppercase">Total</p>
                    <span className="rounded-full bg-white/20 px-2 py-0.5 text-[11px] font-bold text-white tracking-wide">
                      {totalCartUnits} {totalCartUnits === 1 ? 'artículo' : 'artículos'}
                    </span>
                  </div>
                  <p className="mt-1 text-4xl font-bold tracking-normal text-white">
                    {money(cartTotals.total)}
                  </p>
                </div>
                <div className="min-w-32 space-y-1.5 text-right text-xs text-white/80">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-white/70">Total artículos:</span>
                    <span className="font-bold font-mono text-white text-sm">{totalCartUnits}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-white/70">Líneas de ticket:</span>
                    <span className="font-bold font-mono text-white">{cart.length}</span>
                  </div>
                  <div className="border-t border-white/20 pt-1">
                    <AmountRow label="Subtotal" value={cartTotals.subtotal} className="text-white" />
                    {cartTotals.discount > 0 && (
                      <AmountRow label="Desc." value={cartTotals.discount} muted className="text-white/70" />
                    )}
                  </div>
                </div>
              </div>
            </div>

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden p-4">
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
              <div className="flex min-h-0 flex-1 flex-col gap-3">
                {pendingInvoicePromotion && (
                  <InvoicePromotionDecisionPanel
                    promotionName={pendingInvoicePromotion.promotion_name ?? 'Descuento de factura'}
                    decision={invoicePromotionAction}
                    onDecision={setInvoicePromotionAction}
                  />
                )}
                <AmountRow label="Pagado" value={paymentTotals.paid} />
                {payments.length > 0 && (
                  <div className="text-text-muted flex shrink-0 items-center justify-between px-1 text-xs font-semibold tracking-wide uppercase">
                    <span>Pagos aplicados</span>
                    <span>{payments.length}</span>
                  </div>
                )}
                {payments.length > 0 && (
                  <div className="border-border/70 bg-bg/30 min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain rounded-lg border p-2 pr-1">
                    {payments.map((payment) => (
                      <PaymentChip
                        key={payment.id}
                        payment={payment}
                        methods={configuredPaymentMethods}
                        rateTypes={exchangeRateTypes}
                        onChange={(patch) => updatePayment(payment.id, patch)}
                        locked={Boolean(exchangeReturnId && payment.method === 'customer_credit')}
                        onRemove={() =>
                          setPayments((current) => current.filter((item) => item.id !== payment.id))
                        }
                      />
                    ))}
                  </div>
                )}
                {!exchangeReturnId &&
                  selectedCustomer &&
                  Number(customerCredit?.available_base_amount ?? 0) > 0 &&
                  paymentTotals.remaining > 0 &&
                  !payments.some((payment) => payment.method === 'customer_credit') && (
                    <Button
                      className="w-full"
                      variant="outline"
                      onClick={() => applyCustomerCredit()}
                    >
                      Aplicar saldo a favor:{' '}
                      {money(
                        Math.min(
                          Number(customerCredit?.available_base_amount ?? 0),
                          paymentTotals.remaining,
                        ),
                      )}
                    </Button>
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
              <div className="border-border bg-bg/50 mt-4 shrink-0 space-y-2 rounded border p-3">
                <AmountRow label="Restante USD" value={paymentTotals.remaining} />
                {activeRate && (
                  <AmountRow
                    label={`Restante en bolivares · ${activeRate.name}`}
                    value={paymentAmountForCurrency(
                      paymentTotals.remaining,
                      'VES',
                      activeRate.rate,
                    )}
                    currency="VES"
                  />
                )}
                <div className="bg-success/10 mt-2 rounded p-3">
                  <p className="text-text-muted text-xs">Vuelto</p>
                  <p className="text-success text-3xl font-bold">{money(paymentTotals.change)}</p>
                  {paymentTotals.change > 0 && paymentTotals.change_currency === 'VES' && (
                    <p className="text-success mt-1 text-sm font-semibold">
                      Bs {formatLocalNumber(paymentTotals.change_amount ?? 0)}
                      {paymentTotals.change_rate
                        ? ` · ${exchangeRateTypes.find((type) => type.id === paymentTotals.change_rate_type_id)?.code ?? 'Tasa'} @ ${formatLocalNumber(paymentTotals.change_rate)}`
                        : ''}
                    </p>
                  )}
                </div>
              </div>
            </div>

            <div className="border-border shrink-0 space-y-2 border-t p-3">
              {checkoutBlockReason && (
                <p className="border-warning bg-warning/10 text-warning rounded border px-3 py-2 text-xs">
                  {checkoutBlockReason}
                </p>
              )}
              {sellerOnlyMode ? (
                <Button
                  className="h-12 w-full text-base"
                  disabled={cart.length === 0 || holdOrder.isPending}
                  onClick={() => void holdSale()}
                >
                  {holdOrder.isPending ? (
                    <Loader2 className="size-5 animate-spin" />
                  ) : (
                    <PauseCircle className="size-5" />
                  )}
                  Armar orden
                </Button>
              ) : (
                <Button
                  className="h-12 w-full text-base"
                  disabled={
                    cart.length === 0 ||
                    hasStockIssue(cart) ||
                    hasPriceIssue(cart) ||
                    Boolean(priceListPaymentIssue) ||
                    checkout.isPending ||
                    (payments.length > 0 && Boolean(checkoutBlockReason))
                  }
                  onClick={() => {
                    if (panel === null && payments.length === 0 && cart.length > 0) {
                      if (priceListPaymentIssue) return toast.error(priceListPaymentIssue);
                      setPanel('pay');
                    } else {
                      void confirmPaidSale();
                    }
                  }}
                >
                  {checkout.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <CreditCard className="size-5" />
                  )}
                  <ShortcutText label="F10" text="Cobrar" />
                </Button>
              )}
              {!sellerOnlyMode && (
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
              )}
            </div>
          </aside>
        </main>

        {panel && panel !== 'product-search' && (
          <PanelShell
            title={panelTitle(panel)}
            onClose={() => setPanel(null)}
            wide={
              panel === 'pay' ||
              panel === 'customer' ||
              panel === 'promotions' ||
              panel === 'cash'
            }
            actions={
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setProductSearch(query || '');
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
                  if (exchangeReturnId) {
                    toast.error('El cliente de un canje no puede cambiarse.');
                    return;
                  }
                  setSelectedCustomer(null);
                  setCustomerName('Consumidor Final');
                  setPanel(null);
                }}
                onName={setCustomerName}
                onFormChange={(patch) => setQuickCustomer((current) => ({ ...current, ...patch }))}
                onCreate={() => void createQuickCustomer()}
                onSelect={(customer) => {
                  if (exchangeReturnId && customer.id !== selectedCustomer?.id) {
                    toast.error('El cliente de un canje no puede cambiarse.');
                    return;
                  }
                  setSelectedCustomer(customer);
                  setCustomerName(customer.name);
                  setPanel(null);
                }}
              />
            )}
            {panel === 'promotions' && (
              <PromotionsPanel
                invoicePromotions={availableInvoicePromotions.data ?? []}
                combos={availableCombos.data ?? []}
                productOffers={availableProductOffers.data ?? []}
                view={promotionView}
                selectedInvoiceId={selectedInvoicePromotion?.id ?? null}
                selectedComboIds={comboApplications.map((application) => application.promotion.id)}
                onSelectCombo={(promotion: Promotion, sets: number) => {
                  void loadPromotion(promotion, sets);
                }}
                onSelectProductOffer={(promotion) => {
                  applyProductOffer(promotion);
                }}
                selectedId={selectedPromotion?.id ?? null}
                isLoading={promotionsLoading}
                error={promotionsError ? 'No se pudieron cargar las promociones.' : null}
                onSelect={(promotion: Promotion, sets: number) => {
                  void loadPromotion(promotion, sets);
                }}
                onSelectDiscount={(promotion) => {
                  if (cart.length === 0) {
                    toast.error('Agrega productos antes de aplicar un descuento de factura.');
                    return;
                  }

                  if (selectedInvoicePromotion?.id === promotion.id) {
                    clearSelectedInvoicePromotion();
                  } else {
                    setSelectedInvoicePromotion(promotion);
                  }
                  setPanel(null);
                }}
              />
            )}
            {panel === 'hold' && (
              <HoldPanel
                orders={pendingOrders}
                selected={selectedPending}
                canCancel={canCancel}
                canCharge={canCheckout}
                onSelect={setSelectedPending}
                onPaySelected={() => selectedPending && void recoverPendingOrder(selectedPending)}
                onCancel={(order) => cancelOrder.mutate(order.id)}
              />
            )}
            {panel === 'cash' && activeSession && (
              <CashPanel
                session={activeSession}
                canMove={canMoveCash}
                canClose={canCloseCash}
                movement={cashMovement}
                closeForm={cashCloseForm}
                rate={activeRate?.rate ?? null}
                closing={closeCash.isPending}
                onMovementChange={setCashMovement}
                onCloseForm={setCashCloseForm}
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
                  const totals = cashCountTotals(cashCloseForm.counts);
                  const usd = cashCloseForm.counts.length
                    ? totals.USD
                    : Number(cashCloseForm.usd || 0);
                  const ves = Number(cashCloseForm.ves || 0);
                  const diff = closeDifference(activeSession, cashCloseForm, activeRate?.rate ?? null);

                  if (usd < 0 || ves < 0) {
                    return toast.error('El efectivo contado no puede ser negativo.');
                  }
                  if (ves > 0 && !activeRate) {
                    return toast.error(
                      'Configura una tasa activa USD/VES antes de cerrar con efectivo VES.',
                    );
                  }
                  // En modo ciego NO se exige nota por diferencia: eso revelaria al
                  // cajero que hay descuadre. La nota solo es obligatoria en standard.
                  if (
                    !cashCloseForm.blind &&
                    hasDifference(diff.cashUsd, diff.cashVes) &&
                    !cashCloseForm.notes.trim()
                  ) {
                    return toast.error('Indica una nota para justificar la diferencia de caja.');
                  }

                  setCashSessionClosed(true);
                  closeCash.mutate(
                    {
                      sessionId: activeSession.id,
                      payload: {
                        counted_base_amount: usd,
                        counted_local_amount: ves,
                        counted_cash_usd: usd,
                        counted_cash_ves: ves,
                        exchange_rate_type_id:
                          ves > 0 ? (activeRate?.exchange_rate_type_id ?? null) : null,
                        counts: cashCloseForm.counts.length ? cashCloseForm.counts : undefined,
                        counting_mode: cashCloseForm.blind ? 'blind' : 'standard',
                        closing_notes: cashCloseForm.notes.trim() || null,
                      },
                    },
                    {
                      onSuccess: () =>
                        setCashCloseForm({
                          sessionId: null,
                          usd: '',
                          ves: '',
                          notes: '',
                          counts: [],
                          blind: true,
                        }),
                      onError: (error) => {
                        setCashSessionClosed(false);
                        toast.error(errorMessage(error));
                      },
                    },
                  );
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
                onDownloadPdf={() => lastReceipt && void requestDigitalPdf(lastReceipt)}
              />
            )}
            {panel === 'pay' && (
              <QuickPaymentPanel
                methods={allowedPaymentMethods}
                cartTotal={cartTotals.total}
                payments={payments}
                rate={activeRate}
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
                search={serialSearch}
                onSearch={setSerialSearch}
                onToggle={(serial) => toggleSerial(serialLine.id, serial)}
              />
            )}
            <VariantPicker
              productId={variantPickerProduct?.id ?? 0}
              productName={variantPickerProduct?.name ?? ''}
              warehouseId={selectedWarehouse?.id ?? warehouseId}
              open={variantPickerProduct !== null}
              initialQuantity={variantPickerQuantity}
              onClose={() => {
                setVariantPickerProduct(null);
                setVariantPickerPromotion(null);
                setPendingPromotionLoad(null);
              }}
              onSelect={async ({ variant, quantity }) => {
                const product = variantPickerProduct;
                const promotion = variantPickerPromotion;
                const pending = pendingPromotionLoad;
                setVariantPickerProduct(null);
                setVariantPickerPromotion(null);
                if (product) {
                  const added = await addProduct(
                    product,
                    undefined,
                    quantity,
                    variant,
                    promotion?.price,
                    promotion?.ref ?? null,
                    promotion?.comboInstanceUuid ?? null,
                  );
                  if (!added) {
                    setPendingPromotionLoad(null);
                    return;
                  }
                  if (pending) {
                    await continuePromotionLoad({
                      ...pending,
                      nextIndex: pending.nextIndex + 1,
                    });
                  }
                }
              }}
            />
          </PanelShell>
        )}

        {/* Modal Desktop de Búsqueda y Detalle de Productos (F3) */}
        <ProductSearchDetailModal
          open={panel === 'product-search'}
          onClose={() => setPanel(null)}
          warehouses={warehouses}
          warehouseId={warehouseId}
          onWarehouseChange={setWarehouseId}
          priceLists={priceLists}
          selectedPriceList={selectedPriceList}
          activeRate={activeRate}
          initialSearch={productSearch}
          onSelect={async (product) => {
            const added = await addProduct(product);
            if (added) setPanel(null);
          }}
        />

        {/* Previsualizacion centrada del ticket al cobrar (F10) */}
        <TicketPreviewDialog
          order={previewOrder}
          onClose={() => setPreviewOrder(null)}
          canThermalPrint={canThermalPrint}
          canDigital={canDigital}
          busy={createPrintJob.isPending || updatePrintJobStatus.isPending}
          onPrintThermal={() =>
            previewOrder && void createAndDispatchPrintJobs(previewOrder, false, 'thermal')
          }
          onDownloadPdf={() => previewOrder && void requestDigitalPdf(previewOrder)}
        />
      </div>

      <QuotationCreateDialog
        open={quotationOpen}
        onOpenChange={setQuotationOpen}
        defaultWarehouseId={cart[0]?.warehouse_id ?? null}
        defaultCustomerName={customerName && customerName !== 'Consumidor Final' ? customerName : undefined}
        initialItems={cart.map((line) => ({
          product_id: line.product_id,
          product_variant_id: line.product_variant_id ?? null,
          product_variant_name: line.product_variant_name ?? null,
          quantity: line.quantity,
          price_list_id: line.price_list_id ?? null,
          price_list_name: line.price_list_name ?? null,
          name: line.name,
          unit_price: line.unit_price,
        }))}
        onCreated={() => toast.success('Cotizacion creada. El ticket se mantiene para cobrar.')}
      />

      <QuotationPickerDialog
        open={quotationsOpen}
        onOpenChange={setQuotationsOpen}
        onConverted={() => toast.success('Orden pendiente lista para cobrar.')}
      />
    </PosShell>
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
    if (selectedPending) {
      toast.info('La orden pendiente conserva la lista de precio seleccionada por el vendedor.');
      return;
    }
    if (nextId === selectedPriceListId) return;
    const nextList = nextId ? priceLists.find((list) => list.id === nextId) : null;
    if (nextId && !nextList) return;

    setPriceListNotice(null);
    if (cart.length === 0) {
      setSelectedPriceListId(nextId);
      persistPriceListPreference(nextId);
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
            price_source: 'base',
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
              price_source: 'price_list',
              price_list_name: found.quote.price_list_name ?? nextList.name,
              price_issue: null,
            };
          }),
        );
      }
      setSelectedPriceListId(nextId);
      persistPriceListPreference(nextId);
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

  function persistPriceListPreference(priceListId: number | null): void {
    if (!activeSession) return;

    savePersistedPriceListPreference(
      Number(activeSession.tenant_id),
      Number(activeSession.cashier_id),
      priceListId,
    );
  }

  async function addProduct(
    product: Product,
    scannedSerial?: ProductSerial,
    requestedQuantity = 1,
    selectedVariant?: ProductVariant | null,
    promotionPriceOverride?: number,
    promotionRef?: { id: number; code?: string | null; benefitType?: string } | null,
    comboInstanceUuid?: string | null,
  ): Promise<boolean> {
    const warehouse = selectedWarehouse;
    if (!warehouse) {
      toast.error('Selecciona un almacen antes de agregar productos.');
      return false;
    }

    if (!selectedVariant && !scannedSerial) {
      try {
        const variants = await getProductVariants(product.id, warehouse.id);
        if (requiresPosVariantSelection(variants)) {
          setVariantPickerProduct(product);
          setVariantPickerQuantity(Math.max(1, Math.floor(Number(requestedQuantity) || 1)));
          // Conserva el contexto de promocion si esta linea viene de una
          // promocion cargada, para que al elegir color se mantenga el precio.
          if (promotionPriceOverride !== undefined) {
            setVariantPickerPromotion({ price: promotionPriceOverride, ref: promotionRef ?? null });
          } else {
            setVariantPickerPromotion(null);
          }
          return false;
        }
        selectedVariant = null;
      } catch {
        toast.error('No se pudieron consultar las variantes de este producto. Intenta de nuevo.');
        return false;
      }
    }

    const available = Number(selectedVariant?.stock_available ?? product.available_stock ?? 0);
    if ((product.track_stock ?? true) && available <= 0) {
      toast.error('Producto sin stock disponible.');
      return false;
    }
    const quote = selectedPriceList ? await quoteProduct(product, selectedPriceList) : null;
    if (selectedPriceList && !quote) return false;

    const shouldSelectSerials = product.tracking_type === 'serialized';
    const quantity = Math.max(1, Math.floor(Number(requestedQuantity) || 1));
    const variantMatch = {
      product_id: product.id,
      warehouse_id: warehouse.id,
      product_variant_id: selectedVariant?.id ?? null,
      combo_instance_uuid: comboInstanceUuid ?? null,
    };
    const matchingLine = findMatchingVariantLine(cart, variantMatch);
    const maximumQuantity = product.track_stock === false ? Number.MAX_SAFE_INTEGER : available;
    if (matchingLine && matchingLine.quantity + quantity > maximumQuantity) {
      toast.error(`No hay stock suficiente de ${product.name} para cargar la promoción.`);
      return false;
    }
    if (scannedSerial && matchingLine) {
      if (matchingLine.selected_serials?.some((serial) => serial.id === scannedSerial.id)) {
        toast.error('Ese IMEI/serial ya esta agregado al ticket.');
        return false;
      }
      if (
        clampQuantity(matchingLine.quantity + 1, matchingLine.available_stock) <=
        matchingLine.quantity
      ) {
        toast.error('No hay mas unidades disponibles de este producto.');
        return false;
      }
    }
    let newLineId: string | null = null;
    setCart((current) => {
      const existing = findMatchingVariantLine(current, variantMatch);
      if (existing) {
        newLineId = existing.id;
        return current.map((line) =>
          line.id === existing.id
            ? {
                ...line,
                unit_of_measure: line.unit_of_measure ?? product.unit_of_measure ?? 'unit',
                quantity: Math.min(line.quantity + quantity, maximumQuantity),
                selected_serials: scannedSerial
                  ? [
                      ...(line.selected_serials ?? []),
                      {
                        id: scannedSerial.id,
                        serial_type: scannedSerial.serial_type,
                        serial_number: scannedSerial.serial_number,
                      },
                    ]
                  : line.selected_serials,
              }
            : line,
        );
      }
      newLineId = createClientId();
      return [
        ...current,
        {
          id: newLineId,
          product_id: product.id,
          product_variant_id: selectedVariant?.id ?? null,
          product_variant_name: selectedVariant?.color ?? null,
          name: product.name,
          sku: product.sku,
          barcode: product.barcode,
          warehouse_id: warehouse.id,
          combo_instance_uuid: comboInstanceUuid ?? null,
          quantity,
          available_stock: available,
          unit_price:
            promotionPriceOverride ??
            quote?.base_price_usd ??
            Number(selectedVariant?.price_override ?? product.base_price ?? 0),
          base_unit_price: Number(selectedVariant?.price_override ?? product.base_price ?? 0),
          promotion_id: promotionRef?.id ?? null,
          promotion_code: promotionRef?.code ?? null,
          promotion_benefit_type: promotionRef?.benefitType ?? null,
          currency: quote?.sale_currency ?? product.sale_currency ?? 'USD',
          base_currency: product.sale_currency ?? 'USD',
          exchange_rate: quote?.exchange_rate ?? null,
          exchange_rate_type_id: quote?.exchange_rate_type_id ?? null,
          exchange_rate_type_code: quote?.exchange_rate_type_code ?? null,
          price_list_id: selectedPriceList?.id ?? null,
          price_source: selectedPriceList ? 'price_list' : 'base',
          price_list_name:
            quote?.price_list_name ?? selectedPriceList?.name ?? BASE_PRICE_LIST_LABEL,
          price_issue: null,
          image_url: productImageSrc(product),
          tracking_type: product.tracking_type,
          unit_of_measure: product.unit_of_measure ?? 'unit',
          // `track_stock` por defecto es true en el backend; si el producto
          // es un servicio o concepto facturable, el listado lo trae en
          // false y el POS no exige stock ni genera movimiento (QW10).
          track_stock: product.track_stock !== false,
          selected_serials: scannedSerial
            ? [
                {
                  id: scannedSerial.id,
                  serial_type: scannedSerial.serial_type,
                  serial_number: scannedSerial.serial_number,
                },
              ]
            : [],
        },
      ];
    });
    setQuery('');
    // Cierra el teclado virtual (si el input de busqueda tenia foco) para
    // que el layout no cambie al agregar y la accion no se pierda en tablet.
    if (document.activeElement instanceof HTMLElement && isTouchPrimaryDevice()) {
      document.activeElement.blur();
    }
    if (shouldSelectSerials && !scannedSerial) {
      window.setTimeout(() => {
        if (newLineId) setSerialLineId(newLineId);
        setPanel('serials');
      }, 0);
    }
    return true;
  }

  async function continuePromotionLoad(load: PendingPromotionLoad): Promise<void> {
    if (!selectedWarehouse) {
      setPendingPromotionLoad(null);
      toast.error('Selecciona un almacen antes de cargar una promoción.');
      return;
    }

    try {
      for (let index = load.nextIndex; index < load.entries.length; index += 1) {
        const entry = load.entries[index];
        if (!entry) continue;
        const variants = await getProductVariants(entry.product.id, selectedWarehouse.id);

        if (requiresPosVariantSelection(variants)) {
          setPendingPromotionLoad({ ...load, nextIndex: index });
          setVariantPickerProduct(entry.product);
          setVariantPickerQuantity(Math.max(1, Math.floor(entry.item.quantity)));
          setVariantPickerPromotion({
            price: entry.unitPrice,
            ref: {
              id: load.promotion.id,
              code: load.promotion.code,
              benefitType: load.promotion.benefit_type,
            },
            comboInstanceUuid: load.instanceUuid,
          });
          return;
        }

        const added = await addProduct(
          entry.product,
          undefined,
          entry.item.quantity,
          undefined,
          entry.unitPrice,
          {
            id: load.promotion.id,
            code: load.promotion.code,
            benefitType: load.promotion.benefit_type,
          },
          load.instanceUuid,
        );
        if (!added) {
          setPendingPromotionLoad(null);
          return;
        }
      }

      addComboApplication(load.promotion, load.instanceUuid, load.sets);
      setSelectedPromotion(load.promotion);
      setPendingPromotionLoad(null);
      setPanel(null);
      toast.success(`${load.sets} conjunto(s) de ${load.promotion.name} cargado(s) al ticket.`);
    } catch {
      setPendingPromotionLoad(null);
      toast.error('No se pudieron cargar todos los productos de la promoción.');
    }
  }

  async function loadPromotion(promotion: Promotion, sets: number): Promise<void> {
    if (!selectedWarehouse) {
      toast.error('Selecciona un almacen antes de cargar una promoción.');
      return;
    }

    const items = expandPromotionItems(promotion.items, sets);
    if (items.length === 0) {
      toast.error('La promoción no tiene componentes cargables.');
      return;
    }

    const totalPromotionQuantity = items.reduce((sum, item) => sum + item.quantity, 0);
    const instanceUuid = createClientId();

    try {
      const products = await Promise.all(
        items.map((item) => getProductForPos(item.product_id, selectedWarehouse.id)),
      );
      const loadedItems = items
        .map((item, index) => ({ item, product: products[index] }))
        .filter(
          (entry): entry is { item: (typeof items)[number]; product: Product } =>
            entry.product !== undefined,
        );
      if (loadedItems.length !== items.length) {
        toast.error('No se pudieron cargar todos los productos de la promoción.');
        return;
      }

      // La selección de variantes se procesa una por una. No se debe marcar
      // el combo como cargado hasta que todas sus líneas estén en el carrito.
      const pending: PendingPromotionLoad = {
        promotion,
        sets,
        instanceUuid,
        entries: loadedItems.map(({ item, product }) => ({
          item,
          product,
          unitPrice: promotionLineUnitPrice(
            promotion,
            Number(product.base_price ?? 0),
            totalPromotionQuantity,
          ),
        })),
        nextIndex: 0,
      };
      setPendingPromotionLoad(pending);
      await continuePromotionLoad(pending);
    } catch {
      setPendingPromotionLoad(null);
      toast.error('No se pudieron cargar todos los productos de la promoción.');
    }
  }

  function applyProductOffer(promotion: Promotion): void {
    const eligibleLine = cart.find(
      (line) =>
        !line.combo_instance_uuid &&
        promotion.items.some((item) => item.product_id === line.product_id),
    );
    if (!eligibleLine) {
      toast.error('Agrega una línea normal elegible antes de aplicar la oferta.');
      return;
    }

    const unitPrice = promotion.benefit_type === 'free_item' ? 0 : Number(promotion.price_usd ?? 0);
    addProductOfferApplication(promotion, eligibleLine.id);
    updateLine(eligibleLine.id, {
      unit_price: unitPrice,
      promotion_id: promotion.id,
      promotion_code: promotion.code,
      promotion_benefit_type: promotion.benefit_type,
    });
    setPanel(null);
    toast.success(`Oferta ${promotion.name} aplicada a ${eligibleLine.name}.`);
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
    if (isHandlingBarcodeEnterRef.current) return;
    const term = query.trim();
    if (term.length < 2) return;
    isHandlingBarcodeEnterRef.current = true;
    const normalized = term.toLowerCase();
    const normalizedNoLeadingZeros = normalized.replace(/^0+/, '');

    const matchesCode = (val?: string | null): boolean => {
      if (!val) return false;
      const clean = val.toLowerCase().trim();
      return (
        clean === normalized ||
        (normalizedNoLeadingZeros.length > 0 && clean === normalizedNoLeadingZeros) ||
        clean.replace(/^0+/, '') === normalized ||
        (normalizedNoLeadingZeros.length > 0 && clean.replace(/^0+/, '') === normalizedNoLeadingZeros)
      );
    };

    try {
      // 1. Verificar si ya tenemos el producto exacto en memoria (en quickSearchResults o en la lista cargada)
      const inMemoryExact =
        quickSearchResults.find(
          (product) => matchesCode(product.barcode) || matchesCode(product.sku),
        ) ??
        products.find(
          (product) => matchesCode(product.barcode) || matchesCode(product.sku),
        );

      if (inMemoryExact) {
        const added = await addProduct(inMemoryExact);
        if (added) {
          setQuery('');
          setQuickSearchIndex(0);
        }
        return;
      }

      // 2. Búsqueda directa e inmediata al backend (omite debounce para lectores de código de barras)
      try {
        const directResults = await fetchPosProductsDirect(term, effectiveWarehouseId);
        const directExact = directResults.find(
          (product) => matchesCode(product.barcode) || matchesCode(product.sku),
        );

        if (directExact) {
          const added = await addProduct(directExact);
          if (added) {
            setQuery('');
            setQuickSearchIndex(0);
          }
          return;
        }

        // Si la consulta directa devolvió productos, agregar el primero directamente sin abrir modal
        if (directResults.length >= 1 && directResults[0]) {
          const added = await addProduct(directResults[0]);
          if (added) {
            setQuery('');
            setQuickSearchIndex(0);
          }
          return;
        }
      } catch {
        // Fallback a seriales
      }

      // 3. Verificación de seriales / IMEI
      if (/^[A-Za-z0-9-]{6,}$/.test(term) && /\d/.test(term)) {
        const scanned = await addScannedSerial(term);
        if (scanned) {
          setQuery('');
          setQuickSearchIndex(0);
          return;
        }
      }

      // 4. Si hay productos en la lista previa pero ninguno coincidió exactamente
      if (products[0]) {
        setProductSearch(term);
        setPanel('product-search');
        return;
      }

      toast.error('No se encontro un producto con ese codigo.');
    } finally {
      isHandlingBarcodeEnterRef.current = false;
    }
  }

  async function addScannedSerial(serial: string): Promise<boolean> {
    if (!selectedWarehouse) {
      toast.error('Selecciona un almacen antes de escanear un IMEI.');
      return true;
    }

    let found: (ProductSerial & { product_id: number }) | null = null;
    for (const serialType of ['imei', 'serial'] as const) {
      try {
        found = await lookupProductSerialRequest({
          warehouseId: selectedWarehouse.id,
          serial,
          serialType,
        });
        break;
      } catch {
        // Un scanner puede enviar IMEI o serial; probamos ambos formatos.
      }
    }

    if (!found) return false;
    if (found.status !== 'available') {
      toast.error(`El IMEI/serial no esta disponible: ${found.status}.`);
      return true;
    }

    try {
      const product = await getProductForPos(found.product_id);
      if (product.tracking_type !== 'serialized') {
        toast.error('La unidad escaneada no pertenece a un producto serializado.');
        return true;
      }
      await addProduct(product, found);
      return true;
    } catch {
      toast.error('No se pudo cargar el producto del IMEI escaneado.');
      return true;
    }
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
    const rate = activeRate;
    setPayments((current) => [
      ...current,
      {
        id: createClientId(),
        method: configured?.method ?? method,
        currency,
        amount: paymentAmountForCurrency(
          Math.max(0, cartTotals.total - calculatePaymentTotals(current, cartTotals.total).paid),
          currency,
          rate?.rate ?? null,
        ),
        received_amount:
          method === 'cash'
            ? paymentAmountForCurrency(
                Math.max(
                  0,
                  cartTotals.total - calculatePaymentTotals(current, cartTotals.total).paid,
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

  function applyCustomerCredit(): void {
    if (exchangeReturnId) {
      toast.error('El saldo del canje ya está aplicado en este ticket.');
      return;
    }
    if (payments.some((payment) => payment.method === 'customer_credit')) {
      toast.error('Ya aplicaste saldo a favor en este ticket.');
      return;
    }
    const available = Number(customerCredit?.available_base_amount ?? 0);
    const remaining = calculatePaymentTotals(payments, cartTotals.total).remaining;
    const amount = roundMoney(Math.min(available, remaining));

    if (amount <= 0) return;

    setPayments((current) => [
      ...current,
      {
        id: `credit-${Date.now()}`,
        method: 'customer_credit',
        currency: 'USD',
        amount,
        payment_method_id: null,
        exchange_rate_type_id: null,
        exchange_rate: null,
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
    if (selectedPending) {
      if (payments.length === 0) {
        setPanel('pay');
        return;
      }
      await payPendingOrder(selectedPending);
      return;
    }
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
      if (exchangeReturnId) {
        await completeExchange.mutateAsync({ id: exchangeReturnId, pos_order_id: order.id });
        setExchangeReturnId(null);
      }
      setLastReceipt(order);
      if (canThermalPrint) void createAndDispatchPrintJobs(order, false);
      setPreviewOrder(order);
      clearTicket();
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
      if (canThermalPrint) void createAndDispatchPrintJobs(order, false);
      setPreviewOrder(order);
      clearTicket();
      setCreditDueDate('');
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
    if (cart.length === 0) return;
    if (priceListPaymentIssue) {
      toast.error(priceListPaymentIssue);
      return;
    }
    if (priceIssue) {
      toast.error(priceIssue);
      return;
    }
    if (!canHold) {
      toast.error('No tienes permiso pos.orders.hold para armar ordenes.');
      return;
    }
    try {
      await holdOrder.mutateAsync(buildHoldPayload());
      clearTicket();
      toast.success('Orden armada. La cajera podra cobrarla.');
    } catch (error) {
      void bootstrap.refetch();
      toast.error(error instanceof Error ? error.message : 'No se pudo armar la orden.');
    }
  }

  async function payPendingOrder(order: PosOrder): Promise<void> {
    if (payments.length === 0) {
      toast.error('Agrega pagos para completar el ticket.');
      return;
    }
    if (pendingInvoicePromotion && invoicePromotionAction === null) {
      toast.error('Selecciona Validar o Rechazar antes de cobrar la promoción.');
      setPanel('pay');
      return;
    }
    if (!activeSession) {
      toast.error('No hay caja abierta para cobrar.');
      return;
    }
    const items = cart
      .filter((line) => line.sale_item_id && (line.selected_serials?.length ?? 0) > 0)
      .map((line) => ({
        sale_item_id: line.sale_item_id!,
        product_unit_ids: (line.selected_serials ?? []).map((serial) => serial.id),
      }));
    const paid = await addPayments.mutateAsync({
      orderId: order.id,
      payments: payments.map(toPaymentPayload),
      cashRegisterSessionId: activeSession.id,
      items,
      invoicePromotionAction: pendingInvoicePromotion
        ? (invoicePromotionAction ?? undefined)
        : undefined,
    });
    setLastReceipt(paid);
    if (canThermalPrint) void createAndDispatchPrintJobs(paid, false);
    setPreviewOrder(paid);
    clearTicket();
  }

  async function recoverPendingOrder(order: PosOrder): Promise<void> {
    interface PendingSaleItem {
      id: number;
      product_id: number;
      product_variant_id?: number | null;
      product_variant_name?: string | null;
      warehouse_id: number;
      quantity: number;
      sale_currency?: CurrencyCode;
      unit_price: number;
      base_unit_price?: number;
      price_list_id?: number | null;
      price_list_name?: string | null;
      discount_type?: DiscountType | null;
      discount_value?: number;
      discount_reason?: string | null;
      exchange_rate_type_id?: number | null;
      exchange_rate_type_code?: string | null;
      exchange_rate?: number | null;
      product_unit_ids?: number[];
      serial_units?: { id: number; serial_type?: string | null; serial_number: string }[];
    }

    const items = ((order.sale as { items?: PendingSaleItem[] } | undefined)?.items ?? []).filter(
      (item) => item.product_id && item.warehouse_id,
    );
    if (items.length === 0) {
      toast.error('El ticket pendiente no tiene líneas recuperables.');
      return;
    }

    try {
      const lines = await Promise.all(
        items.map(async (item) => {
          const product = await getProductForPos(item.product_id);
          const selectedSerials = (item.serial_units ?? []).map((serial) => ({
            id: serial.id,
            serial_type: serial.serial_type,
            serial_number: serial.serial_number,
          }));

          return {
            id: `pending-${item.id}`,
            product_id: item.product_id,
            product_variant_id: item.product_variant_id ?? null,
            product_variant_name: item.product_variant_name ?? null,
            name: product.name,
            sku: product.sku,
            barcode: product.barcode,
            warehouse_id: item.warehouse_id,
            sale_item_id: item.id,
            quantity: Number(item.quantity),
            available_stock: Math.max(Number(product.available_stock ?? 0), Number(item.quantity)),
            unit_price: Number(item.unit_price),
            base_unit_price: Number(item.base_unit_price ?? item.unit_price),
            currency: item.sale_currency ?? product.sale_currency ?? 'USD',
            base_currency: product.sale_currency ?? 'USD',
            discount_type: item.discount_type ?? null,
            discount_value: Number(item.discount_value ?? 0),
            discount_reason: item.discount_reason ?? null,
            price_list_id: item.price_list_id ?? null,
            price_source: item.price_list_id ? 'price_list' : 'base',
            price_list_name:
              item.price_list_name ?? (item.price_list_id ? null : BASE_PRICE_LIST_LABEL),
            exchange_rate_type_id: item.exchange_rate_type_id ?? null,
            exchange_rate_type_code: item.exchange_rate_type_code ?? null,
            exchange_rate: item.exchange_rate ?? null,
            tracking_type: product.tracking_type,
            track_stock: product.track_stock !== false,
            unit_of_measure: product.unit_of_measure ?? 'unit',
            image_url: product.image_url,
            selected_serials: selectedSerials,
          } satisfies PosCartLine;
        }),
      );

      setCart(lines);
      setPayments([]);
      clearSelectedInvoicePromotion();
      clearComboApplications();
      clearProductOfferApplications();
      setInvoicePromotionAction(null);
      const applicationPromotion = (application: {
        promotion_id?: number | null;
        promotion_name?: string | null;
        promotion_code?: string | null;
        benefit_type?: string | null;
        payment_currency?: string | null;
        base_after_amount?: number | null;
        discount_percent?: number | null;
      }): Promotion => ({
        id: application.promotion_id ?? 0,
        name: application.promotion_name ?? 'Promoción recuperada',
        code: application.promotion_code ?? null,
        benefit_type: (application.benefit_type ?? 'percent_discount') as Promotion['benefit_type'],
        price_currency: 'USD',
        payment_currency: (application.payment_currency ?? 'ANY') as Promotion['payment_currency'],
        price_usd: 0,
        discount_percent: application.discount_percent ?? null,
        discount_amount_usd: null,
        priority: 0,
        is_active: true,
        items: [],
      });
      const applications = order.sale?.promotion_applications ?? [];
      for (const application of applications.filter((entry) => entry.scope === 'combo')) {
        if (application.promotion_id && application.instance_uuid) {
          addComboApplication(applicationPromotion(application), application.instance_uuid, 1);
        }
      }
      for (const application of applications.filter((entry) => entry.scope === 'product_offer')) {
        if (!application.promotion_id) continue;
        for (const applicationItem of application.items ?? []) {
          const line = lines.find((entry) => entry.sale_item_id === applicationItem.sale_item_id);
          if (line) addProductOfferApplication(applicationPromotion(application), line.id);
        }
      }
      setSelectedPriceListId(items[0]?.price_list_id ?? null);
      setWarehouseId(items[0]?.warehouse_id ?? warehouseId);
      setCustomerName(order.customer_name ?? 'Consumidor Final');
      setPanel(null);
      toast.success(`Ticket #${order.id} recuperado.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo recuperar el ticket.');
    }
  }

  function buildCheckoutPayload(
    sessionId: number,
    status: 'captured' | 'pending',
  ): CheckoutPayload {
    return {
      cash_register_session_id: sessionId,
      customer_id: selectedCustomer?.id ?? null,
      customer_name: selectedCustomer ? selectedCustomer.name : customerName,
      invoice_promotion_id: selectedInvoicePromotion?.id ?? null,
      combo_applications: comboApplications.map(({ promotion, instance_uuid, sets }) => ({
        promotion_id: promotion.id,
        instance_uuid,
        sets,
      })),
      product_offer_applications: productOfferApplications
        .map(({ promotion, line_id }) => {
          const itemIndex = cart.findIndex((line) => line.id === line_id);
          return itemIndex < 0 ? null : { promotion_id: promotion.id, item_index: itemIndex };
        })
        .filter(
          (application): application is { promotion_id: number; item_index: number } =>
            application !== null,
        ),
      items: cart.map((line) => ({
        warehouse_id: line.warehouse_id,
        product_id: line.product_id,
        product_variant_id: line.product_variant_id ?? null,
        price_list_id: line.price_list_id ?? selectedPriceList?.id ?? null,
        price_source:
          line.price_source ?? (line.price_list_id || selectedPriceList ? 'price_list' : 'base'),
        quantity: line.quantity,
        combo_instance_uuid: line.combo_instance_uuid ?? null,
        discount_type: canDiscount ? (line.discount_type ?? null) : null,
        discount_value: canDiscount ? (line.discount_value ?? null) : null,
        discount_reason: canDiscount ? (line.discount_reason ?? null) : null,
        product_unit_ids:
          line.tracking_type === 'serialized'
            ? (line.selected_serials ?? []).map((serial) => serial.id)
            : [],
      })),
      payments: status === 'captured' ? payments.map(toPaymentPayload) : [],
    };
  }

  function buildHoldPayload(): HoldPayload {
    return {
      customer_id: selectedCustomer?.id ?? null,
      customer_name: selectedCustomer ? selectedCustomer.name : customerName,
      invoice_promotion_id: selectedInvoicePromotion?.id ?? null,
      combo_applications: comboApplications.map(({ promotion, instance_uuid, sets }) => ({
        promotion_id: promotion.id,
        instance_uuid,
        sets,
      })),
      product_offer_applications: productOfferApplications
        .map(({ promotion, line_id }) => {
          const itemIndex = cart.findIndex((line) => line.id === line_id);
          return itemIndex < 0 ? null : { promotion_id: promotion.id, item_index: itemIndex };
        })
        .filter(
          (application): application is { promotion_id: number; item_index: number } =>
            application !== null,
        ),
      items: cart.map((line) => ({
        warehouse_id: line.warehouse_id,
        product_id: line.product_id,
        product_variant_id: line.product_variant_id ?? null,
        price_list_id: line.price_list_id ?? selectedPriceList?.id ?? null,
        price_source:
          line.price_source ?? (line.price_list_id || selectedPriceList ? 'price_list' : 'base'),
        quantity: line.quantity,
        combo_instance_uuid: line.combo_instance_uuid ?? null,
        discount_type: canDiscount ? (line.discount_type ?? null) : null,
        discount_value: canDiscount ? (line.discount_value ?? null) : null,
        discount_reason: canDiscount ? (line.discount_reason ?? null) : null,
        product_unit_ids: [],
      })),
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

  function clearPos(): void {
    if ((cart.length > 0 || payments.length > 0) && !window.confirm('¿Limpiar el ticket actual?')) {
      return;
    }

    clearTicket();
    setPanel(null);
    toast.success('POS limpiado.');
  }

  function clearTicket(): void {
    clearAll();
    setPriceListNotice(null);
    setSelectedPending(null);
    setExchangeDraft(null);
    setExchangeReturnId(null);
    setInvoicePromotionAction(null);
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
            if (job.output === 'digital') {
              await updatePrintJobStatus.mutateAsync({ jobId: job.id, status: 'generated' });
              return;
            }

            if (job.print_connector_id) {
              toast.success('Ticket enviado a la cola del conector local.');
              return;
            }

            await updatePrintJobStatus.mutateAsync({ jobId: job.id, status: 'sent' });
            const result = await sendJobToLocalAgent(job);
            await updatePrintJobStatus.mutateAsync({
              jobId: job.id,
              status: 'printed',
              message: result.message ?? null,
              digitalPdfPath: result.pdf_path ?? null,
              digitalHtmlPath: result.html_path ?? null,
            });
            if (job.output === 'thermal') toast.success('Ticket enviado a impresora.');
          } catch (error) {
            await updatePrintJobStatus.mutateAsync({
              jobId: job.id,
              status: 'failed',
              message: error instanceof Error ? error.message : 'No se pudo imprimir.',
            });
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

  async function requestDigitalPdf(order: PosOrder): Promise<void> {
    if (!canDigital) {
      toast.error('No tienes permiso para generar tickets digitales.');
      return;
    }

    try {
      const jobs = await createPrintJob.mutateAsync({
        orderId: order.id,
        output: 'digital',
        copy: false,
        printerStationId: activePrinterStation?.id ?? null,
      });
      setLastPrintJobs(jobs);
      const job = jobs.find((item) => item.output === 'digital') ?? jobs[0];

      if (!job) {
        toast.error('No se pudo generar el PDF del ticket.');
        return;
      }

      await downloadTicketPdf(job);
      await updatePrintJobStatus.mutateAsync({ jobId: job.id, status: 'generated' });
      toast.success('PDF del ticket descargado.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo descargar el PDF.');
    }
  }
}

function CartLineRow({
  line,
  canDiscount,
  onChange,
  onSerials,
  onRemove,
}: {
  line: PosCartLine;
  canDiscount: boolean;
  onChange: (patch: Partial<PosCartLine>) => void;
  onSerials: () => void;
  onRemove: () => void;
}) {
  const stockIssue = line.quantity > line.available_stock;
  const serialCount = line.selected_serials?.length ?? 0;
  const serialIssue = line.tracking_type === 'serialized' && serialCount !== Number(line.quantity);

  const unitLabel = formatCartUnitPrice(line.unit_of_measure);
  const unitSuffix = formatUnitSuffix(line.unit_of_measure);

  return (
    <div
      className={cn(
        'bg-surface rounded-xl border border-border/80 p-3.5 shadow-xs transition-colors space-y-2.5',
        (stockIssue || serialIssue) && 'border-warning/50 bg-warning/5',
      )}
    >
      {/* ============================================================ */}
      {/* 1. Encabezado del Producto: Nombre Completo y Acciones        */}
      {/* ============================================================ */}
      <div className="flex items-start gap-3">
        <ProductImageView
          src={line.image_url ?? undefined}
          alt={line.name}
          variant="thumb"
          className="border-border bg-bg size-12 shrink-0 rounded-xl border"
        />
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex items-start justify-between gap-3">
            {/* Nombre Completo sin truncate */}
            <div className="min-w-0 flex-1">
              <p className="text-base font-bold text-text-primary leading-snug break-words">
                {line.name}
              </p>
            </div>

            {/* Badge de Stock y Botón Eliminar */}
            <div className="flex items-center gap-2 shrink-0">
              <span
                className={cn(
                  'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold border',
                  stockIssue
                    ? 'bg-warning/20 text-warning border-warning/60 animate-pulse'
                    : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border-emerald-500/40',
                )}
              >
                {stockIssue ? `⚠️ Stock: ${line.available_stock}` : `✓ Stock: ${line.available_stock}`}
                {unitSuffix ? ` ${unitSuffix.toLowerCase()}` : ''}
              </span>
              <Button
                size="icon-sm"
                variant="ghost"
                onClick={onRemove}
                aria-label="Eliminar linea"
                className="text-text-muted hover:text-danger hover:bg-danger/10 size-8 rounded-lg"
              >
                <Trash2 className="size-4" />
              </Button>
            </div>
          </div>

          {/* Subtítulo / Metadata del Producto */}
          <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-xs">
            <span className="font-mono text-xs font-bold text-text-primary bg-bg border border-border px-2 py-0.5 rounded shadow-2xs">
              {line.sku ?? line.barcode ?? line.product_id}
            </span>
            {line.product_variant_name && (
              <Badge variant="default" className="text-xs font-semibold px-2 py-0.5">
                {line.product_variant_name}
              </Badge>
            )}
            <span className="font-bold text-text-primary text-sm">
              {money(line.unit_price)} {unitLabel}
            </span>
            {unitSuffix && (
              <Badge variant="outline" className="text-xs font-bold uppercase tracking-wider text-text-primary bg-surface-subtle border-border px-2 py-0.5">
                {unitSuffix}
              </Badge>
            )}
            {line.price_list_name && (
              <Badge variant="outline" className="text-xs font-semibold text-text-primary border-border px-2 py-0.5">
                {line.price_list_name}
              </Badge>
            )}
            {line.tracking_type === 'serialized' && (
              <button
                type="button"
                className={cn('font-bold text-xs', serialIssue ? 'text-warning' : 'text-success')}
                onClick={onSerials}
              >
                IMEI {serialCount}/{line.quantity}
              </button>
            )}
          </div>
        </div>
      </div>

      {/* IMEIs / Seriales Seleccionados */}
      {line.tracking_type === 'serialized' && serialCount > 0 && (
        <div className="flex flex-wrap gap-1 pl-15">
          {line.selected_serials?.map((serial) => (
            <Badge key={serial.id} variant="default" className="font-mono text-xs font-bold px-2 py-0.5">
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

      {/* ============================================================ */}
      {/* 2. Barra de Opciones Reordenada: Cantidad, Descuento y Total  */}
      {/* ============================================================ */}
      <div className="flex flex-wrap items-center justify-between gap-3 pt-2.5 border-t border-border/60">
        {/* Selector de Cantidad */}
        <div className="flex items-center gap-1.5">
          <span className="text-xs text-text-primary font-bold">Cant:</span>
          <div className="flex items-center gap-1">
            <Button
              size="icon-sm"
              variant="outline"
              className="size-8 text-text-primary font-bold"
              onClick={() => onChange({ quantity: line.quantity - 1 })}
            >
              <Minus className="size-3.5" />
            </Button>
            <Input
              className="h-8 w-16 text-center text-sm font-bold text-text-primary bg-surface border-border"
              type="number"
              min="1"
              value={line.quantity}
              onChange={(event) => onChange({ quantity: Number(event.target.value) })}
            />
            <Button
              size="icon-sm"
              variant="outline"
              className="size-8 text-text-primary font-bold"
              onClick={() => onChange({ quantity: line.quantity + 1 })}
            >
              <Plus className="size-3.5" />
            </Button>
          </div>
          {unitSuffix && (
            <span className="text-xs font-bold text-text-primary uppercase">
              {unitSuffix}
            </span>
          )}
        </div>

        {/* Opciones de Descuento (Reordenadas para no comprimir el nombre) */}
        {canDiscount && (
          <div className="flex items-center gap-1.5">
            <span className="text-xs text-text-primary font-bold">Desc:</span>
            <Select
              value={line.discount_type ?? ''}
              onChange={(event) =>
                onChange({ discount_type: (event.target.value || null) as DiscountType | null })
              }
              className="h-8 text-xs w-28 text-text-primary font-medium"
            >
              <option value="">Sin desc.</option>
              <option value="percent">% Porcentaje</option>
              <option value="fixed">$ Monto</option>
            </Select>
            {line.discount_type && (
              <Input
                type="number"
                min="0"
                className="h-8 w-20 text-xs text-center font-bold text-text-primary"
                placeholder={line.discount_type === 'percent' ? '%' : '$'}
                value={line.discount_value ?? ''}
                onChange={(event) => onChange({ discount_value: Number(event.target.value || 0) })}
              />
            )}
          </div>
        )}

        {/* Botón de IMEI si es serializado */}
        {line.tracking_type === 'serialized' && (
          <Button
            variant={serialIssue ? 'secondary' : 'outline'}
            size="sm"
            className="h-8 text-xs font-bold"
            onClick={onSerials}
          >
            {serialCount > 0 ? 'Cambiar IMEI' : 'Asignar IMEI'}
          </Button>
        )}

        {/* Total Línea a la derecha */}
        <div className="flex items-center gap-2 ml-auto text-right">
          <span className="text-xs text-text-secondary font-bold">Total:</span>
          <span className="text-lg font-bold font-mono text-text-primary">
            {money(lineTotal(line))}
          </span>
        </div>
      </div>
    </div>
  );
}

function PaymentChip({
  payment,
  methods,
  rateTypes,
  onChange,
  onRemove,
  locked = false,
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
  locked?: boolean;
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
              ? `${rateType.name}${payment.exchange_rate ? ` @ ${formatLocalNumber(payment.exchange_rate)}` : ''}`
              : methodLabel(payment.method)}
          </p>
        </div>
        <Input
          className="h-9 text-right text-sm font-semibold"
          type="number"
          min="0"
          value={payment.amount}
          disabled={locked}
          onChange={(event) => onChange({ amount: Number(event.target.value) })}
          placeholder="Monto"
          data-testid={`pos-payment-amount-${payment.id}`}
        />
        <Button size="icon-sm" variant="ghost" disabled={locked} onClick={onRemove}>
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
              data-testid={`pos-payment-reference-${payment.id}`}
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
                    data-testid="pos-cash-open-branch"
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
                    data-testid="pos-cash-open-register"
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
                    type="text"
                    inputMode="decimal"
                    value={props.baseAmount}
                    onChange={(event) => props.onBaseAmountChange(event.target.value)}
                    placeholder="0.00"
                    data-testid="pos-cash-open-base"
                  />
                </LabeledControl>
                <LabeledControl label="Fondo VES">
                  <Input
                    type="text"
                    inputMode="decimal"
                    value={props.localAmount}
                    onChange={(event) => props.onLocalAmountChange(event.target.value)}
                    placeholder="0.00"
                    data-testid="pos-cash-open-local"
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
                data-testid="pos-cash-open-submit"
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
  search,
  onSearch,
  onToggle,
}: {
  line: PosCartLine;
  serials: ProductSerial[];
  loading: boolean;
  search: string;
  onSearch: (value: string) => void;
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

      <div className="relative">
        <Search
          className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
          aria-hidden="true"
        />
        <Input
          value={search}
          onChange={(event) => onSearch(event.target.value)}
          placeholder="Buscar IMEI o serial..."
          className="h-10 pl-9 font-mono"
          data-testid="pos-serial-search"
        />
        <p className="text-text-muted mt-1 px-1 text-xs">
          Escribe una parte del IMEI o escanéalo para encontrarlo rápidamente.
        </p>
      </div>

      {loading ? (
        <div className="border-border text-text-muted flex items-center gap-2 rounded border p-3 text-sm">
          <Loader2 className="size-4 animate-spin" /> Buscando IMEIs disponibles...
        </div>
      ) : serials.length === 0 ? (
        <div className="border-warning bg-warning/10 text-warning rounded border p-3 text-sm">
          No hay IMEIs disponibles que coincidan con la búsqueda.
        </div>
      ) : (
        <div className="divide-border border-border bg-surface max-h-[70vh] divide-y overflow-auto rounded-2xl border shadow-sm">
          {serials.map((serial) => {
            const checked = selectedIds.has(serial.id);
            const disabled = !checked && selectedIds.size >= Number(line.quantity);
            return (
              <TapButton
                key={serial.id}
                disabled={disabled}
                onPress={() => onToggle(serial)}
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
              </TapButton>
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
  rate,
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
  rate: { exchange_rate_type_id: number; code: string; name: string; rate: number } | null;
  priceListName: string;
  issue: string | null;
  onSelect: (methodId: number) => void;
}) {
  const remaining = calculatePaymentTotals(payments, cartTotal).remaining;
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
                Bs {formatLocalNumber(paymentAmountForCurrency(remaining, 'VES', rate.rate))}
              </p>
              <p className="text-xs text-white/60">{formatPosRateLabel(rate)}</p>
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
            const preview = previewQuickPayment(method, cartTotal, payments, rate);
            return (
              <TapButton
                key={method.id}
                onPress={() => onSelect(method.id)}
                data-testid={`pos-add-payment-${method.id}`}
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
              </TapButton>
            );
          })}
        </div>
      )}
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
  canCharge: boolean;
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
          description="Cuando armes o pongas en espera una venta aparecerá aquí para retomarla y cobrarla."
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
                    {order.seller?.name && (
                      <p className="text-text-muted mt-1 text-xs">Armada por {order.seller.name}</p>
                    )}
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
              {props.canCharge ? (
                <Button
                  className="h-11 w-full"
                  disabled={!props.selected}
                  onClick={props.onPaySelected}
                >
                  Cobrar seleccionado
                </Button>
              ) : null}
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

export function CashPanel(props: {
  session: CashRegisterSession;
  canMove: boolean;
  canClose: boolean;
  movement: { type: string; amount: string; notes: string };
  closeForm: CloseForm;
  rate: number | null;
  closing: boolean;
  onMovementChange: (value: { type: string; amount: string; notes: string }) => void;
  onCloseForm: (value: CloseForm) => void;
  onAddMovement: () => void;
  onCloseSession: () => void;
}) {
  const session = props.session;
  const isOpen = session.status === 'open';
  const blind = props.closeForm.blind;
  const difference = Number(session.difference_cash_usd ?? session.difference_base_amount ?? 0);
  const hasCounted =
    session.counted_base_amount !== null && session.counted_base_amount !== undefined;

  // Arqueo de cierre (misma logica que el modulo Cajas).
  const totals = cashCountTotals(props.closeForm.counts);
  const calculatedForm = props.closeForm.counts.length
    ? { ...props.closeForm, usd: String(totals.USD), ves: String(totals.VES) }
    : props.closeForm;
  const diff = closeDifference(session, calculatedForm, props.rate);
  const needsNote = hasDifference(diff.cashUsd, diff.cashVes);
  // En modo ciego la nota NO es obligatoria por diferencia (no se le revela al
  // cajero que hay descuadre). Solo en standard se exige para justificar.
  const canSubmitClose = !props.closing && (blind || !needsNote || props.closeForm.notes.trim().length > 0);

  return (
    <div className="space-y-4">
      {/* Hero: turno activo */}
      <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#17112f] via-[#241761] to-[#4338ca] p-5 text-white shadow-lg">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(14,165,233,0.22),transparent_45%)]" />
        <div className="relative flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 shadow-lg backdrop-blur">
              <Wallet className="size-6" aria-hidden="true" />
            </div>
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/55">
                Turno activo
              </p>
              <h3 className="text-lg leading-tight font-bold">
                {session.cash_register?.name ?? 'Caja POS'}
              </h3>
              <p className="text-sm text-white/70">Sesión #{session.id}</p>
            </div>
          </div>
          <Badge
            variant={isOpen ? 'success' : 'info'}
            className="bg-white/15 text-white shadow-sm backdrop-blur"
          >
            {isOpen ? 'Turno abierto' : session.status}
          </Badge>
        </div>
        <div className="relative mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
          <HeroStat label="Sucursal" value={session.branch?.name ?? '—'} />
          <HeroStat label="Apertura" value={formatPosTime(session.opened_at)} />
          <HeroStat label="Cajero" value={session.cashier?.name ?? '—'} />
        </div>
      </section>

      {/* Métricas clave (el esperado se oculta en modo ciego) */}
      <div className="grid gap-3 sm:grid-cols-2">
        <MetricCard
          icon={<Coins className="size-4 text-text-muted" aria-hidden="true" />}
          label="Fondo inicial"
          value={money(session.opening_base_amount ?? 0)}
        />
        {!blind && (
          <MetricCard
            icon={<CircleCheck className="size-4 text-success" aria-hidden="true" />}
            label="Esperado"
            value={money(session.expected_cash_usd ?? session.expected_base_amount ?? 0)}
            tone="success"
          />
        )}
      </div>

      {!blind && hasCounted && (
        <div
          className={cn(
            'flex items-center justify-between rounded-2xl border p-4 text-sm font-semibold',
            difference >= 0
              ? 'border-success/40 bg-success/10 text-success'
              : 'border-danger/40 bg-danger/10 text-danger',
          )}
          data-testid="pos-cash-difference"
        >
          <span>Diferencia de cierre</span>
          <span>{money(difference)}</span>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {props.canMove && (
          <PanelCard
            eyebrow="Caja"
            title="Movimiento extra"
            description="Entradas, salidas o ajustes de efectivo fuera de una venta."
            icon={<SlidersHorizontal className="size-4 text-text-muted" aria-hidden="true" />}
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
                  type="text"
                  inputMode="decimal"
                  value={props.movement.amount}
                  onChange={(event) =>
                    props.onMovementChange({ ...props.movement, amount: event.target.value })
                  }
                  placeholder="0.00"
                  data-testid="pos-cash-movement-amount"
                />
              </LabeledControl>
              <LabeledControl label="Motivo">
                <Input
                  value={props.movement.notes}
                  onChange={(event) =>
                    props.onMovementChange({ ...props.movement, notes: event.target.value })
                  }
                  placeholder="Motivo del movimiento"
                  data-testid="pos-cash-movement-notes"
                />
              </LabeledControl>
              <Button
                className="h-11 w-full"
                onClick={props.onAddMovement}
                data-testid="pos-cash-movement-submit"
              >
                Registrar movimiento
              </Button>
            </div>
          </PanelCard>
        )}

        {props.canClose && (
          <PanelCard
            eyebrow="Cierre"
            title="Cerrar caja"
            description={
              blind
                ? 'El monto esperado está oculto. Cuenta el efectivo sin referencia.'
                : 'Ingresa el efectivo contado para comparar contra el esperado.'
            }
            tone="danger"
            icon={<ArrowDownToLine className="size-4 text-danger" aria-hidden="true" />}
          >
            <div className="space-y-3">
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-text-muted">
                  {blind ? 'Cierre ciego activo.' : 'Puedes contar con referencia o usar cierre ciego.'}
                </p>
                <Button
                  type="button"
                  size="sm"
                  variant={blind ? 'primary' : 'outline'}
                  onClick={() =>
                    props.onCloseForm({
                      ...props.closeForm,
                      blind: !blind,
                      usd: blind ? props.closeForm.usd : '',
                      ves: blind ? props.closeForm.ves : '',
                      counts: blind ? props.closeForm.counts : [],
                    })
                  }
                  data-testid="pos-cash-blind-toggle"
                >
                  {blind ? (
                    <EyeOff className="size-4" aria-hidden="true" />
                  ) : (
                    <Eye className="size-4" aria-hidden="true" />
                  )}
                  {blind ? 'Cierre ciego activo' : 'Usar cierre ciego'}
                </Button>
              </div>

              <div className="grid gap-2 sm:grid-cols-2">
                <Input
                  type="text"
                  inputMode="decimal"
                  value={calculatedForm.usd}
                  readOnly={props.closeForm.counts.length > 0}
                  onChange={(event) =>
                    props.onCloseForm({ ...props.closeForm, usd: event.target.value })
                  }
                  placeholder="Efectivo contado USD"
                  data-testid="pos-cash-closing-amount"
                />
                <Input
                  type="text"
                  inputMode="decimal"
                  value={props.closeForm.ves}
                  onChange={(event) =>
                    props.onCloseForm({ ...props.closeForm, ves: event.target.value })
                  }
                  placeholder="Efectivo contado Bs (VES)"
                  data-testid="pos-cash-closing-ves"
                />
              </div>

              <div className="space-y-3 rounded-lg border border-border/70 p-3">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm font-semibold">Conteo por denominaciones (USD)</p>
                  {props.closeForm.counts.length > 0 && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => props.onCloseForm({ ...props.closeForm, counts: [] })}
                    >
                      Limpiar conteo
                    </Button>
                  )}
                </div>
                <p className="text-xs text-text-muted">
                  Si registras denominaciones, el total en USD se calcula automáticamente. El
                  efectivo en bolívares se ingresa manualmente.
                </p>
                <DenominationGrid currency="USD" form={props.closeForm} onForm={props.onCloseForm} />
              </div>

              <div className="grid gap-2 rounded-lg border border-border/70 p-2 text-sm sm:grid-cols-2">
                <div>
                  <p className="text-text-muted text-xs uppercase">Declarado USD</p>
                  <p className="font-semibold">{money(Number(calculatedForm.usd || 0))}</p>
                </div>
                <div>
                  <p className="text-text-muted text-xs uppercase">Declarado Bs (VES)</p>
                  <p className="font-semibold">{localMoney(Number(calculatedForm.ves || 0))}</p>
                </div>
                <div>
                  <p className="text-text-muted text-xs uppercase">Total equiv. USD</p>
                  <p className="font-semibold">{money(diff.declaredBase)}</p>
                </div>
                <span className="text-text-muted rounded bg-bg/40 px-2 py-1 text-[10px] leading-tight">
                  El total equiv. USD = USD + (Bs ÷ tasa). Cada moneda se declara por separado.
                </span>
                {!blind && (
                  <div>
                    <p className="text-text-muted text-xs uppercase">Esperado USD</p>
                    <p className="font-semibold">{money(diff.expectedUsd)}</p>
                  </div>
                )}
                {!blind && (
                  <div>
                    <p className="text-text-muted text-xs uppercase">Diferencia física USD</p>
                    <p className="font-semibold">{money(diff.cashUsd)}</p>
                  </div>
                )}
                {!blind && (
                  <div>
                    <p className="text-text-muted text-xs uppercase">Esperado VES</p>
                    <p className="font-semibold">{localMoney(diff.expectedVes)}</p>
                  </div>
                )}
                {!blind && (
                  <div>
                    <p className="text-text-muted text-xs uppercase">Diferencia física VES</p>
                    <p className="font-semibold">{localMoney(diff.cashVes)}</p>
                  </div>
                )}
                {blind && (
                  <p className="bg-primary/5 rounded p-2 text-xs text-text-muted sm:col-span-2">
                    La diferencia se calculará al confirmar el cierre y quedará visible para el
                    responsable.
                  </p>
                )}
              </div>

              <Input
                value={props.closeForm.notes}
                onChange={(event) =>
                  props.onCloseForm({ ...props.closeForm, notes: event.target.value })
                }
                placeholder={
                  blind ? 'Notas de cierre (opcional)' : needsNote ? 'Nota obligatoria por diferencia' : 'Notas de cierre'
                }
                data-testid="pos-cash-closing-notes"
              />

              <Button
                className="h-11 w-full"
                variant="danger"
                disabled={!canSubmitClose}
                onClick={props.onCloseSession}
                data-testid="pos-cash-close-submit"
              >
                {props.closing && <Loader2 className="size-4 animate-spin" aria-hidden="true" />}
                <ArrowUpFromLine className="size-4" aria-hidden="true" />
                Cerrar turno
              </Button>
            </div>
          </PanelCard>
        )}
      </div>
    </div>
  );
}

function HeroStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-white/10 bg-white/10 p-3 backdrop-blur">
      <p className="text-[10px] font-semibold tracking-wide text-white/55 uppercase">{label}</p>
      <p className="mt-0.5 truncate text-sm font-semibold">{value}</p>
    </div>
  );
}

function formatPosTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
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
  onDownloadPdf,
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
  onDownloadPdf: () => void;
}) {
  if (!order) return <p className="text-text-muted text-sm">Aun no hay recibo en esta sesion.</p>;
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
          <Button variant="outline" disabled={busy} onClick={onDownloadPdf}>
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
                <span className="font-semibold">{money(item.total_base_amount ?? 0)}</span>
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
  icon,
  tone = 'default',
  children,
  className,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  action?: React.ReactNode;
  icon?: React.ReactNode;
  tone?: 'default' | 'danger';
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section
      className={cn(
        'border-border bg-surface rounded-2xl border p-4 shadow-sm',
        tone === 'danger' && 'border-danger/30',
        className,
      )}
    >
      <div className="mb-4 flex items-start justify-between gap-3">
        <div className="flex items-start gap-2.5">
          {icon ? <span className="mt-0.5 shrink-0">{icon}</span> : null}
          <div>
            {eyebrow ? (
              <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">
                {eyebrow}
              </p>
            ) : null}
            <h3 className="mt-1 text-lg font-semibold tracking-tight">{title}</h3>
            {description ? <p className="text-text-muted mt-1 text-sm">{description}</p> : null}
          </div>
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
  icon,
}: {
  label: string;
  value: string;
  tone?: 'default' | 'success';
  icon?: React.ReactNode;
}) {
  return (
    <div className="border-border bg-surface rounded-2xl border p-4 shadow-sm">
      <div className="flex items-center justify-between gap-2">
        <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">{label}</p>
        {icon ? <span className="shrink-0">{icon}</span> : null}
      </div>
      <p className={cn('mt-2 text-2xl font-bold', tone === 'success' && 'text-success')}>{value}</p>
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
  className,
}: {
  label: string;
  value: number;
  muted?: boolean;
  currency?: CurrencyCode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex items-center justify-between text-sm',
        muted ? 'text-text-secondary opacity-90' : 'text-text-primary font-medium',
        className,
      )}
    >
      <span className="font-medium">{label}</span>
      <span className="font-bold font-mono">
        {currency === 'VES' ? `Bs ${formatLocalNumber(value)}` : money(value)}
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
    case 'promotions':
      return 'Promociones';
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
  promotionPaymentIssue: string | null;
  priceListPaymentIssue: string | null;
}): string | null {
  if (!input.canCheckout) return 'No tienes permiso pos.checkout para cobrar ventas.';
  if (!input.hasSession) return 'No hay caja abierta para cobrar.';
  if (input.cartCount === 0) return 'Agrega al menos un producto para cobrar.';
  if (input.promotionPaymentIssue) return input.promotionPaymentIssue;
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
    if (payment.method === 'customer_credit') continue;
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
  rate: { exchange_rate_type_id: number; code: string; name: string; rate: number } | null,
): { amountLabel: string; detail: string } {
  const remaining = Math.max(0, total - calculatePaymentTotals(payments, total).paid);
  const currency = method.currency_mode === 'VES' ? 'VES' : 'USD';
  const amount = paymentAmountForCurrency(remaining, currency, rate?.rate ?? null);
  const amountLabel = currency === 'VES' ? `Bs ${formatLocalNumber(amount)}` : money(amount);
  const detail =
    currency === 'VES'
      ? `${rate?.name ?? rate?.code ?? 'Tasa'}${rate?.rate ? ` @ ${formatLocalNumber(rate.rate)}` : ' sin valor activo'}`
      : methodLabel(method.method);

  return { amountLabel, detail };
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
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(roundMoney(Number(value || 0)));
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
