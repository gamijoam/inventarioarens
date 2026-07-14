/**
 * ProductForm: formulario completo de producto (create + edit).
 * Renderiza todos los campos del backend (ver docs/INVENTORY_CATALOG_API.md).
 * Usa react-hook-form + zodResolver via useProductForm().
 *
 * Secciones:
 *  1. Identificacion (name, sku, barcode, image_url)
 *  2. Catalogos (brand, categories, tags)
 *  3. Control de stock (tracking_type, unit_of_measure, track_stock, min/max/reorder)
 *  4. Precios (base_price, sale_currency, sale_exchange_rate_type)
 *  5. Garantia + Estado (warranty_policy_id, is_active, description, long_description)
 */
import { Controller, type UseFormReturn } from 'react-hook-form';
import { useMemo } from 'react';
import { Link2, Tag as TagIcon, Tags as TagsIcon } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Combobox } from '@/components/ui/Combobox';
import { TreeSelect } from '@/components/ui/TreeSelect';
import { Label } from '@/components/ui/Label';
import { cn } from '@/lib/cn';

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
      { value: '', label: 'ÔÇö Sin marca ÔÇö' },
      ...brands.map((b) => ({ value: String(b.id), label: b.name })),
    ],
    [brands],
  );

  const warrantyOptions = useMemo(
    () => [
      { value: '', label: 'ÔÇö Sin garant├¡a ÔÇö' },
      ...warrantyPolicies.map((w) => ({ value: String(w.id), label: w.name })),
    ],
    [warrantyPolicies],
  );

  const rateTypeOptions = useMemo(
    () => [
      { value: '', label: 'ÔÇö Heredar del sistema ÔÇö' },
      ...rateTypes.map((r) => ({ value: String(r.id), label: `${r.code} (${r.name})` })),
    ],
    [rateTypes],
  );

  // Tags son din├ímicos; el form mantiene un array de IDs pero necesitamos los
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
          <Field name="sku" label="SKU" hint="Opcional, unico por empresa" error={form.formState.errors.sku?.message}>
            <Input {...form.register('sku')} placeholder="IPH15-128" />
          </Field>
          <Field name="barcode" label="Codigo de barras" hint="Opcional, unico por empresa">
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
      {/* 2. Catalogos                                                   */}
      {/* ============================================================ */}
      <fieldset className="space-y-3">
        <SectionLegend>Catalogos</SectionLegend>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field name="brand_id" label="Marca" error={form.formState.errors.brand_id?.message}>
            <Select
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
          </Field>
        </div>
          <Field
          name="category_ids"
          label="Categorias"
          hint="Jerárquicas: selecciona las hojas o ramas que apliquen"
        >
            <Controller
              control={form.control}
              name="category_ids"
              render={({ field }) => {
                // El form devuelve unknown[] generico; hacemos cast a TreeLike[]
                // para que el compilador no se queje del shape.
                const tree = categoryTree as unknown as TreeLike[];
                return (
                  <TreeSelect
                    nodes={tree.map(toNode)}
                    value={(field.value ?? [])}
                    onChange={(v) => field.onChange(v)}
                  />
                );
              }}
            />
          </Field>
        <Field name="tag_ids" label="Tags" hint="Selecciona varios (typeahead arriba)">
          <Controller
            control={form.control}
            name="tag_ids"
            render={({ field }) => (
              <Combobox
                options={tagSelectOptions}
                value={(field.value ?? [])}
                onChange={field.onChange}
                placeholder="Buscar tags..."
              />
            )}
          />
        </Field>
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
        <Controller
          control={form.control}
          name="track_stock"
          render={({ field }) => (
            <div className="flex items-center gap-2">
              <Switch id="track_stock" checked={Boolean(field.value)} onCheckedChange={field.onChange} />
              <Label htmlFor="track_stock">Trackear stock de este producto</Label>
            </div>
          )}
        />
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
        <SectionLegend>Garantia y estado</SectionLegend>
        <Field
          name="warranty_policy_id"
          label="Politica de garantia"
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
        <Controller
          control={form.control}
          name="is_active"
          render={({ field }) => (
            <div className="flex items-center gap-2">
              <Switch id="is_active" checked={Boolean(field.value)} onCheckedChange={field.onChange} />
              <Label htmlFor="is_active">Producto activo (visible en ventas)</Label>
            </div>
          )}
        />
      </fieldset>

      {/* Botones de accion */}
      <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            disabled={isSubmitting}
            className="rounded border border-border-strong bg-surface px-3 py-2 text-sm hover:bg-bg disabled:opacity-50"
          >
            Cancelar
          </button>
        )}
        <button
          type="submit"
          disabled={isSubmitting}
          className="rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary-hover disabled:opacity-50"
        >
          {isSubmitting ? 'Guardando...' : submitLabel}
        </button>
      </div>
    </form>
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
