export type DiscountType = 'percent' | 'fixed';
export type CurrencyCode = 'USD' | 'VES';

export interface PosCartLine {
  id: string;
  product_id: number;
  name: string;
  sku?: string | null;
  barcode?: string | null;
  warehouse_id: number;
  quantity: number;
  available_stock: number;
  unit_price: number;
  base_unit_price?: number | null;
  currency: CurrencyCode;
  base_currency?: CurrencyCode | null;
  discount_type?: DiscountType | null;
  discount_value?: number | null;
  discount_reason?: string | null;
  price_list_id?: number | null;
  price_list_name?: string | null;
  price_issue?: string | null;
  /**
   * Tasa de cambio asociada a esta linea. Viene del `quote` que retorna
   * `GET /products/{id}/price` y refleja la tasa anclada al producto
   * (ej. PARALELO), no la tasa default del bootstrap. El POS la usa
   * para mostrar el equivalente VES correcto en la sidebar.
   */
  exchange_rate?: number | null;
  exchange_rate_type_id?: number | null;
  exchange_rate_type_code?: string | null;
  /**
   * URL de la imagen (del listado de productos, sin auth requerida).
   * Usado por CartLineRow para mostrar un thumbnail en el carrito.
   * Opcional: el componente hace fallback si no esta presente.
   */
  image_url?: string | null;
  tracking_type?: string | null;
  /**
   * Si `false`, el producto no exige stock ni genera movimiento de
   * inventario en el checkout. Si es `undefined` o `true`, se valida
   * stock y se descuenta del warehouse al confirmar la venta.
   */
  track_stock?: boolean;
  selected_serials?: Array<{
    id: number;
    serial_type?: string | null;
    serial_number: string;
  }>;
}

export interface PosPaymentLine {
  id: string;
  method: string;
  currency: CurrencyCode;
  amount: number;
  received_amount?: number | null;
  reference?: string | null;
  payment_method_id?: number | null;
  exchange_rate_type_id?: number | null;
  exchange_rate?: number | null;
  status?: 'captured' | 'pending' | 'failed';
}

export interface CartTotals {
  subtotal: number;
  discount: number;
  total: number;
}

export interface PaymentTotals {
  paid: number;
  remaining: number;
  change: number;
  change_currency?: CurrencyCode | null;
  change_amount?: number;
  change_rate?: number | null;
}

export function clampQuantity(quantity: number, available: number): number {
  const parsed = Number.isFinite(quantity) ? quantity : 1;
  const positive = Math.max(1, parsed);

  return Math.min(positive, Math.max(0, available));
}

export function lineSubtotal(line: Pick<PosCartLine, 'quantity' | 'unit_price'>): number {
  return roundMoney(line.quantity * line.unit_price);
}

export function lineDiscount(line: Pick<PosCartLine, 'quantity' | 'unit_price' | 'discount_type' | 'discount_value'>): number {
  const subtotal = lineSubtotal(line);
  const value = Math.max(0, Number(line.discount_value ?? 0));

  if (!line.discount_type || value <= 0) return 0;
  if (line.discount_type === 'percent') return roundMoney(Math.min(subtotal, subtotal * (value / 100)));

  return roundMoney(Math.min(subtotal, value));
}

export function lineTotal(line: PosCartLine): number {
  return roundMoney(lineSubtotal(line) - lineDiscount(line));
}

export function calculateCartTotals(lines: PosCartLine[]): CartTotals {
  const subtotal = roundMoney(lines.reduce((sum, line) => sum + lineSubtotal(line), 0));
  const discount = roundMoney(lines.reduce((sum, line) => sum + lineDiscount(line), 0));

  return {
    subtotal,
    discount,
    total: roundMoney(subtotal - discount),
  };
}

export function calculatePaymentTotals(payments: PosPaymentLine[], total: number): PaymentTotals {
  const capturedPayments = payments.filter((payment) => (payment.status ?? 'captured') === 'captured');
  const paid = roundMoney(capturedPayments.reduce((sum, payment) => sum + paymentBaseAmount(payment), 0));
  const cashReceived = payments
    .filter((payment) => payment.method === 'cash')
    .reduce((sum, payment) => sum + Math.max(0, Number(payment.received_amount ?? payment.amount ?? 0)), 0);
  const cashAmount = payments
    .filter((payment) => payment.method === 'cash')
    .reduce((sum, payment) => sum + Math.max(0, Number(payment.amount || 0)), 0);
  const overpaidBase = roundMoney(Math.max(0, paid - total));
  const cashChange = roundMoney(Math.max(0, cashReceived - cashAmount));
  const changeBase = Math.max(cashChange, overpaidBase);
  const changePayment = cashChange >= overpaidBase && cashChange > 0
    ? lastCapturedPayment(capturedPayments.filter((payment) => payment.method === 'cash'))
    : lastCapturedPayment(capturedPayments);
  const changeCurrency = changePayment?.currency ?? null;
  const changeAmount = changeCurrency === 'VES'
    ? roundMoney(changeBase * Number(changePayment?.exchange_rate || 0))
    : changeBase;

  return {
    paid,
    remaining: roundMoney(Math.max(0, total - paid)),
    change: roundMoney(changeBase),
    change_currency: changeCurrency,
    change_amount: roundMoney(changeAmount),
    change_rate: changePayment?.exchange_rate ?? null,
  };
}

export function paymentBaseAmount(payment: PosPaymentLine): number {
  const amount = Math.max(0, Number(payment.amount || 0));
  if (payment.currency === 'VES') {
    const rate = Number(payment.exchange_rate || 0);
    return rate > 0 ? roundMoney(amount / rate) : 0;
  }

  return amount;
}

function lastCapturedPayment(payments: PosPaymentLine[]): PosPaymentLine | null {
  return payments.length > 0 ? payments[payments.length - 1] ?? null : null;
}

export function hasStockIssue(lines: PosCartLine[]): boolean {
  // Productos con track_stock=false (servicios, suscripciones, conceptos
  // facturables) no requieren stock disponible y no generan movimiento
  // de inventario al confirmar la venta. Ver docs/SPRINT2_UX.md (QW10).
  return lines.some(
    (line) => line.track_stock !== false && line.quantity > line.available_stock,
  );
}

export function hasPriceIssue(lines: PosCartLine[]): boolean {
  return lines.some((line) => Boolean(line.price_issue));
}

export function firstPriceIssue(lines: PosCartLine[]): string | null {
  return lines.find((line) => line.price_issue)?.price_issue ?? null;
}

export function missingSerialIssue(lines: PosCartLine[]): string | null {
  const line = lines.find((item) => item.tracking_type === 'serialized' && (item.selected_serials?.length ?? 0) !== Number(item.quantity));
  if (!line) return null;

  return `${line.name} requiere ${Number(line.quantity)} IMEI/serial seleccionado.`;
}

export function roundMoney(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
