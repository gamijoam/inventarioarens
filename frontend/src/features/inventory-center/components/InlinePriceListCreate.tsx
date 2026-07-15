/**
 * InlinePriceListCreate: dialog mini para crear una lista de precios
 * sin salir del PricesEditor / ProductForm. Al confirmar, la lista se
 * revalida y la nueva lista se auto-selecciona via onCreated(id).
 */
import { useState } from 'react';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
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
import { useCreatePriceList } from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys } from '@/features/inventory-center/queries';
import { ValidationError } from '@/types/api';

export function InlinePriceListCreate({ onCreated }: { onCreated: (id: number) => void }) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [description, setDescription] = useState('');
  const [isDefault, setIsDefault] = useState(false);
  const [isActive, setIsActive] = useState(true);
  const [sortOrder, setSortOrder] = useState('0');
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const qc = useQueryClient();
  const create = useCreatePriceList();

  const reset = () => {
    setName('');
    setCode('');
    setDescription('');
    setIsDefault(false);
    setIsActive(true);
    setSortOrder('0');
    setFieldErrors({});
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (!name.trim()) errors.name = 'El nombre es obligatorio.';
    if (!code.trim()) errors.code = 'El codigo es obligatorio.';
    const sort = Number(sortOrder);
    if (!Number.isFinite(sort) || sort < 0) errors.sort_order = 'Debe ser >= 0.';
    if (description.length > 1000) errors.description = 'Maximo 1000 caracteres.';
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);
    try {
      const created = await create.mutateAsync({
        name: name.trim(),
        code: code.trim().toUpperCase(),
        description: description.trim() || null,
        is_default: isDefault,
        is_active: isActive,
        sort_order: sort,
        payment_method_ids: [],
      });
      void qc.invalidateQueries({ queryKey: catalogKeys.priceLists() });
      toast.success('Lista de precios creada.');
      onCreated((created as { id: number }).id);
      setOpen(false);
      reset();
    } catch (err) {
      if (err instanceof ValidationError && err.fieldErrors) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.fieldErrors)) {
          if (v && v.length > 0) mapped[k] = v[0]!;
        }
        setFieldErrors(mapped);
      } else {
        toast.error(err instanceof Error ? err.message : 'Error al crear.');
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
        data-testid="inline-create-price-list"
      >
        <Plus className="size-3.5" aria-hidden="true" />
        Nueva lista
      </Button>
      <Dialog open={open} onOpenChange={(o) => { if (!o) reset(); setOpen(o); }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Nueva lista de precios</DialogTitle>
            <DialogDescription>
              Crea una lista para segmentar precios (detal, mayor, empleados, etc.) sin salir del formulario.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="ipl-name">Nombre <span className="text-danger">*</span></Label>
              <Input
                id="ipl-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Detal"
                autoFocus
                aria-invalid={Boolean(fieldErrors.name)}
              />
              {fieldErrors.name && <p className="text-xs text-danger">{fieldErrors.name}</p>}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="ipl-code">Codigo <span className="text-danger">*</span></Label>
                <Input
                  id="ipl-code"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="RETAIL"
                  maxLength={50}
                  aria-invalid={Boolean(fieldErrors.code)}
                />
                {fieldErrors.code && <p className="text-xs text-danger">{fieldErrors.code}</p>}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ipl-sort">Orden</Label>
                <Input
                  id="ipl-sort"
                  type="number"
                  min={0}
                  value={sortOrder}
                  onChange={(e) => setSortOrder(e.target.value)}
                />
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ipl-desc">Descripcion</Label>
              <Textarea
                id="ipl-desc"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={2}
                placeholder="Opcional."
              />
            </div>
            <div className="flex items-center gap-4">
              <div className="flex items-center gap-2">
                <Switch id="ipl-default" checked={isDefault} onCheckedChange={setIsDefault} />
                <Label htmlFor="ipl-default">Predeterminada</Label>
              </div>
              <div className="flex items-center gap-2">
                <Switch id="ipl-active" checked={isActive} onCheckedChange={setIsActive} />
                <Label htmlFor="ipl-active">Activa</Label>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
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
