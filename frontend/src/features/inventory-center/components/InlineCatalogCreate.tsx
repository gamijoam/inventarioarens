/**
 * InlineCatalogCreate: componente que permite crear catalogos (marca,
 * categoria o tag) inline desde el formulario de producto, sin necesidad
 * de ir a /inventory/catalogs.
 *
 * Renderiza un boton "+ Nueva X" que abre un mini Dialog. Al confirmar,
 * la lista se revalida y el item nuevo se auto-selecciona en el form padre
 * via onCreated(id).
 *
 * Cubre el UX gap reportado por el usuario: antes habia que ir a catalogos,
 * crear, volver al form y seleccionar. Ahora todo es inline.
 */
import { useState } from 'react';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Label } from '@/components/ui/Label';
import {
  useCreateBrand,
  useCreateCategory,
  useCreateTag,
} from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys, productKeys } from '@/features/inventory-center/queries';

type Kind = 'brand' | 'category' | 'tag';

const COPY: Record<Kind, { title: string; description: string; name: string; slug: string }> = {
  brand: {
    title: 'Nueva marca',
    description: 'Crea una marca sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug (URL-safe, ej: apple)',
  },
  category: {
    title: 'Nueva categoría',
    description: 'Crea una categoría sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug (URL-safe, ej: electronica)',
  },
  tag: {
    title: 'Nuevo tag',
    description: 'Crea un tag sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug (URL-safe, ej: oferta)',
  },
};

interface InlineCatalogCreateProps {
  kind: Kind;
  onCreated: (id: number) => void;
}

export function InlineCatalogCreate({ kind, onCreated }: InlineCatalogCreateProps) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  const qc = useQueryClient();
  const createBrand = useCreateBrand();
  const createCategory = useCreateCategory();
  const createTag = useCreateTag();

  const copy = COPY[kind];

  const reset = () => {
    setName('');
    setSlug('');
    setIsActive(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    setSubmitting(true);
    try {
      const payload = {
        name: name.trim(),
        slug: slug.trim() || undefined,
        is_active: isActive,
      };
      let newId: number | undefined;
      if (kind === 'brand') {
        const created = await createBrand.mutateAsync(payload);
        newId = (created as { id: number }).id;
      } else if (kind === 'category') {
        const created = await createCategory.mutateAsync(payload);
        newId = (created as { id: number }).id;
      } else {
        const created = await createTag.mutateAsync(payload);
        newId = (created as { id: number }).id;
      }
      // Refrescar listas para que el dropdown incluya el nuevo item.
      void qc.invalidateQueries({ queryKey: catalogKeys.brands() });
      void qc.invalidateQueries({ queryKey: catalogKeys.categories() });
      void qc.invalidateQueries({ queryKey: catalogKeys.tags() });
      void qc.invalidateQueries({ queryKey: catalogKeys.categoryTree() });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      toast.success(`${copy.title.replace('Nueva ', 'Nuevo ').toLowerCase()} creada correctamente.`);
      if (newId) onCreated(newId);
      reset();
      setOpen(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <Button
        type="button"
        size="sm"
        variant="outline"
        onClick={() => setOpen(true)}
        data-testid={`inline-create-${kind}`}
      >
        <Plus className="size-3.5" aria-hidden="true" />
        {copy.title}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{copy.title}</DialogTitle>
            <DialogDescription>{copy.description}</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="inline-name">{copy.name}</Label>
              <Input
                id="inline-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoFocus
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="inline-slug">
                {copy.slug}{' '}
                <span className="text-xs font-normal text-text-muted">(opcional)</span>
              </Label>
              <Input
                id="inline-slug"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                placeholder="auto desde nombre"
              />
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="inline-active"
                checked={isActive}
                onCheckedChange={setIsActive}
              />
              <Label htmlFor="inline-active">Activo</Label>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
                disabled={submitting}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={submitting}>
                Crear
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}