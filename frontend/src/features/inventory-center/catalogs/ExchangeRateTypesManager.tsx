/**
 * ExchangeRateTypesManager: gestion CRUD de tipos de tasa de cambio
 * (BCV, Paralelo, etc.) para la pagina /inventory/currency.
 *
 * Solo UI; la logica de mutaciones viene de los hooks en api.ts.
 *
 * Mismo patron que BrandsManager: tabla con switches, dialog con form,
 * confirm dialog para eliminar, y mensajes en espanol.
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, Pencil, Trash2, TrendingUp } from 'lucide-react';
import { toast } from '@/lib/toast';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
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
import {
  useExchangeRateTypes,
  useCreateExchangeRateType,
  useUpdateExchangeRateType,
  useDeleteExchangeRateType,
} from '@/features/inventory-center/api';
import {
  StoreExchangeRateTypeSchema,
  type ExchangeRateType,
} from '@/features/inventory-center/schemas';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';

const formSchema = z.object({
  code: z
    .string()
    .max(50)
    .transform((s) => s.trim().toUpperCase())
    .refine(
      (s) => /^[A-Z0-9_-]+$/.test(s),
      'Solo letras mayusculas, numeros y guiones (sin espacios).',
    ),
  name: z
    .string()
    .max(255)
    .transform((s) => s.trim())
    .refine((s) => s.length > 0, 'El nombre es obligatorio.'),
  is_default: z.boolean().default(false),
  is_active: z.boolean().default(true),
});
type FormValues = z.infer<typeof formSchema>;

export function ExchangeRateTypesManager() {
  const { data: types = [], isLoading, isError } = useExchangeRateTypes();
  const createType = useCreateExchangeRateType();
  const updateType = useUpdateExchangeRateType();
  const deleteType = useDeleteExchangeRateType();

  const canManage = useCan(PERMISSIONS.CURRENCY_MANAGE);

  const [editing, setEditing] = useState<ExchangeRateType | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<ExchangeRateType | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { code: '', name: '', is_default: false, is_active: true },
    mode: 'onBlur',
  });

  const openCreate = () => {
    form.reset({ code: '', name: '', is_default: false, is_active: true });
    setEditing(null);
    setCreating(true);
  };

  const openEdit = (t: ExchangeRateType) => {
    form.reset({
      code: t.code,
      name: t.name,
      is_default: t.is_default,
      is_active: t.is_active,
    });
    setCreating(false);
    setEditing(t);
  };

  const closeDialog = () => {
    setCreating(false);
    setEditing(null);
    form.reset();
  };

  const onSubmit = form.handleSubmit(async (values) => {
    // Validar contra el schema del backend (defensivo: revalida en el cliente).
    const parsed = StoreExchangeRateTypeSchema.safeParse(values);
    if (!parsed.success) {
      const firstError = parsed.error.issues[0];
      toast.error(firstError?.message ?? 'Datos invalidos.');
      return;
    }

    try {
      if (editing) {
        await updateType.mutateAsync({ id: editing.id, ...parsed.data });
        toast.success('Tipo de tasa actualizado.');
      } else {
        await createType.mutateAsync(parsed.data);
        toast.success('Tipo de tasa creado.');
      }
      closeDialog();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar.');
    }
  });

  const onDelete = async () => {
    if (!deleting) return;
    try {
      await deleteType.mutateAsync(deleting.id);
      toast.success('Tipo de tasa eliminado.');
      setDeleting(null);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
    }
  };

  if (isLoading) return <Skeleton className="h-48" />;
  if (isError) {
    return (
      <EmptyState title="Error al cargar tipos de tasa" description="Reintenta en unos segundos." />
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-text-muted text-sm">
          Tipos de tasa disponibles para asignar a productos. Solo uno puede ser el predeterminado.
        </p>
        {canManage && (
          <Button onClick={openCreate} data-testid="new-rate-type">
            <Plus className="size-3.5" aria-hidden="true" />
            Nuevo tipo de tasa
          </Button>
        )}
      </div>

      {types.length === 0 ? (
        <EmptyState
          title="Sin tipos de tasa"
          description="Crea el primer tipo de tasa (ej: BCV, Paralelo) para poder asociar tasas a tus productos."
          icon={<TrendingUp className="size-8" aria-hidden="true" />}
        />
      ) : (
        <div className="border-border bg-surface rounded-lg border p-2">
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
                  Estado
                </th>
                <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">
                  Acciones
                </th>
              </tr>
            </thead>
            <tbody>
              {types.map((t) => (
                <tr key={t.id} className="border-border border-b last:border-b-0">
                  <td className="px-3 py-2 font-mono text-sm">{t.code}</td>
                  <td className="px-3 py-2">
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{t.name}</span>
                      {t.is_default && (
                        <Badge variant="info" className="text-xs">
                          Predeterminado
                        </Badge>
                      )}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    {t.is_active ? (
                      <Badge variant="success" className="text-xs">
                        Activo
                      </Badge>
                    ) : (
                      <Badge variant="default" className="text-xs">
                        Inactivo
                      </Badge>
                    )}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {canManage && (
                      <div className="flex items-center justify-end gap-0.5">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          onClick={() => openEdit(t)}
                          aria-label={`Editar ${t.name}`}
                          data-testid={`rate-type-edit-${t.id}`}
                        >
                          <Pencil className="size-4" aria-hidden="true" />
                        </Button>
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          onClick={() => setDeleting(t)}
                          aria-label={`Eliminar ${t.name}`}
                          data-testid={`rate-type-delete-${t.id}`}
                        >
                          <Trash2 className="size-4" aria-hidden="true" />
                        </Button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Dialog de crear/editar */}
      <Dialog open={creating || editing !== null} onOpenChange={(o) => !o && closeDialog()}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar tipo de tasa' : 'Nuevo tipo de tasa'}</DialogTitle>
            <DialogDescription>
              Define el codigo y nombre del tipo de tasa. Solo uno puede ser predeterminado.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={onSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="code">
                Codigo <span className="text-danger">*</span>{' '}
                <span className="text-text-muted text-xs font-normal">(ej: BCV, PARALELO)</span>
              </Label>
              <Input
                id="code"
                placeholder="BCV"
                {...form.register('code')}
                disabled={editing !== null}
                aria-invalid={Boolean(form.formState.errors.code)}
              />
              {form.formState.errors.code && (
                <p className="text-danger text-xs">{form.formState.errors.code.message}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="name">
                Nombre <span className="text-danger">*</span>
              </Label>
              <Input
                id="name"
                placeholder="Banco Central de Venezuela"
                {...form.register('name')}
                aria-invalid={Boolean(form.formState.errors.name)}
              />
              {form.formState.errors.name && (
                <p className="text-danger text-xs">{form.formState.errors.name.message}</p>
              )}
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="is_default"
                checked={form.watch('is_default')}
                onCheckedChange={(v) => form.setValue('is_default', v)}
              />
              <Label htmlFor="is_default">Marcar como predeterminado</Label>
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="is_active"
                checked={form.watch('is_active')}
                onCheckedChange={(v) => form.setValue('is_active', v)}
              />
              <Label htmlFor="is_active">Activo</Label>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={closeDialog}
                disabled={createType.isPending || updateType.isPending}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={createType.isPending || updateType.isPending}>
                {editing ? 'Guardar cambios' : 'Crear'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Dialog de confirmar eliminar */}
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Eliminar tipo de tasa"
        description={`Seguro que quieres eliminar "${deleting?.name}"? Las rates historicas asociadas quedaran huerfanas.`}
        confirmLabel="Eliminar"
        onConfirm={onDelete}
        loading={deleteType.isPending}
      />
    </div>
  );
}
