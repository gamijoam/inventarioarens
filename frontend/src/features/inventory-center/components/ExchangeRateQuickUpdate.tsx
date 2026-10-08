import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { toast } from 'sonner';
import { TrendingUp } from 'lucide-react';

import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
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
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
import { useCreateExchangeRate, useExchangeRateTypes } from '@/features/inventory-center/api';
import type { CurrentExchangeRate } from '@/features/pos/api';
import { formatMoney } from '@/lib/money';

const SOURCE_OPTIONS = [
  { value: 'manual', label: 'Registro manual' },
  { value: 'bcv_official', label: 'Publicación oficial BCV' },
  { value: 'market_reference', label: 'Referencia de mercado' },
  { value: 'import', label: 'Importación' },
] as const;

export function ExchangeRateQuickUpdate({
  currentRate,
}: {
  currentRate: CurrentExchangeRate | null;
}) {
  const canView = useCan(PERMISSIONS.CURRENCY_VIEW);
  const canManage = useCan(PERMISSIONS.CURRENCY_MANAGE);
  const { data: types = [] } = useExchangeRateTypes(canView);
  const createRate = useCreateExchangeRate();
  const [open, setOpen] = useState(false);
  const [rateValue, setRateValue] = useState('');
  const [effectiveDate, setEffectiveDate] = useState(todayDate());
  const [source, setSource] = useState<(typeof SOURCE_OPTIONS)[number]['value']>('manual');

  if (!canView) return null;

  const defaultType = types.find((type) => type.is_default);
  const activeDefaultType = defaultType?.is_active ? defaultType : undefined;
  const activeTypes = types.filter((type) => type.is_active);

  const openDialog = () => {
    setRateValue('');
    setEffectiveDate(todayDate());
    setSource('manual');
    setOpen(true);
  };

  const saveRate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!activeDefaultType) return;
    const numericRate = Number(rateValue);
    if (!Number.isFinite(numericRate) || numericRate <= 0) {
      toast.error('Ingresa una tasa mayor que cero.');
      return;
    }
    try {
      await createRate.mutateAsync({
        exchange_rate_type_id: activeDefaultType.id,
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: numericRate,
        effective_at: `${effectiveDate}T12:00:00`,
        source,
        is_active: true,
      });
      toast.success('Tasa actualizada. La anterior quedó en el historial.');
      setOpen(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo actualizar la tasa.');
    }
  };

  return (
    <>
      <section className="border-border bg-surface mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 shadow-sm">
        <div className="flex min-w-0 items-center gap-3">
          <span className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
            <TrendingUp className="size-5" aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="text-text-muted text-[length:var(--text-ui-caption)] font-medium">
              Tasa de cambio vigente
            </p>
            {currentRate ? (
              <p className="truncate text-[length:var(--text-ui-body)] font-semibold">
                {currentRate.exchange_rate_type_code ?? 'Tasa'} ·{' '}
                {formatMoney(currentRate.rate, { showCurrency: true })} por USD
                <span className="text-text-muted ml-2 font-normal">
                  {currentRate.effective_at
                    ? new Date(currentRate.effective_at).toLocaleDateString('es-VE')
                    : 'Fecha sin indicar'}
                  {' · '}
                  {sourceLabel(currentRate.source)}
                </span>
              </p>
            ) : (
              <p className="text-[length:var(--text-ui-body)] font-semibold">
                No hay una tasa vigente registrada
              </p>
            )}
          </div>
        </div>
        {canManage && activeDefaultType && (
          <Button size="md" onClick={openDialog} leftIcon={<TrendingUp />}>
            Actualizar tasa
          </Button>
        )}
      </section>

      {defaultType && !defaultType.is_active && (
        <Alert variant="warning" className="mb-4">
          <p className="font-semibold">
            El tipo predeterminado ({defaultType.code}) está inactivo.
          </p>
          <p className="mt-1">Actívalo en la configuración de tasas para poder actualizarlo.</p>
          <Link to="/inventory/currency" className="mt-2 inline-flex font-semibold underline">
            Revisar tipos de tasa
          </Link>
        </Alert>
      )}
      {!defaultType && activeTypes.length === 0 && (
        <Alert variant="warning" className="mb-4">
          No hay un tipo de tasa activo y predeterminado. Configúralo antes de registrar la tasa
          vigente.
          <Link to="/inventory/currency" className="ml-2 font-semibold underline">
            Configurar tasas
          </Link>
        </Alert>
      )}
      {!defaultType && activeTypes.length > 0 && (
        <Alert variant="warning" className="mb-4">
          No hay tipo predeterminado configurado. Elige uno en{' '}
          <Link to="/inventory/currency" className="font-semibold underline">
            Precios y tasas
          </Link>
          .
        </Alert>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Actualizar tasa vigente</DialogTitle>
            <DialogDescription>
              Se guardará un nuevo registro para USD → VES y la tasa anterior quedará en el
              historial.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={saveRate} className="space-y-4">
            <div className="space-y-1.5">
              <Label>Tipo predeterminado</Label>
              <div className="bg-bg rounded-md px-3 py-2 text-[length:var(--text-ui-body)] font-medium">
                {activeDefaultType?.code} · {activeDefaultType?.name}
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="quick-rate-value">Nueva tasa (Bs por USD)</Label>
              <Input
                id="quick-rate-value"
                type="number"
                min="0.000001"
                step="0.000001"
                value={rateValue}
                onChange={(event) => setRateValue(event.target.value)}
                required
                autoFocus
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="quick-rate-date">Fecha efectiva</Label>
                <Input
                  id="quick-rate-date"
                  type="date"
                  value={effectiveDate}
                  onChange={(event) => setEffectiveDate(event.target.value)}
                  required
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="quick-rate-source">Fuente</Label>
                <Select
                  id="quick-rate-source"
                  value={source}
                  onChange={(event) => setSource(event.target.value as typeof source)}
                >
                  {SOURCE_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
                <p className="text-text-muted text-[length:var(--text-ui-caption)]">
                  Origen declarado; la actualización se registra manualmente.
                </p>
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
                disabled={createRate.isPending}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={createRate.isPending}>
                Guardar y activar
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}

function sourceLabel(source?: string | null): string {
  const labels: Record<string, string> = {
    manual: 'Registro manual',
    bcv_official: 'Publicación BCV',
    market_reference: 'Referencia de mercado',
    import: 'Importación',
  };
  if (!source) return 'Fuente sin indicar';
  return (
    labels[source] ?? (source.toLowerCase().includes('demo') ? 'Dato de demostración' : source)
  );
}

function todayDate(): string {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}
