/**
 * ProductForm: formulario completo de producto (create + edit).
 * Renderiza todos los campos del backend (ver docs/INVENTORY_CATALOG_API.md).
 * Usa react-hook-form + zodResolver via useProductForm().
 *
 * Secciones:
 *  1. Identificacion (name, sku, barcode, image_url)
 *  2. Catalogos (brand, categories, tags) — con inline create.
 *  3. Control de stock (tracking_type, unit_of_measure, track_stock, min/max/reorder)
 *  4. Precios (base_price, sale_currency, sale_exchange_rate_type)
 *  5. Garantia + Estado (warranty_policy_id, is_active, description, long_description)
 */
import { type UseFormReturn } from 'react-hook-form';
import { useMemo } from 'react';
import { Link2, Tag as TagIcon, Tags as TagsIcon } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Combobox } from '@/components/ui/Combobox';
import { TreeSelect } from '@/components/ui/TreeSelect';
import { Label } from '@/components/ui/Label';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { Controller, useController } from 'react-hook-form';

import {
  SALE_CURRENCIES,
  TRACKING_TYPES,
  UNITS_OF_MEASURE,
  type StoreProductInput,
  type StoreProductValues,
} from '../schemas';
import {
  useBrands,
  useCategoriesTree,
  useExchangeRateTypes,
  useWarrantyPolicies,
} from '@/features/inventory-center/lookups';
import { InlineCatalogCreate } from './InlineCatalogCreate';

export interface ProductFormProps {
  // Acepta cualquier UseFormReturn cuyo TFieldValues extienda nuestro schema.
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
  tagOptions: { value: number; label: string; color?: string }[];
  /** Modo "compact" oculta descripciones largas y reduce spacing. */
  compact?: boolean;
  onCancel?: () => void;
  submitLabel?: string;
  onSubmit: () => void;
  isSubmitting: boolean;
}

export function ProductForm({
  form,
  tagOptions,
  compact = false,
  onCancel,
  submitLabel = 'Guardar',
  onSubmit,
  isSubmitting,
}: ProductFormProps) {
  const { data: brands = [] } = useBrands();
  const { data: categoryTree = [] } = useCategoriesTree();
  const { data: warrantyPolicies = [] } = useWarrantyPolicies();
  const { data: rateTypes = [] } = useExchangeRateTypes();

  // Convertir brand/warranty/rate a options.
  const brandOptions = useMemo(
    () => [
      { value: '', label: '— Sin marca —' },
      ...brands.map((b) => ({ value: String(b.id), label: b.name })),
    ],
    [brands],
  );

  const warrantyOptions = useMemo(
    () => [
      { value: '', label: '— Sin garantía —' },
      ...warrantyPolicies.map((w) => ({ value: String(w.id), label: w.name })),
    ],
    [warrantyPolicies],
  );

  const rateTypeOptions = useMemo(
    () => [
      { value: '', label: '— Heredar del sistema —' },
      ...rateTypes.map((r) => ({ value: String(r.id), label: `${r.code} (${r.name})` })),
    ],
    [rateTypes],
  );

  // Tags son dinámicos; el form mantiene un array de IDs pero necesitamos los
  // options disponibles (que vienen del padre via prop).
  const tagSelectOptions = tagOptions;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit();
      }}
      className={cn('space-y-6', compact && 'space-y-3')}
    >
      {/* ============================================================ */}
      {/* 1. Identificacion                                            */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Identificacion</SectionLegend>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field name="name" label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="iPhone 15" />
          </Field>
          <Field name="sku" label="SKU" hint="Opcional, único por empresa" error={form.formState.errors.sku?.message}>
            <Input {...form.register('sku')} placeholder="IPH15-128" />
          </Field>
          <Field name="barcode" label="Código de barras" hint="Opcional, único por empresa">
            <Input {...form.register('barcode')} placeholder="0194253714750" />
          </Field>
          <Field name="image_url" label="URL de imagen" error={form.formState.errors.image_url?.message}>
            <Input {...form.register('image_url')} placeholder="https://..." />
          </Field>
        </div>
        <Field name="description" label="Descripción corta" error={form.formState.errors.description?.message}>
          <Textarea {...form.register('description')} rows={2} placeholder="Smartphone Apple" />
        </Field>
        {!compact && (
          <Field name="long_description" label="Descripción larga" hint="Hasta 50000 caracteres (HTML permitido)">
            <Textarea {...form.register('long_description')} rows={4} placeholder="<p>Flagship 2023</p>" />
          </Field>
        )}
      </fieldset>

      {/* ============================================================ */}
      {/* 2. Catalogos (con inline create)                             */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Catálogos</SectionLegend>
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor="brand_id">Marca</Label>
            <InlineCatalogCreate
              kind="brand"
              onCreated={(id) => form.setValue('brand_id', id, { shouldValidate: true })}
            />
          </div>
          <Select
            id="brand_id"
            value={form.watch('brand_id') ? String(form.watch('brand_id')) : ''}
            onChange={(e) => {
              const v = e.target.value;
              form.setValue('brand_id', v === '' ? undefined : Number(v), {
                shouldValidate: true,
              });
            }}
          >
            {brandOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          {form.formState.errors.brand_id?.message && (
            <p className="text-xs text-danger">{form.formState.errors.brand_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label>Categorías</Label>
            <InlineCatalogCreate
              kind="category"
              onCreated={() => {
                // Para categorias, append al array. El usuario tendra que
                // seleccionarla manualmente en el tree (siguiente iteracion
                // mejorar el auto-select en TreeSelect).
              }}
            />
          </div>
          <Controller
            control={form.control}
            name="category_ids"
            render={({ field }) => {
              const tree = categoryTree as unknown as TreeLike[];
              return (
                <TreeSelect
                  nodes={tree.map(toNode)}
                  value={field.value ?? []}
                  onChange={(v) => field.onChange(v)}
                />
              );
            }}
          />
          <p className="text-xs text-text-muted">Jerárquicas: selecciona las hojas o ramas que apliquen</p>
        </div>
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label>Tags</Label>
            <InlineCatalogCreate
              kind="tag"
              onCreated={() => {
                // Auto-select requeriria que el Combobox soporte un nuevo value.
                // Por ahora el usuario debe volver a seleccionarlo.
              }}
            />
          </div>
          <Controller
            control={form.control}
            name="tag_ids"
            render={({ field }) => (
              <Combobox
                options={tagSelectOptions}
                value={field.value ?? []}
                onChange={field.onChange}
                placeholder="Buscar tags..."
              />
            )}
          />
          <p className="text-xs text-text-muted">Selecciona varios (typeahead arriba)</p>
        </div>
      </fieldset>

      {/* ============================================================ */}
      {/* 3. Control de stock                                           */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Control de stock</SectionLegend>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field name="tracking_type" label="Tipo de control" required>
            <Select {...form.register('tracking_type')}>
              {TRACKING_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t === 'quantity' ? 'Por cantidad' : 'Serializado (IMEI/serial)'}
                </option>
              ))}
            </Select>
          </Field>
          <Field name="unit_of_measure" label="Unidad de medida">
            <Select {...form.register('unit_of_measure')}>
              {UNITS_OF_MEASURE.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <SwitchField form={form} name="track_stock" label="Trackear stock de este producto" />
        <div className="grid grid-cols-3 gap-3">
          <Field name="min_stock" label="Stock mínimo" error={form.formState.errors.min_stock?.message}>
            <Input type="number" min="0" {...form.register('min_stock', { valueAsNumber: true })} />
          </Field>
          <Field name="max_stock" label="Stock máximo" error={form.formState.errors.max_stock?.message}>
            <Input type="number" min="0" {...form.register('max_stock', { valueAsNumber: true })} />
          </Field>
          <Field name="reorder_quantity" label="Cantidad a reordenar" error={form.formState.errors.reorder_quantity?.message}>
            <Input type="number" min="0" {...form.register('reorder_quantity', { valueAsNumber: true })} />
          </Field>
        </div>
      </fieldset>

      {/* ============================================================ */}
      {/* 4. Precios                                                    */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Precios</SectionLegend>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field name="base_price" label="Precio base" error={form.formState.errors.base_price?.message}>
            <Input type="number" min="0" step="0.01" {...form.register('base_price', { valueAsNumber: true })} />
          </Field>
          <Field name="sale_currency" label="Moneda de venta">
            <Select {...form.register('sale_currency')}>
              {SALE_CURRENCIES.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </Select>
          </Field>
          <Field
            name="sale_exchange_rate_type_id"
            label="Tipo de tasa"
            error={form.formState.errors.sale_exchange_rate_type_id?.message}
          >
            <Select
              value={form.watch('sale_exchange_rate_type_id') ? String(form.watch('sale_exchange_rate_type_id')) : ''}
              onChange={(e) => {
                const v = e.target.value;
                form.setValue('sale_exchange_rate_type_id', v === '' ? undefined : Number(v), {
                  shouldValidate: true,
                });
              }}
            >
              {rateTypeOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </fieldset>

      {/* ============================================================ */}
      {/* 5. Garantia + Estado                                          */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Garantía y estado</SectionLegend>
        <Field
          name="warranty_policy_id"
          label="Política de garantía"
          error={form.formState.errors.warranty_policy_id?.message}
        >
          <Select
            value={form.watch('warranty_policy_id') ? String(form.watch('warranty_policy_id')) : ''}
            onChange={(e) => {
              const v = e.target.value;
              form.setValue('warranty_policy_id', v === '' ? undefined : Number(v), {
                shouldValidate: true,
              });
            }}
          >
            {warrantyOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </Field>
        <SwitchField form={form} name="is_active" label="Producto activo (visible en ventas)" />
      </fieldset>

      {/* Botones de accion */}
      <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel} disabled={isSubmitting}>
            Cancelar
          </Button>
        )}
        <Button type="submit" loading={isSubmitting}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}

/**
 * SwitchField: renderiza un Switch conectado a un campo del form.
 *
 * Usa useController de RHF internamente (en lugar de <Controller>) para
 * evitar el bug "Cannot read properties of null (reading _names)" en
 * React 18 Strict Mode que sufria <Controller> (ver BrandsManager).
 */
function SwitchField({
  form,
  name,
  label,
}: {
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
  name: 'is_active' | 'track_stock';
  label: string;
}) {
  const { field } = useController({ name, control: form.control });
  return (
    <div className="flex items-center gap-2">
      <Switch
        id={name}
        checked={Boolean(field.value)}
        onCheckedChange={field.onChange}
      />
      <Label htmlFor={name}>{label}</Label>
    </div>
  );
}

// ============================================================================
// Helpers internos
// ============================================================================

interface TreeLike { id: number; name: string; children?: unknown[] }
interface TreeNode { id: number; label: string; children?: TreeNode[] }

const toNode = (c: TreeLike): TreeNode => ({
  id: c.id,
  label: c.name,
  children: (c.children as TreeLike[] | undefined)?.map(toNode),
});

function SectionLegend({ children }: { children: React.ReactNode }) {
  return (
    <legend className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">
      {children}
    </legend>
  );
}

interface FieldProps {
  name: string;
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}

function Field({ name, label, required, hint, error, children }: FieldProps) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={name} className="flex items-center gap-1">
        {label}
        {required && <span className="text-danger">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-xs text-text-muted">{hint}</p>}
      {error && <p className="text-xs text-danger">{error}</p>}
    </div>
  );
}

// Re-export del icon para no importar lucide en cada page.
export { Link2, TagIcon, TagsIcon };