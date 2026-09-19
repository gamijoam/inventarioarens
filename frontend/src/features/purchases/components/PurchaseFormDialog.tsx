/**
 * PurchaseFormDialog: dialog full-width tipo ERP para crear un PurchaseOrder en
 * estado `draft` (POST /api/purchases).
 *
 * Características ERP:
 * - Cabecera compacta de compra (proveedor, documento, fechas, moneda).
 * - Barra Sticky superior con buscador rápido de productos (quick add),
 *   conteo en vivo de unidades/items y selector de almacén por defecto.
 * - Modo Vista ERP (Tabla compacta interactiva) y Modo Tarjetas detalladas.
 * - Botón inferior al final de la lista para añadir productos sin tener que scrollear arriba.
 * - Conteo en vivo de unidades físicas y subtotales por cada tarjeta/fila.
 */
import { useEffect, useMemo, useState } from 'react';
import {
  Boxes,
  ChevronDown,
  ChevronUp,
  FileText,
  LayoutGrid,
  Package,
  PackagePlus,
  Plus,
  Table,
} from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Label } from '@/components/ui/Label';
import { useCreatePurchase } from '@/features/purchases/api';
import { useExchangeRateTypes, useWarehouses } from '@/features/inventory-center/api';
import { StorePurchaseSchema, type PurchaseItemInput } from '@/features/purchases/schemas';
import { SupplierAutocomplete, type SupplierOption } from './SupplierAutocomplete';
import { PurchaseItemRow, type PurchaseItemRowValue } from './PurchaseItemRow';
import { PurchaseItemTableRow } from './PurchaseItemTableRow';
import { ProductAutocomplete, type ProductAutocompleteOption } from './ProductAutocomplete';
import type { ImeiInput } from './ImeiListInput';
import { cn } from '@/lib/cn';
import { todayDateString } from '@/lib/format';

interface PurchaseFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (purchaseId: number) => void;
}

function emptyItem(warehouseId: number | null = null): PurchaseItemRowValue {
  return {
    warehouse_id: warehouseId,
    product_id: null,
    product_variant_id: null,
    product_info: null,
    quantity: '',
    unit_cost: '',
    new_sale_price: '',
    update_sale_price: false,
    serial_units: [],
  };
}

export function PurchaseFormDialog({ open, onOpenChange, onCreated }: PurchaseFormDialogProps) {
  const create = useCreatePurchase();
  const { data: rateTypes = [] } = useExchangeRateTypes();
  const { data: warehouses = [] } = useWarehouses();

  // Almacén por defecto de la orden (para agilizar la adición masiva)
  const [defaultWarehouseId, setDefaultWarehouseId] = useState<number | null>(null);

  useEffect(() => {
    if (warehouses.length > 0 && defaultWarehouseId === null && warehouses[0]) {
      setDefaultWarehouseId(warehouses[0].id);
    }
  }, [warehouses, defaultWarehouseId]);

  // Modo de visualización: 'table' (Vista ERP) o 'cards' (Tarjetas)
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');

  // Estado para contraer la cabecera de datos de compra y ganar espacio vertical
  const [headerCollapsed, setHeaderCollapsed] = useState(false);

  // Header state.
  const [supplierId, setSupplierId] = useState<number | null>(null);
  const [, setSupplier] = useState<SupplierOption | null>(null);
  const [documentNumber, setDocumentNumber] = useState('');
  const [issuedAt, setIssuedAt] = useState<string>(todayDateString());
  const [dueDate, setDueDate] = useState<string>('');
  const [currency, setCurrency] = useState<'USD' | 'VES'>('USD');
  const [rateTypeId, setRateTypeId] = useState<number | null>(null);

  // Items state.
  const [items, setItems] = useState<PurchaseItemRowValue[]>([emptyItem()]);
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
  const [expandedTableRows, setExpandedTableRows] = useState<Set<number>>(new Set());
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  // Totales en vivo (Importe, Unidades acumuladas y Productos válidos)
  const totals = useMemo(() => {
    let base = 0;
    let totalUnits = 0;
    let validProducts = 0;
    for (const item of items) {
      const quantity = Number(item.quantity);
      const cost = Number(item.unit_cost);
      if (item.product_id) validProducts++;
      if (Number.isFinite(quantity) && quantity > 0) {
        totalUnits += quantity;
      }
      if (Number.isFinite(quantity) && Number.isFinite(cost)) {
        base += quantity * cost;
      }
    }
    return { base, totalUnits, validProducts };
  }, [items]);

  function reset() {
    setSupplierId(null);
    setSupplier(null);
    setDocumentNumber('');
    setIssuedAt(todayDateString());
    setDueDate('');
    setCurrency('USD');
    setRateTypeId(null);
    setItems([emptyItem(defaultWarehouseId)]);
    setCollapsed(new Set());
    setExpandedTableRows(new Set());
    setFieldErrors({});
  }

  function addItem() {
    const targetWh = defaultWarehouseId ?? (warehouses[0]?.id ?? null);
    setItems((prev) => [...prev, emptyItem(targetWh)]);
    // En vista tarjetas colapsa las anteriores para ver la nueva
    setCollapsed(new Set(Array.from({ length: items.length }, (_, i) => i)));
  }

  // Agregado rápido desde la barra superior fija
  function addProductQuick(product: ProductAutocompleteOption) {
    const prevCost =
      product.last_purchase_cost != null && Number(product.last_purchase_cost) > 0
        ? Number(product.last_purchase_cost)
        : (product.average_cost != null && Number(product.average_cost) > 0
            ? Number(product.average_cost)
            : '');

    const targetWarehouse = defaultWarehouseId ?? (warehouses[0]?.id ?? null);

    const newItem: PurchaseItemRowValue = {
      warehouse_id: targetWarehouse,
      product_id: product.id,
      product_variant_id: null,
      product_info: product,
      quantity: 1,
      unit_cost: prevCost !== '' ? String(prevCost) : '',
      new_sale_price: '',
      update_sale_price: false,
      serial_units: [],
    };

    setItems((prev) => {
      // Si la primera fila está vacía y sin producto, reemplazarla
      if (prev.length === 1 && prev[0]?.product_id == null) {
        return [newItem];
      }
      return [...prev, newItem];
    });

    toast.success(`"${product.name}" agregado a la compra (1 ud).`);
  }

  function updateItem(index: number, next: PurchaseItemRowValue) {
    setItems((prev) => prev.map((it, i) => (i === index ? next : it)));
  }

  function removeItem(index: number) {
    if (items.length <= 1) {
      // Si solo queda uno, lo resetea a vacío
      setItems([emptyItem(defaultWarehouseId)]);
      return;
    }
    setItems((prev) => prev.filter((_, i) => i !== index));
    setCollapsed((prev) => {
      const next = new Set(prev);
      next.delete(index);
      return next;
    });
    setExpandedTableRows((prev) => {
      const next = new Set(prev);
      next.delete(index);
      return next;
    });
  }

  function toggleCollapse(index: number) {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(index)) next.delete(index);
      else next.add(index);
      return next;
    });
  }

  function toggleTableExpand(index: number) {
    setExpandedTableRows((prev) => {
      const next = new Set(prev);
      if (next.has(index)) next.delete(index);
      else next.add(index);
      return next;
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const localErrors: Record<string, string> = {};
    for (const [index, item] of items.entries()) {
      const isSerialized = item.product_info?.tracking_type === 'serialized';
      if (!isSerialized) continue;

      const quantity = Number(item.quantity);
      const serials = item.serial_units
        .map((serial) => serial.serial_number.trim())
        .filter(Boolean);
      const unique = new Set(
        item.serial_units.map(
          (serial) => `${serial.serial_type}:${serial.serial_number.trim().toUpperCase()}`,
        ),
      );

      if (!Number.isInteger(quantity) || quantity <= 0) {
        localErrors[`items.${index}.quantity`] =
          'Los productos serializados deben comprarse en unidades enteras.';
      } else if (serials.length !== quantity || item.serial_units.length !== quantity) {
        localErrors[`items.${index}.serial_units`] =
          `Captura ${quantity} IMEI/serial(es) para este producto.`;
      } else if (unique.size !== item.serial_units.length) {
        localErrors[`items.${index}.serial_units`] =
          'No puedes repetir IMEIs o seriales dentro de la misma linea.';
      }
    }

    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors);
      toast.error('Revisa los IMEIs/seriales: debe haber uno por cada unidad comprada.');
      return;
    }

    const serializedItems: PurchaseItemInput[] = items.map((it) => {
      const serialUnits: ImeiInput[] =
        it.product_info?.tracking_type === 'serialized'
          ? it.serial_units.filter((s) => s.serial_number.trim() !== '')
          : [];
      return {
        warehouse_id: it.warehouse_id ?? 0,
        product_id: it.product_id ?? 0,
        product_variant_id: it.product_variant_id ?? undefined,
        quantity: Number(it.quantity) || 0,
        unit_cost: Number(it.unit_cost) || 0,
        new_sale_price:
          it.update_sale_price && Number(it.new_sale_price) > 0
            ? Number(it.new_sale_price)
            : undefined,
        serial_units: serialUnits,
      };
    });

    const payload = {
      supplier_id: supplierId ?? undefined,
      document_number: documentNumber.trim() || undefined,
      issued_at: issuedAt || undefined,
      due_date: dueDate || undefined,
      purchase_currency: currency,
      exchange_rate_type_id: currency === 'VES' ? (rateTypeId ?? undefined) : undefined,
      items: serializedItems,
    };

    const parsed = StorePurchaseSchema.safeParse(payload);
    if (!parsed.success) {
      const mapped: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = issue.path.join('.');
        mapped[key] ??= issue.message;
      }
      setFieldErrors(mapped);
      toast.error('Hay errores en el formulario. Revisa los campos resaltados.');
      return;
    }

    const itemsWithoutWarehouse = items.filter((it) => !it.warehouse_id);
    if (itemsWithoutWarehouse.length > 0) {
      toast.error('Todos los items deben tener un almacén seleccionado.');
      return;
    }

    const itemsWithoutProduct = items.filter((it) => !it.product_id);
    if (itemsWithoutProduct.length > 0) {
      toast.error('Hay líneas sin producto seleccionado. Selecciona un producto o elimina la línea.');
      return;
    }

    setSubmitting(true);
    try {
      const result = await create.mutateAsync(parsed.data);
      toast.success('Compra creada en borrador exitosamente.');
      onCreated?.((result as { id: number }).id);
      reset();
      onOpenChange(false);
    } catch (err) {
      if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error('Error al crear la compra.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex h-[min(96vh,1100px)] max-w-[1450px] flex-col gap-0 overflow-hidden p-0">
        {/* Cabecera Principal del Dialog */}
        <DialogHeader className="border-border shrink-0 border-b px-6 py-4 bg-surface">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg shadow-xs">
                <PackagePlus className="size-5" />
              </div>
              <div>
                <DialogTitle className="text-lg font-bold">Nueva Orden de Compra</DialogTitle>
                <DialogDescription className="text-xs text-text-muted mt-0.5">
                  Registra los productos que ingresarán al inventario con su costo de reposición.
                </DialogDescription>
              </div>
            </div>

            {/* Badges de resumen en el header superior */}
            <div className="flex items-center gap-2">
              <Badge variant="outline" className="text-xs px-2.5 py-1 font-mono font-semibold">
                Líneas: {totals.validProducts}
              </Badge>
              <Badge variant="primary" className="text-xs px-2.5 py-1 font-bold flex items-center gap-1">
                <Boxes className="size-3.5" />
                <span>{totals.totalUnits} uds</span>
              </Badge>
              <div className="bg-primary/10 text-primary font-mono font-black text-sm px-3 py-1 rounded-md border border-primary/20">
                ${totals.base.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} {currency}
              </div>
            </div>
          </div>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <div className="min-h-0 flex-1 flex flex-col overflow-y-auto">
            {/* 1. Sección Compacta de Datos de la Compra */}
            <div className="border-b border-border bg-surface-subtle/30 px-6 py-3 shrink-0">
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-2 text-xs font-bold uppercase text-text-secondary">
                  <FileText className="text-primary size-4" />
                  <span>Datos de la Factura / Proveedor</span>
                </div>
                <button
                  type="button"
                  onClick={() => setHeaderCollapsed(!headerCollapsed)}
                  className="text-xs text-text-muted hover:text-text-primary flex items-center gap-1 cursor-pointer"
                >
                  <span>{headerCollapsed ? 'Mostrar datos' : 'Ocultar datos'}</span>
                  {headerCollapsed ? <ChevronDown className="size-3.5" /> : <ChevronUp className="size-3.5" />}
                </button>
              </div>

              {!headerCollapsed && (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 pt-1 text-xs">
                  {/* Proveedor */}
                  <div className="space-y-1">
                    <Label className="text-xs font-semibold">Proveedor</Label>
                    <SupplierAutocomplete
                      value={supplierId}
                      onChange={(id, sup) => {
                        setSupplierId(id);
                        setSupplier(sup ?? null);
                      }}
                    />
                  </div>

                  {/* N° Factura / Documento */}
                  <div className="space-y-1">
                    <Label htmlFor="doc-number" className="text-xs font-semibold">N° Factura / Control</Label>
                    <Input
                      id="doc-number"
                      value={documentNumber}
                      onChange={(e) => setDocumentNumber(e.target.value)}
                      placeholder="Ej. FACT-00123"
                      maxLength={100}
                      className="h-10 text-xs font-mono"
                    />
                  </div>

                  {/* Fechas */}
                  <div className="grid grid-cols-2 gap-2">
                    <div className="space-y-1">
                      <Label htmlFor="issued-at" className="text-xs font-semibold">Emisión</Label>
                      <Input
                        id="issued-at"
                        type="date"
                        value={issuedAt}
                        onChange={(e) => setIssuedAt(e.target.value)}
                        className="h-10 text-xs px-2"
                      />
                    </div>
                    <div className="space-y-1">
                      <Label htmlFor="due-date" className="text-xs font-semibold">Vence</Label>
                      <Input
                        id="due-date"
                        type="date"
                        value={dueDate}
                        onChange={(e) => setDueDate(e.target.value)}
                        className="h-10 text-xs px-2"
                      />
                    </div>
                  </div>

                  {/* Moneda y Almacén por defecto */}
                  <div className="grid grid-cols-2 gap-2">
                    <div className="space-y-1">
                      <Label className="text-xs font-semibold">Moneda</Label>
                      <Select
                        value={currency}
                        onChange={(e) => setCurrency(e.target.value as 'USD' | 'VES')}
                        className="h-10 text-xs"
                      >
                        <option value="USD">USD ($)</option>
                        <option value="VES">VES (Bs.)</option>
                      </Select>
                    </div>

                    <div className="space-y-1">
                      <Label className="text-xs font-semibold">Almacén rápido</Label>
                      <Select
                        value={defaultWarehouseId ? String(defaultWarehouseId) : ''}
                        onChange={(e) => setDefaultWarehouseId(e.target.value ? Number(e.target.value) : null)}
                        className="h-10 text-xs font-semibold"
                      >
                        {warehouses.map((wh) => (
                          <option key={wh.id} value={String(wh.id)}>
                            {wh.code}
                          </option>
                        ))}
                      </Select>
                    </div>
                  </div>

                  {currency === 'VES' && (
                    <div className="space-y-1 sm:col-span-2 lg:col-span-4 pt-1">
                      <Label className="text-xs font-semibold">Tipo de tasa de cambio</Label>
                      <Select
                        value={rateTypeId ? String(rateTypeId) : ''}
                        onChange={(e) => setRateTypeId(e.target.value ? Number(e.target.value) : null)}
                        className={cn('h-10 text-xs', fieldErrors.exchange_rate_type_id && 'border-danger')}
                      >
                        <option value="">— Seleccionar tipo de tasa —</option>
                        {rateTypes.map((rt) => (
                          <option key={rt.id} value={String(rt.id)}>
                            {rt.code} - {rt.name}
                          </option>
                        ))}
                      </Select>
                      {fieldErrors.exchange_rate_type_id && (
                        <p className="text-danger text-xs">{fieldErrors.exchange_rate_type_id}</p>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* 2. Barra Sticky de Entrada Rápida y Acciones (SIEMPRE VISIBLE) */}
            <div className="sticky top-0 z-20 border-b border-border bg-surface px-6 py-3 shadow-xs space-y-2.5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                {/* Contadores en vivo */}
                <div className="flex items-center gap-2">
                  <Badge variant="primary" className="text-xs px-3 py-1 font-bold flex items-center gap-1.5 shadow-xs">
                    <Package className="size-3.5" />
                    <span>{totals.validProducts} de {items.length} productos</span>
                  </Badge>

                  <Badge
                    variant={totals.totalUnits > 0 ? 'success' : 'outline'}
                    className="text-xs px-3 py-1 font-bold flex items-center gap-1.5 shadow-xs"
                  >
                    <Boxes className="size-3.5" />
                    <span>Total unidades: {totals.totalUnits.toLocaleString('es-VE')}</span>
                  </Badge>

                  <div className="hidden sm:flex items-center gap-1.5 font-mono text-xs text-text-secondary bg-surface-subtle px-2.5 py-1 rounded border border-border">
                    <span>Subtotal:</span>
                    <strong className="text-text-primary text-sm font-bold">
                      ${totals.base.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </strong>
                  </div>
                </div>

                {/* Switch de Vista y Acciones Rápidas */}
                <div className="flex items-center gap-2">
                  <div className="flex items-center rounded-lg border border-border bg-surface-subtle p-0.5 text-xs">
                    <button
                      type="button"
                      onClick={() => setViewMode('table')}
                      className={cn(
                        'flex items-center gap-1.5 px-3 py-1 rounded-md font-bold transition-colors cursor-pointer',
                        viewMode === 'table'
                          ? 'bg-surface text-primary shadow-xs'
                          : 'text-text-muted hover:text-text-primary',
                      )}
                    >
                      <Table className="size-3.5" />
                      <span>Vista ERP</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setViewMode('cards')}
                      className={cn(
                        'flex items-center gap-1.5 px-3 py-1 rounded-md font-bold transition-colors cursor-pointer',
                        viewMode === 'cards'
                          ? 'bg-surface text-primary shadow-xs'
                          : 'text-text-muted hover:text-text-primary',
                      )}
                    >
                      <LayoutGrid className="size-3.5" />
                      <span>Tarjetas</span>
                    </button>
                  </div>

                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={addItem}
                    className="h-8 text-xs font-semibold"
                  >
                    <Plus className="size-3.5" /> Línea en blanco
                  </Button>
                </div>
              </div>

              {/* Buscador Rápido de Producto Sticky (Para agregar sin subir) */}
              <div className="flex items-center gap-3">
                <div className="flex-1 min-w-0">
                  <ProductAutocomplete
                    value={null}
                    onChange={(_, product) => {
                      if (product) addProductQuick(product);
                    }}
                    placeholder="⚡ Escanear código de barras o buscar repuesto para agregar a la compra (Enter)..."
                  />
                </div>
              </div>
            </div>

            {/* 3. Lista de Productos: Modo Tabla ERP o Modo Tarjetas */}
            <div className="px-6 py-4 flex-1">
              {fieldErrors.items && (
                <div className="mb-3 rounded-md bg-danger/10 border border-danger/20 p-2.5 text-danger text-xs font-semibold">
                  {fieldErrors.items}
                </div>
              )}

              {viewMode === 'table' ? (
                /* VISTA ERP TABULAR: Compacta, ágil, para 20+ productos */
                <div className="rounded-lg border border-border bg-surface overflow-hidden shadow-xs">
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                      <thead className="bg-surface-subtle border-b border-border text-[11px] uppercase tracking-wider font-bold text-text-secondary sticky top-0">
                        <tr>
                          <th className="p-3 text-center w-14">#</th>
                          <th className="p-3">Producto / Repuesto</th>
                          <th className="p-3 w-[150px]">Almacén</th>
                          <th className="p-3 text-right w-[130px]">Cantidad</th>
                          <th className="p-3 text-right w-[140px]">Costo Unit. ($)</th>
                          <th className="p-3 text-right w-[140px]">Subtotal ($)</th>
                          <th className="p-3 text-center w-[90px]">Acciones</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-border/60">
                        {items.map((item, index) => (
                          <PurchaseItemTableRow
                            key={index}
                            index={index}
                            value={{
                              ...item,
                              error:
                                fieldErrors[`items.${index}.serial_units`] ??
                                fieldErrors[`items.${index}.quantity`],
                            }}
                            onChange={(next) => updateItem(index, next)}
                            onRemove={() => removeItem(index)}
                            canRemove={items.length > 1}
                            disabled={submitting}
                            isExpanded={expandedTableRows.has(index)}
                            onToggleExpand={toggleTableExpand}
                          />
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ) : (
                /* VISTA TARJETAS: Detallada con badge de cantidad en el header */
                <div className="space-y-3">
                  {items.map((item, index) => (
                    <PurchaseItemRow
                      key={index}
                      index={index}
                      value={{
                        ...item,
                        error:
                          fieldErrors[`items.${index}.serial_units`] ??
                          fieldErrors[`items.${index}.quantity`],
                      }}
                      onChange={(next) => updateItem(index, next)}
                      onRemove={() => removeItem(index)}
                      canRemove={items.length > 1}
                      collapsed={collapsed.has(index)}
                      onToggleCollapse={toggleCollapse}
                      disabled={submitting}
                    />
                  ))}
                </div>
              )}

              {/* Botón inferior para agregar producto al final de la lista sin tener que subir */}
              <div className="mt-4 pt-2">
                <Button
                  type="button"
                  variant="outline"
                  className="w-full py-4 border-dashed border-2 border-border/80 hover:border-primary hover:bg-primary/5 text-text-secondary hover:text-primary font-bold flex items-center justify-center gap-2 cursor-pointer transition-colors shadow-2xs"
                  onClick={addItem}
                >
                  <Plus className="size-4.5 text-primary" />
                  <span>Agregar otro producto a la compra</span>
                </Button>
              </div>
            </div>
          </div>

          {/* Footer Fijo con Totales y Acciones */}
          <DialogFooter className="border-border bg-surface shrink-0 border-t px-6 py-3.5 sm:justify-between flex items-center">
            <div className="flex items-center gap-4">
              <div className="flex items-center gap-3">
                <div className="text-xs text-text-muted">
                  <span className="font-semibold uppercase block">Productos</span>
                  <strong className="text-sm font-bold text-text-primary">{totals.validProducts} ítems</strong>
                </div>
                <div className="h-7 w-px bg-border" />
                <div className="text-xs text-text-muted">
                  <span className="font-semibold uppercase block">Total unidades</span>
                  <strong className="text-sm font-bold text-text-primary">{totals.totalUnits.toLocaleString('es-VE')} uds</strong>
                </div>
                <div className="h-7 w-px bg-border" />
                <div>
                  <span className="text-text-muted text-xs font-semibold uppercase block">Total compra</span>
                  <strong className="text-xl font-black tabular-nums text-primary font-mono">
                    ${totals.base.toLocaleString('es-VE', {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2,
                    })}{' '}
                    {currency}
                  </strong>
                </div>
              </div>
            </div>

            <div className="flex items-center gap-2.5">
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={submitting}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={submitting} className="font-bold px-5">
                Crear borrador de compra
              </Button>
            </div>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
