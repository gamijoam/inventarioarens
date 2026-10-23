import { useMemo, useState } from 'react';
import { Plus, Trash2, Sparkles, Layers } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from '@/lib/toast';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { Select } from '@/components/ui/Select';
import { useWarehouses } from '@/features/inventory-center/api';
import { productVariantKeys, useProductVariants } from '@/features/inventory-center/variantApi';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';
import { cn } from '@/lib/cn';
import { deleteOne, patchOne, postOne } from '@/api/client';

export interface PendingVariant {
  tempId: string;
  color: string;
  color_hex?: string | null;
  sku_variant?: string | null;
  barcode_variant?: string | null;
  price_override?: number | null;
  position: number;
  is_active: boolean;
}

export interface ProductVariantsTabProps {
  productId?: number;
  pendingVariants?: PendingVariant[];
  onPendingVariantsChange?: (variants: PendingVariant[]) => void;
  baseSku?: string;
  basePrice?: number;
}

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

export function ProductVariantsTab({
  productId,
  pendingVariants = [],
  onPendingVariantsChange,
  baseSku = '',
  basePrice = 0,
}: ProductVariantsTabProps) {
  const qc = useQueryClient();
  const { data: warehouses = [] } = useWarehouses();
  const [warehouseId, setWarehouseId] = useState<number | null>(null);
  const selectedWarehouseId = warehouseId ?? warehouses[0]?.id ?? null;

  // Consulta al servidor solo si productId existe
  const {
    data: serverVariants = [],
    isLoading,
    refetch,
  } = useProductVariants(productId ?? 0, selectedWarehouseId);

  const [form, setForm] = useState<VariantFormState>(EMPTY_FORM);
  const [editingId, setEditingId] = useState<number | string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Unifica variantes del servidor o pendientes en memoria
  const sorted = useMemo(() => {
    if (productId) {
      return [...serverVariants].sort((a, b) => a.position - b.position || a.id - b.id);
    }
    return [...pendingVariants].sort((a, b) => a.position - b.position);
  }, [productId, serverVariants, pendingVariants]);

  function startEdit(variant: ProductVariant | PendingVariant) {
    const id = 'id' in variant ? variant.id : variant.tempId;
    setEditingId(id);
    setForm({
      color: variant.color ?? '',
      color_hex: variant.color_hex ?? '#888888',
      sku_variant: variant.sku_variant ?? '',
      barcode_variant: variant.barcode_variant ?? '',
      price_override: variant.price_override != null ? String(variant.price_override) : '',
      position: String(variant.position ?? 0),
    });
  }

  function cancelEdit() {
    setEditingId(null);
    setForm(EMPTY_FORM);
  }

  function suggestSku() {
    if (!form.color.trim()) {
      toast.info('Ingresa primero el color o nombre de la variante.');
      return;
    }
    const cleanColor = form.color
      .trim()
      .toUpperCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^A-Z0-9]+/g, '-');
    const prefix = baseSku ? baseSku.trim().toUpperCase() : 'VAR';
    setForm((prev) => ({ ...prev, sku_variant: `${prefix}-${cleanColor}` }));
  }

  async function submit(event?: React.FormEvent | React.SyntheticEvent) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    if (!form.color.trim()) {
      toast.error('Indica al menos el color o nombre de la variante.');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        color: form.color.trim(),
        color_hex: form.color_hex.trim() || '#888888',
        sku_variant: form.sku_variant.trim() || null,
        barcode_variant: form.barcode_variant.trim() || null,
        price_override: form.price_override ? Number(form.price_override) : null,
        position: Number(form.position) || (sorted.length + 1),
        is_active: true,
      };

      if (productId) {
        // Modo edición con producto guardado en backend
        if (editingId) {
          await patchOne(`/products/${productId}/variants/${editingId}`, payload);
          toast.success('Variante actualizada.');
        } else {
          await postOne(`/products/${productId}/variants`, payload);
          toast.success('Variante creada.');
        }
        cancelEdit();
        await qc.invalidateQueries({ queryKey: productVariantKeys.all });
        await refetch();
      } else {
        // Modo creación en memoria
        if (editingId) {
          const next = pendingVariants.map((v) =>
            v.tempId === String(editingId) ? { ...v, ...payload, tempId: v.tempId } : v,
          );
          onPendingVariantsChange?.(next);
          toast.success('Variante actualizada.');
        } else {
          const newVar: PendingVariant = {
            tempId: `temp-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            ...payload,
          };
          onPendingVariantsChange?.([...pendingVariants, newVar]);
          toast.success('Variante agregada a la lista.');
        }
        cancelEdit();
      }
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar la variante.');
    } finally {
      setSubmitting(false);
    }
  }

  async function removeVariant(variant: ProductVariant | PendingVariant) {
    const isServer = 'id' in variant;
    const name = variant.color ?? 'esta variante';
    if (!confirm(`¿Eliminar la variante "${name}"?`)) return;

    if (productId && isServer) {
      try {
        await deleteOne(`/products/${productId}/variants/${variant.id}`);
        toast.success('Variante eliminada.');
        await refetch();
      } catch (error) {
        toast.error(error instanceof Error ? error.message : 'No se pudo eliminar.');
      }
    } else {
      const tempId = 'tempId' in variant ? variant.tempId : String((variant as { id: number }).id);
      const next = pendingVariants.filter((v) => v.tempId !== tempId);
      onPendingVariantsChange?.(next);
      toast.success('Variante eliminada.');
    }
  }

  async function toggleActive(variant: ProductVariant | PendingVariant) {
    const isServer = 'id' in variant;
    if (productId && isServer) {
      try {
        await patchOne(`/products/${productId}/variants/${variant.id}`, {
          is_active: !variant.is_active,
        });
        await refetch();
      } catch (error) {
        toast.error(error instanceof Error ? error.message : 'No se pudo cambiar el estado.');
      }
    } else {
      const tempId = 'tempId' in variant ? variant.tempId : String((variant as { id: number }).id);
      const next = pendingVariants.map((v) =>
        v.tempId === tempId ? { ...v, is_active: !v.is_active } : v,
      );
      onPendingVariantsChange?.(next);
    }
  }

  if (Boolean(productId && isLoading)) return <Skeleton className="h-32 w-full" />;

  return (
    <div className="space-y-4">
      {/* Tarjeta de Formulario Nueva/Editar Variante */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <CardTitle className="text-sm font-bold flex items-center gap-2">
                <Layers className="size-4 text-primary" />
                {editingId ? 'Editar variante' : 'Nueva variante'}
              </CardTitle>
              <CardDescription className="text-xs">
                {productId
                  ? 'Define los colores o presentaciones disponibles. Cada variante maneja su propio stock y código.'
                  : 'Agrega todas las variaciones de color o tamaño. Se crearán automáticamente con el nuevo producto.'}
              </CardDescription>
            </div>
            {!productId && pendingVariants.length > 0 && (
              <Badge variant="success" className="text-xs font-semibold">
                {pendingVariants.length} variante(s) lista(s)
              </Badge>
            )}
          </div>
        </CardHeader>
        <CardContent>
          <div
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                void submit(event);
              }
            }}
            className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3"
          >
            <div className="space-y-1">
              <Label htmlFor="variant-color" className="text-xs font-semibold">Color / Nombre *</Label>
              <Input
                id="variant-color"
                value={form.color}
                onChange={(event) => setForm({ ...form, color: event.target.value })}
                placeholder="Ej. Rojo, 128GB, Talla L"
                className="h-8 text-xs font-medium"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="variant-color-hex" className="text-xs font-semibold">Muestra de Color</Label>
              <div className="flex items-center gap-2">
                <input
                  id="variant-color-hex"
                  type="color"
                  value={form.color_hex || '#888888'}
                  onChange={(event) => setForm({ ...form, color_hex: event.target.value })}
                  className="border-border bg-surface h-8 w-10 cursor-pointer rounded border p-0.5"
                  title="Seleccionar color visual"
                />
                <Input
                  value={form.color_hex}
                  onChange={(event) => setForm({ ...form, color_hex: event.target.value })}
                  placeholder="#888888"
                  className="flex-1 h-8 text-xs font-mono"
                />
              </div>
            </div>

            <div className="space-y-1">
              <div className="flex items-center justify-between">
                <Label htmlFor="variant-sku" className="text-xs font-semibold">SKU Variante</Label>
                <button
                  type="button"
                  onClick={suggestSku}
                  className="text-[10px] text-primary hover:underline font-semibold flex items-center gap-0.5 cursor-pointer"
                  title="Generar SKU automáticamente"
                >
                  <Sparkles className="size-2.5" />
                  Auto
                </button>
              </div>
              <Input
                id="variant-sku"
                value={form.sku_variant}
                onChange={(event) => setForm({ ...form, sku_variant: event.target.value })}
                placeholder="Ej. CAM-ROJ-L"
                className="h-8 text-xs font-mono"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="variant-barcode" className="text-xs font-semibold">Código de Barras</Label>
              <Input
                id="variant-barcode"
                value={form.barcode_variant}
                onChange={(event) => setForm({ ...form, barcode_variant: event.target.value })}
                placeholder="750123456789"
                className="h-8 text-xs font-mono"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="variant-price" className="text-xs font-semibold">Precio Específico ($)</Label>
              <Input
                id="variant-price"
                type="number"
                step="0.01"
                min={0}
                value={form.price_override}
                onChange={(event) => setForm({ ...form, price_override: event.target.value })}
                placeholder={basePrice > 0 ? `Base: $${basePrice.toFixed(2)}` : 'Hereda precio base'}
                className="h-8 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="variant-position" className="text-xs font-semibold">Orden Visual</Label>
              <Input
                id="variant-position"
                type="number"
                step="1"
                min={0}
                value={form.position}
                onChange={(event) => setForm({ ...form, position: event.target.value })}
                className="h-8 text-xs font-mono"
              />
            </div>

            <div className="flex items-center gap-2 sm:col-span-2 md:col-span-3 justify-end pt-1">
              {editingId && (
                <Button type="button" variant="outline" size="sm" onClick={cancelEdit} disabled={submitting} className="h-8 text-xs">
                  Cancelar
                </Button>
              )}
              <Button type="button" size="sm" onClick={() => void submit()} loading={submitting} className="h-8 text-xs gap-1.5 font-semibold">
                <Plus className="size-3.5" />
                {editingId ? 'Guardar cambios' : 'Agregar variante'}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Tarjeta de Lista de Variantes */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle className="text-sm font-bold">
                Variantes configuradas ({sorted.filter((v) => v.is_active).length})
              </CardTitle>
              <CardDescription className="text-xs">
                {productId
                  ? 'El stock se muestra para el almacén seleccionado.'
                  : 'Estas variantes se crearán y asociarán al guardar el producto.'}
              </CardDescription>
            </div>
            {Boolean(productId && warehouses.length > 0) && (
              <div className="min-w-56 space-y-1">
                <Label htmlFor="variant-stock-warehouse" className="text-xs">Almacén</Label>
                <Select
                  id="variant-stock-warehouse"
                  value={selectedWarehouseId ?? ''}
                  onChange={(event) => setWarehouseId(Number(event.target.value) || null)}
                  className="h-8 text-xs"
                >
                  {warehouses.map((warehouse) => (
                    <option key={warehouse.id} value={warehouse.id}>
                      {warehouse.code} - {warehouse.name}
                    </option>
                  ))}
                </Select>
              </div>
            )}
          </div>
        </CardHeader>
        <CardContent>
          {sorted.length === 0 ? (
            <EmptyState
              title="Sin variantes adicionales"
              description={
                productId
                  ? 'Este producto solo tiene la variante por defecto. Crea una para vender por color o tamaño.'
                  : 'Aún no has agregado variantes. Puedes crear varias antes de guardar el nuevo producto.'
              }
            />
          ) : (
            <ul className="space-y-2" data-testid="variants-list">
              {sorted.map((variant) => {
                const key = 'id' in variant ? variant.id : variant.tempId;
                const stockVal = 'stock_available' in variant ? Number(variant.stock_available ?? 0) : null;
                return (
                  <li
                    key={key}
                    className={cn(
                      'flex items-center gap-3 rounded-lg border p-2.5 transition-colors',
                      variant.is_active
                        ? 'border-border bg-surface hover:bg-surface-subtle/50'
                        : 'border-border/40 bg-bg/40 opacity-70',
                    )}
                    data-testid={`variant-row-${key}`}
                  >
                    <span
                      className="border-border inline-block size-7 shrink-0 rounded-full border shadow-xs"
                      style={{ backgroundColor: variant.color_hex ?? '#888888' }}
                      aria-hidden="true"
                    />
                    <div className="min-w-0 flex-1">
                      <div className="font-semibold text-xs text-text-primary flex items-center gap-1.5">
                        {variant.color ?? 'Default'}
                        {variant.sku_variant && (
                          <span className="font-mono text-[11px] text-text-muted font-normal">
                            ({variant.sku_variant})
                          </span>
                        )}
                      </div>
                      <div className="text-text-muted text-[11px] font-mono mt-0.5">
                        {variant.barcode_variant ? `BAR: ${variant.barcode_variant}` : 'Sin código de barra'}
                      </div>
                    </div>

                    {stockVal !== null && (
                      <Badge
                        variant={stockVal > 0 ? 'success' : 'warning'}
                        className="shrink-0 text-[10px] py-0"
                      >
                        Stock {stockVal}
                      </Badge>
                    )}

                    {variant.price_override != null && Number(variant.price_override) > 0 ? (
                      <Badge variant="info" className="text-[10px] py-0 font-mono font-bold">
                        ${Number(variant.price_override).toFixed(2)}
                      </Badge>
                    ) : (
                      <span className="text-[10px] text-text-muted font-medium hidden sm:inline">
                        PVP Base
                      </span>
                    )}

                    <Badge variant="default" className="text-[10px] py-0">
                      #{variant.position}
                    </Badge>

                    <Switch
                      checked={variant.is_active}
                      onChange={() => void toggleActive(variant)}
                      aria-label="Variante activa"
                    />

                    <Button
                      type="button"
                      size="icon-sm"
                      variant="ghost"
                      onClick={() => startEdit(variant)}
                      aria-label={`Editar ${variant.color ?? 'variante'}`}
                      className="size-7"
                    >
                      <PencilIcon />
                    </Button>
                    <Button
                      type="button"
                      size="icon-sm"
                      variant="ghost"
                      onClick={() => void removeVariant(variant)}
                      aria-label={`Eliminar ${variant.color ?? 'variante'}`}
                      className="size-7 text-danger hover:text-danger-hover"
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </li>
                );
              })}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function PencilIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="size-3.5">
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
    </svg>
  );
}
