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
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { useProducts } from '@/features/inventory-center/api';

import {
  useCombos,
  useCreateCombo,
  useCreateInvoicePromotion,
  useCreateProductOffer,
  useDeleteCombo,
  useDeleteInvoicePromotion,
  useDeleteProductOffer,
  useInvoicePromotions,
  useProductOffers,
  useUpdateCombo,
  useUpdateInvoicePromotion,
  useUpdateProductOffer,
} from './api';
import {
  StorePromotionSchema,
  type Promotion,
  type PromotionBenefitType,
  type PromotionDomain,
  type PromotionPaymentCurrency,
  type StorePromotionInput,
} from './schemas';

type PromotionItemRole = 'eligible' | 'trigger' | 'reward';

interface PromotionFormState {
  name: string;
  code: string;
  benefit_type: PromotionBenefitType;
  payment_currency: PromotionPaymentCurrency;
  allows_combos: boolean;
  price_usd: string;
  discount_percent: string;
  discount_amount_usd: string;
  priority: string;
  is_active: boolean;
  items: { product_id: number; quantity: number; item_role: PromotionItemRole }[];
}

interface SelectedPromotion {
  domain: PromotionDomain;
  promotion: Promotion;
}

interface PromotionDomainConfig {
  title: string;
  createLabel: string;
  createSubmitLabel: string;
  emptyLabel: string;
  benefitTypes: PromotionBenefitType[];
}

const domainConfig: Record<PromotionDomain, PromotionDomainConfig> = {
  invoice: {
    title: 'Descuentos de factura',
    createLabel: 'Nuevo descuento de factura',
    createSubmitLabel: 'Crear descuento de factura',
    emptyLabel: 'No hay descuentos de factura configurados.',
    benefitTypes: ['percent_discount', 'fixed_discount'],
  },
  combo: {
    title: 'Combos',
    createLabel: 'Nuevo combo',
    createSubmitLabel: 'Crear combo',
    emptyLabel: 'No hay combos configurados.',
    benefitTypes: ['fixed_bundle_price', 'buy_x_get_y'],
  },
  product_offer: {
    title: 'Ofertas de productos',
    createLabel: 'Nueva oferta de producto',
    createSubmitLabel: 'Crear oferta de producto',
    emptyLabel: 'No hay ofertas de productos configuradas.',
    benefitTypes: ['fixed_item_price', 'free_item'],
  },
};

function emptyForm(domain: PromotionDomain): PromotionFormState {
  return {
    name: '',
    code: '',
    benefit_type:
      domain === 'invoice'
        ? 'percent_discount'
        : domain === 'combo'
          ? 'fixed_bundle_price'
          : 'fixed_item_price',
    payment_currency: 'ANY',
    allows_combos: false,
    price_usd: '',
    discount_percent: '',
    discount_amount_usd: '',
    priority: '0',
    is_active: true,
    items: [],
  };
}

export function PromotionsManager() {
  const invoices = useInvoicePromotions();
  const combos = useCombos();
  const productOffers = useProductOffers();
  const createInvoice = useCreateInvoicePromotion();
  const updateInvoice = useUpdateInvoicePromotion();
  const deleteInvoice = useDeleteInvoicePromotion();
  const createCombo = useCreateCombo();
  const updateCombo = useUpdateCombo();
  const deleteCombo = useDeleteCombo();
  const createProductOffer = useCreateProductOffer();
  const updateProductOffer = useUpdateProductOffer();
  const deleteProductOffer = useDeleteProductOffer();
  const [form, setForm] = useState<{ domain: PromotionDomain; promotion: Promotion | null } | null>(
    null,
  );
  const [deleting, setDeleting] = useState<SelectedPromotion | null>(null);

  if (invoices.isLoading || combos.isLoading || productOffers.isLoading) {
    return <Skeleton className="h-40 w-full" />;
  }

  async function savePromotion(
    domain: PromotionDomain,
    promotion: Promotion | null,
    values: StorePromotionInput,
  ): Promise<void> {
    if (domain === 'invoice') {
      await (promotion
        ? updateInvoice.mutateAsync({ id: promotion.id, ...values })
        : createInvoice.mutateAsync(values));
    } else if (domain === 'combo') {
      await (promotion
        ? updateCombo.mutateAsync({ id: promotion.id, ...values })
        : createCombo.mutateAsync(values));
    } else {
      await (promotion
        ? updateProductOffer.mutateAsync({ id: promotion.id, ...values })
        : createProductOffer.mutateAsync(values));
    }
  }

  async function deletePromotion(selected: SelectedPromotion): Promise<void> {
    if (selected.domain === 'invoice') {
      await deleteInvoice.mutateAsync(selected.promotion.id);
    } else if (selected.domain === 'combo') {
      await deleteCombo.mutateAsync(selected.promotion.id);
    } else {
      await deleteProductOffer.mutateAsync(selected.promotion.id);
    }
  }

  const savePending = form
    ? form.domain === 'invoice'
      ? createInvoice.isPending || updateInvoice.isPending
      : form.domain === 'combo'
        ? createCombo.isPending || updateCombo.isPending
        : createProductOffer.isPending || updateProductOffer.isPending
    : false;
  const deletePending = deleting
    ? deleting.domain === 'invoice'
      ? deleteInvoice.isPending
      : deleting.domain === 'combo'
        ? deleteCombo.isPending
        : deleteProductOffer.isPending
    : false;

  return (
    <>
      <div className="space-y-5">
        <PromotionSection
          domain="invoice"
          promotions={invoices.data ?? []}
          onCreate={() => setForm({ domain: 'invoice', promotion: null })}
          onEdit={(promotion) => setForm({ domain: 'invoice', promotion })}
          onDelete={(promotion) => setDeleting({ domain: 'invoice', promotion })}
        />
        <PromotionSection
          domain="combo"
          promotions={combos.data ?? []}
          onCreate={() => setForm({ domain: 'combo', promotion: null })}
          onEdit={(promotion) => setForm({ domain: 'combo', promotion })}
          onDelete={(promotion) => setDeleting({ domain: 'combo', promotion })}
        />
        <PromotionSection
          domain="product_offer"
          promotions={productOffers.data ?? []}
          onCreate={() => setForm({ domain: 'product_offer', promotion: null })}
          onEdit={(promotion) => setForm({ domain: 'product_offer', promotion })}
          onDelete={(promotion) => setDeleting({ domain: 'product_offer', promotion })}
        />
      </div>

      {form && (
        <PromotionFormDialog
          domain={form.domain}
          promotion={form.promotion}
          loading={savePending}
          onClose={() => setForm(null)}
          onSubmit={async (values) => {
            try {
              await savePromotion(form.domain, form.promotion, values);
              toast.success(form.promotion ? 'Promoción actualizada.' : 'Promoción creada.');
              setForm(null);
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
          title={`Desactivar "${deleting.promotion.name}"`}
          description="La promoción dejará de aparecer en el POS, pero conservará su historial."
          confirmLabel="Desactivar"
          variant="danger"
          loading={deletePending}
          onConfirm={async () => {
            try {
              await deletePromotion(deleting);
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

function PromotionSection({
  domain,
  promotions,
  onCreate,
  onEdit,
  onDelete,
}: {
  domain: PromotionDomain;
  promotions: Promotion[];
  onCreate: () => void;
  onEdit: (promotion: Promotion) => void;
  onDelete: (promotion: Promotion) => void;
}) {
  const config = domainConfig[domain];

  return (
    <section>
      <div className="mb-2 flex items-center justify-between gap-3">
        <h3 className="text-sm font-bold tracking-wide uppercase">{config.title}</h3>
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={onCreate}>
          {config.createLabel}
        </Button>
      </div>
      {promotions.length === 0 ? (
        <div className="border-border bg-surface text-text-muted rounded-lg border px-4 py-6 text-center text-sm">
          {config.emptyLabel}
        </div>
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
                      onClick={() => onEdit(promotion)}
                    >
                      <Pencil className="size-4" />
                    </Button>
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={`Eliminar ${promotion.name}`}
                      onClick={() => onDelete(promotion)}
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
    </section>
  );
}

function PromotionFormDialog({
  domain,
  promotion,
  loading,
  onClose,
  onSubmit,
}: {
  domain: PromotionDomain;
  promotion: Promotion | null;
  loading: boolean;
  onClose: () => void;
  onSubmit: (values: StorePromotionInput) => Promise<void>;
}) {
  const config = domainConfig[domain];
  const [form, setForm] = useState<PromotionFormState>(() =>
    promotion
      ? {
          name: promotion.name,
          code: promotion.code ?? '',
          benefit_type: promotion.benefit_type,
          payment_currency: promotion.payment_currency ?? 'ANY',
          allows_combos: promotion.allows_combos ?? false,
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
      : emptyForm(domain),
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
      payment_currency: form.payment_currency,
      allows_combos: domain === 'invoice' && form.allows_combos,
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
      items: domain === 'invoice' ? [] : form.items,
    });
    if (!result.success) {
      toast.error(
        domain === 'invoice'
          ? 'Completa el nombre y el descuento de factura.'
          : domain === 'combo'
            ? 'Completa el nombre, precio y los componentes del combo.'
            : 'Completa el nombre, precio y al menos un producto.',
      );
      return;
    }
    await onSubmit(result.data);
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {promotion ? `Editar ${domainLabel(domain)}` : config.createLabel}
          </DialogTitle>
          <DialogDescription>{formDescription(form.benefit_type)}</DialogDescription>
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
                  updateForm({ benefit_type: event.target.value as PromotionBenefitType })
                }
              >
                {config.benefitTypes.map((benefitType) => (
                  <option key={benefitType} value={benefitType}>
                    {benefitTypeLabel(benefitType)}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1">
              <Label htmlFor="promotion-payment-currency">Moneda de pago</Label>
              <select
                id="promotion-payment-currency"
                className="border-border bg-surface text-text-primary focus:border-primary h-10 w-full rounded-lg border px-3 text-sm outline-none"
                value={form.payment_currency}
                onChange={(event) =>
                  updateForm({ payment_currency: event.target.value as PromotionPaymentCurrency })
                }
              >
                <option value="ANY">Cualquier moneda</option>
                <option value="VES">Solo VES (bolívares)</option>
              </select>
              <p className="text-text-muted text-xs">
                Solo VES exige que el pago completo sea en bolívares, sin pagos mixtos.
              </p>
            </div>
            <PromotionValueField form={form} updateForm={updateForm} />
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
            {domain !== 'invoice' && (
              <div className="space-y-1">
                <Label htmlFor="promotion-product-search">Buscar producto</Label>
                <Input
                  id="promotion-product-search"
                  value={productSearch}
                  onChange={(event) => setProductSearch(event.target.value)}
                  placeholder="Nombre, SKU o código"
                />
              </div>
            )}
          </div>

          {domain === 'invoice' ? (
            <div className="border-border bg-primary/5 space-y-3 rounded-lg border p-3 text-sm">
              <div>
                <p className="font-semibold">Descuento aplicado a toda la factura</p>
                <p className="text-text-muted mt-1">
                  No necesitas seleccionar productos. El descuento se calcula sobre el total del
                  ticket al cobrar.
                </p>
              </div>
              <label className="flex items-center gap-2 font-medium">
                <input
                  type="checkbox"
                  checked={form.allows_combos}
                  onChange={(event) => updateForm({ allows_combos: event.target.checked })}
                />
                Permitir combinar con combos
              </label>
            </div>
          ) : (
            <ProductComponents
              domain={domain}
              form={form}
              products={products}
              selectedIds={selectedIds}
              updateForm={updateForm}
              addProduct={addProduct}
            />
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {promotion ? 'Guardar cambios' : config.createSubmitLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PromotionValueField({
  form,
  updateForm,
}: {
  form: PromotionFormState;
  updateForm: (patch: Partial<PromotionFormState>) => void;
}) {
  const isPercentage = form.benefit_type === 'percent_discount';
  const isFixedDiscount = form.benefit_type === 'fixed_discount';
  const isFixedItemPrice = form.benefit_type === 'fixed_item_price';
  const isFreeItem = form.benefit_type === 'free_item';
  const isBuyGet = form.benefit_type === 'buy_x_get_y';
  const inputId = isPercentage
    ? 'promotion-percent'
    : isFixedDiscount
      ? 'promotion-discount-amount'
      : 'promotion-price';
  const label = isPercentage
    ? 'Descuento porcentual'
    : isFixedDiscount
      ? 'Descuento fijo USD'
      : isFixedItemPrice
        ? 'Precio por artículo USD'
        : isFreeItem
          ? 'Precio final'
          : isBuyGet
            ? 'Precio de recompensa'
            : 'Precio del combo USD';

  return (
    <div className="space-y-1">
      <Label htmlFor={inputId}>{label}</Label>
      {isPercentage ? (
        <Input
          id={inputId}
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
          id={inputId}
          type="number"
          min="0.01"
          step="0.01"
          value={form.discount_amount_usd}
          onChange={(event) => updateForm({ discount_amount_usd: event.target.value })}
          placeholder="10"
        />
      ) : isFreeItem ? (
        <p
          id={inputId}
          className="border-border bg-bg text-text-secondary rounded-lg border px-3 py-2 text-sm"
        >
          $0.00 por unidad
        </p>
      ) : isBuyGet ? (
        <p
          id={inputId}
          className="border-border bg-bg text-text-secondary rounded-lg border px-3 py-2 text-sm"
        >
          Precio de recompensa: $0.00
        </p>
      ) : (
        <Input
          id={inputId}
          type="number"
          min="0"
          step="0.01"
          value={form.price_usd}
          onChange={(event) => updateForm({ price_usd: event.target.value })}
        />
      )}
      {isPercentage && (
        <p className="text-text-muted text-xs">Porcentaje aplicado al total de la factura.</p>
      )}
    </div>
  );
}

function ProductComponents({
  domain,
  form,
  products,
  selectedIds,
  updateForm,
  addProduct,
}: {
  domain: PromotionDomain;
  form: PromotionFormState;
  products: { id: number; name: string }[];
  selectedIds: Set<number>;
  updateForm: (patch: Partial<PromotionFormState>) => void;
  addProduct: (productId: number) => void;
}) {
  const isBuyGet = form.benefit_type === 'buy_x_get_y';

  return (
    <div className="border-border rounded-lg border p-3">
      <p className="text-text-muted mb-2 text-xs font-semibold uppercase">
        {domain === 'combo'
          ? isBuyGet
            ? '2x1: compra X / recibe Y'
            : 'Componentes del combo'
          : 'Productos de la oferta'}
      </p>
      <div className="flex flex-wrap gap-2">
        {products
          .filter(
            (product) =>
              !selectedIds.has(product.id) ||
              (isBuyGet && form.items.filter((item) => item.product_id === product.id).length < 2),
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
                            ? {
                                ...entry,
                                item_role: event.target.value as PromotionItemRole,
                              }
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
  );
}

function domainLabel(domain: PromotionDomain): string {
  return domain === 'invoice'
    ? 'descuento de factura'
    : domain === 'combo'
      ? 'combo'
      : 'oferta de producto';
}

function formDescription(type: PromotionBenefitType): string {
  return type === 'percent_discount'
    ? 'Define un descuento porcentual para toda la factura.'
    : type === 'fixed_discount'
      ? 'Define un monto fijo de descuento para toda la factura.'
      : type === 'fixed_item_price'
        ? 'Define un precio fijo por unidad para productos seleccionados.'
        : type === 'free_item'
          ? 'Define productos que se entregarán gratis.'
          : type === 'buy_x_get_y'
            ? 'Configura qué producto se compra y cuál se entrega gratis.'
            : 'Define un combo con precio total configurable en USD.';
}

function benefitTypeLabel(type: PromotionBenefitType): string {
  return type === 'percent_discount'
    ? 'Descuento porcentual'
    : type === 'fixed_discount'
      ? 'Descuento fijo USD'
      : type === 'fixed_item_price'
        ? 'Precio fijo por artículo'
        : type === 'free_item'
          ? 'Artículo gratis'
          : type === 'buy_x_get_y'
            ? '2x1 / Compra X y recibe Y'
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
            ? '2x1'
            : `$${promotion.price_usd.toFixed(2)}`;
}
