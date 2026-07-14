/**
 * CategoriesManager: tree de categorias + crear + editar + eliminar.
 * Las categorias tienen jerarquia via parent_id.
 */
import { useState } from 'react';
import { ChevronRight, ChevronDown, Plus, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
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
import { Select } from '@/components/ui/Select';
import {
  useCategoriesTree,
  useCreateCategory,
  useUpdateCategory,
  useDeleteCategory,
} from '@/features/inventory-center/api';
import type { Category } from '@/features/inventory-center/schemas';

export function CategoriesManager() {
  const { data: tree = [], isLoading } = useCategoriesTree();
  const createCategory = useCreateCategory();
  const updateCategory = useUpdateCategory();
  const deleteCategory = useDeleteCategory();
  const [editing, setEditing] = useState<Category | null>(null);
  const [creating, setCreating] = useState<{ parentId: number | null } | null>(null);
  const [deleting, setDeleting] = useState<Category | null>(null);
  const [expanded, setExpanded] = useState<Set<number>>(new Set());

  const toggle = (id: number) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button
          size="sm"
          leftIcon={<Plus className="size-4" />}
          onClick={() => setCreating({ parentId: null })}
        >
          Nueva categoria raiz
        </Button>
      </div>

      {tree.length === 0 ? (
        <EmptyState
          title="Sin categorías"
          description="Crea la primera categoría raíz para empezar a organizar tu catálogo."
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface p-2">
          {renderNodes(tree, 0)}
        </div>
      )}

      {Boolean(creating ?? null) || Boolean(editing ?? null) ? (
        <CategoryFormDialog
          category={editing}
          defaultParentId={creating?.parentId ?? null}
          onClose={() => {
            setCreating(null);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await updateCategory.mutateAsync({ id: editing.id, ...values });
                toast.success('Categoría actualizada.');
              } else {
                await createCategory.mutateAsync(values);
                toast.success('Categoría creada.');
              }
              setCreating(null);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar categoria.');
            }
          }}
          loading={createCategory.isPending ?? updateCategory.isPending ?? false}
        />
      ) : null}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setDeleting(null);
          }}
          title={`Eliminar categoria "${deleting.name}"`}
          description="Las subcategorias quedaran sin padre. Los productos perderan esta categoria."
          confirmLabel="Eliminar"
          variant="danger"
          loading={deleteCategory.isPending}
          onConfirm={async () => {
            try {
              await deleteCategory.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Categoría eliminada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );

  function renderNodes(nodes: Category[], level: number) {
    return nodes.map((c) => {
      const hasChildren = (c.children?.length ?? 0) > 0;
      const isExpanded = expanded.has(c.id) ? true : level < 1;
      return (
        <div key={c.id}>
          <div
            className="group flex items-center gap-1.5 rounded px-1.5 py-1 hover:bg-bg"
            style={{ paddingLeft: `${level * 16 + 6}px` }}
          >
            {hasChildren ? (
              <button
                type="button"
                onClick={() => toggle(c.id)}
                className="rounded p-0.5 text-text-muted hover:bg-bg"
                aria-label={isExpanded ? 'Colapsar' : 'Expandir'}
              >
                {isExpanded ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
              </button>
            ) : (
              <span className="w-4" />
            )}
            <span className="flex-1 truncate text-sm">
              <span className="font-medium">{c.name}</span>
              <span className="ml-1 text-xs text-text-muted">
                {c.full_path ?? c.slug}
              </span>
              {c.products_count !== undefined && c.products_count > 0 && (
                <span className="ml-2 text-xs text-text-muted">
                  ({c.products_count} productos)
                </span>
              )}
            </span>
            <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100">
              <Button
                size="icon-sm"
                variant="ghost"
                onClick={() => setCreating({ parentId: c.id })}
                aria-label="Añadir subcategoría"
                title="Añadir subcategoría"
              >
                <Plus className="size-3.5" />
              </Button>
              <Button
                size="icon-sm"
                variant="ghost"
                onClick={() => setEditing(c)}
                aria-label={`Editar ${c.name}`}
              >
                <Pencil className="size-4" />
              </Button>
              <Button
                size="icon-sm"
                variant="ghost"
                onClick={() => setDeleting(c)}
                aria-label={`Eliminar ${c.name}`}
              >
                <Trash2 className="size-4 text-danger" />
              </Button>
            </div>
          </div>
          {hasChildren && isExpanded && renderNodes(c.children!, level + 1)}
        </div>
      );
    });
  }
}

function CategoryFormDialog({
  category,
  defaultParentId,
  onClose,
  onSubmit,
  loading,
}: {
  category: Category | null;
  defaultParentId: number | null;
  onClose: () => void;
  onSubmit: (values: { name: string; slug: string; parent_id: number | null; description: string; is_active: boolean }) => Promise<void>;
  loading: boolean;
}) {
  const { data: tree = [] } = useCategoriesTree();
  const isEdit = category !== null;

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle>
          <DialogDescription>
            Las categorías se organizan en jerarquía. Define parent_id para crear subcategorías.
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            void e.preventDefault();
            const fd = new FormData(e.currentTarget);
            void onSubmit({
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              name: String(fd.get('name')?.toString() ?? '').trim(),
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              slug: String(fd.get('slug')?.toString() ?? '').trim(),
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              parent_id: fd.get('parent_id') ? Number(String(fd.get('parent_id'))) : null,
              // eslint-disable-next-line @typescript-eslint/no-base-to-string
              description: String(fd.get('description')?.toString() ?? '').trim(),
              is_active: fd.get('is_active') === 'on',
            });
          }}
          className="space-y-3"
        >
          <div className="space-y-1.5">
            <Label htmlFor="cat-name">Nombre *</Label>
            <Input
              id="cat-name"
              name="name"
              required
              defaultValue={category?.name ?? ''}
              placeholder="Electronica"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cat-slug">Slug *</Label>
            <Input
              id="cat-slug"
              name="slug"
              required
              defaultValue={category?.slug ?? ''}
              placeholder="electronica"
              pattern="[-a-z0-9]+"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cat-parent">Categoría padre</Label>
            <Select
              id="cat-parent"
              name="parent_id"
              defaultValue={String(category?.parent_id ?? defaultParentId ?? '')}
            >
              <option value="">(Sin padre - categoría raíz)</option>
              {renderOptions(tree, '', 0)}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cat-desc">Descripcion</Label>
            <Textarea id="cat-desc" name="description" rows={2} defaultValue={category?.description ?? ''} />
          </div>
          <div className="flex items-center gap-2">
            <Switch
              id="cat-active"
              name="is_active"
              defaultChecked={category?.is_active ?? true}
            />
            <Label htmlFor="cat-active">Categoría activa</Label>
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

  function renderOptions(nodes: Category[], prefix: string, depth: number): React.ReactNode[] {
    return nodes.flatMap((c) => {
      if (isEdit && c.id === category.id) return [];
      const path = prefix ? `${prefix} / ${c.name}` : c.name;
      const children = c.children ? renderOptions(c.children, path, depth + 1) : [];
      return [<option key={c.id} value={c.id}>{path}</option>, ...children];
    });
  }
}

