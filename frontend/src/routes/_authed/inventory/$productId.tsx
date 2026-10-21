/**
 * Detalle de producto con tabs: General, Stock, Seriales, Precios, Galería, Variantes, Movimientos, Kardex, Auditoría.
 * Diseño compacto, centrado y altamente organizado para operaciones de inventario.
 */
import { useState, useMemo, useEffect } from 'react';
import { Link, useNavigate, createFileRoute } from '@tanstack/react-router';
import {
  ArrowLeft,
  Edit,
  Trash2,
  ExternalLink,
  Package,
  TrendingUp,
  Boxes,
  FolderTree,
  Calculator,
  AlignLeft,
  Copy,
  Check,
} from 'lucide-react';
import { toast } from 'sonner';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/Tabs';
import { Input } from '@/components/ui/Input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { formatCost, formatMoney } from '@/lib/money';
import { formatRelative } from '@/lib/format';
import { cn } from '@/lib/cn';

import {
  useProduct,
  useProductSerials,
  useProductStockByWarehouse,
  useProductMovements,
  useProductImages,
  useUpdateProductProfitMargin,
  useRecalculateProductPrice,
} from '@/features/inventory-center/api';
import { PricesEditor } from '@/features/inventory-center/components/PricesEditor';
import { ProductVariantsTab } from '@/features/inventory-center/components/ProductVariantsTab';
import { KardexTab } from '@/features/inventory-center/components/KardexTab';
import { AuditsTab } from '@/features/inventory-center/components/AuditsTab';
import { ProductGalleryPanel } from '@/features/inventory-center/components/ProductGalleryPanel';
import { ImageGallery } from '@/features/inventory-center/components/ImageGallery';
import {
  movementTypeLabel,
  MOVEMENT_IN_TYPES,
  referenceTypeLabel,
  referenceLink,
} from '@/features/inventory-center/movementLabels';
import { EditProductDialog } from '@/features/inventory-center/dialogs/EditProductDialog';
import { DeleteProductDialog } from '@/features/inventory-center/dialogs/DeleteProductDialog';
import type { ProductStock, ProductSerial, ProductMovement } from '@/features/inventory-center/schemas';

export const Route = createFileRoute('/_authed/inventory/$productId')({
  component: ProductDetailPage,
});

function stripHtml(html: string): string {
  if (typeof document === 'undefined') return html;
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  return tmp.textContent ?? tmp.innerText ?? '';
}

function CopyBadge({ text }: { text: string }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    void navigator.clipboard.writeText(text);
    setCopied(true);
    toast.success('Copiado al portapapeles');
    setTimeout(() => setCopied(false), 1500);
  };

  return (
    <button
      type="button"
      onClick={handleCopy}
      className="inline-flex items-center gap-1 rounded bg-bg px-1.5 py-0.5 font-mono text-xs text-text-primary hover:bg-border/60 transition-colors"
      title="Copiar al portapapeles"
    >
      <span>{text}</span>
      {copied ? (
        <Check className="size-2.5 text-success" />
      ) : (
        <Copy className="size-2.5 text-text-muted" />
      )}
    </button>
  );
}

function CompactRow({
  label,
  value,
  children,
  className,
}: {
  label: string;
  value?: React.ReactNode;
  children?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex items-center justify-between gap-3 py-1.5 border-b border-border/40 last:border-b-0 text-xs',
        className,
      )}
    >
      <span className="text-text-muted font-medium text-[11px] uppercase tracking-wider shrink-0">
        {label}
      </span>
      <div className="font-medium text-text-primary text-right truncate">
        {children ?? value ?? '—'}
      </div>
    </div>
  );
}

function ProductDetailPage() {
  const { productId } = Route.useParams();
  const id = parseInt(productId, 10);
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState('general');
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  // Modal para editar margen de ganancia
  const [marginDialogOpen, setMarginDialogOpen] = useState(false);
  const [marginInput, setMarginInput] = useState<string>('25');
  const [savingMargin, setSavingMargin] = useState(false);
  const [recalculating, setRecalculating] = useState(false);

  // Hooks (siempre antes de cualquier return condicional)
  const { data: product, isLoading, isError } = useProduct(id);
  const { data: stock_by_warehouse = [] } = useProductStockByWarehouse(id);
  const { data: serialsData = [] } = useProductSerials(id);
  const { data: recent_movements = [] } = useProductMovements(id);
  const { data: galleryImages = [] } = useProductImages(id);
  const serials: ProductSerial[] = serialsData;

  const updateMargin = useUpdateProductProfitMargin();
  const recalc = useRecalculateProductPrice();

  useEffect(() => {
    if (product?.profit_margin != null) {
      setMarginInput(String(product.profit_margin));
    }
  }, [product?.profit_margin]);

  // Stock total calculado
  const totalStock = useMemo(() => {
    if (stock_by_warehouse.length > 0) {
      return stock_by_warehouse.reduce((acc, s) => {
        const val = typeof s.available === 'number' ? s.available : parseFloat(String(s.available || 0));
        return acc + (Number.isFinite(val) ? val : 0);
      }, 0);
    }
    return product?.available_stock != null ? Number(product.available_stock) : 0;
  }, [stock_by_warehouse, product?.available_stock]);

  const warehousesWithStock = useMemo(() => {
    return stock_by_warehouse.filter((s) => {
      const val = typeof s.available === 'number' ? s.available : parseFloat(String(s.available || 0));
      return Number.isFinite(val) && val > 0;
    }).length;
  }, [stock_by_warehouse]);

  const minStockNum = product?.min_stock != null ? Number(product.min_stock) : null;
  const stockBadgeVariant =
    totalStock <= 0
      ? 'danger'
      : minStockNum != null && totalStock <= minStockNum
        ? 'warning'
        : 'success';
  const stockBadgeLabel =
    totalStock <= 0
      ? 'Sin stock'
      : minStockNum != null && totalStock <= minStockNum
        ? 'Stock bajo'
        : 'Disponible';

  const hasImages =
    (product?.images && product.images.length > 0) ||
    Boolean(product?.image_url) ||
    (galleryImages && galleryImages.length > 0);

  async function handleSaveMargin() {
    const inputNum = marginInput.trim() === '' ? NaN : Number(marginInput);
    if (Number.isNaN(inputNum) || inputNum < 0 || inputNum > 999.99) {
      toast.error('El margen debe estar entre 0 y 999.99%.');
      return;
    }
    setSavingMargin(true);
    try {
      const res = await updateMargin.mutateAsync({ id, profit_margin: inputNum });
      const newPrice = res?.base_price;
      if (newPrice != null) {
        toast.success(`Margen guardado. Precio recalculado: ${formatMoney(newPrice)}`);
      } else {
        toast.success('Margen guardado correctamente.');
      }
      setMarginDialogOpen(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar el margen.');
    } finally {
      setSavingMargin(false);
    }
  }

  async function handleRecalculate() {
    setRecalculating(true);
    try {
      const res = (await recalc.mutateAsync({ id })) as {
        base_price: number;
        profit_margin: number;
      };
      toast.success(
        `Precio recalculado: ${formatMoney(res.base_price)} (margen ${res.profit_margin}%).`,
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al recalcular.');
    } finally {
      setRecalculating(false);
    }
  }

  if (isLoading) {
    return (
      <PageLayout title="Cargando producto..." className="max-w-6xl mx-auto w-full">
        <Skeleton className="h-28 w-full rounded-xl" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <Skeleton className="h-44 w-full rounded-xl" />
          <Skeleton className="h-44 w-full rounded-xl" />
          <Skeleton className="h-44 w-full rounded-xl" />
          <Skeleton className="h-44 w-full rounded-xl" />
        </div>
      </PageLayout>
    );
  }

  if (isError || !product) {
    return (
      <PageLayout title="Producto no encontrado" className="max-w-6xl mx-auto w-full">
        <EmptyState
          title="No se encontró el producto"
          description="El producto puede haber sido eliminado o no tienes permiso para verlo."
          action={
            <Button
              variant="outline"
              onClick={() =>
                navigate({
                  to: '/inventory',
                  search: {
                    search: '',
                    tracking: 'all',
                    stock: 'all',
                    status: 'all',
                    page: 1,
                    brand_id: undefined,
                    category_id: undefined,
                    tag_id: undefined,
                    warehouse_id: undefined,
                    low_stock_threshold: undefined,
                    sort_by: undefined,
                    sort_dir: undefined,
                  },
                })
              }
            >
              <ArrowLeft className="size-4" aria-hidden="true" />
              Volver al inventario
            </Button>
          }
        />
      </PageLayout>
    );
  }

  const lastCost =
    product.last_purchase_cost != null
      ? Number(product.last_purchase_cost)
      : product.average_cost != null
        ? Number(product.average_cost)
        : null;

  return (
    <PageLayout
      title={product.name}
      description={`SKU: ${product.sku || 'Sin SKU'} · ${product.tracking_type === 'serialized' ? 'Serializado (IMEI/Serial)' : 'Por cantidad'}`}
      className="max-w-[97%] xl:max-w-[1550px] 2xl:max-w-[1750px] mx-auto w-full pb-8"
      breadcrumb={
        <Link
          to="/inventory"
          search={{
            search: '',
            tracking: 'all',
            stock: 'all',
            status: 'all',
            page: 1,
            brand_id: undefined,
            category_id: undefined,
            tag_id: undefined,
            warehouse_id: undefined,
            low_stock_threshold: undefined,
            sort_by: undefined,
            sort_dir: undefined,
          }}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-text-muted hover:text-primary transition-colors"
        >
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          Volver a Inventario
        </Link>
      }
      actions={
        <div className="flex items-center gap-2">
          <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
            <Button
              variant="outline"
              size="sm"
              leftIcon={<Edit className="size-3.5" />}
              onClick={() => setEditOpen(true)}
              data-testid="edit-product"
            >
              Editar
            </Button>
          </Can>
          <Can I={PERMISSIONS.PRODUCTS_DELETE}>
            <Button
              variant="danger"
              size="sm"
              leftIcon={<Trash2 className="size-3.5" />}
              onClick={() => setDeleteOpen(true)}
              data-testid="delete-product"
            >
              Eliminar
            </Button>
          </Can>
        </div>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <div className="overflow-x-auto pb-1">
          <TabsList className="w-full justify-start sm:w-auto">
            <TabsTrigger value="general">General</TabsTrigger>
            <TabsTrigger value="stock">Stock</TabsTrigger>
            {product.tracking_type === 'serialized' && (
              <TabsTrigger value="serials">Seriales / IMEI</TabsTrigger>
            )}
            <TabsTrigger value="prices">Precios por lista</TabsTrigger>
            <TabsTrigger value="images">Galería</TabsTrigger>
            <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
              <TabsTrigger value="variants">Variantes</TabsTrigger>
            </Can>
            <TabsTrigger value="movements">Movimientos</TabsTrigger>
            <TabsTrigger value="kardex">Kardex</TabsTrigger>
            <TabsTrigger value="audits">Auditoría</TabsTrigger>
          </TabsList>
        </div>

        <TabsContent value="general" className="space-y-4 mt-4">
          {/* Layout centrado y adaptativo: si hay fotos se muestra panel compacto a la izquierda, sino las tarjetas abarcan todo */}
          <div
            className={cn(
              'grid grid-cols-1 gap-4 items-start',
              hasImages ? 'lg:grid-cols-12' : '',
            )}
          >
            {hasImages && (
              <div className="lg:col-span-4 space-y-4">
                <ProductGalleryPanel
                  images={product.images ?? galleryImages}
                  fallbackUrl={product.image_url}
                />
              </div>
            )}

            <div
              className={cn(
                'grid grid-cols-1 md:grid-cols-2 gap-4 items-start',
                hasImages ? 'lg:col-span-8' : 'w-full',
              )}
            >
              {/* Tarjeta 1: Identificación y Estado */}
              <Card className="rounded-xl border border-border bg-surface shadow-xs">
                <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold flex items-center gap-1.5 text-text-primary">
                      <Package className="size-4 text-primary" />
                      Identificación
                    </CardTitle>
                    <div className="flex items-center gap-1">
                      <Badge
                        variant={product.is_active ? 'success' : 'default'}
                        className="text-[10px] px-1.5 py-0"
                      >
                        {product.is_active ? 'Activo' : 'Inactivo'}
                      </Badge>
                      <Badge
                        variant={product.tracking_type === 'serialized' ? 'info' : 'default'}
                        className="text-[10px] px-1.5 py-0"
                      >
                        {product.tracking_type === 'serialized' ? 'Serializado' : 'Cantidad'}
                      </Badge>
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="p-3.5 pt-2.5 space-y-2">
                  {/* Nombre destacado a todo lo ancho para que nombres largos de repuestos no se monten en 3 filas */}
                  <div className="pb-2 border-b border-border/40">
                    <div className="text-[10px] font-semibold uppercase tracking-wider text-text-muted mb-0.5">
                      Nombre del producto
                    </div>
                    <div className="text-sm sm:text-base font-bold text-text-primary leading-snug break-words">
                      {product.name}
                    </div>
                  </div>

                  <div className="space-y-0.5">
                    <CompactRow label="SKU">
                      {product.sku ? <CopyBadge text={product.sku} /> : <span className="text-text-muted">Sin SKU</span>}
                    </CompactRow>
                    <CompactRow label="Código de barras">
                      {product.barcode ? (
                        <CopyBadge text={product.barcode} />
                      ) : (
                        <span className="text-text-muted">Sin código</span>
                      )}
                    </CompactRow>
                    <CompactRow label="Unidad de medida">
                      <span className="text-text-primary">{product.unit_of_measure || 'Unidad (UND)'}</span>
                    </CompactRow>
                    <CompactRow label="Control de stock">
                      <Badge variant={product.track_stock !== false ? 'success' : 'default'} className="text-[10px] px-1.5 py-0">
                        {product.track_stock !== false ? 'Sí' : 'No'}
                      </Badge>
                    </CompactRow>
                    <CompactRow label="Vendible en POS">
                      <Badge variant={product.is_active ? 'success' : 'default'} className="text-[10px] px-1.5 py-0">
                        {product.is_active ? 'Sí' : 'No'}
                      </Badge>
                    </CompactRow>
                    <CompactRow label="Actualizado">
                      <span className="text-text-muted text-[11px]">
                        {product.updated_at ? formatRelative(product.updated_at) : '—'}
                      </span>
                    </CompactRow>
                  </div>
                </CardContent>
              </Card>

              {/* Tarjeta 2: Precios y Rentabilidad */}
              <Card className="rounded-xl border border-border bg-surface shadow-xs">
                <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold flex items-center gap-1.5 text-text-primary">
                      <TrendingUp className="size-4 text-emerald-600 dark:text-emerald-400" />
                      Precios y Rentabilidad
                    </CardTitle>
                    <Badge variant="outline" className="text-[10px] font-mono px-1.5 py-0">
                      {product.sale_currency || 'USD'}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="p-3.5 pt-2 space-y-2">
                  {/* Hero precio y margen */}
                  <div className="flex items-baseline justify-between rounded-lg bg-surface-muted/40 p-2.5 border border-border/40">
                    <div>
                      <div className="text-[10px] font-medium uppercase tracking-wider text-text-muted">
                        Precio Base
                      </div>
                      <div className="text-lg font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {formatMoney(product.base_price)}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-[10px] font-medium uppercase tracking-wider text-text-muted">
                        Margen
                      </div>
                      <div className="flex items-center gap-1 mt-0.5 justify-end">
                        {product.profit_margin != null ? (
                          <span className="font-bold text-xs text-primary tabular-nums">
                            {Number(product.profit_margin).toFixed(1)}%
                          </span>
                        ) : (
                          <span className="text-xs text-text-muted">0%</span>
                        )}
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-5 px-1.5 text-[10px]"
                          onClick={() => setMarginDialogOpen(true)}
                        >
                          Editar
                        </Button>
                      </div>
                    </div>
                  </div>

                  <div className="space-y-0.5">
                    <CompactRow label="Último costo">
                      {product.last_purchase_cost != null ? formatMoney(product.last_purchase_cost) : '—'}
                    </CompactRow>
                    <CompactRow label="Costo promedio (WAC)">
                      {product.average_cost != null ? formatMoney(product.average_cost) : '—'}
                    </CompactRow>
                    <CompactRow label="Modo fijación">
                      <span className="text-[11px]">
                        {product.pricing_mode === 'automatic' ? 'Automático' : 'Manual'}
                      </span>
                    </CompactRow>
                  </div>

                  {product.pricing_mode === 'automatic' && lastCost != null && product.profit_margin != null && (
                    <div className="pt-1 flex items-center justify-between border-t border-border/40">
                      <span className="text-[10px] text-text-muted truncate">Recálculo automático:</span>
                      <Button
                        size="sm"
                        variant="secondary"
                        className="h-6 text-[10px] px-2"
                        onClick={handleRecalculate}
                        loading={recalculating}
                      >
                        <Calculator className="size-3 mr-1" /> Recalcular
                      </Button>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Tarjeta 3: Stock y Disponibilidad */}
              <Card className="rounded-xl border border-border bg-surface shadow-xs">
                <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold flex items-center gap-1.5 text-text-primary">
                      <Boxes className="size-4 text-blue-600 dark:text-blue-400" />
                      Stock y Almacén
                    </CardTitle>
                    <Badge variant={stockBadgeVariant} className="text-[10px] px-1.5 py-0">
                      {stockBadgeLabel}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="p-3.5 pt-2 space-y-2">
                  {/* Hero stock disponible */}
                  <div className="flex items-baseline justify-between rounded-lg bg-surface-muted/40 p-2.5 border border-border/40">
                    <div>
                      <div className="text-[10px] font-medium uppercase tracking-wider text-text-muted">
                        Disponible Total
                      </div>
                      <div className="text-lg font-bold tabular-nums text-text-primary">
                        {totalStock.toFixed(2)}{' '}
                        <span className="text-xs font-normal text-text-muted">
                          {product.unit_of_measure || 'uds.'}
                        </span>
                      </div>
                    </div>
                    <div className="text-right">
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-6 text-[11px] text-primary p-0 hover:bg-transparent hover:underline"
                        onClick={() => setActiveTab('stock')}
                      >
                        Ver almacenes →
                      </Button>
                    </div>
                  </div>

                  <div className="space-y-0.5">
                    <CompactRow label="Stock mínimo">
                      {product.min_stock != null ? Number(product.min_stock).toFixed(2) : '—'}
                    </CompactRow>
                    <CompactRow label="Stock máximo">
                      {product.max_stock != null ? Number(product.max_stock).toFixed(2) : '—'}
                    </CompactRow>
                    <CompactRow label="Punto de reorden">
                      {product.reorder_quantity != null ? Number(product.reorder_quantity).toFixed(2) : '—'}
                    </CompactRow>
                    <CompactRow label="Distribución">
                      <span className="text-xs">
                        {warehousesWithStock} de {stock_by_warehouse.length} almacenes
                      </span>
                    </CompactRow>
                  </div>
                </CardContent>
              </Card>

              {/* Tarjeta 4: Clasificación y Relaciones */}
              <Card className="rounded-xl border border-border bg-surface shadow-xs">
                <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-semibold flex items-center gap-1.5 text-text-primary">
                      <FolderTree className="size-4 text-indigo-600 dark:text-indigo-400" />
                      Clasificación
                    </CardTitle>
                    <Badge variant={product.is_catalog_master ? 'info' : 'default'} className="text-[10px] px-1.5 py-0">
                      {product.is_catalog_master ? 'Catálogo Maestro' : 'Catálogo Local'}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="p-3.5 pt-2 space-y-0.5">
                  <CompactRow label="Marca">
                    {product.brand ? (
                      <span className="font-semibold text-text-primary text-xs">
                        {product.brand.name}
                        {product.brand.slug && (
                          <span className="ml-1 text-[10px] font-normal text-text-muted">
                            ({product.brand.slug})
                          </span>
                        )}
                      </span>
                    ) : (
                      <span className="text-text-muted text-xs">Sin marca</span>
                    )}
                  </CompactRow>

                  <CompactRow label="Categorías">
                    {product.categories && product.categories.length > 0 ? (
                      <div className="flex flex-wrap gap-1 justify-end max-w-[70%]">
                        {product.categories.map((c) => (
                          <Badge key={c.id} variant="info" className="text-[10px] font-normal py-0 px-1.5">
                            {c.name}
                          </Badge>
                        ))}
                      </div>
                    ) : (
                      <span className="text-text-muted text-xs">Sin categoría</span>
                    )}
                  </CompactRow>

                  <CompactRow label="Etiquetas">
                    {product.tags && product.tags.length > 0 ? (
                      <div className="flex flex-wrap gap-1 justify-end max-w-[70%]">
                        {product.tags.map((t) => (
                          <Badge
                            key={t.id}
                            variant="default"
                            className="text-[10px] font-normal py-0 px-1.5"
                            style={t.color ? { backgroundColor: `${t.color}20`, color: t.color } : undefined}
                          >
                            {t.name}
                          </Badge>
                        ))}
                      </div>
                    ) : (
                      <span className="text-text-muted text-xs">Sin etiquetas</span>
                    )}
                  </CompactRow>

                  <CompactRow label="Garantía">
                    {product.warranty_policy ? (
                      <span className="text-xs">
                        <span className="font-medium text-text-primary">{product.warranty_policy.name}</span>
                        <span className="ml-1 text-text-muted text-[10px]">
                          ({product.warranty_policy.duration_days} días)
                        </span>
                      </span>
                    ) : (
                      <span className="text-text-muted text-xs">Sin garantía</span>
                    )}
                  </CompactRow>
                </CardContent>
              </Card>
            </div>
          </div>

          {/* Tarjeta de descripción opcional */}
          {(product.description || product.long_description) && (
            <Card className="rounded-xl border border-border bg-surface shadow-xs">
              <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
                <CardTitle className="text-sm font-semibold flex items-center gap-1.5 text-text-primary">
                  <AlignLeft className="size-4 text-primary" />
                  Descripción del producto
                </CardTitle>
              </CardHeader>
              <CardContent className="p-3.5 pt-3 space-y-3 text-xs leading-relaxed text-text-secondary">
                {product.description && (
                  <div>
                    <div className="font-semibold text-text-muted text-[11px] uppercase mb-1">
                      Descripción corta
                    </div>
                    <p className="text-text-primary text-sm whitespace-pre-line">{product.description}</p>
                  </div>
                )}
                {product.long_description && (
                  <div className="border-t border-border/40 pt-2.5">
                    <div className="font-semibold text-text-muted text-[11px] uppercase mb-1">
                      Descripción detallada
                    </div>
                    <p className="whitespace-pre-line text-sm text-text-secondary">
                      {stripHtml(product.long_description)}
                    </p>
                  </div>
                )}
              </CardContent>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="stock" className="space-y-4 mt-4">
          <StockTab productId={id} initialStock={stock_by_warehouse} />
        </TabsContent>

        {product.tracking_type === 'serialized' && (
          <TabsContent value="serials" className="space-y-4 mt-4">
            <SerialsTab productId={id} initialSerials={serials} />
          </TabsContent>
        )}

        <TabsContent value="prices" className="space-y-4 mt-4">
          <PricesTab productId={id} />
        </TabsContent>

        <TabsContent value="images" className="space-y-4 mt-4">
          <ImageGallery productId={id} images={galleryImages} canEdit />
        </TabsContent>

        <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
          <TabsContent value="variants" className="space-y-4 mt-4">
            <ProductVariantsTab productId={id} />
          </TabsContent>
        </Can>

        <TabsContent value="movements" className="space-y-4 mt-4">
          <MovementsTab movements={recent_movements} />
        </TabsContent>

        <TabsContent value="kardex" className="space-y-4 mt-4">
          <KardexTab productId={id} />
        </TabsContent>

        <TabsContent value="audits" className="space-y-4 mt-4">
          <AuditsTab productId={id} />
        </TabsContent>
      </Tabs>

      {/* Dialog para editar margen de ganancia */}
      <Dialog open={marginDialogOpen} onOpenChange={setMarginDialogOpen}>
        <DialogContent className="w-[95vw] max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <TrendingUp className="size-4" />
              Editar margen de ganancia
            </DialogTitle>
            <DialogDescription>
              Define el porcentaje de recargo sobre el costo de compra para calcular el precio de venta.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div>
              <label className="text-xs font-medium uppercase tracking-wider text-text-muted">
                Porcentaje de margen (%)
              </label>
              <div className="mt-1.5 flex items-center gap-2">
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  max="999.99"
                  value={marginInput}
                  onChange={(e) => setMarginInput(e.target.value)}
                  placeholder="Ej: 30"
                  autoFocus
                />
                <span className="text-sm font-semibold">%</span>
              </div>
            </div>

            {/* Preview del precio proyectado */}
            {lastCost != null && (
              <div className="rounded-lg bg-surface-muted/50 p-3 text-xs space-y-1.5 border border-border/40">
                <div className="flex justify-between text-text-muted">
                  <span>Costo de referencia:</span>
                  <span className="font-medium text-text-primary">{formatMoney(lastCost)}</span>
                </div>
                <div className="flex justify-between font-semibold text-text-primary pt-1.5 border-t border-border/40">
                  <span>Precio proyectado:</span>
                  <span className="text-emerald-600 dark:text-emerald-400 font-bold">
                    {formatMoney(lastCost * (1 + (Number(marginInput) || 0) / 100))}
                  </span>
                </div>
              </div>
            )}
          </div>

          <DialogFooter className="flex justify-end gap-2">
            <Button
              variant="outline"
              onClick={() => setMarginDialogOpen(false)}
              disabled={savingMargin}
            >
              Cancelar
            </Button>
            <Button onClick={handleSaveMargin} loading={savingMargin}>
              Guardar margen
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {product && (
        <EditProductDialog
          product={product}
          open={editOpen}
          onOpenChange={setEditOpen}
        />
      )}

      {product && (
        <DeleteProductDialog
          productId={product.id}
          productName={product.name}
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
        />
      )}
    </PageLayout>
  );
}

function StockTab({
  productId,
  initialStock,
}: {
  productId: number;
  initialStock: ProductStock[];
}) {
  const { data: freshStock = [], isLoading } = useProductStockByWarehouse(productId);
  const stock = freshStock.length > 0 || isLoading ? freshStock : initialStock;

  if (isLoading && initialStock.length === 0) {
    return <Spinner label="Cargando stock..." />;
  }

  if (stock.length === 0) {
    return (
      <EmptyState
        title="Sin stock registrado"
        description="Este producto aún no tiene stock en ningún almacén."
      />
    );
  }

  return (
    <Card className="rounded-xl border border-border bg-surface shadow-xs">
      <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
        <CardTitle className="text-sm font-semibold">Stock por almacén</CardTitle>
        <CardDescription className="text-xs">Distribución actual del producto en las distintas ubicaciones.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">
                Almacén
              </th>
              <th className="px-3.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-secondary">
                Disponible
              </th>
              <th className="px-3.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-secondary">
                Reservado
              </th>
              <th className="px-3.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-secondary">
                Dañado
              </th>
            </tr>
          </thead>
          <tbody>
            {stock.map((s) => {
              const parseNum = (v: unknown): number => {
                if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
                if (typeof v === 'string') {
                  const n = parseFloat(v);
                  return Number.isFinite(n) ? n : 0;
                }
                return 0;
              };
              const qty = parseNum(s.available);
              const res = parseNum(s.reserved ?? 0);
              const dmg = parseNum(s.damaged ?? 0);
              return (
                <tr key={s.warehouse_id} className="border-b border-border/50 last:border-b-0 hover:bg-surface-muted/30">
                  <td className="px-3.5 py-2 text-xs">
                    <div className="font-medium text-text-primary">{s.warehouse_name}</div>
                    <div className="text-[11px] text-text-muted">{s.warehouse_code}</div>
                  </td>
                  <td className="px-3.5 py-2 text-right tabular-nums text-text-primary font-semibold text-xs">
                    {qty.toFixed(2)}
                  </td>
                  <td className="px-3.5 py-2 text-right tabular-nums text-text-muted text-xs">
                    {res.toFixed(2)}
                  </td>
                  <td className="px-3.5 py-2 text-right tabular-nums text-text-muted text-xs">
                    {dmg.toFixed(2)}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

function SerialsTab({
  productId,
  initialSerials,
}: {
  productId: number;
  initialSerials: ProductSerial[];
}) {
  const { data: serialList = [], isLoading } = useProductSerials(productId);
  const serials: ProductSerial[] = serialList.length > 0 ? serialList : initialSerials;
  if (isLoading) return <Spinner label="Cargando seriales..." />;
  if (serials.length === 0) {
    return (
      <EmptyState
        title="Sin seriales registrados"
        description="Este producto serializado aún no tiene IMEI/serial asignados."
      />
    );
  }

  return (
    <Card className="rounded-xl border border-border bg-surface shadow-xs">
      <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
        <CardTitle className="text-sm font-semibold">Seriales / IMEI</CardTitle>
        <CardDescription className="text-xs">{serials.length} unidades físicas registradas.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Serial</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Almacén</th>
            </tr>
          </thead>
          <tbody>
            {serials.map((s: ProductSerial) => (
              <tr key={s.id} className="border-b border-border/50 last:border-b-0 hover:bg-surface-muted/30">
                <td className="px-3.5 py-2 font-mono text-xs">{s.serial_number}</td>
                <td className="px-3.5 py-2 text-text-muted text-xs">{s.serial_type}</td>
                <td className="px-3.5 py-2">
                  <Badge
                    variant={
                      s.status === 'available'
                        ? 'success'
                        : s.status === 'sold'
                          ? 'default'
                          : s.status === 'damaged'
                            ? 'danger'
                            : 'warning'
                    }
                    className="text-[10px]"
                  >
                    {s.status}
                  </Badge>
                </td>
                <td className="px-3.5 py-2 text-text-muted text-xs">{s.warehouse_name ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

function PricesTab({ productId }: { productId: number }) {
  return <PricesEditor productId={productId} />;
}

function MovementsTab({ movements }: { movements: ProductMovement[] }) {
  if (movements.length === 0) {
    return (
      <EmptyState
        title="Sin movimientos"
        description="Este producto aún no tiene movimientos de stock."
      />
    );
  }

  return (
    <Card className="rounded-xl border border-border bg-surface shadow-xs">
      <CardHeader className="p-3.5 pb-2 border-b border-border/40 bg-surface-muted/30">
        <CardTitle className="text-sm font-semibold">Movimientos recientes</CardTitle>
        <CardDescription className="text-xs">Últimos {movements.length} movimientos de stock registrados.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Almacén</th>
              <th className="px-3.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-secondary">Cantidad</th>
              <th className="px-3.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-text-secondary">Costo unit.</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Referencia</th>
              <th className="px-3.5 py-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">Usuario</th>
            </tr>
          </thead>
          <tbody>
            {movements.map((m) => (
              <tr key={m.id} className="border-b border-border/50 last:border-b-0 hover:bg-surface-muted/30">
                <td className="px-3.5 py-2 text-text-muted text-xs">{formatRelative(m.created_at)}</td>
                <td className="px-3.5 py-2">
                  <Badge
                    variant={MOVEMENT_IN_TYPES.has(m.type) ? 'success' : 'warning'}
                    data-testid={`movement-type-${m.id}`}
                    className="text-[10px]"
                  >
                    {movementTypeLabel(m.type)}
                  </Badge>
                </td>
                <td className="px-3.5 py-2 text-text-muted text-xs">{m.warehouse_name ?? '—'}</td>
                <td className="px-3.5 py-2 text-right tabular-nums text-xs font-medium">{m.quantity}</td>
                <td className={cn('px-3.5 py-2 text-right tabular-nums text-xs', m.unit_cost == null && 'text-text-muted')}>
                  {formatCost(m.unit_cost)}
                </td>
                <td className="px-3.5 py-2 text-xs text-text-muted">
                  {(() => {
                    const link = referenceLink(m.reference_type, m.reference_id);
                    if (link) {
                      return (
                        <Link
                          to={link.to}
                          className="inline-flex items-center gap-1 text-primary hover:underline"
                          data-testid={`movement-ref-${m.id}`}
                        >
                          {link.label} #{m.reference_id}
                          <ExternalLink className="size-3" />
                        </Link>
                      );
                    }
                    return (
                      <span>
                        {referenceTypeLabel(m.reference_type)}
                        {m.reference_id != null ? ` #${m.reference_id}` : ''}
                      </span>
                    );
                  })()}
                </td>
                <td className="px-3.5 py-2 text-text-muted text-xs">{m.user_name ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}
