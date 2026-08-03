/**
 * PriceListsManager: CRUD de listas de precios. Usado por la pagina
 * /inventory/admin y como lookup por PricesEditor / ProductForm.
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
import {
  usePriceLists,
  useCreatePriceList,
  useUpdatePriceList,
  useDeletePriceList,
  useExchangeRateTypes,
} from '@/features/inventory-center/api';
import {
  StorePriceListSchema,
  type ExchangeRateType,
  type PriceList,
} from '@/features/inventory-center/schemas';
import { usePaymentMethods, type PaymentMethod } from '@/features/pos/api';

type FormValues = z.input<typeof StorePriceListSchema>;

export function PriceListsManager() {
  const { data: priceLists = [], isLoading } = usePriceLists(false);
  const { data: paymentMethods = [] } = usePaymentMethods();
  const { data: exchangeRateTypes = [] } = useExchangeRateTypes();
  const create = useCreatePriceList();
  const update = useUpdatePriceList();
  const remove = useDeletePriceList();
  const [editing, setEditing] = useState<PriceList | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<PriceList | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nueva lista
        </Button>
      </div>

      {priceLists.length === 0 ? (
        <EmptyState
          title="Sin listas de precios"
          description="Crea la primera lista (detal, mayor, empleados) para poder asignar precios por lista a los productos."
        />
      ) : (
        <div className="border-border bg-surface rounded-lg border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Nombre
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Codigo
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Incremento
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Orden
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Metodos POS
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Tasa cobro VES
                </th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">
                  Predet.
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
              {priceLists.map((l) => (
                <tr key={l.id} className="border-border border-b last:border-b-0">
                  <td className="px-3 py-2 font-medium">{l.name}</td>
                  <td className="text-text-muted px-3 py-2">
                    <code className="bg-bg rounded px-1.5 py-0.5 text-xs">{l.code}</code>
                  </td>
                  <td className="px-3 py-2 tabular-nums">
                    {l.markup_percentage == null ? 'Manual' : `+${l.markup_percentage}%`}
                  </td>
                  <td className="px-3 py-2 tabular-nums">{l.sort_order ?? 0}</td>
                  <td className="px-3 py-2">
                    <PaymentMethodBadges priceList={l} paymentMethods={paymentMethods} />
                  </td>
                  <td className="px-3 py-2">
                    {l.payment_exchange_rate_type ? (
                      <Badge
                        variant={
                          l.payment_exchange_rate_type.is_active === false ? 'warning' : 'info'
                        }
                      >
                        {l.payment_exchange_rate_type.name}
                      </Badge>
                    ) : (
                      <span className="text-text-muted text-xs">Predeterminada</span>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    {l.is_default ? (
                      <Badge variant="info">Si</Badge>
                    ) : (
                      <span className="text-text-muted">-</span>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant={l.is_active ? 'success' : 'default'}>
                      {l.is_active ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => setEditing(l)}
                        aria-label={`Editar ${l.name}`}
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => setDeleting(l)}
                        aria-label={`Eliminar ${l.name}`}
                      >
                        <Trash2 className="text-danger size-4" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <FormDialog
          priceList={editing}
          paymentMethods={paymentMethods}
          exchangeRateTypes={exchangeRateTypes}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Lista actualizada.');
              } else {
                await create.mutateAsync(values);
                toast.success('Lista creada.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar lista.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setDeleting(null);
          }}
          title={`Eliminar lista "${deleting.name}"`}
          description="Los precios asignados a esta lista quedaran huerfanos."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Lista eliminada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function FormDialog({
  priceList,
  paymentMethods,
  exchangeRateTypes,
  onClose,
  onSubmit,
  loading,
}: {
  priceList: PriceList | null;
  paymentMethods: PaymentMethod[];
  exchangeRateTypes: ExchangeRateType[];
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<FormValues>({
    resolver: zodResolver(StorePriceListSchema),
    defaultValues: {
      name: priceList?.name ?? '',
      code: priceList?.code ?? '',
      description: priceList?.description ?? '',
      markup_percentage: priceList?.markup_percentage ?? null,
      payment_exchange_rate_type_id: priceList?.payment_exchange_rate_type_id ?? null,
      is_default: priceList?.is_default ?? false,
      is_active: priceList?.is_active ?? true,
      sort_order: priceList?.sort_order ?? 0,
      payment_method_ids: priceList?.payment_method_ids ?? [],
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-hidden p-0">
        <DialogHeader className="border-border border-b px-5 py-4 pr-12">
          <DialogTitle>{priceList ? 'Editar lista' : 'Nueva lista'}</DialogTitle>
          <DialogDescription>
            Las listas permiten segmentar precios (detal, mayor, empleados).
          </DialogDescription>
        </DialogHeader>
        <form
          onSubmit={form.handleSubmit((values) => void onSubmit(values))}
          className="flex min-h-0 flex-col"
        >
          <div className="grid min-h-0 gap-0 overflow-y-auto md:grid-cols-2">
            <section className="space-y-3 px-5 py-4">
              <h3 className="text-sm font-semibold">Datos de la lista</h3>
              <Field label="Nombre" required error={form.formState.errors.name?.message}>
                <Input {...form.register('name')} placeholder="Detal" />
              </Field>
              <div className="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">
                <Field label="Codigo" required error={form.formState.errors.code?.message}>
                  <Input {...form.register('code')} placeholder="RETAIL" />
                </Field>
                <Field label="Orden" error={form.formState.errors.sort_order?.message}>
                  <Input
                    type="number"
                    min={0}
                    {...form.register('sort_order', { valueAsNumber: true })}
                  />
                </Field>
              </div>
              <Field label="Descripcion" error={form.formState.errors.description?.message}>
                <Textarea {...form.register('description')} rows={2} placeholder="Opcional." />
              </Field>
              <Field
                label="Incremento sobre precio base (%)"
                hint="Ejemplo: 45% convierte $100 en $145. Vacio permite precios manuales."
                error={form.formState.errors.markup_percentage?.message}
              >
                <Input
                  type="number"
                  min={0}
                  max={999.99}
                  step="0.01"
                  {...form.register('markup_percentage', {
                    setValueAs: (value) => (value === '' ? null : Number(value)),
                  })}
                  placeholder="Ej. 45"
                />
              </Field>
              <div className="flex flex-wrap items-center gap-x-5 gap-y-2 pt-1">
                <div className="flex items-center gap-2">
                  <Switch
                    id="pl-default"
                    checked={Boolean(form.watch('is_default'))}
                    onCheckedChange={(v) => form.setValue('is_default', v)}
                  />
                  <Label htmlFor="pl-default">Predeterminada</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    id="pl-active"
                    checked={Boolean(form.watch('is_active'))}
                    onCheckedChange={(v) => form.setValue('is_active', v)}
                  />
                  <Label htmlFor="pl-active">Activa</Label>
                </div>
              </div>
            </section>

            <section className="border-border space-y-3 border-t px-5 py-4 md:border-t-0 md:border-l">
              <h3 className="text-sm font-semibold">Reglas de cobro en POS</h3>
              <Field
                label="Tasa para pagos en bolivares"
                hint="Se aplica automaticamente al saldo que quede en USD."
                error={form.formState.errors.payment_exchange_rate_type_id?.message}
              >
                <select
                  className="border-border bg-surface h-10 w-full rounded border px-3 text-sm"
                  {...form.register('payment_exchange_rate_type_id', {
                    setValueAs: (value) => (value === '' ? null : Number(value)),
                  })}
                >
                  <option value="">Tasa predeterminada de la empresa</option>
                  {exchangeRateTypes.map((rateType) => (
                    <option key={rateType.id} value={rateType.id} disabled={!rateType.is_active}>
                      {rateType.name} ({rateType.code}){rateType.is_active ? '' : ' - Inactiva'}
                    </option>
                  ))}
                </select>
              </Field>
              <Field
                label="Metodos permitidos"
                hint="El POS mostrara unicamente los metodos seleccionados."
              >
                <div className="border-border bg-bg/40 max-h-64 space-y-1 overflow-auto rounded border p-2">
                  {paymentMethods.length === 0 ? (
                    <p className="text-text-muted p-2 text-xs">
                      No hay metodos de pago configurados.
                    </p>
                  ) : (
                    paymentMethods.map((method) => {
                      const selected =
                        form.watch('payment_method_ids')?.includes(method.id) ?? false;
                      return (
                        <label
                          key={method.id}
                          className="hover:bg-surface flex cursor-pointer items-center justify-between gap-3 rounded px-2 py-1.5 text-sm"
                        >
                          <span>
                            <span className="font-medium">{method.name}</span>
                            <span className="text-text-muted ml-2 text-xs">
                              {paymentMethodSummary(method)}
                            </span>
                          </span>
                          <input
                            type="checkbox"
                            checked={selected}
                            onChange={(event) => {
                              const current = form.getValues('payment_method_ids') ?? [];
                              form.setValue(
                                'payment_method_ids',
                                event.target.checked
                                  ? [...new Set([...current, method.id])]
                                  : current.filter((id) => id !== method.id),
                                { shouldDirty: true },
                              );
                            }}
                          />
                        </label>
                      );
                    })
                  )}
                </div>
              </Field>
            </section>
          </div>
          <DialogFooter className="border-border border-t px-5 py-3">
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>
              {priceList ? 'Guardar' : 'Crear'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PaymentMethodBadges({
  priceList,
  paymentMethods,
}: {
  priceList: PriceList;
  paymentMethods: PaymentMethod[];
}) {
  const ids = priceList.payment_method_ids ?? [];
  if (ids.length === 0) return <Badge variant="warning">Sin metodos</Badge>;

  const methods = priceList.payment_methods?.length
    ? priceList.payment_methods
    : paymentMethods.filter((method) => ids.includes(method.id));

  return (
    <div className="flex max-w-sm flex-wrap gap-1">
      {methods.slice(0, 3).map((method) => (
        <Badge key={method.id} variant={method.currency_mode === 'VES' ? 'info' : 'default'}>
          {method.name}
        </Badge>
      ))}
      {methods.length > 3 && <Badge variant="default">+{methods.length - 3}</Badge>}
    </div>
  );
}

function paymentMethodSummary(method: PaymentMethod): string {
  const currency =
    method.currency_mode === 'flexible' ? 'USD/VES' : (method.currency_mode ?? 'USD');
  return `${currency} - ${method.method ?? 'metodo'}`;
}

function Field({
  label,
  required,
  hint,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
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
      {hint && !error && <p className="text-text-muted text-xs">{hint}</p>}
      {error && <p className="text-danger text-xs">{error}</p>}
    </div>
  );
}
