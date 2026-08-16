import { useMemo, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { useProducts } from '@/features/inventory-center/api';
import { useCreatePromotion, useDeletePromotion, usePromotions, useUpdatePromotion } from './api';
import {
  StorePromotionSchema,
  type Promotion,
  type PromotionBenefitType,
  type StorePromotionInput,
} from './schemas';

type SupportedBenefitType = Extract<
  PromotionBenefitType,
  | 'percent_discount'
  | 'fixed_discount'
  | 'fixed_item_price'
  | 'free_item'
  | 'buy_x_get_y'
  | 'fixed_bundle_price'
>;
type PromotionItemRole = 'eligible' | 'trigger' | 'reward';

interface PromotionFormState {
  name: string;
  code: string;
  benefit_type: SupportedBenefitType;
  price_usd: string;
  discount_percent: string;
  discount_amount_usd: string;
  priority: string;
  is_active: boolean;
  items: { product_id: number; quantity: number; item_role: PromotionItemRole }[];
}

const emptyForm: PromotionFormState = {
  name: '',
  code: '',
  benefit_type: 'fixed_bundle_price',
  price_usd: '',
  discount_percent: '',
  discount_amount_usd: '',
  priority: '0',
  is_active: true,
  items: [],
};

export function PromotionsManager() {
  const { data: promotions = [], isLoading } = usePromotions(false);
  const create = useCreatePromotion();
  const update = useUpdatePromotion();
  const remove = useDeletePromotion();
  const [editing, setEditing] = useState<Promotion | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState<Promotion | null>(null);

  if (isLoading) return <Skeleton className="h-40 w-full" />;

  return (
    <>
      <div className="flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setFormOpen(true)}>
          Nueva promoción
        </Button>
      </div>

      {promotions.length === 0 ? (
        <EmptyState
          title="Sin promociones"
          description="Crea un combo o precio especial para mostrarlo en el POS."
        />
      ) : (
        <div className="border-border bg-surface overflow-x-auto rounded-lg border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>
                <th className="px-3 py-2">Promoción</th>
                <th className="px-3 py-2">Código</th>
                <th className="px-3 py-2">Tipo</th>
                <th className="px-3 py-2">Precio USD</th>
                <th className="px-3 py-2">Estado</th>
                <th className="px-3 py-2 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {promotions.map((promotion) => (
                <tr key={promotion.id} className="border-border border-b last:border-b-0">
                  <td className="px-3 py-2 font-medium">{promotion.name}</td>
                  <td className="text-text-muted px-3 py-2">{promotion.code ?? '-'}</td>
                  <td className="px-3 py-2">{benefitTypeLabel(promotion.benefit_type)}</td>
                  <td className="px-3 py-2 tabular-nums">{formatPromotionValue(promotion)}</td>
                  <td className="px-3 py-2">
                    <Badge variant={promotion.is_active ? 'success' : 'default'}>
                      {promotion.is_active ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={`Editar ${promotion.name}`}
                      onClick={() => {
                        setEditing(promotion);
                        setFormOpen(true);
                      }}
                    >
                      <Pencil className="size-4" />
                    </Button>
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={`Eliminar ${promotion.name}`}
                      onClick={() => setDeleting(promotion)}
                    >
                      <Trash2 className="text-danger size-4" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {formOpen && (
        <PromotionFormDialog
          promotion={editing}
          loading={create.isPending || update.isPending}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Promoción actualizada.');
              } else {
                await create.mutateAsync(values);
                toast.success('Promoción creada.');
              }
              setFormOpen(false);
              setEditing(null);
            } catch (error) {
              toast.error(
                error instanceof Error ? error.message : 'No se pudo guardar la promoción.',
              );
            }
          }}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setDeleting(null);
          }}
          title={`Desactivar "${deleting.name}"`}
          description="La promoción dejará de aparecer en el POS, pero conservará su historial."
          confirmLabel="Desactivar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Promoción desactivada.');
            } catch (error) {
              toast.error(error instanceof Error ? error.message : 'No se pudo desactivar.');
            }
          }}
        />
      )}
    </>
  );
}

function PromotionFormDialog({
  promotion,
  loading,
  onClose,
  onSubmit,
}: {
  promotion: Promotion | null;
  loading: boolean;
  onClose: () => void;
  onSubmit: (values: StorePromotionInput) => Promise<void>;
}) {
  const [form, setForm] = useState<PromotionFormState>(() =>
    promotion
      ? {
          name: promotion.name,
          code: promotion.code ?? '',
          benefit_type: promotion.benefit_type,
          price_usd: String(promotion.price_usd),
          discount_percent:
            promotion.discount_percent == null ? '' : String(promotion.discount_percent),
          discount_amount_usd:
            promotion.discount_amount_usd == null ? '' : String(promotion.discount_amount_usd),
          priority: String(promotion.priority),
          is_active: promotion.is_active,
          items: promotion.items.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
            item_role: item.item_role ?? 'eligible',
          })),
        }
      : emptyForm,
  );
  const [productSearch, setProductSearch] = useState('');
  const { data: productPage } = useProducts({
    search: productSearch,
    tracking_type: 'all',
    stock_status: 'all',
    active_status: 'active',
    page: 1,
    per_page: 25,
  });
  const products = productPage?.data ?? [];
  const selectedIds = useMemo(
    () => new Set(form.items.map((item) => item.product_id)),
    [form.items],
  );
  const isPercentage = form.benefit_type === 'percent_discount';
  const isFixedDiscount = form.benefit_type === 'fixed_discount';
  const isFixedItemPrice = form.benefit_type === 'fixed_item_price';
  const isFreeItem = form.benefit_type === 'free_item';
  const isBuyGet = form.benefit_type === 'buy_x_get_y';

  const updateForm = (patch: Partial<PromotionFormState>) =>
    setForm((current) => ({ ...current, ...patch }));

  function addProduct(productId: number): void {
    const productItems = form.items.filter((item) => item.product_id === productId);
    if (!isBuyGet && productItems.length > 0) return;
    if (isBuyGet && productItems.length >= 2) return;

    const itemRole: PromotionItemRole = isBuyGet
      ? productItems.some((item) => item.item_role === 'trigger')
        ? 'reward'
        : 'trigger'
      : 'eligible';
    updateForm({
      items: [...form.items, { product_id: productId, quantity: 1, item_role: itemRole }],
    });
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const result = StorePromotionSchema.safeParse({
      name: form.name,
      code: form.code,
      benefit_type: form.benefit_type,
      price_currency: 'USD',
      price_usd:
        isPercentage || isFixedDiscount || isFreeItem || isBuyGet || form.price_usd === ''
          ? null
          : Number(form.price_usd),
      discount_percent:
        isPercentage && form.discount_percent !== '' ? Number(form.discount_percent) : null,
      discount_amount_usd:
        isFixedDiscount && form.discount_amount_usd !== ''
          ? Number(form.discount_amount_usd)
          : null,
      priority: Number(form.priority),
      is_active: form.is_active,
      items: form.items,
    });
    if (!result.success) {
      toast.error(
        isPercentage || isFixedDiscount
          ? 'Completa el nombre, descuento y al menos un producto.'
          : 'Completa el nombre, precio y al menos dos productos.',
      );
      return;
    }
    await onSubmit(result.data);
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{promotion ? 'Editar promoción' : 'Nueva promoción'}</DialogTitle>
          <DialogDescription>
            {isPercentage
              ? 'Define un descuento porcentual para productos seleccionados.'
              : isFixedDiscount
                ? 'Define un monto fijo de descuento para productos seleccionados.'
                : isFixedItemPrice
                  ? 'Define un precio fijo por unidad para productos seleccionados.'
                  : isFreeItem
                    ? 'Define productos que se entregarán gratis.'
                    : isBuyGet
                      ? 'Define qué productos activan y cuáles se entregan gratis.'
                      : 'Define un combo con precio total configurable en USD.'}
          </DialogDescription>
        </DialogHeader>
        <form className="space-y-4" onSubmit={submit}>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1 sm:col-span-2">
              <Label htmlFor="promotion-name">Nombre</Label>
              <Input
                id="promotion-name"
                value={form.name}
                onChange={(event) => updateForm({ name: event.target.value })}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="promotion-code">Código opcional</Label>
              <Input
                id="promotion-code"
                value={form.code}
                onChange={(event) => updateForm({ code: event.target.value.toUpperCase() })}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="promotion-benefit-type">Tipo de promoción</Label>
              <select
                id="promotion-benefit-type"
                className="border-border bg-surface text-text-primary focus:border-primary h-10 w-full rounded-lg border px-3 text-sm outline-none"
                value={form.benefit_type}
                onChange={(event) =>
                  updateForm({ benefit_type: event.target.value as SupportedBenefitType })
                }
              >
                <option value="fixed_bundle_price">Combo con precio fijo</option>
                <option value="percent_discount">Descuento porcentual</option>
                <option value="fixed_discount">Descuento fijo USD</option>
                <option value="fixed_item_price">Precio fijo por artículo</option>
                <option value="free_item">Artículo gratis</option>
                <option value="buy_x_get_y">Compra X y recibe Y</option>
              </select>
            </div>
            <div className="space-y-1">
              <Label
                htmlFor={
                  isPercentage
                    ? 'promotion-percent'
                    : isFixedDiscount
                      ? 'promotion-discount-amount'
                      : 'promotion-price'
                }
              >
                {isPercentage
                  ? 'Descuento porcentual'
                  : isFixedDiscount
                    ? 'Descuento fijo USD'
                    : isFixedItemPrice
                      ? 'Precio por artículo USD'
                      : isFreeItem
                        ? 'Precio final'
                        : 'Precio del combo USD'}
              </Label>
              {isPercentage ? (
                <Input
                  id="promotion-percent"
                  type="number"
                  min="0.01"
                  max="100"
                  step="0.01"
                  value={form.discount_percent}
                  onChange={(event) => updateForm({ discount_percent: event.target.value })}
                  placeholder="25"
                />
              ) : isFixedDiscount ? (
                <Input
                  id="promotion-discount-amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={form.discount_amount_usd}
                  onChange={(event) => updateForm({ discount_amount_usd: event.target.value })}
                  placeholder="10"
                />
              ) : isFreeItem ? (
                <p className="border-border bg-bg text-text-secondary rounded-lg border px-3 py-2 text-sm">
                  $0.00 por unidad
                </p>
              ) : isBuyGet ? (
                <p className="border-border bg-bg text-text-secondary rounded-lg border px-3 py-2 text-sm">
                  Precio de recompensa: $0.00
                </p>
              ) : (
                <Input
                  id="promotion-price"
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.price_usd}
                  onChange={(event) => updateForm({ price_usd: event.target.value })}
                />
              )}
              {isPercentage && <p className="text-text-muted text-xs">Porcentaje de descuento</p>}
            </div>
            <div className="space-y-1">
              <Label htmlFor="promotion-priority">Prioridad</Label>
              <Input
                id="promotion-priority"
                type="number"
                min="0"
                value={form.priority}
                onChange={(event) => updateForm({ priority: event.target.value })}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="promotion-product-search">Buscar producto</Label>
              <Input
                id="promotion-product-search"
                value={productSearch}
                onChange={(event) => setProductSearch(event.target.value)}
                placeholder="Nombre, SKU o código"
              />
            </div>
          </div>
          <div className="border-border rounded-lg border p-3">
            <p className="text-text-muted mb-2 text-xs font-semibold uppercase">
              {isBuyGet
                ? 'Compra X / recibe Y'
                : isPercentage || isFixedDiscount || isFixedItemPrice || isFreeItem
                  ? 'Productos elegibles'
                  : 'Componentes del combo'}
            </p>
            <div className="flex flex-wrap gap-2">
              {products
                .filter(
                  (product) =>
                    !selectedIds.has(product.id) ||
                    (isBuyGet &&
                      form.items.filter((item) => item.product_id === product.id).length < 2),
                )
                .slice(0, 8)
                .map((product) => (
                  <Button
                    key={`${product.id}-${form.items.filter((item) => item.product_id === product.id).length}`}
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => addProduct(product.id)}
                  >
                    + {product.name}
                  </Button>
                ))}
            </div>
            <div className="mt-3 space-y-2">
              {form.items.map((item, index) => {
                const product = products.find((entry) => entry.id === item.product_id);
                return (
                  <div
                    key={`${item.product_id}-${index}`}
                    className="bg-bg flex items-center justify-between rounded px-3 py-2 text-sm"
                  >
                    <span>{product?.name ?? `Producto #${item.product_id}`}</span>
                    <div className="flex items-center gap-2">
                      <Input
                        aria-label={`Cantidad ${product?.name ?? item.product_id}`}
                        className="h-8 w-20"
                        type="number"
                        min="0.01"
                        step="0.01"
                        value={item.quantity}
                        onChange={(event) =>
                          updateForm({
                            items: form.items.map((entry) =>
                              entry === item
                                ? {
                                    ...entry,
                                    quantity: Math.max(0.01, Number(event.target.value) || 0.01),
                                  }
                                : entry,
                            ),
                          })
                        }
                      />
                      {isBuyGet && (
                        <select
                          aria-label={`Rol ${product?.name ?? item.product_id}`}
                          className="border-border bg-surface text-text-primary h-8 rounded border px-2 text-xs"
                          value={item.item_role}
                          onChange={(event) =>
                            updateForm({
                              items: form.items.map((entry, entryIndex) =>
                                entryIndex === index
                                  ? { ...entry, item_role: event.target.value as PromotionItemRole }
                                  : entry,
                              ),
                            })
                          }
                        >
                          <option value="trigger">Compra</option>
                          <option value="reward">Recibe gratis</option>
                        </select>
                      )}
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                          updateForm({
                            items: form.items.filter((_, entryIndex) => entryIndex !== index),
                          })
                        }
                      >
                        Quitar
                      </Button>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {promotion ? 'Guardar cambios' : 'Crear promoción'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function benefitTypeLabel(type: Promotion['benefit_type']): string {
  return type === 'percent_discount'
    ? 'Descuento porcentual'
    : type === 'fixed_discount'
      ? 'Descuento fijo USD'
      : type === 'fixed_item_price'
        ? 'Precio fijo por artículo'
        : type === 'free_item'
          ? 'Artículo gratis'
          : type === 'buy_x_get_y'
            ? 'Compra X y recibe Y'
            : 'Combo con precio fijo';
}

function formatPromotionValue(promotion: Promotion): string {
  return promotion.benefit_type === 'percent_discount'
    ? `${promotion.discount_percent ?? 0}% OFF`
    : promotion.benefit_type === 'fixed_discount'
      ? `-$${(promotion.discount_amount_usd ?? 0).toFixed(2)}`
      : promotion.benefit_type === 'fixed_item_price'
        ? `$${promotion.price_usd.toFixed(2)}/u`
        : promotion.benefit_type === 'free_item'
          ? 'GRATIS'
          : promotion.benefit_type === 'buy_x_get_y'
            ? 'COMPRA / REGALO'
            : `$${promotion.price_usd.toFixed(2)}`;
}
