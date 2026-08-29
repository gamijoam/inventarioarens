import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Pencil, Plus } from 'lucide-react';
import { toast } from 'sonner';
import type { z } from 'zod';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import {
  FISCAL_TAX_CATEGORIES,
  FISCAL_TAX_CATEGORY_LABELS,
  FiscalTaxRateInputSchema,
  type FiscalTaxCategory,
  type FiscalTaxRate,
  useCreateFiscalTaxRate,
  useFiscalTaxRates,
  useUpdateFiscalTaxRate,
} from './taxRates';

type FormValues = z.infer<typeof FiscalTaxRateInputSchema>;

export function FiscalTaxRatesManager() {
  const { data: rates = [], isLoading } = useFiscalTaxRates();
  const create = useCreateFiscalTaxRate();
  const update = useUpdateFiscalTaxRate();
  const [editing, setEditing] = useState<FiscalTaxRate | null>(null);
  const [creating, setCreating] = useState(false);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nueva alícuota
        </Button>
      </div>

      {rates.length === 0 ? (
        <EmptyState
          title="Sin tratamientos fiscales"
          description="Crea IVA general y las categorías exento, exonerado o no gravado para asignarlas a tus productos."
        />
      ) : (
        <div className="border-border bg-surface rounded-lg border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Código
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Nombre
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Categoría
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Tasa
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Estado
                </th>
                <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                  Acciones
                </th>
              </tr>
            </thead>
            <tbody>
              {rates.map((rate) => (
                <tr key={rate.id} className="border-border border-b last:border-b-0">
                  <td className="px-3 py-2 font-mono text-sm">{rate.code}</td>
                  <td className="px-3 py-2 font-medium">{rate.name}</td>
                  <td className="text-text-muted px-3 py-2">
                    {FISCAL_TAX_CATEGORY_LABELS[rate.category]}
                  </td>
                  <td className="px-3 py-2 tabular-nums">{rate.rate}%</td>
                  <td className="px-3 py-2">
                    <Badge variant={rate.is_active ? 'success' : 'default'}>
                      {rate.is_active ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={`Editar ${rate.name}`}
                      onClick={() => setEditing(rate)}
                    >
                      <Pencil className="size-4" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <FiscalTaxRateFormDialog
          rate={editing}
          loading={create.isPending || update.isPending}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Tratamiento fiscal actualizado.');
              } else {
                await create.mutateAsync(values);
                toast.success('Tratamiento fiscal creado.');
              }
              setCreating(false);
              setEditing(null);
            } catch (error) {
              toast.error(
                error instanceof Error
                  ? error.message
                  : 'No se pudo guardar el tratamiento fiscal.',
              );
            }
          }}
        />
      )}
    </>
  );
}

function FiscalTaxRateFormDialog({
  rate,
  loading,
  onClose,
  onSubmit,
}: {
  rate: FiscalTaxRate | null;
  loading: boolean;
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
}) {
  const form = useForm<FormValues>({
    resolver: zodResolver(FiscalTaxRateInputSchema),
    defaultValues: {
      code: rate?.code ?? '',
      name: rate?.name ?? '',
      rate: rate ? Number(rate.rate) : 16,
      category: rate?.category ?? 'taxable',
      is_active: rate?.is_active ?? true,
    },
  });

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {rate ? 'Editar tratamiento fiscal' : 'Nuevo tratamiento fiscal'}
          </DialogTitle>
          <DialogDescription>
            Las categorías exento, exonerado y no gravado siempre usan tasa 0%.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <Field label="Código" required error={form.formState.errors.code?.message}>
            <Input {...form.register('code')} placeholder="IVA16" maxLength={50} />
          </Field>
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="IVA general" maxLength={120} />
          </Field>
          <Field label="Categoría" required error={form.formState.errors.category?.message}>
            <Select
              value={form.watch('category')}
              onChange={(event) => {
                const category = event.target.value as FiscalTaxCategory;
                form.setValue('category', category, { shouldValidate: true });
                if (category !== 'taxable') form.setValue('rate', 0, { shouldValidate: true });
              }}
            >
              {FISCAL_TAX_CATEGORIES.map((category) => (
                <option key={category} value={category}>
                  {FISCAL_TAX_CATEGORY_LABELS[category]}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Tasa (%)" required error={form.formState.errors.rate?.message}>
            <Input
              type="number"
              min={0}
              max={100}
              step="0.01"
              disabled={form.watch('category') !== 'taxable'}
              {...form.register('rate', { valueAsNumber: true })}
            />
          </Field>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" {...form.register('is_active')} />
            Tratamiento activo y disponible para asignar
          </label>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {rate ? 'Guardar' : 'Crear'}
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
  error,
  children,
}: {
  label: string;
  required?: boolean;
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
      {error && <p className="text-danger text-xs">{error}</p>}
    </div>
  );
}
