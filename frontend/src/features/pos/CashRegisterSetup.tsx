import { useMemo, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Banknote, Building2, Eye, EyeOff, FileText, Loader2, Plus, Store } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  type CashRegisterSession,
  useAddCashMovement,
  useBranchesForPos,
  useCashRegisters,
  useCashSessions,
  useCashSessionsList,
  useCloseCashSession,
  useCreateCashRegister,
  useCreatePosBranch,
  useCurrentExchangeRatesForPos,
  useExchangeRateTypesForPos,
  useOpenCashSession,
} from './api';
import { useUsers } from '@/features/users/api';
import { openReportZPdf } from '@/features/cash-register/reportZApi';
import { CashRegisterCommandCenter } from './CashRegisterCommandCenter';

export type CashCount = { currency: 'USD' | 'VES'; denomination: number; quantity: number };
export type CloseForm = { sessionId: number | null; usd: string; ves: string; notes: string; counts: CashCount[]; blind: boolean };
export const CASH_DENOMINATIONS: Record<CashCount['currency'], number[]> = {
  USD: [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2, 5, 10, 20, 50, 100],
  VES: [0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000],
};

export function CashRegisterSetup() {
  const { data: branches = [], isLoading: loadingBranches } = useBranchesForPos();
  const { data: registers = [], isLoading: loadingRegisters } = useCashRegisters();
  const { data: mySessions = [], isLoading: loadingMySession } = useCashSessions();
  const { data: openSessions = [], isLoading: loadingOpenSessions } = useCashSessionsList({ status: 'open', perPage: 50 });
  const { data: closedSessions = [], isLoading: loadingClosedSessions } = useCashSessionsList({ status: 'closed', perPage: 25 });
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const { data: rateTypes = [] } = useExchangeRateTypesForPos();
  const canOpen = useCan(PERMISSIONS.CASH_REGISTER_OPEN);
  const canAssignCashier = useCan(PERMISSIONS.CASH_REGISTER_CLOSE);
  const { data: usersResponse, isLoading: loadingUsers } = useUsers({
    status: 'active',
    scope: 'tenant',
    page: 1,
    per_page: 100,
    search: '',
  });
  const cashierOptions = usersResponse?.data ?? [];
  const canMove = useCan(PERMISSIONS.CASH_REGISTER_MOVE) || useCan(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canClose = useCan(PERMISSIONS.CASH_REGISTER_CLOSE);
  const createBranch = useCreatePosBranch();
  const createRegister = useCreateCashRegister();
  const openSession = useOpenCashSession();
  const addMovement = useAddCashMovement();
  const closeSession = useCloseCashSession();
  const [branchForm, setBranchForm] = useState({ name: '', code: '' });
  const [registerForm, setRegisterForm] = useState({ name: '', code: '', branch_id: '' });
  const [openForm, setOpenForm] = useState({ branch_id: '', cash_register_id: '', cashier_id: '', opening_base_amount: '0', opening_local_amount: '0' });
  const [movementForm, setMovementForm] = useState({ type: 'outflow', amount: '', notes: '' });
  const [closeForm, setCloseForm] = useState<CloseForm>({ sessionId: null, usd: '', ves: '', notes: '', counts: [], blind: false });

  const branchOptions = useMemo(() => branches.filter((branch) => (branch.status ?? 'active') === 'active'), [branches]);
  const registerOptions = useMemo(() => registers.filter((register) => (register.status ?? 'active') === 'active'), [registers]);
  const activeSession = mySessions.find((session) => session.status === 'open' && Boolean(session.cash_register_id)) ?? null;
  const activeRate = bestActiveRate(rates, rateTypes);
  const rateLabel = activeRate
    ? `${activeRate.name} (${activeRate.code}) @ ${formatLocalNumber(activeRate.rate)}`
    : null;
  const overviewStats = [
    {
      label: 'Tu turno',
      value: activeSession ? 'Abierto' : 'Sin turno',
      hint: activeSession
        ? `${activeSession.cash_register?.name ?? 'Caja física'} · ${activeSession.branch?.name ?? 'Sucursal'}`
        : 'Abre un turno para iniciar operaciones.',
      tone: activeSession ? 'success' : 'warning',
    },
    {
      label: 'Turnos abiertos',
      value: String(openSessions.length),
      hint: 'Historial operativo visible ahora.',
      tone: 'default',
    },
    {
      label: 'Efectivo esperado',
      value: activeSession ? money(activeSession.expected_cash_usd ?? activeSession.opening_base_amount) : '$0.00',
      hint: activeRate ? `USD · ${rateLabel}` : 'USD físico en caja.',
      tone: activeSession ? 'success' : 'default',
    },
    {
      label: 'Infraestructura',
      value: `${branchOptions.length} suc. · ${registerOptions.length} cajas`,
      hint: 'Disponibles para operar.',
      tone: 'info',
    },
  ] as const;

  function submitOpen(): void {
    if (!openForm.branch_id || !openForm.cash_register_id) {
      toast.error('Selecciona sucursal y caja física activa.');
      return;
    }
    if (Number(openForm.opening_local_amount || 0) > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de abrir con fondo VES.');
      return;
    }

    openSession.mutate({
      branch_id: Number(openForm.branch_id),
      cash_register_id: Number(openForm.cash_register_id),
      ...(openForm.cashier_id ? { cashier_id: Number(openForm.cashier_id) } : {}),
      opening_base_amount: Number(openForm.opening_base_amount || 0),
      opening_local_amount: Number(openForm.opening_local_amount || 0),
      exchange_rate_type_id: Number(openForm.opening_local_amount || 0) > 0 ? activeRate?.exchange_rate_type_id : null,
      notes: 'Apertura desde modulo Cajas',
    }, {
      onSuccess: () => setOpenForm({ branch_id: '', cash_register_id: '', cashier_id: '', opening_base_amount: '0', opening_local_amount: '0' }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  function submitMovement(): void {
    if (!activeSession) return;
    if (!movementForm.amount || Number(movementForm.amount) <= 0) {
      toast.error('Indica un monto valido.');
      return;
    }

    addMovement.mutate({
      sessionId: activeSession.id,
      payload: {
        type: movementForm.type as 'inflow' | 'outflow' | 'adjustment',
        method: 'cash',
        currency: 'USD',
        amount: Number(movementForm.amount),
        notes: movementForm.notes || null,
      },
    }, {
      onSuccess: () => setMovementForm({ type: 'outflow', amount: '', notes: '' }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  function submitClose(session: CashRegisterSession): void {
    const totals = cashCountTotals(closeForm.counts);
    const usd = closeForm.counts.length ? totals.USD : Number(closeForm.usd || 0);
    const ves = closeForm.counts.length ? totals.VES : Number(closeForm.ves || 0);
    const diff = closeDifference(session, closeForm, activeRate?.rate ?? null);

    if (usd < 0 || ves < 0) {
      toast.error('El efectivo contado no puede ser negativo.');
      return;
    }
    if (ves > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de cerrar con efectivo VES.');
      return;
    }
    if (hasDifference(diff.cashUsd, diff.cashVes) && !closeForm.blind && !closeForm.notes.trim()) {
      toast.error('Indica una nota para justificar la diferencia de caja.');
      return;
    }

    closeSession.mutate({
      sessionId: session.id,
      payload: {
        counted_base_amount: usd,
         counted_local_amount: ves,
         counted_cash_usd: usd,
         counted_cash_ves: ves,
         exchange_rate_type_id: ves > 0 ? activeRate?.exchange_rate_type_id : null,
         counts: closeForm.counts.length ? closeForm.counts : undefined,
         counting_mode: closeForm.blind ? 'blind' : 'standard',
         closing_notes: closeForm.notes.trim() || null,
      },
    }, {
       onSuccess: () => setCloseForm({ sessionId: null, usd: '', ves: '', notes: '', counts: [], blind: false }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  return (
    <PageLayout
      title="Cajas"
      description="Opera turnos, arqueos y cajas físicas con control por permisos."
      actions={
        <Button asChild>
          <Link to="/pos">
            <Banknote className="size-4" /> Ir al POS
          </Link>
        </Button>
      }
    >
      <div className="space-y-4">
        <Card className="overflow-hidden">
          <CardContent className="p-0">
            <div className="border-border/80 border-b bg-gradient-to-br from-primary/10 via-surface to-surface px-5 py-5 lg:px-6 lg:py-6">
              <div className="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                <div className="max-w-2xl space-y-3">
                  <div className="flex flex-wrap gap-2">
                    <Badge variant="primary">Operación de caja</Badge>
                    <Badge variant={activeRate ? 'success' : 'warning'}>{rateLabel ?? 'Sin tasa activa'}</Badge>
                    <Badge variant={activeSession ? 'success' : 'default'}>{activeSession ? 'Turno abierto' : 'Sin turno abierto'}</Badge>
                  </div>
                  <div>
                    <h2 className="text-2xl font-semibold tracking-tight text-text-primary lg:text-3xl">Control de turnos y arqueos</h2>
                    <p className="mt-1 max-w-xl text-sm text-text-muted">
                      La lógica de apertura, movimientos y cierre se mantiene intacta. Solo reorganizamos la pantalla para que la caja se lea como un panel operativo, no como un formulario largo.
                    </p>
                  </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {overviewStats.map((stat) => (
                    <OverviewStat key={stat.label} label={stat.label} value={stat.value} hint={stat.hint} tone={stat.tone} />
                  ))}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <Tabs defaultValue="operacion" className="space-y-4">
          <TabsList className="grid h-auto w-full grid-cols-2 gap-1 p-1 sm:w-fit sm:grid-cols-4">
            <TabsTrigger value="operacion">Mi turno</TabsTrigger>
            <TabsTrigger value="supervision">Supervisión</TabsTrigger>
            <TabsTrigger value="historial">Historial</TabsTrigger>
            <TabsTrigger value="infraestructura">Configuración</TabsTrigger>
          </TabsList>

          <TabsContent value="operacion" className="space-y-4">
            <div className="grid gap-4">
              <CashSessionCard
                session={activeSession}
                loading={loadingMySession}
                branches={branchOptions}
                registers={registerOptions}
        canOpen={canOpen}
        canAssignCashier={canAssignCashier}
        cashierOptions={cashierOptions}
        loadingUsers={loadingUsers}
                canMove={canMove}
                canClose={canClose}
                openForm={openForm}
                movementForm={movementForm}
                closeForm={closeForm}
                rate={activeRate?.rate ?? null}
                rateLabel={rateLabel}
                opening={openSession.isPending}
                moving={addMovement.isPending}
                closing={closeSession.isPending}
                onOpenForm={setOpenForm}
                onMovementForm={setMovementForm}
                 onCloseForm={setCloseForm}
                onOpen={submitOpen}
                onMovement={submitMovement}
                onClose={submitClose}
              />
            </div>
          </TabsContent>

          <TabsContent value="supervision" className="space-y-4">
            <CashRegisterCommandCenter branches={branchOptions} registers={registerOptions} />
          </TabsContent>

          <TabsContent value="historial" className="space-y-4">
            <div className="grid gap-4 xl:grid-cols-2">
              <SessionsBoard
                title="Turnos abiertos"
                description="Cajas actualmente pendientes de cierre. Las acciones dependen de permisos y rol."
                sessions={openSessions}
                loading={loadingOpenSessions}
                canClose={canClose}
                closeForm={closeForm}
                rate={activeRate?.rate ?? null}
                closing={closeSession.isPending}
                 onCloseForm={setCloseForm}
                onClose={submitClose}
              />

              <SessionsBoard
                title="Turnos cerrados"
                description="Historial reciente con declarado, esperado y diferencia final."
                sessions={closedSessions}
                loading={loadingClosedSessions}
                canClose={false}
                closeForm={closeForm}
                rate={activeRate?.rate ?? null}
                closing={false}
                onCloseForm={setCloseForm}
                onClose={submitClose}
              />
            </div>
          </TabsContent>

          <TabsContent value="infraestructura" className="space-y-4">
            <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
              <BranchesCard
                branches={branches}
                branchOptions={branchOptions}
                loading={loadingBranches}
                form={branchForm}
                creating={createBranch.isPending}
                onForm={setBranchForm}
                onCreate={() => {
                  if (!branchForm.name.trim() || !branchForm.code.trim()) {
                    toast.error('Indica nombre y código de la sucursal.');
                    return;
                  }
                  createBranch.mutate({
                    name: branchForm.name.trim(),
                    code: branchForm.code.trim().toUpperCase(),
                    status: 'active',
                  }, { onSuccess: () => setBranchForm({ name: '', code: '' }) });
                }}
              />
              <RegistersCard
                registers={registers}
                branchOptions={branchOptions}
                loading={loadingRegisters}
                form={registerForm}
                creating={createRegister.isPending}
                onForm={setRegisterForm}
                onCreate={() => {
                  if (!registerForm.name.trim() || !registerForm.code.trim() || !registerForm.branch_id) {
                    toast.error('Indica nombre, código y sucursal de la caja.');
                    return;
                  }
                  createRegister.mutate({
                    name: registerForm.name.trim(),
                    code: registerForm.code.trim().toUpperCase(),
                    branch_id: Number(registerForm.branch_id),
                    status: 'active',
                  }, { onSuccess: () => setRegisterForm({ name: '', code: '', branch_id: '' }) });
                }}
              />
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </PageLayout>
  );
}

function OverviewStat({
  label,
  value,
  hint,
  tone,
}: {
  label: string;
  value: string;
  hint: string;
  tone: 'success' | 'warning' | 'info' | 'default';
}) {
  const badgeVariant: 'outline' | 'success' | 'warning' | 'info' =
    tone === 'default' ? 'outline' : tone;

  return (
    <Card className="min-w-[160px] border-border/80 bg-surface/80 shadow-none">
      <CardContent className="space-y-1 p-3">
        <div className="flex items-center justify-between gap-2">
          <p className="text-xs uppercase tracking-wide text-text-muted">{label}</p>
          <Badge variant={badgeVariant}>{tone === 'success' ? 'OK' : tone === 'warning' ? 'Atencion' : tone === 'info' ? 'Dato' : 'Estado'}</Badge>
        </div>
        <p className="text-lg font-semibold text-text-primary">{value}</p>
        <p className="text-xs text-text-muted">{hint}</p>
      </CardContent>
    </Card>
  );
}

function CashSessionCard({
  session,
  loading,
  branches,
  registers,
  canOpen,
  canAssignCashier,
  cashierOptions,
  loadingUsers,
  canMove,
  canClose,
  openForm,
  movementForm,
  closeForm,
  rate,
  rateLabel,
  opening,
  moving,
  closing,
  onOpenForm,
  onMovementForm,
  onCloseForm,
  onOpen,
  onMovement,
  onClose,
}: {
  session: CashRegisterSession | null;
  loading: boolean;
  branches: Array<{ id: number; name: string; code: string }>;
  registers: Array<{ id: number; name: string; code?: string | null; branch_id?: number | null }>;
  canOpen: boolean;
  canAssignCashier: boolean;
  canMove: boolean;
  canClose: boolean;
  openForm: { branch_id: string; cash_register_id: string; cashier_id: string; opening_base_amount: string; opening_local_amount: string };
  cashierOptions: Array<{ id: number; name: string; email: string }>;
  loadingUsers: boolean;
  movementForm: { type: string; amount: string; notes: string };
  closeForm: CloseForm;
  rate: number | null;
  rateLabel: string | null;
  opening: boolean;
  moving: boolean;
  closing: boolean;
  onOpenForm: (value: { branch_id: string; cash_register_id: string; cashier_id: string; opening_base_amount: string; opening_local_amount: string }) => void;
  onMovementForm: (value: { type: string; amount: string; notes: string }) => void;
  onCloseForm: (value: CloseForm) => void;
  onOpen: () => void;
  onMovement: () => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  const availableRegisters = openForm.branch_id
    ? registers.filter((register) => Number(register.branch_id) === Number(openForm.branch_id))
    : registers;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Banknote className="size-4" /> Mi turno abierto
        </CardTitle>
        <CardDescription>El POS solo puede vender con un turno propio abierto en una caja física activa.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <LoadingLine label="Buscando tu turno abierto..." />
        ) : session ? (
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_520px]">
            <SessionSummary session={session} />
            <div className="space-y-3 rounded-lg border border-border bg-bg/40 p-4 shadow-sm">
              {canMove && (
                <div className="grid gap-2 sm:grid-cols-[120px_1fr_auto]">
                  <Select value={movementForm.type} onChange={(event) => onMovementForm({ ...movementForm, type: event.target.value })}>
                    <option value="inflow">Entrada</option>
                    <option value="outflow">Salida</option>
                    <option value="adjustment">Ajuste</option>
                  </Select>
                  <Input type="number" min="0" value={movementForm.amount} onChange={(event) => onMovementForm({ ...movementForm, amount: event.target.value })} placeholder="Monto USD" />
                  <Button className="whitespace-nowrap" disabled={moving} onClick={onMovement}>{moving && <Loader2 className="size-4 animate-spin" />} Registrar</Button>
                  <Input className="sm:col-span-3" value={movementForm.notes} onChange={(event) => onMovementForm({ ...movementForm, notes: event.target.value })} placeholder="Notas del movimiento" />
                </div>
              )}
              {canClose ? (
                <ClosePanel session={session} form={closeForm} rate={rate} closing={closing} onForm={onCloseForm} onClose={onClose} />
              ) : (
                <p className="text-sm text-text-muted">No tienes permiso para cerrar este turno.</p>
              )}
            </div>
          </div>
        ) : (
          <div className="rounded border border-border bg-bg/40 p-3">
            {!canOpen ? (
              <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">No tienes permiso para abrir turno.</p>
            ) : (
              <>
                <div className="grid gap-2 lg:grid-cols-[1fr_1fr_1.2fr_140px_140px_auto]">
                  <Select value={openForm.branch_id} onChange={(event) => onOpenForm({ ...openForm, branch_id: event.target.value, cash_register_id: '' })}>
                    <option value="">Sucursal...</option>
                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
                  </Select>
                  <Select value={openForm.cash_register_id} onChange={(event) => onOpenForm({ ...openForm, cash_register_id: event.target.value })}>
                    <option value="">Caja física...</option>
                    {availableRegisters.map((register) => <option key={register.id} value={register.id}>{register.code ?? register.id} - {register.name}</option>)}
                  </Select>
                  {canAssignCashier ? (
                    <Select value={openForm.cashier_id} onChange={(event) => onOpenForm({ ...openForm, cashier_id: event.target.value })}>
                      <option value="">{loadingUsers ? 'Cargando cajeros...' : 'Cajero responsable...'}</option>
                      {cashierOptions.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                    </Select>
                  ) : null}
                  <div className="flex flex-col gap-1">
                    <label className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">
                      Fondo $ (USD)
                    </label>
                    <Input type="number" min="0" value={openForm.opening_base_amount} onChange={(event) => onOpenForm({ ...openForm, opening_base_amount: event.target.value })} placeholder="0.00" />
                  </div>
                  <div className="flex flex-col gap-1">
                    <label className="text-text-muted text-[10px] font-semibold tracking-wide uppercase">
                      Fondo Bs (VES)
                    </label>
                    <Input type="number" min="0" value={openForm.opening_local_amount} onChange={(event) => onOpenForm({ ...openForm, opening_local_amount: event.target.value })} placeholder="0.00" />
                  </div>
                  <Button disabled={opening || !openForm.branch_id || !openForm.cash_register_id || (canAssignCashier && !openForm.cashier_id)} onClick={onOpen}>
                    {opening && <Loader2 className="size-4 animate-spin" />} Abrir turno
                  </Button>
                </div>
                <p className="mt-2 text-xs text-text-muted">
                  {canAssignCashier
                    ? 'Selecciona el cajero que será responsable de este turno. '
                    : 'El turno se asignará a tu usuario. '}
                  {rateLabel ? `Fondo VES se convierte con ${rateLabel}.` : 'Sin tasa activa USD/VES para convertir fondo VES.'}
                </p>
              </>
            )}
            {(branches.length === 0 || registers.length === 0) && (
              <p className="mt-3 text-sm text-warning">Configura al menos una sucursal y una caja física activa antes de abrir turno.</p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function SessionsBoard({
  title,
  description,
  sessions,
  loading,
  canClose,
  closeForm,
  rate,
  closing,
  onCloseForm,
  onClose,
}: {
  title: string;
  description: string;
  sessions: CashRegisterSession[];
  loading: boolean;
  canClose: boolean;
  closeForm: CloseForm;
  rate: number | null;
  closing: boolean;
  onCloseForm: (value: CloseForm) => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="max-h-[560px] space-y-3 overflow-y-auto pr-2">
        {loading && <LoadingLine label="Cargando turnos..." />}
        {!loading && sessions.length === 0 && <EmptySetup text="No hay turnos para mostrar." />}
        {sessions.map((session) => (
          <div key={session.id} className="rounded border border-border p-3">
            <div className="grid gap-3 lg:grid-cols-[1fr_auto]">
              <SessionSummary session={session} compact />
              <div className="flex flex-col items-start gap-2 lg:items-end">
                <Badge variant={session.status === 'open' ? 'success' : 'default'}>{session.status === 'open' ? 'Abierta' : 'Cerrada'}</Badge>
                {canClose && session.status === 'open' && (
                  <Button size="sm" variant="outline" onClick={() => onCloseForm({ sessionId: session.id, usd: '', ves: '', notes: '', counts: [], blind: false })}>
                    Cerrar turno
                  </Button>
                )}
                {session.status === 'closed' && (
                  <Button size="sm" variant="outline" onClick={() => void openReportZPdf(session.id)}>
                    <FileText className="size-4" /> Reporte Z
                  </Button>
                )}
              </div>
            </div>
            {canClose && closeForm.sessionId === session.id && (
              <div className="mt-3 border-t border-border pt-3">
                <ClosePanel session={session} form={closeForm} rate={rate} closing={closing} onForm={onCloseForm} onClose={onClose} />
              </div>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function SessionSummary({ session, compact = false }: { session: CashRegisterSession; compact?: boolean }) {
  return (
    <div className={compact ? '' : 'rounded border border-border bg-bg/40 p-3'}>
      <div className="space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1 space-y-1">
            <p className="text-xs uppercase tracking-wide text-text-muted">{session.status === 'open' ? 'Caja abierta' : 'Caja cerrada'}</p>
            <p className="break-words text-lg font-semibold leading-tight text-text-primary">{session.cash_register?.name ?? 'Caja física'}</p>
            <p className="break-words text-sm leading-snug text-text-muted">
              {session.branch?.name ?? 'Sucursal'}
              <span className="mx-1">·</span>
              Cajero: {session.cashier?.name ?? session.cashier_id ?? '-'}
            </p>
          </div>
          <Badge variant={session.status === 'open' ? 'success' : 'default'}>{session.status === 'open' ? 'Abierta' : 'Cerrada'}</Badge>
        </div>
        <p className="text-xs text-text-muted">
          Abierta {formatDate(session.opened_at)}{session.closed_at ? ` · Cerrada ${formatDate(session.closed_at)}` : ''}
        </p>
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-2">
        <Metric label="Fondo USD" value={money(session.opening_base_amount)} />
        <Metric label="Fondo VES" value={localMoney(session.opening_local_amount)} />
        <Metric label="Efectivo esperado USD" value={money(session.expected_cash_usd ?? session.opening_base_amount)} />
        <Metric label="Efectivo esperado VES" value={localMoney(session.expected_cash_ves ?? session.opening_local_amount)} />
        {session.status === 'closed' && (
          <>
            <Metric label="Efectivo contado USD" value={money(session.counted_cash_usd ?? session.counted_base_amount)} />
            <Metric label="Efectivo contado VES" value={localMoney(session.counted_cash_ves ?? session.counted_local_amount)} />
            <Metric label="Diferencia física USD" value={money(session.difference_cash_usd ?? session.difference_base_amount)} />
            <Metric label="Diferencia física VES" value={localMoney(session.difference_cash_ves ?? session.difference_local_amount)} />
          </>
        )}
      </div>
      {session.movements && session.movements.length > 0 && (
        <div className="mt-4 divide-y divide-border overflow-hidden rounded border border-border bg-surface">
          {session.movements.slice(0, 5).map((movement) => (
            <div key={movement.id} className="flex items-start justify-between gap-3 p-3 text-sm">
              <span className="min-w-0 flex-1 break-words">{movement.type} - {movement.notes ?? movement.method ?? 'movimiento'}</span>
              <span className="shrink-0 font-medium">{movement.currency === 'VES' ? localMoney(movement.amount) : money(movement.amount)}</span>
            </div>
          ))}
        </div>
      )}
      {session.closing_notes && <p className="mt-3 text-sm text-text-muted">Nota de cierre: {session.closing_notes}</p>}
    </div>
  );
}

function ClosePanel({ session, form, rate, closing, onForm, onClose }: {
  session: CashRegisterSession;
  form: CloseForm;
  rate: number | null;
  closing: boolean;
  onForm: (value: CloseForm) => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  const activeForm = form.sessionId === session.id ? form : { sessionId: session.id, usd: '', ves: '', notes: '', counts: [], blind: false };
  const totals = cashCountTotals(activeForm.counts);
  const calculatedForm = activeForm.counts.length ? { ...activeForm, usd: String(totals.USD), ves: String(totals.VES) } : activeForm;
  const diff = closeDifference(session, calculatedForm, rate);
  const needsNote = hasDifference(diff.cashUsd, diff.cashVes);

  return (
    <div className="space-y-2 rounded border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-2">
        <div><p className="font-semibold">Arqueo de cierre</p><p className="text-xs text-text-muted">{activeForm.blind ? 'El monto esperado está oculto.' : 'Puedes contar con referencia o usar cierre ciego.'}</p></div>
        <Button size="sm" variant={activeForm.blind ? 'primary' : 'outline'} onClick={() => onForm({ ...activeForm, blind: !activeForm.blind, usd: activeForm.blind ? activeForm.usd : '', ves: activeForm.blind ? activeForm.ves : '', counts: activeForm.blind ? activeForm.counts : [] })}>
          {activeForm.blind ? <EyeOff className="size-4" /> : <Eye className="size-4" />} {activeForm.blind ? 'Cierre ciego activo' : 'Usar cierre ciego'}
        </Button>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <Input type="number" min="0" value={calculatedForm.usd} readOnly={activeForm.counts.length > 0} onChange={(event) => onForm({ ...activeForm, usd: event.target.value })} placeholder="Efectivo contado USD" />
        <Input type="number" min="0" value={calculatedForm.ves} readOnly={activeForm.counts.length > 0} onChange={(event) => onForm({ ...activeForm, ves: event.target.value })} placeholder="Efectivo contado VES" />
      </div>
      <div className="space-y-3 rounded border border-border/70 p-3">
        <div className="flex items-center justify-between gap-2">
          <p className="text-sm font-semibold">Conteo por denominaciones</p>
          {activeForm.counts.length > 0 && <Button size="sm" variant="ghost" onClick={() => onForm({ ...activeForm, counts: [] })}>Limpiar conteo</Button>}
        </div>
        <p className="text-xs text-text-muted">Si registras denominaciones, los totales anteriores se calculan automáticamente.</p>
        <DenominationGrid currency="USD" form={activeForm} onForm={onForm} />
        <DenominationGrid currency="VES" form={activeForm} onForm={onForm} />
      </div>
      <div className="grid gap-2 rounded border border-border/70 p-2 text-sm sm:grid-cols-2">
        <Metric label="Declarado USD equivalente" value={money(diff.declaredBase)} />
        {!activeForm.blind && <Metric label="Esperado USD" value={money(diff.expectedUsd)} />}
        {!activeForm.blind && <Metric label="Diferencia física USD" value={money(diff.cashUsd)} />}
        <Metric label="Contado VES" value={localMoney(Number(calculatedForm.ves || 0))} />
        {!activeForm.blind && <Metric label="Esperado VES" value={localMoney(diff.expectedVes)} />}
        {!activeForm.blind && <Metric label="Diferencia física VES" value={localMoney(diff.cashVes)} />}
        {activeForm.blind && <p className="rounded bg-primary/5 p-2 text-xs text-text-muted sm:col-span-2">La diferencia se calculará al confirmar el cierre y quedará visible para el responsable.</p>}
      </div>
      <Input value={activeForm.notes} onChange={(event) => onForm({ ...activeForm, notes: event.target.value })} placeholder={activeForm.blind ? 'Notas de cierre (opcional)' : (needsNote ? 'Nota obligatoria por diferencia' : 'Notas de cierre')} />
      <Button variant="danger" disabled={closing || (!activeForm.blind && needsNote && !activeForm.notes.trim())} onClick={() => onClose(session)}>
        {closing && <Loader2 className="size-4 animate-spin" />} Cerrar turno
      </Button>
    </div>
  );
}

function BranchesCard({ branches, loading, form, creating, onForm, onCreate }: {
  branches: Array<{ id: number; name: string; code: string; status?: string | null }>;
  branchOptions: Array<{ id: number; name: string; code: string }>;
  loading: boolean;
  form: { name: string; code: string };
  creating: boolean;
  onForm: (value: { name: string; code: string }) => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><Building2 className="size-4" /> Sucursales</CardTitle>
        <CardDescription>Una caja física siempre pertenece a una sucursal.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <Can I={PERMISSIONS.BRANCHES_CREATE}>
          <div className="grid gap-2 rounded border border-border bg-bg/40 p-3 sm:grid-cols-[1fr_120px_auto]">
            <Input value={form.name} onChange={(event) => onForm({ ...form, name: event.target.value })} placeholder="Nombre de sucursal" />
            <Input value={form.code} onChange={(event) => onForm({ ...form, code: event.target.value })} placeholder="Código" />
            <Button disabled={creating} onClick={onCreate}>{creating ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />} Crear</Button>
          </div>
        </Can>
        {loading ? <LoadingLine label="Cargando sucursales..." /> : branches.length === 0 ? <EmptySetup text="No hay sucursales configuradas." /> : (
          <div className="divide-y divide-border rounded border border-border">
            {branches.map((branch) => (
              <div key={branch.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className="font-medium">{branch.name}</p>
                  <p className="font-mono text-xs text-text-muted">{branch.code}</p>
                </div>
                <Badge variant={(branch.status ?? 'active') === 'active' ? 'success' : 'default'}>{branch.status ?? 'active'}</Badge>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function RegistersCard({ registers, branchOptions, loading, form, creating, onForm, onCreate }: {
  registers: Array<{ id: number; name: string; code?: string | null; status?: string | null; open_session?: unknown }>;
  branchOptions: Array<{ id: number; name: string; code: string }>;
  loading: boolean;
  form: { name: string; code: string; branch_id: string };
  creating: boolean;
  onForm: (value: { name: string; code: string; branch_id: string }) => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><Store className="size-4" /> Cajas físicas</CardTitle>
        <CardDescription>Estas son las cajas que el cajero puede abrir desde POS.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <Can I={PERMISSIONS.CASH_REGISTER_CREATE}>
          <div className="grid gap-2 rounded border border-border bg-bg/40 p-3">
            <div className="grid gap-2 sm:grid-cols-[1fr_120px]">
              <Input value={form.name} onChange={(event) => onForm({ ...form, name: event.target.value })} placeholder="Nombre de caja" />
              <Input value={form.code} onChange={(event) => onForm({ ...form, code: event.target.value })} placeholder="Código" />
            </div>
            <Select value={form.branch_id} onChange={(event) => onForm({ ...form, branch_id: event.target.value })}>
              <option value="">Sucursal...</option>
              {branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
            </Select>
            <Button disabled={creating || branchOptions.length === 0} onClick={onCreate}>{creating ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />} Crear caja</Button>
          </div>
        </Can>
        {loading ? <LoadingLine label="Cargando cajas..." /> : registers.length === 0 ? <EmptySetup text="No hay cajas físicas configuradas." /> : (
          <div className="divide-y divide-border rounded border border-border">
            {registers.map((register) => (
              <div key={register.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className="font-medium">{register.name}</p>
                  <p className="font-mono text-xs text-text-muted">{register.code ?? register.id}</p>
                </div>
                <div className="text-right">
                  <Badge variant={(register.status ?? 'active') === 'active' ? 'success' : 'default'}>{register.status ?? 'active'}</Badge>
                  {Boolean(register.open_session) && <p className="mt-1 text-xs text-success">Turno abierto</p>}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export function closeDifference(session: CashRegisterSession, form: CloseForm, rate: number | null) {
  const usd = Number(form.usd || 0);
  const ves = Number(form.ves || 0);
  const vesBase = rate && rate > 0 ? ves / rate : 0;
  const declaredBase = usd + vesBase;
  const expectedUsd = Number(session.expected_cash_usd ?? session.opening_base_amount ?? 0);
  const expectedVes = Number(session.expected_cash_ves ?? session.opening_local_amount ?? 0);
  const cashUsd = usd - expectedUsd;
  const cashVes = ves - expectedVes;

  return { declaredBase, expectedUsd, expectedVes, cashUsd, cashVes };
}

export function hasDifference(base: number, local: number): boolean {
  return Math.abs(base) >= 0.01 || Math.abs(local) >= 0.01;
}

export function cashCountTotals(counts: CashCount[]): Record<CashCount['currency'], number> {
  return counts.reduce((totals, count) => {
    totals[count.currency] += count.denomination * count.quantity;
    return totals;
  }, { USD: 0, VES: 0 });
}

export function DenominationGrid({ currency, form, onForm }: { currency: CashCount['currency']; form: CloseForm; onForm: (value: CloseForm) => void }) {
  return (
    <div>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{currency}</p>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
        {CASH_DENOMINATIONS[currency].map((denomination) => {
          const count = form.counts.find((item) => item.currency === currency && item.denomination === denomination)?.quantity ?? 0;
          return (
            <label key={denomination} className="space-y-1 text-xs text-text-muted">
              <span>{denomination}</span>
              <Input
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                value={count === 0 ? '' : String(count)}
                onChange={(event) => {
                  const raw = event.target.value.replace(/[^\d]/g, '');
                  const quantity = Math.max(0, raw === '' ? 0 : Number(raw));
                  onForm({
                    ...form,
                    counts: [
                      ...form.counts.filter(
                        (item) => !(item.currency === currency && item.denomination === denomination),
                      ),
                      { currency, denomination, quantity },
                    ],
                  });
                }}
                aria-label={`Cantidad de ${denomination} ${currency}`}
              />
            </label>
          );
        })}
      </div>
    </div>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs uppercase text-text-muted">{label}</p>
      <p className="font-semibold">{value}</p>
    </div>
  );
}

function money(value: number | string | null | undefined): string {
  return `$${Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function localMoney(value: number | string | null | undefined): string {
  return `Bs ${Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatLocalNumber(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function bestActiveRate(
  rates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>,
  rateTypes: Array<{ id: number; code?: string; name?: string; is_default?: boolean; is_active?: boolean }>,
): { exchange_rate_type_id: number; code: string; name: string; rate: number } | null {
  const validRates = rates.filter((rate) => {
    const base = rate.base_currency ?? 'USD';
    const quote = rate.quote_currency ?? 'VES';
    return base === 'USD' && quote === 'VES' && Number(rate.rate) > 0;
  });
  const defaultType = rateTypes.find((rateType) => rateType.is_default && rateType.is_active !== false);
  const selected = validRates.find((rate) => defaultType && rate.exchange_rate_type_id === defaultType.id) ?? validRates[0];
  if (!selected) return null;
  const type = rateTypes.find((rateType) => rateType.id === selected.exchange_rate_type_id);

  return {
    exchange_rate_type_id: selected.exchange_rate_type_id,
    code: selected.exchange_rate_type_code ?? type?.code ?? 'Tasa',
    name: type?.name ?? selected.exchange_rate_type_code ?? type?.code ?? 'Tasa',
    rate: Number(selected.rate),
  };
}

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'No se pudo completar la acción.';
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

function LoadingLine({ label }: { label: string }) {
  return (
    <div className="flex items-center gap-2 rounded border border-border p-3 text-sm text-text-muted">
      <Loader2 className="size-4 animate-spin" /> {label}
    </div>
  );
}

function EmptySetup({ text }: { text: string }) {
  return (
    <div className="rounded border border-dashed border-border p-4 text-sm text-text-muted">
      {text}
    </div>
  );
}
