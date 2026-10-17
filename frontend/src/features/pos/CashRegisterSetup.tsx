import { useMemo, useState, type ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { Banknote, Building2, Eye, EyeOff, Loader2, Plus, Store } from 'lucide-react';
import { toast } from '@/lib/toast';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useUsers } from '@/features/users/api';
import { CashRegisterCommandCenter } from './CashRegisterCommandCenter';
import {
  type CashRegisterSession,
  useAddCashMovement,
  useBranchesForPos,
  useCashRegisters,
  useCashSessions,
  useCloseCashSession,
  useCreateCashRegister,
  useCreatePosBranch,
  useCurrentExchangeRatesForPos,
  useExchangeRateTypesForPos,
  useOpenCashSession,
} from './api';

export interface CloseForm { sessionId: number | null; usd: string; ves: string; notes: string; blind: boolean }

const emptyCloseForm: CloseForm = { sessionId: null, usd: '', ves: '', notes: '', blind: false };
const emptyOpenForm = {
  branch_id: '',
  cash_register_id: '',
  cashier_id: '',
  opening_base_amount: '0',
  opening_local_amount: '0',
};
const emptyMovementForm = { type: 'outflow', amount: '', notes: '' };

export function CashRegisterSetup() {
  const [activeTab, setActiveTab] = useState('operacion');
  const [showOpenForm, setShowOpenForm] = useState(false);
  const [showMovementForm, setShowMovementForm] = useState(false);
  const [branchDialogOpen, setBranchDialogOpen] = useState(false);
  const [registerDialogOpen, setRegisterDialogOpen] = useState(false);
  const [branchForm, setBranchForm] = useState({ name: '', code: '' });
  const [registerForm, setRegisterForm] = useState({ name: '', code: '', branch_id: '' });
  const [openForm, setOpenForm] = useState(emptyOpenForm);
  const [movementForm, setMovementForm] = useState(emptyMovementForm);
  const [closeForm, setCloseForm] = useState<CloseForm>(emptyCloseForm);

  const canOpen = useCan(PERMISSIONS.CASH_REGISTER_OPEN);
  const canAssignCashier = useCan(PERMISSIONS.CASH_REGISTER_CLOSE);
  const canViewTurns = useCan(PERMISSIONS.CASH_REGISTER_VIEW);
  const canViewBranches = useCan(PERMISSIONS.BRANCHES_VIEW);
  const canCreateBranches = useCan(PERMISSIONS.BRANCHES_CREATE);
  const canCreateRegisters = useCan(PERMISSIONS.CASH_REGISTER_CREATE);
  const canUpdateRegisters = useCan(PERMISSIONS.CASH_REGISTER_UPDATE);
  const canConfigure =
    canViewBranches || canCreateBranches || canCreateRegisters || canUpdateRegisters;

  const mySessionsQuery = useCashSessions();
  const mySessions = mySessionsQuery.data ?? [];
  const activeSession =
    mySessions.find((session) => session.status === 'open' && Boolean(session.cash_register_id)) ?? null;

  const loadReferenceOptions =
    activeTab === 'turnos' ||
    activeTab === 'infraestructura' ||
    (activeTab === 'operacion' && !activeSession && canOpen && showOpenForm);
  const { data: branches = [], isLoading: loadingBranches, isError: branchesError, refetch: refetchBranches } =
    useBranchesForPos({ enabled: loadReferenceOptions });
  const { data: registers = [], isLoading: loadingRegisters, isError: registersError, refetch: refetchRegisters } =
    useCashRegisters({ enabled: loadReferenceOptions });

  const rateEnabled =
    activeTab === 'operacion' &&
    ((canOpen && showOpenForm && !activeSession) ||
      (activeSession !== null && closeForm.sessionId === activeSession.id));
  const { data: rates = [] } = useCurrentExchangeRatesForPos({ enabled: rateEnabled });
  const { data: rateTypes = [] } = useExchangeRateTypesForPos({ enabled: rateEnabled });
  const { data: usersResponse, isLoading: loadingUsers } = useUsers(
    {
      status: 'active',
      scope: 'tenant',
      page: 1,
      per_page: 100,
      search: '',
    },
    rateEnabled && canAssignCashier,
  );
  const cashierOptions = usersResponse?.data ?? [];

  const canMovePermission = useCan(PERMISSIONS.CASH_REGISTER_MOVE);
  const canViewMovements = useCan(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canMove = canMovePermission || canViewMovements;
  const canClose = useCan(PERMISSIONS.CASH_REGISTER_CLOSE);
  const createBranch = useCreatePosBranch();
  const createRegister = useCreateCashRegister();
  const openSession = useOpenCashSession();
  const addMovement = useAddCashMovement();
  const closeSession = useCloseCashSession();

  const branchOptions = useMemo(
    () => branches.filter((branch) => (branch.status ?? 'active') === 'active'),
    [branches],
  );
  const registerOptions = useMemo(
    () => registers.filter((register) => (register.status ?? 'active') === 'active'),
    [registers],
  );
  const activeRate = bestActiveRate(rates, rateTypes);
  const rateLabel = activeRate
    ? activeRate.name + ' (' + activeRate.code + ') · ' + formatLocalNumber(activeRate.rate)
    : null;

  function resetOpenForm(): void {
    setOpenForm(emptyOpenForm);
    setShowOpenForm(false);
  }

  function cancelClose(): void {
    setCloseForm(emptyCloseForm);
  }

  function submitOpen(): void {
    if (!openForm.branch_id || !openForm.cash_register_id) {
      toast.error('Selecciona una sucursal y una caja física activa.');
      return;
    }
    if (canAssignCashier && !openForm.cashier_id) {
      toast.error('Selecciona el cajero responsable del turno.');
      return;
    }
    if (Number(openForm.opening_local_amount || 0) > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de abrir con fondo en bolívares.');
      return;
    }

    openSession.mutate(
      {
        branch_id: Number(openForm.branch_id),
        cash_register_id: Number(openForm.cash_register_id),
        ...(openForm.cashier_id ? { cashier_id: Number(openForm.cashier_id) } : {}),
        opening_base_amount: Number(openForm.opening_base_amount || 0),
        opening_local_amount: Number(openForm.opening_local_amount || 0),
        exchange_rate_type_id:
          Number(openForm.opening_local_amount || 0) > 0 ? activeRate?.exchange_rate_type_id : null,
        notes: 'Apertura desde módulo Cajas',
      },
      { onSuccess: resetOpenForm, onError: (error) => toast.error(errorMessage(error)) },
    );
  }

  function submitMovement(): void {
    if (!activeSession) return;
    if (!movementForm.amount || Number(movementForm.amount) <= 0) {
      toast.error('Indica un monto válido.');
      return;
    }

    addMovement.mutate(
      {
        sessionId: activeSession.id,
        payload: {
          type: movementForm.type as 'inflow' | 'outflow' | 'adjustment',
          method: 'cash',
          currency: 'USD',
          amount: Number(movementForm.amount),
          notes: movementForm.notes || null,
        },
      },
      { onSuccess: () => { setMovementForm(emptyMovementForm); setShowMovementForm(false); }, onError: (error) => toast.error(errorMessage(error)) },
    );
  }

  function submitClose(session: CashRegisterSession): void {
    const usd = Number(closeForm.usd || 0);
    const ves = Number(closeForm.ves || 0);
    const diff = closeDifference(session, closeForm, activeRate?.rate ?? null);

    if (usd < 0 || ves < 0) {
      toast.error('El efectivo contado no puede ser negativo.');
      return;
    }
    if (ves > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de cerrar con efectivo en bolívares.');
      return;
    }
    if (hasDifference(diff.cashUsd, diff.cashVes) && !closeForm.blind && !closeForm.notes.trim()) {
      toast.error('Indica una nota para justificar la diferencia de caja.');
      return;
    }

    closeSession.mutate(
      {
        sessionId: session.id,
        payload: {
          counted_base_amount: usd,
          counted_local_amount: ves,
          counted_cash_usd: usd,
          counted_cash_ves: ves,
          exchange_rate_type_id: ves > 0 ? activeRate?.exchange_rate_type_id : null,
          counting_mode: closeForm.blind ? 'blind' : 'standard',
          closing_notes: closeForm.notes.trim() || null,
        },
      },
      { onSuccess: () => setCloseForm(emptyCloseForm), onError: (error) => toast.error(errorMessage(error)) },
    );
  }

  function submitBranch(): void {
    if (!branchForm.name.trim() || !branchForm.code.trim()) {
      toast.error('Indica el nombre y el código de la sucursal.');
      return;
    }
    createBranch.mutate(
      {
        name: branchForm.name.trim(),
        code: branchForm.code.trim().toUpperCase(),
        status: 'active',
      },
      {
        onSuccess: () => { setBranchForm({ name: '', code: '' }); setBranchDialogOpen(false); },
      },
    );
  }

  function submitRegister(): void {
    if (!registerForm.name.trim() || !registerForm.code.trim() || !registerForm.branch_id) {
      toast.error('Indica el nombre, el código y la sucursal de la caja.');
      return;
    }
    createRegister.mutate(
      {
        name: registerForm.name.trim(),
        code: registerForm.code.trim().toUpperCase(),
        branch_id: Number(registerForm.branch_id),
        status: 'active',
      },
      {
        onSuccess: () => {
          setRegisterForm({ name: '', code: '', branch_id: '' });
          setRegisterDialogOpen(false);
        },
      },
    );
  }

  return (
    <PageLayout
      title="Cajas"
      description="Opera tu caja primero. Los turnos y la configuración están en sus propias vistas."
      actions={
        <Button asChild className="min-h-11">
          <Link to="/pos">
            <Banknote className="size-4" /> Ir al POS
          </Link>
        </Button>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-3">
        <TabsList className="flex h-auto w-full flex-wrap gap-1 sm:w-fit">
          <TabsTrigger value="operacion" className="min-h-11 flex-1 sm:flex-none">Mi caja</TabsTrigger>
          {canViewTurns && <TabsTrigger value="turnos" className="min-h-11 flex-1 sm:flex-none">Turnos</TabsTrigger>}
          {canConfigure && <TabsTrigger value="infraestructura" className="min-h-11 flex-1 sm:flex-none">Configuración</TabsTrigger>}
        </TabsList>

        <TabsContent value="operacion" className="space-y-3">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2">
                <Banknote className="size-4" /> Mi caja
              </CardTitle>
              <CardDescription>Estado del turno y acciones de caja en un solo lugar.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {mySessionsQuery.isLoading ? (
                <LoadingLine label="Buscando tu caja..." />
              ) : mySessionsQuery.isError ? (
                <div className="flex flex-col gap-3 rounded border border-danger/40 bg-danger/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert">
                  <span>No se pudo cargar tu caja. Intenta nuevamente.</span>
                  <Button variant="outline" className="min-h-11" onClick={() => void mySessionsQuery.refetch()}>
                    Reintentar
                  </Button>
                </div>
              ) : activeSession ? (
                <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(280px,0.75fr)]">
                  <SessionSummary session={activeSession} />
                  <div className="space-y-3 rounded-lg border border-border bg-bg/40 p-3">
                    {canMove && (
                      <div className="space-y-2">
                        {!showMovementForm ? (
                          <Button
                            variant="outline"
                            className="min-h-11 w-full"
                            onClick={() => setShowMovementForm(true)}
                          >
                            Registrar movimiento
                          </Button>
                        ) : (
                          <MovementPanel
                            form={movementForm}
                            pending={addMovement.isPending}
                            onForm={setMovementForm}
                            onCancel={() => { setMovementForm(emptyMovementForm); setShowMovementForm(false); }}
                            onSubmit={submitMovement}
                          />
                        )}
                      </div>
                    )}
                    {canClose && (
                      closeForm.sessionId === activeSession.id ? (
                        <ClosePanel
                          session={activeSession}
                          form={closeForm}
                          rate={activeRate?.rate ?? null}
                          rateLabel={rateLabel}
                          closing={closeSession.isPending}
                          onForm={setCloseForm}
                          onCancel={cancelClose}
                          onClose={submitClose}
                        />
                      ) : (
                        <Button
                          variant="danger"
                          className="min-h-11 w-full"
                          onClick={() => setCloseForm({ ...emptyCloseForm, sessionId: activeSession.id })}
                        >
                          Cerrar caja
                        </Button>
                      )
                    )}
                    {!canMove && !canClose && (
                      <p className="rounded border border-border bg-surface p-3 text-sm text-text-muted">
                        Tu turno está disponible en modo de solo lectura.
                      </p>
                    )}
                  </div>
                </div>
              ) : (
                <div className="rounded-lg border border-dashed border-border bg-bg/30 p-4 sm:p-5">
                  {!canOpen ? (
                    <p className="text-sm text-text-muted">No tienes permiso para abrir un turno.</p>
                  ) : !showOpenForm ? (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="font-semibold text-text-primary">No tienes una caja abierta</p>
                        <p className="mt-1 text-sm text-text-muted">Abre un turno para iniciar la operación.</p>
                      </div>
                      <Button className="min-h-11" onClick={() => setShowOpenForm(true)}>
                        <Plus className="size-4" /> Abrir turno
                      </Button>
                    </div>
                  ) : (
                    <OpenSessionPanel
                      branches={branchOptions}
                      registers={registerOptions}
                      cashierOptions={cashierOptions}
                      canAssignCashier={canAssignCashier}
                      loadingOptions={loadingBranches || loadingRegisters}
                      optionsError={branchesError || registersError}
                      loadingUsers={loadingUsers}
                      rateLabel={rateLabel}
                      form={openForm}
                      opening={openSession.isPending}
                      onForm={setOpenForm}
                      onCancel={resetOpenForm}
                      onOpen={submitOpen}
                      onRetryOptions={() => {
                        void refetchBranches();
                        void refetchRegisters();
                      }}
                    />
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {canViewTurns && (
          <TabsContent value="turnos" className="space-y-3">
            {branchesError || registersError ? (
              <div className="flex flex-col gap-3 rounded border border-danger/40 bg-danger/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert">
                <span>No se pudieron cargar las opciones de sucursal y caja.</span>
                <Button variant="outline" className="min-h-11" onClick={() => { void refetchBranches(); void refetchRegisters(); }}>
                  Reintentar
                </Button>
              </div>
            ) : null}
            <CashRegisterCommandCenter
              branches={branchOptions}
              registers={registerOptions}
              loadingOptions={loadingBranches || loadingRegisters}
            />
          </TabsContent>
        )}

        {canConfigure && (
          <TabsContent value="infraestructura" className="space-y-3">
            <div className="grid gap-3 xl:grid-cols-2">
              <BranchesCard
                branches={branches}
                loading={loadingBranches}
                error={branchesError}
                onRetry={() => void refetchBranches()}
                onCreate={() => setBranchDialogOpen(true)}
              />
              <RegistersCard
                registers={registers}
                branches={branchOptions}
                loading={loadingRegisters}
                error={registersError}
                onRetry={() => void refetchRegisters()}
                onCreate={() => setRegisterDialogOpen(true)}
              />
            </div>

            <Dialog open={branchDialogOpen} onOpenChange={setBranchDialogOpen}>
              <DialogContent className="w-[calc(100%-2rem)] sm:max-w-md">
                <DialogHeader>
                  <DialogTitle>Nueva sucursal</DialogTitle>
                  <DialogDescription>Agrega una sucursal para organizar sus cajas físicas.</DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                  <Field label="Nombre de sucursal">
                    <Input className="min-h-11" value={branchForm.name} onChange={(event) => setBranchForm({ ...branchForm, name: event.target.value })} />
                  </Field>
                  <Field label="Código">
                    <Input className="min-h-11" value={branchForm.code} onChange={(event) => setBranchForm({ ...branchForm, code: event.target.value })} />
                  </Field>
                </div>
                <DialogFooter>
                  <Button variant="outline" className="min-h-11" onClick={() => setBranchDialogOpen(false)}>Cancelar</Button>
                  <Button className="min-h-11" disabled={createBranch.isPending} onClick={submitBranch}>
                    {createBranch.isPending && <Loader2 className="size-4 animate-spin" />} Crear sucursal
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>

            <Dialog open={registerDialogOpen} onOpenChange={setRegisterDialogOpen}>
              <DialogContent className="w-[calc(100%-2rem)] sm:max-w-md">
                <DialogHeader>
                  <DialogTitle>Nueva caja física</DialogTitle>
                  <DialogDescription>Asigna la caja a la sucursal donde se utilizará.</DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                  <Field label="Nombre de caja">
                    <Input className="min-h-11" value={registerForm.name} onChange={(event) => setRegisterForm({ ...registerForm, name: event.target.value })} />
                  </Field>
                  <Field label="Código">
                    <Input className="min-h-11" value={registerForm.code} onChange={(event) => setRegisterForm({ ...registerForm, code: event.target.value })} />
                  </Field>
                  <Field label="Sucursal">
                    <Select className="min-h-11" value={registerForm.branch_id} onChange={(event) => setRegisterForm({ ...registerForm, branch_id: event.target.value })}>
                      <option value="">Selecciona una sucursal</option>
                      {branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} · {branch.name}</option>)}
                    </Select>
                  </Field>
                </div>
                <DialogFooter>
                  <Button variant="outline" className="min-h-11" onClick={() => setRegisterDialogOpen(false)}>Cancelar</Button>
                  <Button className="min-h-11" disabled={createRegister.isPending || branchOptions.length === 0} onClick={submitRegister}>
                    {createRegister.isPending && <Loader2 className="size-4 animate-spin" />} Crear caja
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </TabsContent>
        )}
      </Tabs>
    </PageLayout>
  );
}

function SessionSummary({ session }: { session: CashRegisterSession }) {
  return (
    <section className="min-w-0 rounded-lg border border-border bg-surface p-3 sm:p-4" aria-labelledby="active-cash-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id="active-cash-title" className="text-lg font-semibold leading-tight text-text-primary">
            Mi caja ahora está abierta
          </h2>
          <p className="mt-1 break-words text-sm text-text-muted">
            {session.cash_register?.name ?? 'Caja física'} · {session.branch?.name ?? 'Sucursal'}
          </p>
          <p className="mt-1 text-xs text-text-muted">
            Cajero: {session.cashier?.name ?? session.cashier_id ?? '—'} · Abierta {formatDate(session.opened_at)}
          </p>
        </div>
        <Badge variant="success">Turno abierto</Badge>
      </div>
      <div className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3">
        <Metric label="Efectivo esperado USD" value={money(session.expected_cash_usd ?? session.expected_base_amount)} />
        <Metric
          label="Efectivo esperado Bs"
          value={localMoney(session.expected_cash_ves ?? session.expected_local_amount)}
        />
      </div>
      {session.notes && (
        <p className="mt-3 rounded border border-border bg-bg/40 p-2 text-sm text-text-muted">
          Nota de apertura: {session.notes}
        </p>
      )}
      {session.movements?.length ? (
        <div className="mt-3 border-t border-border pt-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">Actividad reciente</p>
          <ul className="space-y-1">
            {session.movements.slice(-3).reverse().map((movement) => (
              <li key={movement.id} className="flex min-w-0 justify-between gap-3 text-sm">
                <span className="min-w-0 break-words text-text-muted">
                  {movement.type === 'inflow' ? 'Entrada' : movement.type === 'outflow' ? 'Salida' : movement.type} · {movement.notes ?? 'Movimiento'}
                </span>
                <strong className="shrink-0">{movement.currency === 'VES' ? localMoney(movement.amount) : money(movement.amount)}</strong>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </section>
  );
}

function OpenSessionPanel({
  branches,
  registers,
  cashierOptions,
  canAssignCashier,
  loadingOptions,
  optionsError,
  loadingUsers,
  rateLabel,
  form,
  opening,
  onForm,
  onCancel,
  onOpen,
  onRetryOptions,
}: {
  branches: { id: number; name: string; code: string }[];
  registers: { id: number; name: string; code?: string | null; branch_id?: number | null }[];
  cashierOptions: { id: number; name: string; email?: string | null }[];
  canAssignCashier: boolean;
  loadingOptions: boolean;
  optionsError: boolean;
  loadingUsers: boolean;
  rateLabel: string | null;
  form: typeof emptyOpenForm;
  opening: boolean;
  onForm: (value: typeof emptyOpenForm) => void;
  onCancel: () => void;
  onOpen: () => void;
  onRetryOptions: () => void;
}) {
  const filteredRegisters = form.branch_id
    ? registers.filter((register) => Number(register.branch_id) === Number(form.branch_id))
    : registers;

  return (
    <div className="space-y-3">
      <div>
        <h3 className="font-semibold text-text-primary">Abrir un turno</h3>
        <p className="mt-1 text-sm text-text-muted">Confirma dónde operar y el fondo inicial de ambas monedas.</p>
      </div>
      {loadingOptions ? (
        <LoadingLine label="Cargando sucursales y cajas..." />
      ) : optionsError ? (
        <div className="flex flex-col gap-2 text-sm text-danger sm:flex-row sm:items-center">
          <span>No se pudieron cargar las sucursales y cajas.</span>
          <Button variant="outline" className="min-h-11" onClick={onRetryOptions}>Reintentar</Button>
        </div>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Field label="Sucursal">
              <Select
                aria-label="Sucursal para abrir"
                className="min-h-11"
                value={form.branch_id}
                onChange={(event) => onForm({ ...form, branch_id: event.target.value, cash_register_id: '' })}
                data-testid="pos-open-branch"
              >
                <option value="">Selecciona una sucursal</option>
                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} · {branch.name}</option>)}
              </Select>
            </Field>
            <Field label="Caja física">
              <Select
                aria-label="Caja física para abrir"
                className="min-h-11"
                value={form.cash_register_id}
                onChange={(event) => onForm({ ...form, cash_register_id: event.target.value })}
                data-testid="pos-open-register"
              >
                <option value="">Selecciona una caja</option>
                {filteredRegisters.map((register) => <option key={register.id} value={register.id}>{register.code ?? register.id} · {register.name}</option>)}
              </Select>
            </Field>
            {canAssignCashier && (
              <Field label="Cajero responsable">
                <Select
                  aria-label="Cajero responsable"
                  className="min-h-11"
                  value={form.cashier_id}
                  onChange={(event) => onForm({ ...form, cashier_id: event.target.value })}
                  data-testid="pos-open-cashier"
                >
                  <option value="">{loadingUsers ? 'Cargando cajeros...' : 'Selecciona un cajero'}</option>
                  {cashierOptions.map((user) => <option key={user.id} value={user.id}>{user.name}{user.email ? ' · ' + user.email : ''}</option>)}
                </Select>
              </Field>
            )}
            <Field label="Fondo $ (USD)">
              <Input
                aria-label="Fondo $ (USD)"
                className="min-h-11"
                type="text"
                inputMode="decimal"
                value={form.opening_base_amount}
                onChange={(event) => onForm({ ...form, opening_base_amount: event.target.value })}
                placeholder="0.00"
                data-testid="pos-open-base"
              />
            </Field>
            <Field label="Fondo Bs (VES)">
              <Input
                aria-label="Fondo Bs (VES)"
                className="min-h-11"
                type="text"
                inputMode="decimal"
                value={form.opening_local_amount}
                onChange={(event) => onForm({ ...form, opening_local_amount: event.target.value })}
                placeholder="0.00"
                data-testid="pos-open-local"
              />
            </Field>
          </div>
          <p className="text-xs text-text-muted">
            {canAssignCashier ? 'El turno se asignará al cajero seleccionado. ' : 'El turno se asignará a tu usuario. '}
            {rateLabel ? 'La tasa activa es ' + rateLabel + '.' : 'Necesitas una tasa USD/VES para usar fondo en bolívares.'}
          </p>
        </>
      )}
      <div className="flex flex-col-reverse gap-2 border-t border-border pt-3 sm:flex-row sm:justify-end">
        <Button variant="outline" className="min-h-11" onClick={onCancel}>Cancelar</Button>
        <Button
          className="min-h-11"
          disabled={loadingOptions || optionsError || opening || !form.branch_id || !form.cash_register_id || (canAssignCashier && !form.cashier_id)}
          onClick={onOpen}
        >
          {opening && <Loader2 className="size-4 animate-spin" />} Confirmar apertura
        </Button>
      </div>
    </div>
  );
}

function MovementPanel({
  form,
  pending,
  onForm,
  onCancel,
  onSubmit,
}: {
  form: typeof emptyMovementForm;
  pending: boolean;
  onForm: (value: typeof emptyMovementForm) => void;
  onCancel: () => void;
  onSubmit: () => void;
}) {
  return (
    <div className="space-y-3 rounded border border-border bg-surface p-3">
      <h3 className="font-semibold">Registrar movimiento</h3>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Tipo de movimiento">
          <Select className="min-h-11" value={form.type} onChange={(event) => onForm({ ...form, type: event.target.value })}>
            <option value="inflow">Entrada</option>
            <option value="outflow">Salida</option>
            <option value="adjustment">Ajuste</option>
          </Select>
        </Field>
        <Field label="Monto USD">
          <Input className="min-h-11" type="text" inputMode="decimal" value={form.amount} onChange={(event) => onForm({ ...form, amount: event.target.value })} />
        </Field>
        <Field label="Nota (opcional)">
          <Input className="min-h-11 sm:col-span-2" value={form.notes} onChange={(event) => onForm({ ...form, notes: event.target.value })} />
        </Field>
      </div>
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="outline" className="min-h-11" onClick={onCancel}>Cancelar</Button>
        <Button className="min-h-11" disabled={pending} onClick={onSubmit}>{pending ? 'Registrando...' : 'Guardar movimiento'}</Button>
      </div>
    </div>
  );
}

function ClosePanel({
  session,
  form,
  rate,
  rateLabel,
  closing,
  onForm,
  onCancel,
  onClose,
}: {
  session: CashRegisterSession;
  form: CloseForm;
  rate: number | null;
  rateLabel: string | null;
  closing: boolean;
  onForm: (value: CloseForm) => void;
  onCancel: () => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  const diff = closeDifference(session, form, rate);
  const needsNote = hasDifference(diff.cashUsd, diff.cashVes);

  return (
    <section className="space-y-3 rounded border border-border bg-surface p-3" aria-labelledby="closing-title">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h3 id="closing-title" className="font-semibold">Arqueo de cierre</h3>
          <p className="mt-1 text-xs text-text-muted">
            {form.blind ? 'El efectivo esperado está oculto.' : 'Compara el efectivo contado con el monto esperado.'}
          </p>
        </div>
        <Button
          size="sm"
          className="min-h-11"
          variant={form.blind ? 'primary' : 'outline'}
          onClick={() => onForm({ ...form, blind: !form.blind, usd: form.blind ? form.usd : '', ves: form.blind ? form.ves : '' })}
        >
          {form.blind ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
          {form.blind ? 'Cierre ciego activo' : 'Usar cierre ciego'}
        </Button>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Efectivo contado USD">
          <Input className="min-h-11" type="text" inputMode="decimal" value={form.usd} onChange={(event) => onForm({ ...form, usd: event.target.value })} />
        </Field>
        <Field label="Efectivo contado Bs (VES)">
          <Input className="min-h-11" type="text" inputMode="decimal" value={form.ves} onChange={(event) => onForm({ ...form, ves: event.target.value })} />
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-3 rounded bg-bg/50 p-3 text-sm sm:grid-cols-3">
        <Metric label="Declarado USD" value={money(Number(form.usd || 0))} />
        <Metric label="Declarado Bs" value={localMoney(Number(form.ves || 0))} />
        <Metric label="Total equivalente USD" value={money(diff.declaredBase)} />
        {!form.blind && <Metric label="Esperado USD" value={money(diff.expectedUsd)} />}
        {!form.blind && <Metric label="Diferencia USD" value={money(diff.cashUsd)} />}
        {!form.blind && <Metric label="Esperado Bs" value={localMoney(diff.expectedVes)} />}
        {!form.blind && <Metric label="Diferencia Bs" value={localMoney(diff.cashVes)} />}
        {form.blind && (
          <p className="col-span-2 rounded bg-primary/5 p-2 text-xs text-text-muted sm:col-span-3">
            La diferencia se calculará al confirmar el cierre y quedará visible para el responsable.
          </p>
        )}
      </div>
      {!form.blind && needsNote && (
        <p className="text-xs text-warning">La diferencia requiere una nota para completar el cierre.</p>
      )}
      {rateLabel && <p className="text-xs text-text-muted">Conversión VES: {rateLabel}.</p>}
      <Field label={form.blind || !needsNote ? 'Nota de cierre (opcional)' : 'Nota obligatoria por diferencia'}>
        <Input className="min-h-11" value={form.notes} onChange={(event) => onForm({ ...form, notes: event.target.value })} />
      </Field>
      <div className="flex flex-col-reverse gap-2 border-t border-border pt-3 sm:flex-row sm:justify-end">
        <Button variant="outline" className="min-h-11" onClick={onCancel}>Cancelar</Button>
        <Button
          variant="danger"
          className="min-h-11"
          disabled={closing || (!form.blind && needsNote && !form.notes.trim())}
          onClick={() => onClose(session)}
        >
          {closing && <Loader2 className="size-4 animate-spin" />} Confirmar cierre
        </Button>
      </div>
    </section>
  );
}

function BranchesCard({
  branches,
  loading,
  error,
  onRetry,
  onCreate,
}: {
  branches: { id: number; name: string; code: string; status?: string | null }[];
  loading: boolean;
  error: boolean;
  onRetry: () => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3 pb-3">
        <div>
          <CardTitle className="flex items-center gap-2"><Building2 className="size-4" /> Sucursales</CardTitle>
          <CardDescription>Organiza las cajas físicas por ubicación.</CardDescription>
        </div>
        <Can I={PERMISSIONS.BRANCHES_CREATE}>
          <Button variant="outline" className="min-h-11 shrink-0" onClick={onCreate}><Plus className="size-4" /> Nueva</Button>
        </Can>
      </CardHeader>
      <CardContent>
        {loading ? <LoadingLine label="Cargando sucursales..." /> : error ? (
          <ErrorLine text="No se pudieron cargar las sucursales." onRetry={onRetry} />
        ) : branches.length === 0 ? <EmptyLine text="No hay sucursales configuradas." /> : (
          <ul className="divide-y divide-border overflow-hidden rounded border border-border">
            {branches.map((branch) => (
              <li key={branch.id} className="flex min-h-11 items-center justify-between gap-3 p-3">
                <span className="min-w-0">
                  <strong className="block break-words">{branch.name}</strong>
                  <span className="font-mono text-xs text-text-muted">{branch.code}</span>
                </span>
                <Badge variant={(branch.status ?? 'active') === 'active' ? 'success' : 'default'}>
                  {branch.status === 'inactive' ? 'Inactiva' : 'Activa'}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function RegistersCard({
  registers,
  branches,
  loading,
  error,
  onRetry,
  onCreate,
}: {
  registers: { id: number; name: string; code?: string | null; branch_id?: number | null; status?: string | null; open_session?: unknown }[];
  branches: { id: number; name: string; code: string }[];
  loading: boolean;
  error: boolean;
  onRetry: () => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3 pb-3">
        <div>
          <CardTitle className="flex items-center gap-2"><Store className="size-4" /> Cajas físicas</CardTitle>
          <CardDescription>Las cajas que los cajeros pueden abrir desde el POS.</CardDescription>
        </div>
        <Can I={PERMISSIONS.CASH_REGISTER_CREATE}>
          <Button variant="outline" className="min-h-11 shrink-0" onClick={onCreate}><Plus className="size-4" /> Nueva</Button>
        </Can>
      </CardHeader>
      <CardContent>
        {loading ? <LoadingLine label="Cargando cajas físicas..." /> : error ? (
          <ErrorLine text="No se pudieron cargar las cajas físicas." onRetry={onRetry} />
        ) : registers.length === 0 ? <EmptyLine text="No hay cajas físicas configuradas." /> : (
          <ul className="divide-y divide-border overflow-hidden rounded border border-border">
            {registers.map((register) => {
              const branch = branches.find((item) => item.id === register.branch_id);
              return (
                <li key={register.id} className="flex min-h-11 items-center justify-between gap-3 p-3">
                  <span className="min-w-0">
                    <strong className="block break-words">{register.name}</strong>
                    <span className="text-xs text-text-muted">
                      {register.code ?? register.id}{branch ? ' · ' + branch.name : ''}
                    </span>
                  </span>
                  <span className="shrink-0 text-right">
                    <Badge variant={(register.status ?? 'active') === 'active' ? 'success' : 'default'}>
                      {register.status === 'inactive' ? 'Inactiva' : 'Activa'}
                    </Badge>
                    {Boolean(register.open_session) && <span className="mt-1 block text-xs text-success">Turno abierto</span>}
                  </span>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block min-w-0 space-y-1"><span className="text-xs font-medium text-text-muted">{label}</span>{children}</label>;
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className="min-w-0"><p className="text-xs text-text-muted">{label}</p><p className="break-words font-semibold text-text-primary">{value}</p></div>;
}

function LoadingLine({ label }: { label: string }) {
  return <div className="flex min-h-11 items-center gap-2 rounded border border-border p-3 text-sm text-text-muted"><Loader2 className="size-4 animate-spin" />{label}</div>;
}

function ErrorLine({ text, onRetry }: { text: string; onRetry: () => void }) {
  return <div className="flex flex-col gap-2 rounded border border-danger/40 bg-danger/5 p-3 text-sm text-danger sm:flex-row sm:items-center sm:justify-between"><span>{text}</span><Button variant="outline" className="min-h-11" onClick={onRetry}>Reintentar</Button></div>;
}

function EmptyLine({ text }: { text: string }) {
  return <div className="rounded border border-dashed border-border p-4 text-sm text-text-muted">{text}</div>;
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

function bestActiveRate(
  rates: { exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }[],
  rateTypes: { id: number; code?: string; name?: string; is_default?: boolean; is_active?: boolean }[],
): { exchange_rate_type_id: number; code: string; name: string; rate: number } | null {
  const validRates = rates.filter((rate) => {
    const base = rate.base_currency ?? 'USD';
    const quote = rate.quote_currency ?? 'VES';
    return base === 'USD' && quote === 'VES' && Number(rate.rate) > 0;
  });
  const defaultType = rateTypes.find((rateType) => rateType.is_default && rateType.is_active !== false);
  const selected = validRates.find((rate) => rate.exchange_rate_type_id === defaultType?.id) ?? validRates[0];
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
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-VE');
}

function formatLocalNumber(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function money(value: number | string | null | undefined): string {
  return '$' + Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function localMoney(value: number | string | null | undefined): string {
  return 'Bs ' + Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
