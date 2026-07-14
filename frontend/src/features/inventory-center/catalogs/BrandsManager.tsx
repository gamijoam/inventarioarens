/**
 * BrandsManager: listado + crear + editar + eliminar marcas.
 * Solo UI; la logica de mutaciones viene de los hooks en api.ts.
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Label } from '@/components/ui/Label';
import { useBrands, useCreateBrand, useUpdateBrand, useDeleteBrand } from '@/features/inventory-center/api';
import type { Brand } from '@/features/inventory-center/schemas';

const BrandFormSchema = z.object({
  name: z.string().min(1, 'Requerido').max(150),
  slug: z
    .string()
    .min(1)
    .max(100)
    .regex(/^[a-z0-9-]+$/, 'Solo letras minusculas, numeros y guiones'),
  description: z.string().max(1000).optional(),
  is_active: z.boolean().default(true),
});
type BrandFormValues = z.infer<typeof BrandFormSchema>;

export function BrandsManager() {
  const { data: brands = [], isLoading } = useBrands();
  const createBrand = useCreateBrand();
  const updateBrand = useUpdateBrand();
  const deleteBrand = useDeleteBrand();
  const [editing, setEditing] = useState<Brand | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Brand | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nueva marca
        </Button>
      </div>

      {brands.length === 0 ? (
        <EmptyState title="Sin marcas" description="Crea la primera marca para poder asociarla a productos." />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Nombre</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Slug</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Productos</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {brands.map((b) => (
                <tr key={b.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 font-medium">{b.name}</td>
                  <td className="px-3 py-2 text-text-muted">
                    <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{b.slug}</code>
                  </td>
                  <td className="px-3 py-2 tabular-nums">{b.products_count ?? 0}</td>
                  <td className="px-3 py-2">
                    <Badge variant={b.is_active ? 'success' : 'default'}>
                      {b.is_active ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => setEditing(b)}
                        aria-label={`Editar ${b.name}`}
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => setDeleting(b)}
                        aria-label={`Eliminar ${b.name}`}
                      >
                        <Trash2 className="size-4 text-danger" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <BrandFormDialog
          brand={editing}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await updateBrand.mutateAsync({ id: editing.id, ...values });
                toast.success('Marca actualizada.');
              } else {
                await createBrand.mutateAsync(values);
                toast.success('Marca creada.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar marca.');
            }
          }}
          loading={createBrand.isPending || updateBrand.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setDeleting(null);
          }}
          title={`Eliminar marca "${deleting.name}"`}
          description="Esta accion no se puede deshacer. Los productos que tengan esta marca
          quedaran sin marca asignada."
          confirmLabel="Eliminar"
          variant="danger"
          loading={deleteBrand.isPending}
          onConfirm={async () => {
            try {
              await deleteBrand.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Marca eliminada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar marca.');
            }
          }}
        />
      )}
    </>
  );
}

function BrandFormDialog({
  brand,
  onClose,
  onSubmit,
  loading,
}: {
  brand: Brand | null;
  onClose: () => void;
  onSubmit: (values: BrandFormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<BrandFormValues>({
    resolver: zodResolver(BrandFormSchema),
    defaultValues: {
      name: brand?.name ?? '',
      slug: brand?.slug ?? '',
      description: brand?.description ?? '',
      is_active: brand?.is_active ?? true,
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{brand ? 'Editar marca' : 'Nueva marca'}</DialogTitle>
          <DialogDescription>
            Las marcas se usan para clasificar productos y aplicar politicas por marca.
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={form.handleSubmit((values) => void onSubmit(values))}
          className="space-y-3"
        >
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Apple" />
          </Field>
          <Field label="Slug" required hint="Identificador URL-safe" error={form.formState.errors.slug?.message}>
            <Input {...form.register('slug')} placeholder="apple" />
          </Field>
          <Field label="Descripcion" error={form.formState.errors.description?.message}>
            <Textarea {...form.register('description')} rows={2} />
          </Field>
          {/* Usamos watch + setValue en vez de <Controller> de RHF.
              El Controller dispara un bug "Cannot read properties of null
              (reading '_names')" en React 18 Strict Mode cuando el dialog
              se desmonta (al cerrarse). Ver docs/INVENTORY_MODULE_DEFERRED.md. */}
          <div className="flex items-center gap-2">
            <Switch
              id="brand-active"
              checked={Boolean(form.watch('is_active'))}
              onCheckedChange={(v) => form.setValue('is_active', v)}
            />
            <Label htmlFor="brand-active">Marca activa</Label>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {brand ? 'Guardar' : 'Crear'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  label,
  required,
  hint,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label className="flex items-center gap-1">
        {label}
        {required && <span className="text-danger">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-xs text-text-muted">{hint}</p>}
      {error && <p className="text-xs text-danger">{error}</p>}
    </div>
  );
}



