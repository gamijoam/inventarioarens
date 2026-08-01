import { useMemo, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';
import { cn } from '@/lib/cn';
import { deleteOne, patchOne, postOne } from '@/api/client';

interface VariantFormState {
  color: string;
  color_hex: string;
  sku_variant: string;
  barcode_variant: string;
  price_override: string;
  position: string;
}

const EMPTY_FORM: VariantFormState = {
  color: '',
  color_hex: '#888888',
  sku_variant: '',
  barcode_variant: '',
  price_override: '',
  position: '0',
};

export function ProductVariantsTab({ productId }: { productId: number }) {
  const { data: variants = [], isLoading, refetch } = useProductVariants(productId);
  const [form, setForm] = useState<VariantFormState>(EMPTY_FORM);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const sorted = useMemo(
    () => [...variants].sort((a, b) => a.position - b.position || a.id - b.id),
    [variants],
  );

  function startEdit(variant: ProductVariant) {
    setEditingId(variant.id);
    setForm({
      color: variant.color ?? '',
      color_hex: variant.color_hex ?? '#888888',
      sku_variant: variant.sku_variant ?? '',
      barcode_variant: variant.barcode_variant ?? '',
      price_override: variant.price_override != null ? String(variant.price_override) : '',
      position: String(variant.position),
    });
  }

  function cancelEdit() {
    setEditingId(null);
    setForm(EMPTY_FORM);
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!form.color.trim()) {
      toast.error('Indica al menos el color.');
      return;
    }
    setSubmitting(true);
    try {
      const payload = {
        color: form.color.trim(),
        color_hex: form.color_hex.trim() || null,
        sku_variant: form.sku_variant.trim() || null,
        barcode_variant: form.barcode_variant.trim() || null,
        price_override: form.price_override ? Number(form.price_override) : null,
        position: Number(form.position) || 0,
        is_active: true,
      };
      if (editingId) {
        await patchOne(`/products/${productId}/variants/${editingId}`, payload);
        toast.success('Variante actualizada.');
      } else {
        await postOne(`/products/${productId}/variants`, payload);
        toast.success('Variante creada.');
      }
      cancelEdit();
      await refetch();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar la variante.');
    } finally {
      setSubmitting(false);
    }
  }

  async function removeVariant(variant: ProductVariant) {
    if (!confirm(`Eliminar la variante "${variant.color ?? 'default'}"?`)) return;
    try {
      await deleteOne(`/products/${productId}/variants/${variant.id}`);
      toast.success('Variante eliminada.');
      await refetch();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo eliminar.');
    }
  }

  async function toggleActive(variant: ProductVariant) {
    try {
      await patchOne(`/products/${productId}/variants/${variant.id}`, {
        is_active: !variant.is_active,
      });
      await refetch();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo cambiar.');
    }
  }

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>{editingId ? 'Editar variante' : 'Nueva variante'}</CardTitle>
          <CardDescription>
            Define los colores disponibles para este producto. Cada variante maneja stock propio.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label htmlFor="variant-color">Color *</Label>
              <Input
                id="variant-color"
                value={form.color}
                onChange={(event) => setForm({ ...form, color: event.target.value })}
                placeholder="Azul"
                required
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="variant-color-hex">Color hex</Label>
              <div className="flex items-center gap-2">
                <input
                  id="variant-color-hex"
                  type="color"
                  value={form.color_hex || '#888888'}
                  onChange={(event) => setForm({ ...form, color_hex: event.target.value })}
                  className="border-border-strong bg-surface h-9 w-12 cursor-pointer rounded border"
                />
                <Input
                  value={form.color_hex}
                  onChange={(event) => setForm({ ...form, color_hex: event.target.value })}
                  placeholder="#888888"
                  className="flex-1"
                />
              </div>
            </div>
            <div className="space-y-1">
              <Label htmlFor="variant-sku">SKU variante</Label>
              <Input
                id="variant-sku"
                value={form.sku_variant}
                onChange={(event) => setForm({ ...form, sku_variant: event.target.value })}
                placeholder="Opcional"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="variant-barcode">Código de barras</Label>
              <Input
                id="variant-barcode"
                value={form.barcode_variant}
                onChange={(event) => setForm({ ...form, barcode_variant: event.target.value })}
                placeholder="Opcional"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="variant-price">Precio override</Label>
              <Input
                id="variant-price"
                type="number"
                step="0.0001"
                min={0}
                value={form.price_override}
                onChange={(event) => setForm({ ...form, price_override: event.target.value })}
                placeholder="Usa el precio base si está vacío"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="variant-position">Orden</Label>
              <Input
                id="variant-position"
                type="number"
                step="1"
                min={0}
                value={form.position}
                onChange={(event) => setForm({ ...form, position: event.target.value })}
              />
            </div>
            <div className="flex items-center gap-2 sm:col-span-2 sm:justify-end">
              {editingId && (
                <Button type="button" variant="outline" onClick={cancelEdit} disabled={submitting}>
                  Cancelar
                </Button>
              )}
              <Button type="submit" loading={submitting}>
                <Plus className="size-4" />
                {editingId ? 'Guardar cambios' : 'Crear variante'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Variantes activas ({sorted.filter((v) => v.is_active).length})</CardTitle>
        </CardHeader>
        <CardContent>
          {sorted.length === 0 ? (
            <EmptyState
              title="Sin variantes"
              description="Este producto solo tiene la variante por defecto. Crea una para vender por color."
            />
          ) : (
            <ul className="space-y-2" data-testid="variants-list">
              {sorted.map((variant) => (
                <li
                  key={variant.id}
                  className={cn(
                    'flex items-center gap-3 rounded-md border p-3',
                    variant.is_active
                      ? 'border-border bg-surface'
                      : 'border-border/40 bg-bg/40 opacity-70',
                  )}
                  data-testid={`variant-row-${variant.id}`}
                >
                  <span
                    className="border-border inline-block size-8 shrink-0 rounded-full border"
                    style={{ backgroundColor: variant.color_hex ?? '#888888' }}
                    aria-hidden="true"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="font-medium">{variant.color ?? 'Default'}</div>
                    <div className="text-text-muted text-xs">
                      {variant.sku_variant ?? variant.barcode_variant ?? 'Sin SKU'}
                    </div>
                  </div>
                  {variant.price_override != null && (
                    <Badge variant="info">${Number(variant.price_override).toFixed(2)}</Badge>
                  )}
                  <Badge variant="default">Orden {variant.position}</Badge>
                  <Switch
                    checked={variant.is_active}
                    onChange={() => toggleActive(variant)}
                    aria-label="Variante activa"
                  />
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => startEdit(variant)}
                    aria-label={`Editar ${variant.color ?? 'variante'}`}
                  >
                    <PencilIcon />
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => removeVariant(variant)}
                    aria-label={`Eliminar ${variant.color ?? 'variante'}`}
                  >
                    <Trash2 className="text-danger size-4" />
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function PencilIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="size-4">
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
    </svg>
  );
}
