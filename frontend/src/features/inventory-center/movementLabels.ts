/**
 * Etiquetas en español para el modulo de inventario:
 *  - Tipos de StockMovement (columna "Tipo" en Movimientos y Kardex).
 *  - Tipos de referencia (columna "Ref." / "Referencia") que llegan como
 *    nombre corto de clase (InventoryTransfer) o FQCN (App\Modules\...\InventoryTransfer)
 *    o strings crudos del sync ('product_entry', 'stock_count', 'sync_snapshot').
 */

export const MOVEMENT_TYPE_LABELS: Record<string, string> = {
  purchase: 'Compra',
  purchase_return: 'Devolución de compra',
  sale: 'Venta',
  sale_return: 'Devolución de venta',
  adjustment_in: 'Ajuste de entrada',
  adjustment_out: 'Ajuste de salida',
  transfer_in: 'Traslado (entrada)',
  transfer_out: 'Traslado (salida)',
  transfer_request_in: 'Transferencia inter-empresa (entrada)',
  transfer_request_out: 'Transferencia inter-empresa (salida)',
  return_in: 'Devolución (entrada)',
  return_out: 'Devolución (salida)',
  damaged: 'Dañado',
  reserved: 'Reservado',
  released: 'Liberado',
  entry: 'Entrada',
  exit: 'Salida',
  'adjustment': 'Ajuste',
};

/**
 * Tipos de movimiento que representan ENTRADAS de stock (verde en badges).
 */
export const MOVEMENT_IN_TYPES = new Set([
  'purchase',
  'sale_return',
  'adjustment_in',
  'transfer_in',
  'transfer_request_in',
  'return_in',
  'released',
  'entry',
]);

export function movementTypeLabel(type: string): string {
  return MOVEMENT_TYPE_LABELS[type] ?? type.replace(/_/g, ' ');
}

const REFERENCE_TYPE_LABELS: Record<string, string> = {
  InventoryTransfer: 'Traslado',
  InventoryTransferRequest: 'Solicitud inter-empresa',
  PurchaseOrder: 'Orden de compra',
  PurchaseReturn: 'Devolución de compra',
  ProductEntry: 'Entrada de stock',
  ProductExit: 'Salida de stock',
  Sale: 'Venta',
  PosOrder: 'Venta POS',
  SalesReturn: 'Devolución de venta',
  WarrantyClaim: 'Reclamo de garantía',
  StockCount: 'Conteo de inventario',
  CashRegisterSession: 'Sesión de caja',
  FinancialAdjustment: 'Ajuste financiero',
  AccountsReceivable: 'Cuenta por cobrar',
  AccountsPayable: 'Cuenta por pagar',
  product_entry: 'Entrada de stock',
  product_exit: 'Salida de stock',
  stock_count: 'Conteo de inventario',
  sync_snapshot: 'Sincronización',
};

function shortClassName(refType: string): string {
  const parts = refType.split('\\');
  return parts[parts.length - 1] ?? refType;
}

function prettifyShort(short: string): string {
  return short
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/_/g, ' ')
    .trim();
}

export function referenceTypeLabel(refType: string | null | undefined): string {
  if (!refType) return '—';
  if (REFERENCE_TYPE_LABELS[refType]) return REFERENCE_TYPE_LABELS[refType];

  const short = shortClassName(refType);
  return REFERENCE_TYPE_LABELS[short] ?? prettifyShort(short);
}

/**
 * Mapea reference_type + reference_id del backend a una ruta del frontend
 * cuando es clickeable. Si retorna null, se muestra como texto plano.
 */
export function referenceLink(
  refType: string | null | undefined,
  refId: number | string | null | undefined,
): { label: string; to: string } | null {
  if (!refType || refId == null) return null;

  const short = shortClassName(refType);
  if (short === 'InventoryTransferRequest') {
    return {
      label: 'Solicitud inter-empresa',
      to: `/inventory-transfer-requests/${refId}`,
    };
  }
  if (short === 'InventoryTransfer') {
    return {
      label: 'Traslado',
      to: `/transfers/${refId}`,
    };
  }
  if (short === 'PurchaseOrder') {
    return { label: 'Orden de compra', to: `/purchases/${refId}` };
  }
  if (short === 'Sale' || short === 'PosOrder') {
    return { label: 'Venta', to: `/sales/${refId}` };
  }
  return null;
}
