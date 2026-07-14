/**
 * TagsManager: chips con color + crear + editar + eliminar.
 */
import { useState } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Badge } from '@/components/ui/Badge';
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
import { useTags, useCreateTag, useUpdateTag, useDeleteTag } from '@/features/inventory-center/api';
import type { Tag } from '@/features/inventory-center/schemas';

export function TagsManager() {
  const { data: tags = [], isLoading } = useTags();
  const createTag = useCreateTag();
  const updateTag = useUpdateTag();
  const deleteTag = useDeleteTag();
  const [editing, setEditing] = useState<Tag | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Tag | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nuevo tag
        </Button>
      </div>

      {tags.length === 0 ? (
        <EmptyState title="Sin tags" description="Crea tags para clasificar productos (ej: oferta, nuevo, importado)." />
      ) : (
        <div className="flex flex-wrap gap-2">
          {tags.map((t) => (
            <div
              key={t.id}
              className="group flex items-center gap-1 rounded-md border border-border bg-surface px-2 py-1.5"
            >
              <Badge
                variant="default"
                style={
                  t.color
                    ? { backgroundColor: `${t.color}20`, color: t.color, borderColor: `${t.color}40` }
                    : undefined
                }
              >
                {t.name}
              </Badge>
              {t.products_count !== undefined && t.products_count > 0 && (
                <span className="text-xs text-text-muted">{t.products_count}</span>
              )}
              <div className="ml-1 flex items-center gap-0.5 opacity-0 group-hover:opacity-100">
                <Button
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setEditing(t)}
                  aria-label={`Editar ${t.name}`}
                >
                  <Pencil className="size-3.5" />
                </Button>
                <Button
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setDeleting(t)}
                  aria-label={`Eliminar ${t.name}`}
                >
                  <Trash2 className="size-3.5 text-danger" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      {(creating || editing) && (
        <TagFormDialog
          tag={editing}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await updateTag.mutateAsync({ id: editing.id, ...values });
                toast.success('Tag actualizado.');
              } else {
                await createTag.mutateAsync(values);
                toast.success('Tag creado.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar tag.');
            }
          }}
          loading={createTag.isPending || updateTag.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setDeleting(null);
          }}
          title={`Eliminar tag "${deleting.name}"`}
          description="Los productos que tengan este tag lo perderan."
          confirmLabel="Eliminar"
          variant="danger"
          loading={deleteTag.isPending}
          onConfirm={async () => {
            try {
              await deleteTag.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Tag eliminado.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function TagFormDialog({
  tag,
  onClose,
  onSubmit,
  loading,
}: {
  tag: Tag | null;
  onClose: () => void;
  onSubmit: (values: { name: string; slug: string; color: string | null }) => Promise<void>;
  loading: boolean;
}) {
  const isEdit = tag !== null;
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Editar tag' : 'Nuevo tag'}</DialogTitle>
          <DialogDescription>
            Los tags se usan para clasificar productos (ej: oferta, nuevo).
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            void e.preventDefault();
            // fd.get puede devolver File | string, asi que casteamos via toString()
            const fd = new FormData(e.currentTarget);
            void onSubmit({
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              name: String(fd.get('name')?.toString() ?? '').trim(),
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              slug: String(fd.get('slug')?.toString() ?? '').trim(),
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              color: String(fd.get('color')?.toString() ?? '').trim() || null,
            });
          }}
          className="space-y-3"
        >
          <div className="space-y-1.5">
            <Label htmlFor="tag-name">Nombre *</Label>
            <Input id="tag-name" name="name" required defaultValue={tag?.name ?? ''} placeholder="oferta" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tag-slug">Slug *</Label>
            <Input
              id="tag-slug"
              name="slug"
              required
              defaultValue={tag?.slug ?? ''}
              placeholder="oferta"
              pattern="[a-z0-9-]+"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tag-color">Color (hex, opcional)</Label>
            <Input
              id="tag-color"
              name="color"
              defaultValue={tag?.color ?? ''}
              placeholder="#FF5500"
              pattern="^#[0-9A-Fa-f]{6}$"
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {isEdit ? 'Guardar' : 'Crear'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}