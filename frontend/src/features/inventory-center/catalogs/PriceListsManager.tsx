/**
 * PriceListsManager: CRUD de listas de precios. Usado por la pagina
 * /inventory/admin y como lookup por PricesEditor / ProductForm.
 */
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';
import {
  Plus,
  Pencil,
  Trash2,
  Tag,
  Coins,
  TrendingUp,
  CreditCard,
  Sliders,
  Check,
  CheckCheck,
  Search,
  Sparkles,
  Info,
} from 'lucide-react';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/cn';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Badge } from '@/components/ui/Badge';
import { Checkbox } from '@/components/ui/Checkbox';
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
import {
  usePaymentMethods,
  useCurrentExchangeRatesForPos,
  type PaymentMethod,
  type CurrentExchangeRate,
} from '@/features/pos/api';

type FormValues = z.input<typeof StorePriceListSchema>;

export function PriceListsManager() {
  const { data: priceLists = [], isLoading } = usePriceLists(false);
  const { data: paymentMethods = [] } = usePaymentMethods();
  const { data: exchangeRateTypes = [] } = useExchangeRateTypes();
  const { data: currentRates = [] } = useCurrentExchangeRatesForPos();
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
                    {l.base_price_list ? (
                      <span>
                        Base: <span className="font-medium">{l.base_price_list.name}</span>
                        {l.markup_percentage != null && ` · +${l.markup_percentage}%`}
                      </span>
                    ) : l.markup_percentage == null ? (
                      'Manual'
                    ) : (
                      `+${l.markup_percentage}%`
                    )}
                  </td>
                  <td className="px-3 py-2 tabular-nums">{l.sort_order ?? 0}</td>
                  <td className="px-3 py-2">
                    <PaymentMethodBadges priceList={l} paymentMethods={paymentMethods} />
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex flex-col gap-0.5">
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
                      {l.payment_exchange_rate_markup_percentage != null && (
                        <span className="text-xs font-semibold text-primary">
                          {l.payment_exchange_rate_markup_percentage > 0
                            ? `+${l.payment_exchange_rate_markup_percentage}%`
                            : `${l.payment_exchange_rate_markup_percentage}%`}
                        </span>
                      )}
                    </div>
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
          priceLists={priceLists}
          paymentMethods={paymentMethods}
          exchangeRateTypes={exchangeRateTypes}
          currentRates={currentRates}
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
  priceLists,
  paymentMethods,
  exchangeRateTypes,
  currentRates,
  onClose,
  onSubmit,
  loading,
}: {
  priceList: PriceList | null;
  priceLists: PriceList[];
  paymentMethods: PaymentMethod[];
  exchangeRateTypes: ExchangeRateType[];
  currentRates: CurrentExchangeRate[];
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
  loading: boolean;
}) {
  const [searchMethod, setSearchMethod] = useState('');

  const form = useForm<FormValues>({
    resolver: zodResolver(StorePriceListSchema),
    defaultValues: {
      name: priceList?.name ?? '',
      code: priceList?.code ?? '',
      description: priceList?.description ?? '',
      markup_percentage: priceList?.markup_percentage ?? null,
      base_price_list_id: priceList?.base_price_list_id ?? null,
      payment_exchange_rate_type_id: priceList?.payment_exchange_rate_type_id ?? null,
      payment_exchange_rate_markup_percentage:
        priceList?.payment_exchange_rate_markup_percentage ?? null,
      is_default: priceList?.is_default ?? false,
      is_active: priceList?.is_active ?? true,
      sort_order: priceList?.sort_order ?? 0,
      payment_method_ids: priceList?.payment_method_ids ?? [],
    },
  });

  const selectedTypeId = form.watch('payment_exchange_rate_type_id');
  const markupPercentage = form.watch('payment_exchange_rate_markup_percentage');
  const selectedBaseListId = form.watch('base_price_list_id');
  const selectedMethodIds = form.watch('payment_method_ids') ?? [];

  const selectedRate = useMemo(() => {
    if (selectedTypeId) {
      return currentRates.find((r) => r.exchange_rate_type_id === selectedTypeId) ?? null;
    }
    const defType = exchangeRateTypes.find((t) => t.is_default && t.is_active !== false);
    if (defType) {
      return currentRates.find((r) => r.exchange_rate_type_id === defType.id) ?? null;
    }
    return currentRates[0] ?? null;
  }, [selectedTypeId, currentRates, exchangeRateTypes]);

  const baseRateVal = selectedRate ? Number(selectedRate.rate) : 0;
  const rateTypeName =
    exchangeRateTypes.find((t) => t.id === selectedTypeId)?.name ??
    selectedRate?.exchange_rate_type_name ??
    (selectedTypeId ? 'Tasa personalizada' : 'Tasa predeterminada');

  const baseOptions = priceLists.filter((l) => l.id !== priceList?.id && l.is_active);

  const filteredPaymentMethods = useMemo(() => {
    if (!searchMethod.trim()) return paymentMethods;
    const q = searchMethod.toLowerCase();
    return paymentMethods.filter(
      (m) =>
        m.name.toLowerCase().includes(q) ||
        (m.method && m.method.toLowerCase().includes(q)) ||
        (m.currency_mode && m.currency_mode.toLowerCase().includes(q)),
    );
  }, [paymentMethods, searchMethod]);

  const effectiveRate = useMemo(() => {
    if (baseRateVal <= 0) return 0;
    if (markupPercentage == null || isNaN(Number(markupPercentage))) return baseRateVal;
    return Math.round(baseRateVal * (1 + Number(markupPercentage) / 100) * 10000) / 10000;
  }, [baseRateVal, markupPercentage]);

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-5xl xl:max-w-6xl w-[96vw] max-h-[92vh] overflow-hidden p-0 flex flex-col rounded-2xl shadow-2xl border border-border bg-surface">
        {/* Cabecera ERP */}
        <DialogHeader className="border-border/80 border-b bg-surface-subtle/50 px-6 py-4 pr-14 shrink-0">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="rounded-xl border border-primary/20 bg-primary/10 p-2.5 text-primary shadow-sm">
                <Tag className="size-5" />
              </div>
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <DialogTitle className="text-lg font-bold text-text-primary">
                    {priceList ? `Editar Lista: ${priceList.name}` : 'Nueva Lista de Precios'}
                  </DialogTitle>
                  {priceList && (
                    <Badge variant="outline" className="font-mono text-xs font-semibold">
                      {priceList.code}
                    </Badge>
                  )}
                  {priceList && (
                    <Badge variant={priceList.is_active ? 'success' : 'default'}>
                      {priceList.is_active ? 'Activa' : 'Inactiva'}
                    </Badge>
                  )}
                  {priceList?.is_default && (
                    <Badge variant="info">Predeterminada</Badge>
                  )}
                </div>
                <DialogDescription className="text-text-muted mt-0.5 text-xs">
                  Administración comercial: estructuras tarifarias, márgenes de ganancia, conversión en VES y reglas POS.
                </DialogDescription>
              </div>
            </div>
          </div>
        </DialogHeader>

        {/* Cuerpo del Formulario */}
        <form
          onSubmit={form.handleSubmit((values) => void onSubmit(values))}
          className="flex min-h-0 flex-1 flex-col"
        >
          <div className="flex-1 overflow-y-auto px-6 py-5">
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
              {/* Columna Izquierda (5 cols): Datos Generales y Estrategia de Precios */}
              <div className="lg:col-span-5 space-y-5">
                {/* Panel 1: Identificación y Estado */}
                <div className="rounded-xl border border-border/80 bg-surface p-4 shadow-sm space-y-4">
                  <div className="flex items-center gap-2 border-b border-border/60 pb-2.5">
                    <div className="rounded-lg bg-primary/10 p-1.5 text-primary">
                      <Sliders className="size-4" />
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-text-primary">Identificación de la Lista</h3>
                      <p className="text-[11px] text-text-muted">Nombre comercial, código y prioridad</p>
                    </div>
                  </div>

                  <div className="space-y-3.5">
                    <Field label="Nombre Comercial" required error={form.formState.errors.name?.message}>
                      <Input {...form.register('name')} placeholder="Ej. Detal, Mayorista, Cashea" />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                      <Field label="Código Interno" required error={form.formState.errors.code?.message}>
                        <Input
                          {...form.register('code')}
                          placeholder="RETAIL"
                          className="uppercase font-mono tracking-wider font-semibold"
                        />
                      </Field>
                      <Field label="Orden / Prioridad" error={form.formState.errors.sort_order?.message}>
                        <Input
                          type="number"
                          min={0}
                          {...form.register('sort_order', { valueAsNumber: true })}
                          placeholder="0"
                        />
                      </Field>
                    </div>

                    <Field label="Descripción Comercial" error={form.formState.errors.description?.message}>
                      <Textarea
                        {...form.register('description')}
                        rows={2}
                        placeholder="Opcional. Notas o condiciones especiales de esta lista..."
                      />
                    </Field>

                    {/* Tarjeta de Estados */}
                    <div className="rounded-lg border border-border/60 bg-surface-subtle/40 p-3 space-y-3">
                      <label
                        htmlFor="pl-default"
                        className="flex cursor-pointer items-start justify-between gap-3"
                      >
                        <div className="space-y-0.5">
                          <span className="text-xs font-semibold text-text-primary block">
                            Lista Predeterminada
                          </span>
                          <span className="text-[11px] text-text-muted block">
                            Asignada automáticamente a nuevos clientes y ventas generales.
                          </span>
                        </div>
                        <Switch
                          id="pl-default"
                          checked={Boolean(form.watch('is_default'))}
                          onCheckedChange={(v) => form.setValue('is_default', v, { shouldDirty: true })}
                        />
                      </label>

                      <div className="border-t border-border/50" />

                      <label
                        htmlFor="pl-active"
                        className="flex cursor-pointer items-start justify-between gap-3"
                      >
                        <div className="space-y-0.5">
                          <span className="text-xs font-semibold text-text-primary block">
                            Lista Activa
                          </span>
                          <span className="text-[11px] text-text-muted block">
                            Habilitada para facturación en POS y consultas comerciales.
                          </span>
                        </div>
                        <Switch
                          id="pl-active"
                          checked={Boolean(form.watch('is_active'))}
                          onCheckedChange={(v) => form.setValue('is_active', v, { shouldDirty: true })}
                        />
                      </label>
                    </div>
                  </div>
                </div>

                {/* Panel 2: Estrategia de Precios Base & Margen */}
                <div className="rounded-xl border border-border/80 bg-surface p-4 shadow-sm space-y-4">
                  <div className="flex items-center gap-2 border-b border-border/60 pb-2.5">
                    <div className="rounded-lg bg-emerald-500/10 p-1.5 text-emerald-600 dark:text-emerald-400">
                      <TrendingUp className="size-4" />
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-text-primary">Estrategia de Precios Base</h3>
                      <p className="text-[11px] text-text-muted">Cálculo porcentual sobre otra lista base o precios independientes</p>
                    </div>
                  </div>

                  <div className="space-y-3.5">
                    <Field
                      label="Lista de Precio Base"
                      hint="Toma los precios de otra lista como origen para calcular esta."
                      error={form.formState.errors.base_price_list_id?.message}
                    >
                      <select
                        className="border-border bg-surface h-10 w-full rounded-lg border px-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition"
                        data-testid="price-list-base"
                        value={form.watch('base_price_list_id') ?? ''}
                        onChange={(e) =>
                          form.setValue(
                            'base_price_list_id',
                            e.target.value ? Number(e.target.value) : null,
                            { shouldDirty: true },
                          )
                        }
                      >
                        <option value="">Precio base del producto (Catálogo individual)</option>
                        {baseOptions.map((l) => (
                          <option key={l.id} value={l.id}>
                            {l.name} ({l.code})
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field
                      label="Incremento sobre Precio Base (%)"
                      hint="Ejemplo: +16% para recargo IVA o financiamiento, 0% para igualar base."
                      error={form.formState.errors.markup_percentage?.message}
                    >
                      <div className="relative flex items-center">
                        <Input
                          type="number"
                          min={0}
                          max={999.99}
                          step="0.01"
                          {...form.register('markup_percentage', {
                            setValueAs: (value) => (value === '' ? null : Number(value)),
                          })}
                          placeholder="0.00"
                          className="text-right pr-7 font-mono font-medium"
                        />
                        <span className="absolute right-3 text-xs text-text-muted font-bold pointer-events-none select-none">
                          %
                        </span>
                      </div>
                    </Field>

                    {/* Explicación ERP interactiva */}
                    <div
                      className={cn(
                        'rounded-lg p-3 text-xs transition-colors',
                        selectedBaseListId
                          ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-800 dark:text-emerald-300'
                          : 'bg-surface-subtle/50 border border-border/60 text-text-muted',
                      )}
                    >
                      {selectedBaseListId ? (
                        <div className="space-y-1">
                          <div className="flex items-center gap-1.5 font-semibold">
                            <CheckCheck className="size-3.5 shrink-0" />
                            <span>Fórmula de cálculo automático activada:</span>
                          </div>
                          <p className="text-[11px] leading-relaxed">
                            Precio = Precio en{' '}
                            <strong>
                              {baseOptions.find((b) => b.id === Number(selectedBaseListId))?.name ??
                                'Lista Base'}
                            </strong>
                            {form.watch('markup_percentage') != null &&
                            Number(form.watch('markup_percentage')) !== 0
                              ? ` + ${form.watch('markup_percentage')}% de incremento.`
                              : ' (mismo precio de la base).'}
                          </p>
                        </div>
                      ) : (
                        <div className="flex items-start gap-1.5">
                          <Info className="size-3.5 shrink-0 mt-0.5" />
                          <span>
                            Sin lista base vinculada: Los precios de los productos se asignarán de
                            forma manual e independiente en el editor de catálogo.
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>

              {/* Columna Derecha (7 cols): Tasa de Cobro POS & Métodos de Pago */}
              <div className="lg:col-span-7 space-y-5">
                {/* Panel 3: Reglas de Cobro en POS y Recálculo en Bolívares */}
                <div className="rounded-xl border border-border/80 bg-surface p-4 shadow-sm space-y-4">
                  <div className="flex items-center gap-2 border-b border-border/60 pb-2.5">
                    <div className="rounded-lg bg-amber-500/10 p-1.5 text-amber-600 dark:text-amber-400">
                      <Coins className="size-4" />
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-text-primary">Tasa de Cobro para POS (Bolívares)</h3>
                      <p className="text-[11px] text-text-muted">Ajuste comercial de conversión de divisas para pagos en VES</p>
                    </div>
                  </div>

                  <div className="space-y-3.5">
                    <Field
                      label="Tipo de Tasa para Pagos en Bolívares"
                      hint="Selecciona la tasa de referencia que rige los cobros en moneda nacional."
                      error={form.formState.errors.payment_exchange_rate_type_id?.message}
                    >
                      <select
                        className="border-border bg-surface h-10 w-full rounded-lg border px-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none transition"
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

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <Field
                        label="% Ajuste / Recargo a la Tasa"
                        hint="Margen porcentual sobre la tasa base."
                        error={form.formState.errors.payment_exchange_rate_markup_percentage?.message}
                      >
                        <div className="relative flex items-center">
                          <Input
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={form.watch('payment_exchange_rate_markup_percentage') ?? ''}
                            onChange={(e) => {
                              const v = e.target.value;
                              form.setValue(
                                'payment_exchange_rate_markup_percentage',
                                v === '' ? null : Number(v),
                                { shouldDirty: true },
                              );
                            }}
                            className="text-right pr-7 font-mono font-medium"
                          />
                          <span className="absolute right-3 text-xs text-text-muted font-bold pointer-events-none select-none">
                            %
                          </span>
                        </div>
                      </Field>

                      <Field
                        label="Tasa Recalculada Efectiva"
                        hint={
                          baseRateVal > 0
                            ? `Tasa base oficial: ${baseRateVal.toFixed(4)} Bs`
                            : 'Sin tasa base disponible'
                        }
                      >
                        <div className="relative flex items-center">
                          <Input
                            type="number"
                            step="0.0001"
                            placeholder="0.0000"
                            value={
                              baseRateVal > 0
                                ? (markupPercentage != null
                                    ? (
                                        Math.round(
                                          baseRateVal *
                                            (1 + Number(markupPercentage) / 100) *
                                            10000,
                                        ) / 10000
                                      ).toFixed(4)
                                    : baseRateVal.toFixed(4))
                                : ''
                            }
                            onChange={(e) => {
                              const target = parseFloat(e.target.value);
                              if (!isNaN(target) && baseRateVal > 0) {
                                const p =
                                  Math.round((((target - baseRateVal) / baseRateVal) * 100) * 100) /
                                  100;
                                form.setValue('payment_exchange_rate_markup_percentage', p, {
                                  shouldDirty: true,
                                });
                              } else if (e.target.value === '') {
                                form.setValue('payment_exchange_rate_markup_percentage', null, {
                                  shouldDirty: true,
                                });
                              }
                            }}
                            className="text-right font-mono font-bold text-amber-600 dark:text-amber-400 bg-amber-500/5 border-amber-500/30 focus:border-amber-500 pr-16"
                          />
                          <span className="absolute right-3 text-xs font-semibold text-amber-600/80 dark:text-amber-400/80 pointer-events-none select-none">
                            Bs/USD
                          </span>
                        </div>
                      </Field>
                    </div>

                    {/* Tarjeta Visual de Conversión en Tiempo Real */}
                    <div className="rounded-xl bg-gradient-to-br from-amber-500/10 via-amber-500/5 to-transparent border border-amber-500/25 p-3.5 space-y-2.5">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge
                            variant="outline"
                            className="border-amber-500/40 text-amber-700 dark:text-amber-300 font-mono text-[11px]"
                          >
                            {rateTypeName}
                          </Badge>
                          <span className="text-xs text-text-secondary">
                            Oficial:{' '}
                            <strong className="font-mono text-text-primary">
                              {baseRateVal > 0 ? baseRateVal.toFixed(4) : '0.0000'} Bs
                            </strong>
                          </span>
                          {markupPercentage != null && Number(markupPercentage) !== 0 && (
                            <Badge variant="warning" className="font-mono text-[11px]">
                              {Number(markupPercentage) > 0
                                ? `+${markupPercentage}%`
                                : `${markupPercentage}%`}
                            </Badge>
                          )}
                        </div>
                        <div className="text-right">
                          <span className="text-[11px] text-text-muted block">
                            Tasa Final en Cobro POS:
                          </span>
                          <span className="text-base sm:text-lg font-black font-mono text-amber-600 dark:text-amber-400">
                            {effectiveRate > 0 ? effectiveRate.toFixed(4) : '0.0000'}{' '}
                            <span className="text-xs font-semibold text-text-secondary">
                              Bs/USD
                            </span>
                          </span>
                        </div>
                      </div>
                      {baseRateVal > 0 && (
                        <div className="border-t border-amber-500/20 pt-2 flex items-center justify-between text-xs text-text-secondary">
                          <span>💡 Ejemplo de cobro ($10.00 USD en caja):</span>
                          <span className="font-mono font-bold text-text-primary">
                            {(10 * effectiveRate).toFixed(2)} Bs
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>

                {/* Panel 4: Métodos de Pago Habilitados en POS */}
                <div className="rounded-xl border border-border/80 bg-surface p-4 shadow-sm space-y-4">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-2.5">
                    <div className="flex items-center gap-2">
                      <div className="rounded-lg bg-blue-500/10 p-1.5 text-blue-600 dark:text-blue-400">
                        <CreditCard className="size-4" />
                      </div>
                      <div>
                        <h3 className="text-sm font-bold text-text-primary">
                          Métodos de Pago Autorizados en POS
                        </h3>
                        <p className="text-[11px] text-text-muted">
                          El cajero solo podrá registrar cobros con los métodos activos
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <Badge variant="primary" className="text-xs font-medium">
                        {selectedMethodIds.length} / {paymentMethods.length} seleccionados
                      </Badge>
                    </div>
                  </div>

                  <div className="space-y-3">
                    {/* Barra de Acciones Rápidas */}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            form.setValue(
                              'payment_method_ids',
                              paymentMethods.map((m) => m.id),
                              { shouldDirty: true },
                            );
                          }}
                          className="text-xs text-primary hover:underline font-medium flex items-center gap-1"
                        >
                          <CheckCheck className="size-3" />
                          Marcar todos
                        </button>
                        <span className="text-border">|</span>
                        <button
                          type="button"
                          onClick={() => {
                            form.setValue('payment_method_ids', [], { shouldDirty: true });
                          }}
                          className="text-xs text-text-muted hover:text-text-primary hover:underline font-medium"
                        >
                          Desmarcar todos
                        </button>
                      </div>

                      {paymentMethods.length > 4 && (
                        <div className="relative w-44">
                          <Search className="absolute left-2.5 top-2.5 size-3 text-text-muted pointer-events-none" />
                          <input
                            type="text"
                            placeholder="Buscar método..."
                            value={searchMethod}
                            onChange={(e) => setSearchMethod(e.target.value)}
                            className="w-full h-8 pl-8 pr-2.5 text-xs rounded-lg border border-border bg-surface-subtle/50 focus:border-primary focus:bg-surface outline-none transition"
                          />
                        </div>
                      )}
                    </div>

                    {/* Grid Adaptativo de Métodos de Pago */}
                    <div className="border border-border/70 rounded-xl bg-surface-subtle/30 p-2.5 max-h-56 overflow-y-auto">
                      {filteredPaymentMethods.length === 0 ? (
                        <p className="text-text-muted p-4 text-center text-xs">
                          {searchMethod
                            ? 'No hay métodos que coincidan con la búsqueda.'
                            : 'No hay métodos de pago configurados.'}
                        </p>
                      ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                          {filteredPaymentMethods.map((method) => {
                            const isChecked = selectedMethodIds.includes(method.id);
                            return (
                              <label
                                key={method.id}
                                className={cn(
                                  'flex items-center gap-2.5 rounded-lg border p-2.5 text-xs cursor-pointer transition select-none',
                                  isChecked
                                    ? 'border-primary/50 bg-primary/5 shadow-xs text-text-primary'
                                    : 'border-border/60 bg-surface hover:border-border hover:bg-surface-subtle/50 text-text-secondary',
                                )}
                              >
                                <Checkbox
                                  checked={isChecked}
                                  onCheckedChange={(checked) => {
                                    const current = form.getValues('payment_method_ids') ?? [];
                                    form.setValue(
                                      'payment_method_ids',
                                      checked
                                        ? [...new Set([...current, method.id])]
                                        : current.filter((id) => id !== method.id),
                                      { shouldDirty: true },
                                    );
                                  }}
                                />
                                <div className="min-w-0 flex-1">
                                  <div className="flex items-center justify-between gap-1">
                                    <span className="font-semibold truncate text-text-primary">
                                      {method.name}
                                    </span>
                                    <Badge
                                      variant={
                                        method.currency_mode === 'VES'
                                          ? 'info'
                                          : method.currency_mode === 'flexible'
                                            ? 'warning'
                                            : 'success'
                                      }
                                      className="text-[10px] px-1.5 py-0 uppercase shrink-0 font-mono"
                                    >
                                      {method.currency_mode === 'flexible'
                                        ? 'USD/VES'
                                        : (method.currency_mode ?? 'USD')}
                                    </Badge>
                                  </div>
                                  <span className="text-[11px] text-text-muted truncate block">
                                    {paymentMethodSummary(method)}
                                  </span>
                                </div>
                              </label>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Pie del Modal ERP */}
          <DialogFooter className="border-border/80 border-t bg-surface-subtle/40 px-6 py-3.5 sm:justify-between items-center shrink-0">
            <div className="hidden sm:flex items-center gap-1.5 text-xs text-text-muted">
              <Sparkles className="size-3.5 text-primary shrink-0" />
              <span>Los cambios en tasas y métodos se aplican inmediatamente a los terminales POS.</span>
            </div>
            <div className="flex items-center gap-2.5 w-full sm:w-auto justify-end">
              <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
                Cancelar
              </Button>
              <Button type="submit" loading={loading} className="gap-2">
                <Check className="size-4" />
                {priceList ? 'Guardar Cambios' : 'Crear Lista'}
              </Button>
            </div>
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
