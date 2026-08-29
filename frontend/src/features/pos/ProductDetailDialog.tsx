/**
 * ProductDetailDialog.tsx — Modal de detalle del producto dentro del POS.
 * Se abre desde el icono "i" de cada fila de resultados de busqueda.
 *
 * Muestra:
 *  - Imagen grande (galeria primaria o image_url).
 *  - Nombre, SKU, codigo de barras.
 *  - Descripcion corta y descripcion larga.
 *  - Categorias y tags.
 *  - Variantes (color + stock por almacen).
 *  - Stock disponible y precio base.
 *
 * El boton "Agregar al ticket" delega en onAdd, que reutiliza addProduct
 * (si el producto tiene variantes abre el VariantPicker normal).
 */
import { Loader2 } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/Dialog';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import type { Product } from '@/features/inventory-center/schemas';
import { ProductImage as ProductImageView } from '@/features/inventory-center/components/ProductImage';
import { cn } from '@/lib/cn';

interface ProductDetailDialogProps {
  product: Product;
  warehouseId: number | null;
  priceListName: string;
  onClose: () => void;
  onAdd: (product: Product) => void | Promise<void>;
}

function money(value: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value || 0));
}

function primaryImage(product: Product) {
  return product.images?.find((image) => image.is_primary) ?? product.images?.[0];
}

/** Quita las etiquetas HTML de la descripcion larga para mostrarla como texto plano (sin riesgo de XSS). */
function stripHtml(html: string): string {
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  return tmp.textContent ?? tmp.innerText ?? '';
}

export function ProductDetailDialog({
  product,
  warehouseId,
  priceListName,
  onClose,
  onAdd,
}: ProductDetailDialogProps) {
  const { data: variants = [], isLoading: loadingVariants } = useProductVariants(
    product.id,
    warehouseId,
  );

  const stock = Number(product.available_stock ?? 0);
  const hasVariants = variants.length > 0;

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl" data-testid="product-detail-dialog">
        <DialogHeader>
          <DialogTitle>{product.name}</DialogTitle>
        </DialogHeader>

        <div className="grid max-h-[70vh] gap-4 overflow-y-auto pr-1 md:grid-cols-[200px_1fr]">
          {/* Imagen grande */}
          <div className="self-start">
            <ProductImageView
              image={primaryImage(product)}
              src={product.primary_image_url ?? product.image_url ?? undefined}
              alt={product.name}
              variant="medium"
              fit="contain"
              className="border-border bg-bg aspect-square w-full rounded-xl border"
            />
            <div className="mt-3 flex items-center justify-between gap-2">
              <div>
                <p className="text-text-muted text-[10px] font-semibold uppercase">Precio base</p>
                <p className="text-2xl font-bold">{money(Number(product.base_price ?? 0))}</p>
              </div>
              <Badge variant={stock > 0 ? 'success' : 'warning'} className="text-[11px]">
                {stock > 0 ? `Stock ${stock}` : 'Sin stock'}
              </Badge>
            </div>
            <p className="text-text-muted mt-1 text-xs">
              Se valida precio de lista ({priceListName}) al agregar.
            </p>
          </div>

          {/* Datos */}
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <Detail label="SKU" value={product.sku} mono />
              <Detail label="Código de barras" value={product.barcode} mono />
              <Detail
                label="Categoría"
                value={
                  product.categories && product.categories.length > 0
                    ? product.categories.map((c) => c.full_path ?? c.name).join(', ')
                    : null
                }
              />
              <Detail label="Marca" value={product.brand?.name ?? null} />
            </div>

            {product.tags && product.tags.length > 0 && (
              <div>
                <p className="text-text-muted mb-1 text-[10px] font-semibold uppercase">Tags</p>
                <div className="flex flex-wrap gap-1.5">
                  {product.tags.map((tag) => (
                    <Badge key={tag.id} variant="default" className="text-[11px]">
                      {tag.name}
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            {product.description && (
              <div>
                <p className="text-text-muted mb-1 text-[10px] font-semibold uppercase">
                  Descripción
                </p>
                <p className="text-sm">{product.description}</p>
              </div>
            )}

            {product.long_description && (
              <div>
                <p className="text-text-muted mb-1 text-[10px] font-semibold uppercase">
                  Descripción larga
                </p>
                <p className="text-text-secondary whitespace-pre-wrap text-sm">
                  {stripHtml(product.long_description)}
                </p>
              </div>
            )}

            {/* Variantes */}
            <div>
              <p className="text-text-muted mb-1 text-[10px] font-semibold uppercase">
                Variantes {hasVariants && `(${variants.length})`}
              </p>
              {loadingVariants ? (
                <div className="text-text-muted flex items-center gap-2 text-sm">
                  <Loader2 className="size-4 animate-spin" /> Cargando variantes...
                </div>
              ) : !hasVariants ? (
                <p className="text-text-muted text-sm">Este producto no tiene variantes.</p>
              ) : (
                <ul className="space-y-1.5" data-testid="detail-variants">
                  {variants.map((variant) => {
                    const vstock = Number(variant.stock_available ?? 0);
                    return (
                      <li
                        key={variant.id}
                        className={cn(
                          'flex items-center gap-3 rounded-md border px-3 py-2 text-sm',
                          'border-border',
                        )}
                      >
                        <span
                          className="border-border inline-block size-5 shrink-0 rounded-full border"
                          style={{ backgroundColor: variant.color_hex ?? '#888888' }}
                          aria-hidden="true"
                        />
                        <span className="flex-1 font-medium">
                          {variant.color ?? `Variante #${variant.id}`}
                          <span className="text-text-muted ml-2 font-mono text-xs">
                            {variant.sku_variant ?? variant.barcode_variant ?? ''}
                          </span>
                        </span>
                        <Badge variant={vstock > 0 ? 'success' : 'warning'} className="text-[10px]">
                          {vstock > 0 ? `${vstock} disp.` : 'Sin stock'}
                        </Badge>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Button type="button" variant="outline" onClick={onClose} data-testid="detail-close">
            Cerrar
          </Button>
          <Button
            type="button"
            onClick={() => void onAdd(product)}
            disabled={stock <= 0 && !hasVariants}
            data-testid="detail-add"
          >
            Agregar al ticket
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function Detail({
  label,
  value,
  mono,
}: {
  label: string;
  value?: string | null;
  mono?: boolean;
}) {
  return (
    <div>
      <p className="text-text-muted text-[10px] font-semibold uppercase">{label}</p>
      <p className={cn('text-sm', mono && 'font-mono')}>{value && value !== '' ? value : '—'}</p>
    </div>
  );
}
