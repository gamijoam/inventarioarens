/**
 * WarrantyPoliciesManager: CRUD de politicas de garantia.
 * Backend usa `warranty_policies.manage` para store/update/destroy.
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';
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
import { Select } from '@/components/ui/Select';
import {
  useWarrantyPolicies,
  useCreateWarrantyPolicy,
  useUpdateWarrantyPolicy,
  useDeleteWarrantyPolicy,
} from '@/features/inventory-center/api';
import {
  StoreWarrantyPolicySchema,
  WARRANTY_COVERAGE_LABELS,
  WARRANTY_COVERAGE_TYPES,
  type WarrantyPolicy,
  type WarrantyCoverageType,
} from '@/features/inventory-center/schemas';

type FormValues = z.input<typeof StoreWarrantyPolicySchema>;

export function WarrantyPoliciesManager() {
  const { data: policies = [], isLoading } = useWarrantyPolicies();
  const create = useCreateWarrantyPolicy();
  const update = useUpdateWarrantyPolicy();
  const remove = useDeleteWarrantyPolicy();
  const [editing, setEditing] = useState<WarrantyPolicy | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<WarrantyPolicy | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nueva politica
        </Button>
      </div>

      {policies.length === 0 ? (
        <EmptyState
          title="Sin politicas de garantia"
          description="Crea la primera politica (cobertura + duracion) para asociarla a productos."
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Nombre</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Cobertura</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Duracion</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {policies.map((p) => {
                const isActive = p.is_active ?? true;
                const coverage = (p.coverage_type ?? 'store') as WarrantyCoverageType;
                return (
                  <tr key={p.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2 font-medium">{p.name}</td>
                    <td className="px-3 py-2 text-text-muted">{WARRANTY_COVERAGE_LABELS[coverage] ?? p.coverage_type}</td>
                    <td className="px-3 py-2 tabular-nums">{p.duration_days ?? 0} d</td>
                    <td className="px-3 py-2">
                      <Badge variant={isActive ? 'success' : 'default'}>{isActive ? 'Activa' : 'Inactiva'}</Badge>
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Button size="icon-sm" variant="ghost" onClick={() => setEditing(p)} aria-label={`Editar ${p.name}`}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(p)} aria-label={`Eliminar ${p.name}`}>
                          <Trash2 className="size-4 text-danger" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <PolicyFormDialog
          policy={editing}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Politica actualizada.');
              } else {
                await create.mutateAsync(values);
                toast.success('Politica creada.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar politica.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setDeleting(null); }}
          title={`Eliminar politica "${deleting.name}"`}
          description="Los productos que referencien esta politica quedaran sin garantia."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Politica eliminada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function PolicyFormDialog({
  policy,
  onClose,
  onSubmit,
  loading,
}: {
  policy: WarrantyPolicy | null;
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<FormValues>({
    resolver: zodResolver(StoreWarrantyPolicySchema),
    defaultValues: {
      name: policy?.name ?? '',
      duration_days: policy?.duration_days ?? 30,
      coverage_type: (policy?.coverage_type as WarrantyCoverageType) ?? 'store',
      conditions: policy?.conditions ?? '',
      is_active: policy?.is_active ?? true,
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{policy ? 'Editar politica' : 'Nueva politica'}</DialogTitle>
          <DialogDescription>
            Define la cobertura, duracion y condiciones de la garantia.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Garantia estandar 30 dias" />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Duracion (dias)" required error={form.formState.errors.duration_days?.message}>
              <Input type="number" min={0} max={3650} {...form.register('duration_days', { valueAsNumber: true })} />
            </Field>
            <Field label="Cobertura" required error={form.formState.errors.coverage_type?.message}>
              <Select
                value={form.watch('coverage_type') ?? 'store'}
                onChange={(e) =>
                  form.setValue('coverage_type', e.target.value as WarrantyCoverageType, { shouldValidate: true })
                }
              >
                {WARRANTY_COVERAGE_TYPES.map((t) => (
                  <option key={t} value={t}>{WARRANTY_COVERAGE_LABELS[t]}</option>
                ))}
              </Select>
            </Field>
          </div>
          <Field label="Condiciones" error={form.formState.errors.conditions?.message}>
            <Textarea {...form.register('conditions')} rows={3} placeholder="Opcional. Texto libre con terminos y exclusiones." />
          </Field>
          <div className="flex items-center gap-2">
            <Switch
              id="policy-active"
              checked={Boolean(form.watch('is_active'))}
              onCheckedChange={(v) => form.setValue('is_active', v)}
            />
            <Label htmlFor="policy-active">Politica activa</Label>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>Cancelar</Button>
            <Button type="submit" loading={loading}>{policy ? 'Guardar' : 'Crear'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({ label, required, hint, error, children }: { label: string; required?: boolean; hint?: string; error?: string; children: React.ReactNode }) {
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
