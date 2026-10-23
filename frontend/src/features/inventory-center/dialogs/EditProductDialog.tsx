/**
 * EditProductDialog: dialog para editar un producto existente.
 * Usa useProductForm(mode='edit') con initialValues del producto actual.
 *
 * Props:
 *  - product: datos actuales del producto (Product del backend).
 *  - open / onOpenChange: controlan visibilidad.
 *  - onSuccess?: callback tras guardar.
 */
import { useMemo, useState } from 'react';
import { Trash2 } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { DeleteProductDialog } from './DeleteProductDialog';
import { useProductForm } from '../forms';
import { ProductForm } from '../components/ProductForm';
import { useProduct, useTags } from '../api';
import type { Product, StoreProductValues } from '../schemas';

export interface EditProductDialogProps {
  product: Product;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: (product?: Product) => void;
}

export function EditProductDialog({ product, open, onOpenChange, onSuccess }: EditProductDialogProps) {
  const [deleteOpen, setDeleteOpen] = useState(false);
  const { data: tags = [] } = useTags();

  // Consulta fresca del producto para asegurar que al abrir el modal siempre
  // tengamos los precios y datos mas recientes del servidor.
  const { data: freshProduct } = useProduct(product.id);
  const currentProduct = freshProduct ?? product;

  // Memoizamos los initialValues con dependencia en open y en el producto fresco
  const initialValues = useMemo(
    () => productToFormValues(currentProduct),
    [currentProduct.id, currentProduct.updated_at, currentProduct.base_price, open],
  );

  const { form, onSubmit, isSubmitting } = useProductForm({
    mode: 'edit',
    productId: currentProduct.id,
    initialValues,
    onSuccess: (data) => {
      onOpenChange(false);
      const updated = data && typeof data === 'object' && 'id' in data ? (data as Product) : undefined;
      onSuccess?.(updated);
    },
  });

  const tagOptions = tags.map((t) => ({ value: t.id, label: t.name, color: t.color ?? undefined }));

  return (
    <Dialog key={`edit-${product.id}`} open={open} onOpenChange={onOpenChange}>
      <DialogContent className="h-[95vh] max-h-[95vh] w-[98vw] max-w-[98vw] overflow-hidden p-0 flex flex-col gap-0 rounded-xl border border-border bg-surface shadow-2xl">
        <DialogHeader className="px-6 py-3.5 border-b border-border bg-surface-subtle/40 shrink-0">
          <div className="flex items-start justify-between gap-4 pr-14 sm:pr-16">
            <div className="flex flex-col gap-1">
              <div className="flex items-center gap-2 flex-wrap">
                <span className="inline-flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 text-xs font-mono font-bold uppercase tracking-wider text-primary border border-primary/20">
                  ERP
                </span>
                <DialogTitle className="text-base font-bold tracking-tight">
                  Editar: {product.name}
                </DialogTitle>
                {product.sku && (
                  <span className="text-xs font-mono px-2 py-0.5 rounded bg-bg border border-border text-text-muted">
                    SKU: {product.sku}
                  </span>
                )}
                {product.barcode && (
                  <span className="text-xs font-mono px-2 py-0.5 rounded bg-bg border border-border text-text-muted">
                    BAR: {product.barcode}
                  </span>
                )}
              </div>
              <DialogDescription className="text-xs text-text-muted">
                Modifica los datos del producto. Usa [F1 - F9] para alternar entre pestañas y [Enter] para guardar.
              </DialogDescription>
            </div>
            <Can I={PERMISSIONS.PRODUCTS_DELETE}>
              <Button
                type="button"
                variant="danger"
                size="sm"
                onClick={() => setDeleteOpen(true)}
                className="gap-1.5 text-xs h-8 px-3 shrink-0"
                title="Eliminar producto"
              >
                <Trash2 className="size-3.5" />
                <span>Eliminar producto</span>
              </Button>
            </Can>
          </div>
        </DialogHeader>
        <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
          <ProductForm
            form={form}
            tagOptions={tagOptions}
            onSubmit={onSubmit}
            isSubmitting={isSubmitting}
            onCancel={() => onOpenChange(false)}
            submitLabel="Guardar cambios"
            productId={product.id}
          />
        </div>
      </DialogContent>

      <DeleteProductDialog
        productId={product.id}
        productName={product.name}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        onSuccess={() => {
          onOpenChange(false);
          onSuccess?.();
        }}
      />
    </Dialog>
  );
}

/**
 * Convierte un Product del backend al shape de StoreProductValues (form).
 * Maneja null -> undefined, tipos numericos a number, etc.
 */
function productToFormValues(p: Product): Partial<StoreProductValues> {
  return {
    name: p.name,
    description: p.description ?? '',
    long_description: p.long_description ?? '',
    sku: p.sku ?? '',
    barcode: p.barcode ?? '',
    image_url: p.image_url ?? '',
    tracking_type: p.tracking_type,
    unit_of_measure: p.unit_of_measure ?? 'unit',
    track_stock: p.track_stock ?? true,
    brand_id: p.brand_id ?? undefined,
    category_ids: p.categories?.map((c) => c.id) ?? [],
    tag_ids: p.tags?.map((t) => t.id) ?? [],
    base_price: p.base_price !== null && p.base_price !== undefined ? Number(p.base_price) : undefined,
    profit_margin: p.profit_margin !== null && p.profit_margin !== undefined ? Number(p.profit_margin) : undefined,
    last_purchase_cost:
      p.last_purchase_cost !== null && p.last_purchase_cost !== undefined
        ? Number(p.last_purchase_cost)
        : undefined,
    pricing_mode: p.pricing_mode ?? 'manual',
    sale_currency: p.sale_currency ?? 'USD',
    sale_exchange_rate_type_id: p.sale_exchange_rate_type_id ?? undefined,
    min_stock: p.min_stock !== null && p.min_stock !== undefined ? Number(p.min_stock) : undefined,
    max_stock: p.max_stock !== null && p.max_stock !== undefined ? Number(p.max_stock) : undefined,
    reorder_quantity: p.reorder_quantity !== null && p.reorder_quantity !== undefined ? Number(p.reorder_quantity) : undefined,
    warranty_policy_id: p.warranty_policy_id ?? undefined,
    is_active: p.is_active,
  };
}
