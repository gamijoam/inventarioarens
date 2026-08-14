import { useMemo, useState } from 'react';
import { BadgeDollarSign, Calculator, Plus, Power, UsersRound } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { useExchangeRateTypes } from '@/features/inventory-center/api';
import { useUsers } from '@/features/users/api';
import {
  useCommissionPlans,
  useCommissionSimulation,
  useCreateCommissionPlan,
  useDeactivateCommissionPlan,
} from './api';
import { CommissionPlanInputSchema, type CommissionPlanInput } from './schemas';

const initialForm: CommissionPlanInput = {
  name: '',
  beneficiary_role: 'seller',
  percentage: 3,
  conversion_policy: 'configured_rate',
  exchange_rate_type_id: null,
  credit_policy: 'proportional_collections',
  maturation_days: 7,
  allow_self_stacking: false,
  is_active: true,
  starts_at: null,
  ends_at: null,
  user_ids: [],
};

export function CommissionsManager() {
  const { data: plans = [], isLoading } = useCommissionPlans();
  const { data: usersPage } = useUsers({ search: '', status: 'active', scope: 'tenant', page: 1, per_page: 100 });
  const { data: rateTypes = [] } = useExchangeRateTypes();
  const createPlan = useCreateCommissionPlan();
  const deactivate = useDeactivateCommissionPlan();
  const simulation = useCommissionSimulation();
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<CommissionPlanInput>(initialForm);
  const [simulationAmount, setSimulationAmount] = useState('6000');
  const [simulationCurrency, setSimulationCurrency] = useState<'USD' | 'VES'>('VES');
  const [simulationPercentage, setSimulationPercentage] = useState<string | null>(null);
  const [simulationRateTypeId, setSimulationRateTypeId] = useState<number | null>(null);
  const users = usersPage?.data ?? [];
  const activePlans = plans.filter((plan) => plan.is_active);
  const primaryActivePlan = activePlans[0];
  const assignedPeople = new Set(activePlans.flatMap((plan) => plan.assignments.map((item) => item.user_id))).size;
  const effectiveSimulationPercentage = simulationPercentage ?? String(primaryActivePlan?.percentage ?? form.percentage);
  const effectiveSimulationRateTypeId = simulationRateTypeId
    ?? primaryActivePlan?.exchange_rate_type_id
    ?? rateTypes.find((item) => item.is_active !== false)?.id
    ?? null;

  const selectedRate = useMemo(
    () => rateTypes.find((rateType) => rateType.id === form.exchange_rate_type_id),
    [form.exchange_rate_type_id, rateTypes],
  );
  const selectedSimulationRate = useMemo(
    () => rateTypes.find((rateType) => rateType.id === effectiveSimulationRateTypeId),
    [effectiveSimulationRateTypeId, rateTypes],
  );

  if (isLoading) return <Skeleton className="h-72 w-full" />;

  const runSimulation = async () => {
    try {
      await simulation.mutateAsync({
        amount: Number(simulationAmount),
        currency: simulationCurrency,
        percentage: Number(effectiveSimulationPercentage),
        exchange_rate_type_id: simulationCurrency === 'VES' ? effectiveSimulationRateTypeId ?? undefined : undefined,
      });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo calcular la comisión.');
    }
  };

  return (
    <div className="space-y-5">
      <section className="grid gap-3 md:grid-cols-3" aria-label="Resumen de comisiones">
        <MetricCard label="Planes activos" value={String(activePlans.length)} detail="Reglas vigentes" />
        <MetricCard label="Personas asignadas" value={String(assignedPeople)} detail="Vendedores y cajeros" />
        <MetricCard
          label="Regla de crédito"
          value={primaryActivePlan?.credit_policy === 'sale_confirmation' ? 'Al confirmar' : 'Al cobrar'}
          detail={primaryActivePlan?.credit_policy === 'sale_confirmation' ? 'Al completar la venta' : 'Proporcional a lo recibido'}
        />
      </section>

      <section className="border-border bg-surface grid overflow-hidden rounded-xl border lg:grid-cols-[1fr_360px]">
        <div className="p-5">
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <p className="text-text-muted text-xs font-semibold uppercase tracking-[0.16em]">Estructura vigente</p>
              <h2 className="mt-1 text-lg font-semibold">Planes por responsabilidad</h2>
            </div>
            <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setFormOpen(true)}>
              Nuevo plan
            </Button>
          </div>

          {plans.length === 0 ? (
            <EmptyState title="Aún no hay planes" description="Crea la primera regla para vendedores o cajeros." />
          ) : (
            <div className="space-y-3">
              {plans.map((plan) => (
                <article key={plan.id} className="border-border bg-bg/40 rounded-lg border p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                      <div className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg">
                        {plan.beneficiary_role === 'seller' ? <UsersRound className="size-5" /> : <BadgeDollarSign className="size-5" />}
                      </div>
                      <div>
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="font-semibold">{plan.name}</h3>
                          <Badge variant={plan.is_active ? 'success' : 'default'}>{plan.is_active ? 'Activo' : 'Inactivo'}</Badge>
                        </div>
                        <p className="text-text-muted mt-1 text-sm">
                          {plan.beneficiary_role === 'seller' ? 'Vendedor' : 'Cajero'} · {plan.assignments.length} persona(s) · {plan.maturation_days} días de espera
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className="text-right">
                        <p className="text-2xl font-semibold tabular-nums">{Number(plan.percentage).toFixed(2)}%</p>
                        <p className="text-text-muted text-xs">sobre venta neta</p>
                      </div>
                      {plan.is_active && (
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={`Desactivar ${plan.name}`}
                          onClick={async () => {
                            await deactivate.mutateAsync(plan.id);
                            toast.success('Plan desactivado. El historial se conserva.');
                          }}
                        >
                          <Power className="size-4" />
                        </Button>
                      )}
                    </div>
                  </div>
                  <div className="text-text-muted mt-3 flex flex-wrap gap-x-5 gap-y-1 border-t border-dashed pt-3 text-xs">
                    <span>Tasa: {plan.conversion_policy === 'sale_snapshot' ? 'la registrada en la venta' : plan.exchange_rate_type?.code}</span>
                    <span>Crédito: {plan.credit_policy === 'proportional_collections' ? 'al recibir cada cobro' : 'al confirmar venta'}</span>
                    <span>Acumulación: {plan.allow_self_stacking ? 'permitida' : 'sin doble comisión'}</span>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>

        <aside className="border-border bg-primary/[0.035] border-t p-5 lg:border-l lg:border-t-0">
          <div className="flex items-center gap-2">
            <Calculator className="text-primary size-5" />
            <h2 className="font-semibold">Simulador</h2>
          </div>
          <p className="text-text-muted mt-1 text-sm">Comprueba el resultado antes de activar una regla.</p>
          <div className="mt-5 grid grid-cols-[1fr_110px] gap-2">
            <Input aria-label="Monto a simular" type="number" min="0" value={simulationAmount} onChange={(event) => setSimulationAmount(event.target.value)} />
            <Select aria-label="Moneda a simular" value={simulationCurrency} onChange={(event) => setSimulationCurrency(event.target.value as 'USD' | 'VES')}>
              <option value="VES">Bolívares</option>
              <option value="USD">Dólares</option>
            </Select>
          </div>
          <div className="mt-2 grid grid-cols-[110px_1fr] gap-2">
            <Input
              aria-label="Porcentaje del simulador"
              type="number"
              min="0.0001"
              max="100"
              step="0.01"
              value={effectiveSimulationPercentage}
              onChange={(event) => setSimulationPercentage(event.target.value)}
            />
            <Select
              aria-label="Tipo de tasa del simulador"
              disabled={simulationCurrency === 'USD'}
              value={effectiveSimulationRateTypeId ?? ''}
              onChange={(event) => setSimulationRateTypeId(event.target.value ? Number(event.target.value) : null)}
            >
              <option value="">Seleccionar tasa</option>
              {rateTypes.filter((item) => item.is_active !== false).map((item) => (
                <option key={item.id} value={item.id}>{item.code} — {item.name}</option>
              ))}
            </Select>
          </div>
          <div className="border-border mt-4 rounded-lg border border-dashed p-4">
            <p className="text-text-muted text-xs uppercase tracking-wider">Comisión estimada</p>
            <p className="mt-1 text-3xl font-semibold tabular-nums">
              ${simulation.data ? Number(simulation.data.commission_base_amount).toFixed(2) : '0.00'}
            </p>
            <p className="text-text-muted mt-1 text-xs">
              {simulation.data?.exchange_rate
                ? `${simulation.data.exchange_rate_type_code} · Bs ${Number(simulation.data.exchange_rate).toFixed(2)}/USD`
                : selectedSimulationRate
                  ? `${selectedSimulationRate.code} seleccionada · ${effectiveSimulationPercentage}%`
                  : 'Selecciona una tasa para el escenario.'}
            </p>
          </div>
          <Button className="mt-4 w-full" variant="secondary" onClick={runSimulation} loading={simulation.isPending}>
            Calcular escenario
          </Button>
        </aside>
      </section>

      {formOpen && (
        <Dialog open onOpenChange={(open) => !open && setFormOpen(false)}>
          <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader><DialogTitle>Nuevo plan de comisiones</DialogTitle></DialogHeader>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nombre"><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Ej. Vendedores 3%" /></Field>
              <Field label="Beneficiario"><Select value={form.beneficiary_role} onChange={(event) => setForm({ ...form, beneficiary_role: event.target.value as 'seller' | 'cashier' })}><option value="seller">Vendedor</option><option value="cashier">Cajero</option></Select></Field>
              <Field label="Porcentaje"><Input type="number" min="0.0001" max="100" step="0.01" value={form.percentage} onChange={(event) => setForm({ ...form, percentage: Number(event.target.value) })} /></Field>
              <Field label="Días antes de estar disponible"><Input type="number" min="0" max="365" value={form.maturation_days} onChange={(event) => setForm({ ...form, maturation_days: Number(event.target.value) })} /></Field>
              <Field label="Conversión de bolívares"><Select value={form.conversion_policy} onChange={(event) => setForm({ ...form, conversion_policy: event.target.value as CommissionPlanInput['conversion_policy'] })}><option value="configured_rate">Tasa seleccionada para comisiones</option><option value="sale_snapshot">Tasa registrada en la venta</option></Select></Field>
              <Field label="Tipo de tasa"><Select disabled={form.conversion_policy === 'sale_snapshot'} value={form.exchange_rate_type_id ?? ''} onChange={(event) => setForm({ ...form, exchange_rate_type_id: event.target.value ? Number(event.target.value) : null })}><option value="">Seleccionar</option>{rateTypes.filter((item) => item.is_active !== false).map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</Select></Field>
              <Field label="Ventas a crédito"><Select value={form.credit_policy} onChange={(event) => setForm({ ...form, credit_policy: event.target.value as CommissionPlanInput['credit_policy'] })}><option value="proportional_collections">Generar proporcionalmente al cobrar</option><option value="sale_confirmation">Generar al confirmar la venta</option></Select></Field>
              <label className="border-border flex items-center gap-3 rounded-lg border p-3 text-sm"><Checkbox checked={form.allow_self_stacking} onCheckedChange={(checked) => setForm({ ...form, allow_self_stacking: checked === true })} /><span>Permitir vendedor + cajero si es la misma persona</span></label>
            </div>
            <div>
              <Label>Personas asignadas</Label>
              <div className="border-border mt-2 grid max-h-44 gap-1 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2">
                {users.map((user) => <label key={user.id} className="hover:bg-bg flex items-center gap-2 rounded p-2 text-sm"><Checkbox checked={form.user_ids.includes(user.id)} onCheckedChange={(checked) => setForm({ ...form, user_ids: checked ? [...form.user_ids, user.id] : form.user_ids.filter((id) => id !== user.id) })} /><span className="min-w-0"><span className="block truncate font-medium">{user.name}</span><span className="text-text-muted block truncate text-xs">{user.email}</span></span></label>)}
              </div>
            </div>
            <p className="text-text-muted text-xs">Vista previa: {form.percentage}% · {selectedRate?.code ?? 'tasa de la venta'} · disponible en {form.maturation_days} día(s).</p>
            <DialogFooter>
              <Button variant="ghost" onClick={() => setFormOpen(false)}>Cancelar</Button>
              <Button loading={createPlan.isPending} onClick={async () => {
                const parsed = CommissionPlanInputSchema.safeParse(form);
                if (!parsed.success) { toast.error(parsed.error.issues[0]?.message ?? 'Revisa los datos.'); return; }
                try {
                  await createPlan.mutateAsync(parsed.data);
                  toast.success('Plan de comisiones creado.');
                  setForm(initialForm);
                  setFormOpen(false);
                } catch (error) { toast.error(error instanceof Error ? error.message : 'No se pudo guardar el plan.'); }
              }}>Crear plan</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </div>
  );
}

function MetricCard({ label, value, detail }: { label: string; value: string; detail: string }) {
  return <div className="border-border bg-surface rounded-lg border p-4"><p className="text-text-muted text-xs font-medium uppercase tracking-wider">{label}</p><p className="mt-2 text-2xl font-semibold">{value}</p><p className="text-text-muted mt-1 text-xs">{detail}</p></div>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="space-y-1.5"><Label>{label}</Label>{children}</div>;
}
