/**
 * InlineWarrantyPolicyCreate: dialog mini para crear una politica de
 * garantia sin salir del ProductForm. Al confirmar, la lista se
 * revalida y la nueva politica se auto-selecciona via onCreated(id).
 */
import { useState } from 'react';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useCreateWarrantyPolicy } from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys } from '@/features/inventory-center/queries';
import { WARRANTY_COVERAGE_LABELS, WARRANTY_COVERAGE_TYPES, type WarrantyCoverageType } from '@/features/inventory-center/schemas';
import { ValidationError } from '@/types/api';

export function InlineWarrantyPolicyCreate({ onCreated }: { onCreated: (id: number) => void }) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [durationDays, setDurationDays] = useState('30');
  const [coverageType, setCoverageType] = useState<WarrantyCoverageType>('store');
  const [conditions, setConditions] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const qc = useQueryClient();
  const create = useCreateWarrantyPolicy();

  const reset = () => {
    setName('');
    setDurationDays('30');
    setCoverageType('store');
    setConditions('');
    setIsActive(true);
    setFieldErrors({});
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (!name.trim()) errors.name = 'El nombre es obligatorio.';
    const days = Number(durationDays);
    if (!Number.isFinite(days) || days < 0 || days > 3650) {
      errors.duration_days = 'Entre 0 y 3650 dias.';
    }
    if (conditions.length > 2000) {
      errors.conditions = 'Maximo 2000 caracteres.';
    }
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);
    try {
      const created = await create.mutateAsync({
        name: name.trim(),
        duration_days: days,
        coverage_type: coverageType,
        conditions: conditions.trim() || null,
        is_active: isActive,
      });
      void qc.invalidateQueries({ queryKey: catalogKeys.warrantyPolicies() });
      toast.success('Politica de garantia creada.');
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
        data-testid="inline-create-warranty-policy"
      >
        <Plus className="size-3.5" aria-hidden="true" />
        Nueva politica
      </Button>
      <Dialog open={open} onOpenChange={(o) => { if (!o) reset(); setOpen(o); }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Nueva politica de garantia</DialogTitle>
            <DialogDescription>
              Define la cobertura, duracion y condiciones. Se asocia al producto sin salir del form.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="iwp-name">Nombre <span className="text-danger">*</span></Label>
              <Input
                id="iwp-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Garantia estandar 30 dias"
                autoFocus
                aria-invalid={Boolean(fieldErrors.name)}
              />
              {fieldErrors.name && <p className="text-xs text-danger">{fieldErrors.name}</p>}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="iwp-days">Duracion (dias) <span className="text-danger">*</span></Label>
                <Input
                  id="iwp-days"
                  type="number"
                  min={0}
                  max={3650}
                  value={durationDays}
                  onChange={(e) => setDurationDays(e.target.value)}
                  aria-invalid={Boolean(fieldErrors.duration_days)}
                />
                {fieldErrors.duration_days && (
                  <p className="text-xs text-danger">{fieldErrors.duration_days}</p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="iwp-coverage">Cobertura <span className="text-danger">*</span></Label>
                <Select
                  id="iwp-coverage"
                  value={coverageType}
                  onChange={(e) => setCoverageType(e.target.value as WarrantyCoverageType)}
                >
                  {WARRANTY_COVERAGE_TYPES.map((t) => (
                    <option key={t} value={t}>
                      {WARRANTY_COVERAGE_LABELS[t]}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="iwp-conditions">Condiciones</Label>
              <Textarea
                id="iwp-conditions"
                value={conditions}
                onChange={(e) => setConditions(e.target.value)}
                rows={3}
                placeholder="Opcional. Texto libre con terminos y exclusiones."
              />
              {fieldErrors.conditions && <p className="text-xs text-danger">{fieldErrors.conditions}</p>}
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="iwp-active"
                checked={isActive}
                onCheckedChange={setIsActive}
              />
              <Label htmlFor="iwp-active">Activa</Label>
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
