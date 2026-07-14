/**
 * InlineCatalogCreate: componente que permite crear catalogos (marca,
 * categoria o tag) inline desde el formulario de producto, sin necesidad
 * de ir a /inventory/catalogs.
 *
 * Renderiza un boton "+ Nueva X" que abre un mini Dialog. Al confirmar,
 * la lista se revalida y el item nuevo se auto-selecciona en el form padre
 * via onCreated(id).
 *
 * Comportamiento:
 * - El slug es OBLIGATORIO (el backend lo exige), pero se AUTOGENERA
 *   desde el nombre (slugify) si el usuario no lo completa. Asi evitamos
 *   pedirle trabajo extra, pero el backend siempre recibe un slug valido.
 * - Validacion client-side: regex ^[a-z0-9-]+$ (igual que el backend).
 * - Mensajes de error del backend se muestran inline en el form (en
 *   espanol, customizados en Store*Request.php).
 */
import { useEffect, useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Label } from '@/components/ui/Label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import {
  useCreateBrand,
  useCreateCategory,
  useCreateTag,
} from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys, productKeys } from '@/features/inventory-center/queries';
import { ValidationError } from '@/types/api';

type Kind = 'brand' | 'category' | 'tag';

const COPY: Record<Kind, { title: string; description: string; name: string; slug: string }> = {
  brand: {
    title: 'Nueva marca',
    description: 'Crea una marca sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug',
  },
  category: {
    title: 'Nueva categoría',
    description: 'Crea una categoría sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug',
  },
  tag: {
    title: 'Nuevo tag',
    description: 'Crea un tag sin salir del formulario.',
    name: 'Nombre',
    slug: 'Slug',
  },
};

// Mismas reglas que el backend (StoreBrand/Category/TagRequest).
const SLUG_REGEX = /^[a-z0-9-]+$/;

/** Convierte un string en slug URL-safe (mismas reglas que Str::slug de Laravel). */
function slugify(input: string): string {
  return input
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '') // quitar diacriticos
    .replace(/[^a-z0-9\s-]/g, '') // quitar caracteres no permitidos
    .trim()
    .replace(/\s+/g, '-') // espacios a guion
    .replace(/-+/g, '-') // colapsar guiones multiples
    .replace(/^-|-$/g, ''); // quitar guiones al inicio/final
}

interface InlineCatalogCreateProps {
  kind: Kind;
  onCreated: (id: number) => void;
}

export function InlineCatalogCreate({ kind, onCreated }: InlineCatalogCreateProps) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [isActive, setIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const qc = useQueryClient();
  const createBrand = useCreateBrand();
  const createCategory = useCreateCategory();
  const createTag = useCreateTag();

  const copy = COPY[kind];

  // Slug efectivo: si el usuario ya lo edito, usa lo que escribio.
  // Si no, autogenera desde el name (mismas reglas que Str::slug de Laravel).
  const effectiveSlug = useMemo(() => {
    if (slugTouched) return slug;
    return slugify(name);
  }, [name, slug, slugTouched]);

  // Reset al abrir.
  useEffect(() => {
    if (open) {
      setName('');
      setSlug('');
      setSlugTouched(false);
      setIsActive(true);
      setFieldErrors({});
    }
  }, [open]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Validacion client-side.
    const errors: Record<string, string> = {};
    if (!name.trim()) errors.name = 'El nombre es obligatorio.';
    else if (name.trim().length < 2) errors.name = 'El nombre debe tener al menos 2 caracteres.';

    const finalSlug = effectiveSlug.trim();
    if (!finalSlug) {
      errors.slug = 'El slug es obligatorio.';
    } else if (!SLUG_REGEX.test(finalSlug)) {
      errors.slug =
        'Solo letras minúsculas, números y guiones (sin espacios ni acentos).';
    }
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);

    try {
      const payload = {
        name: name.trim(),
        slug: finalSlug,
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
      toast.success('Creado correctamente.');
      if (newId) onCreated(newId);
      setOpen(false);
    } catch (err) {
      // Mapear errores del backend (en espanol, customizados en los
      // Store*Request) a los campos del form para mostrarlos inline.
      if (err instanceof ValidationError && err.fieldErrors) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.fieldErrors)) {
          if (v && v.length > 0) mapped[k] = v[0]!;
        }
        setFieldErrors(mapped);
      } else {
        toast.error(
          err instanceof Error ? err.message : 'Error al crear.',
        );
      }
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
              <Label htmlFor="inline-name">
                {copy.name} <span className="text-danger">*</span>
              </Label>
              <Input
                id="inline-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoFocus
                aria-invalid={Boolean(fieldErrors.name)}
              />
              {fieldErrors.name && (
                <p className="text-xs text-danger">{fieldErrors.name}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="inline-slug">
                {copy.slug} <span className="text-danger">*</span>{' '}
                <span className="text-xs font-normal text-text-muted">
                  (auto desde el nombre)
                </span>
              </Label>
              <Input
                id="inline-slug"
                value={slugTouched ? slug : effectiveSlug}
                onChange={(e) => {
                  setSlug(e.target.value);
                  setSlugTouched(true);
                }}
                placeholder="auto desde el nombre"
                aria-invalid={Boolean(fieldErrors.slug)}
              />
              {fieldErrors.slug && (
                <p className="text-xs text-danger">{fieldErrors.slug}</p>
              )}
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