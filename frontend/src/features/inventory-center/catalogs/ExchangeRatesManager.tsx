/**
 * ExchangeRatesManager: gestion CRUD de rates historicas de cambio
 * (BCV 2026-07-14 = 36.50, Paralelo 2026-07-13 = 37.20, etc.) para
 * la pagina /inventory/currency.
 *
 * Solo UI; la logica de mutaciones viene de los hooks en api.ts.
 *
 * Features:
 * - Tabla con rates ordenados por fecha (mas reciente primero).
 * - Filtro por tipo de tasa (BCV, Paralelo, etc).
 * - Filtro por rango de fechas (from/to).
 * - Form para crear/activar/desactivar rates.
 * - Activar un rate desactiva automaticamente el rate activo del mismo
 *   tipo+divisa (lo hace el backend).
 * - Mensajes en espanol.
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, TrendingUp, TrendingDown, ChevronUp, ChevronDown } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
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
import { Label } from '@/components/ui/Label';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { formatMoney } from '@/lib/money';
import {
  useExchangeRateTypes,
  useExchangeRates,
  useCreateExchangeRate,
  useActivateExchangeRate,
  useDeactivateExchangeRate,
} from '@/features/inventory-center/api';
import {
  StoreExchangeRateSchema,
  EXCHANGE_RATE_CURRENCIES,
  type ExchangeRateType,
} from '@/features/inventory-center/schemas';

const formSchema = z.object({
  exchange_rate_type_id: z.coerce.number().int().positive('Selecciona un tipo de tasa.'),
  base_currency: z.string().default('USD'),
  quote_currency: z.string().default('VES'),
  rate: z.coerce.number().positive('La tasa debe ser mayor a 0.'),
  effective_at: z
    .string()
    .min(1, 'La fecha efectiva es obligatoria.'),
  source: z.string().optional(),
  is_active: z.boolean().default(true),
});
type FormValues = z.infer<typeof formSchema>;

export interface ExchangeRatesManagerProps {
  /** ID de tipo de tasa preseleccionado (ej: desde el form de producto). */
  initialTypeId?: number;
}

export function ExchangeRatesManager({ initialTypeId }: ExchangeRatesManagerProps = {}) {
  const { data: types = [] } = useExchangeRateTypes();
  const canManage = true; // Gate visual via <Can>. Sin useCan.

  const [typeFilter, setTypeFilter] = useState<string>(initialTypeId ? String(initialTypeId) : '');
  const [fromDate, setFromDate] = useState<string>('');
  const [toDate, setToDate] = useState<string>('');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [showForm, setShowForm] = useState(false);

  const { data: rates = [], isLoading, isError } = useExchangeRates({
    rate_type_id: typeFilter ? Number(typeFilter) : undefined,
    from: fromDate || undefined,
    to: toDate || undefined,
  });

  const createRate = useCreateExchangeRate();
  const activateRate = useActivateExchangeRate();
  const deactivateRate = useDeactivateExchangeRate();

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      exchange_rate_type_id: initialTypeId ?? (types[0]?.id ?? 0),
      base_currency: 'USD',
      quote_currency: 'VES',
      rate: 0,
      effective_at: new Date().toISOString().slice(0, 10),
      source: 'manual',
      is_active: true,
    },
    mode: 'onBlur',
  });

  const openForm = () => {
    form.reset({
      exchange_rate_type_id: (() => {
        const n = Number(typeFilter);
        // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
        return Number.isFinite(n) && n > 0 ? n : (initialTypeId || types[0]?.id || 0);
      })(),
      base_currency: 'USD',
      quote_currency: 'VES',
      rate: 0,
      effective_at: new Date().toISOString().slice(0, 10),
      source: 'manual',
      is_active: true,
    });
    setShowForm(true);
  };

  const onSubmit = form.handleSubmit(async (values) => {
    const parsed = StoreExchangeRateSchema.safeParse(values);
    if (!parsed.success) {
      const firstError = parsed.error.issues[0];
      toast.error(firstError?.message ?? 'Datos invalidos.');
      return;
    }
    try {
      await createRate.mutateAsync(parsed.data);
      toast.success('Tasa creada correctamente.');
      setShowForm(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar.');
    }
  });

  const onActivate = async (id: number) => {
    try {
      await activateRate.mutateAsync(id);
      toast.success('Tasa activada. Las anteriores del mismo tipo+divisa se desactivaron.');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al activar.');
    }
  };

  const onDeactivate = async (id: number) => {
    try {
      await deactivateRate.mutateAsync(id);
      toast.success('Tasa desactivada.');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al desactivar.');
    }
  };

  const sortedRates = (rates as unknown as ExchangeRateRow[])
    .map((r) => ({ rate: r, sortKey: r.effective_at ?? '' }))
    .sort((a, b) => (sortDir === 'desc' ? b.sortKey.localeCompare(a.sortKey) : a.sortKey.localeCompare(b.sortKey)))
    .map((x) => x.rate);

  if (isLoading) return <Skeleton className="h-48" />;
  if (isError) {
    return <EmptyState title="Error al cargar tasas" description="Reintenta en unos segundos." />;
  }

  return (
    <div className="space-y-3">
      {/* Filtros */}
      <div className="rounded-lg border border-border bg-surface p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <div>
            <Label htmlFor="filter-type" className="text-xs text-text-muted">
              Tipo
            </Label>
            <Select
              id="filter-type"
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
            >
              <option value="">Todos</option>
              {types.map((t) => (
                <option key={t.id} value={String(t.id)}>
                  {t.code} - {t.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="filter-from" className="text-xs text-text-muted">
              Desde
            </Label>
            <Input
              id="filter-from"
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
            />
          </div>
          <div>
            <Label htmlFor="filter-to" className="text-xs text-text-muted">
              Hasta
            </Label>
            <Input
              id="filter-to"
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
            />
          </div>
          <div className="flex items-end justify-end">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'))}
              aria-label="Cambiar orden"
            >
              {sortDir === 'desc' ? (
                <>
                  <ChevronDown className="size-3.5" /> Mas recientes
                </>
              ) : (
                <>
                  <ChevronUp className="size-3.5" /> Mas antiguas
                </>
              )}
            </Button>
          </div>
        </div>
      </div>

      <div className="flex items-center justify-between">
        <p className="text-sm text-text-muted">
          {rates.length} tasa{rates.length === 1 ? '' : 's'} encontrada
          {rates.length === 1 ? '' : 's'}.
        </p>
        <Can I={PERMISSIONS.CURRENCY_MANAGE}>
          <Button onClick={openForm} data-testid="new-exchange-rate">
            <Plus className="size-3.5" aria-hidden="true" />
            Nueva tasa
          </Button>
        </Can>
      </div>

      {rates.length === 0 ? (
        <EmptyState
          title="Sin tasas registradas"
          description="Crea la primera tasa historica para este tenant (ej: BCV USD->VES 36.50 con fecha de hoy)."
          icon={<TrendingUp className="size-8" aria-hidden="true" />}
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface p-2">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                  Fecha efectiva
                </th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                  Tipo
                </th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                  Conversion
                </th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                  Tasa
                </th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                  Estado
                </th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                  Acciones
                </th>
              </tr>
            </thead>
            <tbody>
              {sortedRates.map((r) => (
                <tr key={r.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 text-sm">{r.effective_at}</td>
                  <td className="px-3 py-2 text-sm font-mono">
                    {r.type?.code ?? '-'}
                  </td>
                  <td className="px-3 py-2 text-sm">
                    {r.base_currency} &rarr; {r.quote_currency}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums font-medium">
                    {formatMoney(r.rate, { showCurrency: true })}
                  </td>
                  <td className="px-3 py-2">
                    {r.is_active ? (
                      <Badge variant="success" className="text-xs">
                        <TrendingUp className="size-3" aria-hidden="true" /> Activa
                      </Badge>
                    ) : (
                      <Badge variant="info" className="text-xs">
                        <TrendingDown className="size-3" aria-hidden="true" /> Inactiva
                      </Badge>
                    )}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {canManage && (
                      <div className="flex items-center justify-end gap-0.5">
                        {r.is_active ? (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onDeactivate(r.id)}
                            disabled={deactivateRate.isPending}
                            data-testid={`rate-deactivate-${r.id}`}
                          >
                            Desactivar
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="primary"
                            onClick={() => onActivate(r.id)}
                            disabled={activateRate.isPending}
                            data-testid={`rate-activate-${r.id}`}
                          >
                            Activar
                          </Button>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Dialog para crear nueva tasa */}
      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Nueva tasa de cambio</DialogTitle>
            <DialogDescription>
              Crea una nueva tasa para un tipo y fecha. Si la marcas como
              activa, las anteriores del mismo tipo+conversion se desactivan.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={onSubmit} className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="rate-type">
                Tipo de tasa <span className="text-danger">*</span>
              </Label>
              <Select
                id="rate-type"
                value={String(form.watch('exchange_rate_type_id') ?? '')}
                onChange={(e) =>
                  form.setValue('exchange_rate_type_id', Number(e.target.value), {
                    shouldValidate: true,
                  })
                }
              >
                <option value="">Selecciona un tipo</option>
                {types.map((t) => (
                  <option key={t.id} value={String(t.id)}>
                    {t.code} - {t.name}
                  </option>
                ))}
              </Select>
              {form.formState.errors.exchange_rate_type_id && (
                <p className="text-xs text-danger">
                  {form.formState.errors.exchange_rate_type_id.message}
                </p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="base-currency">De</Label>
                <Select
                  id="base-currency"
                  value={form.watch('base_currency')}
                  onChange={(e) => form.setValue('base_currency', e.target.value)}
                >
                  {EXCHANGE_RATE_CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="quote-currency">A</Label>
                <Select
                  id="quote-currency"
                  value={form.watch('quote_currency')}
                  onChange={(e) => form.setValue('quote_currency', e.target.value)}
                >
                  {EXCHANGE_RATE_CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="rate">
                Tasa <span className="text-danger">*</span>{' '}
                <span className="text-xs font-normal text-text-muted">
                  (cuantos {form.watch('quote_currency') || 'VES'} por 1 {form.watch('base_currency') || 'USD'})
                </span>
              </Label>
              <Input
                id="rate"
                type="number"
                min="0"
                step="0.000001"
                {...form.register('rate', { valueAsNumber: true })}
                aria-invalid={Boolean(form.formState.errors.rate)}
              />
              {form.formState.errors.rate && (
                <p className="text-xs text-danger">{form.formState.errors.rate.message}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="effective-at">
                Fecha efectiva <span className="text-danger">*</span>
              </Label>
              <Input
                id="effective-at"
                type="date"
                {...form.register('effective_at')}
                aria-invalid={Boolean(form.formState.errors.effective_at)}
              />
              {form.formState.errors.effective_at && (
                <p className="text-xs text-danger">
                  {form.formState.errors.effective_at.message}
                </p>
              )}
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setShowForm(false)}
                disabled={createRate.isPending}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={createRate.isPending}>
                Crear tasa
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// Tipo auxiliar local (la API retorna data cruda sin validar contra el schema).
interface ExchangeRateRow {
  id: number;
  exchange_rate_type_id: number;
  base_currency: string;
  quote_currency: string;
  rate: string | number;
  effective_at: string | null;
  is_active: boolean;
  type?: Pick<ExchangeRateType, 'id' | 'code' | 'name'> | null;
  source?: string | null;
}


