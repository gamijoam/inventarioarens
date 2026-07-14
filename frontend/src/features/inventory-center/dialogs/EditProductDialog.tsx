/**
 * EditProductDialog: dialog para editar un producto existente.
 * Usa useProductForm(mode='edit') con initialValues del producto actual.
 *
 * Props:
 *  - product: datos actuales del producto (Product del backend).
 *  - open / onOpenChange: controlan visibilidad.
 *  - onSuccess?: callback tras guardar.
 */
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useProductForm } from '../forms';
import { ProductForm } from '../components/ProductForm';
import { useTags } from '../api';
import type { Product, StoreProductValues } from '../schemas';

export interface EditProductDialogProps {
  product: Product;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function EditProductDialog({ product, open, onOpenChange, onSuccess }: EditProductDialogProps) {
  const { data: tags = [] } = useTags();
  const { form, onSubmit, isSubmitting } = useProductForm({
    mode: 'edit',
    productId: product.id,
    initialValues: productToFormValues(product),
    onSuccess: () => {
      onOpenChange(false);
      onSuccess?.();
    },
  });

  const tagOptions = tags.map((t) => ({ value: t.id, label: t.name, color: t.color ?? undefined }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Editar producto</DialogTitle>
          <DialogDescription>
            Modifica los datos del producto. Los cambios se guardan al confirmar.
          </DialogDescription>
        </DialogHeader>
        <ProductForm
          form={form}
          tagOptions={tagOptions}
          onSubmit={onSubmit}
          isSubmitting={isSubmitting}
          onCancel={() => onOpenChange(false)}
          submitLabel="Guardar cambios"
        />
      </DialogContent>
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
    base_price: p.base_price ? Number(p.base_price) : undefined,
    sale_currency: p.sale_currency ?? 'USD',
    sale_exchange_rate_type_id: p.sale_exchange_rate_type_id ?? undefined,
    min_stock: p.min_stock ?? undefined,
    max_stock: p.max_stock ?? undefined,
    reorder_quantity: p.reorder_quantity ?? undefined,
    warranty_policy_id: p.warranty_policy_id ?? undefined,
    is_active: p.is_active,
  };
}