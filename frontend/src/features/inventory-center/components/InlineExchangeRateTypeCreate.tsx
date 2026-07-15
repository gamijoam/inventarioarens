/**
 * InlineExchangeRateTypeCreate: dialog para crear un tipo de tasa (BCV,
 * Paralelo, etc) inline desde el ProductForm, sin necesidad de ir a
 * /inventory/currency.
 *
 * Mismo patron que InlineCatalogCreate pero para tipos de tasa (code
 * autogenerado, is_default + is_active switches).
 */
import { useState } from 'react';
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
import { useCreateExchangeRateType } from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys, productKeys } from '@/features/inventory-center/queries';
interface InlineExchangeRateTypeCreateProps {
  /** Callback con el id del nuevo tipo para auto-seleccion en el form padre. */
  onCreated: (id: number) => void;
}

export function InlineExchangeRateTypeCreate({ onCreated }: InlineExchangeRateTypeCreateProps) {
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [isDefault, setIsDefault] = useState(false);
  const [isActive, setIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const qc = useQueryClient();
  const createType = useCreateExchangeRateType();

  const reset = () => {
    setCode('');
    setName('');
    setIsDefault(false);
    setIsActive(true);
    setFieldErrors({});
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const errors: Record<string, string> = {};
    if (!code.trim()) errors.code = 'El codigo es obligatorio.';
    else if (!/^[A-Z0-9_-]+$/.test(code.trim().toUpperCase()))
      errors.code = 'Solo letras mayusculas, numeros y guiones.';
    if (!name.trim()) errors.name = 'El nombre es obligatorio.';
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);

    try {
      const result = await createType.mutateAsync({
        code: code.trim().toUpperCase(),
        name: name.trim(),
        is_default: isDefault,
        is_active: isActive,
      });
      void qc.invalidateQueries({ queryKey: catalogKeys.exchangeRateTypes() });
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      toast.success('Tipo de tasa creado.');
      const id = (result as { id?: number })?.id;
      if (id) onCreated(id);
      reset();
      setOpen(false);
    } catch (err) {
      const apiErr = err as { fieldErrors?: Record<string, string[]> };
      if (apiErr.fieldErrors) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(apiErr.fieldErrors)) {
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
        data-testid="inline-create-rate-type"
      >
        <Plus className="size-3.5" aria-hidden="true" />
        Nuevo tipo de tasa
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Nuevo tipo de tasa</DialogTitle>
            <DialogDescription>
              Crea el tipo sin salir del formulario. Si lo marcas como
              predeterminado, reemplazara al actual.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="inline-rate-code">
                Codigo <span className="text-danger">*</span>{' '}
                <span className="text-xs font-normal text-text-muted">(ej: BCV, PARALELO)</span>
              </Label>
              <Input
                id="inline-rate-code"
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase())}
                placeholder="BCV"
                autoFocus
                aria-invalid={Boolean(fieldErrors.code)}
              />
              {fieldErrors.code && <p className="text-xs text-danger">{fieldErrors.code}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="inline-rate-name">
                Nombre <span className="text-danger">*</span>
              </Label>
              <Input
                id="inline-rate-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Banco Central de Venezuela"
                aria-invalid={Boolean(fieldErrors.name)}
              />
              {fieldErrors.name && <p className="text-xs text-danger">{fieldErrors.name}</p>}
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="inline-rate-default"
                checked={isDefault}
                onCheckedChange={setIsDefault}
              />
              <Label htmlFor="inline-rate-default">Marcar como predeterminado</Label>
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="inline-rate-active"
                checked={isActive}
                onCheckedChange={setIsActive}
              />
              <Label htmlFor="inline-rate-active">Activo</Label>
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